<?php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

/* =========================================================
   ERROR REPORTING
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');


/* =========================================================
   HELPER
========================================================= */

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   AUTHENTICATION
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'teacher'
) {
    error_log(
        "Unauthorized access attempt to manage_test.php"
    );

    header(
        "Location: /EXAMCENTER/login.php?error=Not logged in"
    );

    exit();
}


/* =========================================================
   INITIAL STATE
========================================================= */

$error = '';

$teacher = null;

$assigned_subjects = [];

$tests = [];


/* =========================================================
   DATABASE
========================================================= */

try {

    $database = Database::getInstance();

    $conn = $database->getConnection();


    if ($conn->connect_error) {

        throw new Exception(
            "Database connection failed: " .
            $conn->connect_error
        );

    }


    $conn->set_charset('utf8mb4');


    $teacher_id = (int) $_SESSION['user_id'];


    /* =====================================================
       TEACHER PROFILE
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            username,
            last_name
        FROM teachers
        WHERE id = ?
        LIMIT 1
    ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare teacher profile query: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "i",
        $teacher_id
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to execute teacher profile query: " .
            $stmt->error
        );

    }


    $teacher = $stmt
        ->get_result()
        ->fetch_assoc();


    $stmt->close();


    if (!$teacher) {

        error_log(
            "No teacher found for user_id={$teacher_id}"
        );

        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();

    }


    /* =====================================================
       ASSIGNED SUBJECTS
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT DISTINCT
            subject
        FROM teacher_subjects
        WHERE teacher_id = ?
          AND subject IS NOT NULL
          AND subject <> ''
        ORDER BY subject ASC
    ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare assigned subjects query: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "i",
        $teacher_id
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to execute assigned subjects query: " .
            $stmt->error
        );

    }


    $subject_result = $stmt->get_result();


    while ($row = $subject_result->fetch_assoc()) {

        $subject = trim(
            (string) $row['subject']
        );

        if ($subject !== '') {

            $assigned_subjects[] = $subject;

        }

    }


    $stmt->close();


    /* =====================================================
       NO SUBJECTS
    ===================================================== */

    if (empty($assigned_subjects)) {

        $error =
            "No subjects have been assigned to you. " .
            "Please contact your administrator.";

    }


    /* =====================================================
       FETCH TESTS
       
       IMPORTANT DATABASE STRUCTURE:

       tests
       ├── academic_level_id
       ├── subject
       ├── title
       ├── year
       └── duration

       tests DOES NOT contain:
       └── teacher_id
       └── stream_id

       Therefore:

       Teacher ownership/filtering:
           teacher_subjects.subject
                   ↓
           tests.subject

       Class:
           tests.academic_level_id
                   ↓
           academic_levels.id
                   ↓
           academic_levels.level_code

       Example:
           academic_level_id = 3
           level_code = JSS2

       We deliberately DO NOT join classes here because
       classes contains streams and a single academic level
       can have multiple streams. Since tests has no
       stream_id, joining classes could duplicate the same
       test.
    ===================================================== */

    if (!empty($assigned_subjects)) {

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($assigned_subjects),
                '?'
            )
        );


        $query = "
            SELECT
                t.id,
                t.title,
                t.academic_level_id,
                t.subject,
                t.year,
                t.duration,
                t.created_at,

                al.level_code,
                al.class_group

            FROM tests AS t

            INNER JOIN academic_levels AS al
                ON al.id = t.academic_level_id

            WHERE t.subject IN ($placeholders)

            ORDER BY
                al.level_code ASC,
                t.subject ASC,
                t.created_at DESC,
                t.id DESC
        ";


        $stmt = $conn->prepare($query);


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare test query: " .
                $conn->error
            );

        }


        $types = str_repeat(
            's',
            count($assigned_subjects)
        );


        $params = $assigned_subjects;


        $stmt->bind_param(
            $types,
            ...$params
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Unable to execute test query: " .
                $stmt->error
            );

        }


        $test_result = $stmt->get_result();


        $tests = $test_result->fetch_all(
            MYSQLI_ASSOC
        );


        $stmt->close();

    }


} catch (Throwable $e) {

    error_log(
        "Manage test error: " .
        $e->getMessage() .
        " in " .
        $e->getFile() .
        ":" .
        $e->getLine()
    );


    $error =
        "Unable to load tests at this time.";

}


/* =========================================================
   HTML
========================================================= */

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Manage Tests | Examcenter
</title>


<link
    href="../css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="../css/all.css"
>

<link
    rel="stylesheet"
    href="../css/admin-dashboard.css"
>


<style>

    :root {

        --primary: #4361ee;
        --primary-dark: #3651d4;

        --surface: #ffffff;
        --background: #f5f7fb;

        --border: #e8ebf2;

        --text: #1f2937;
        --muted: #718096;

        --success: #198754;
        --danger: #dc3545;

    }


    body {

        background: var(--background);
        color: var(--text);

    }


    .main-content {

        min-height: 100vh;
        padding-bottom: 50px;

    }


    /* =====================================================
       PAGE HEADER
    ===================================================== */

    .page-header {

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 20px;

        margin-bottom: 25px;

    }


    .page-title {

        margin: 0;

        font-size: 1.65rem;

        font-weight: 700;

        color: #172033;

    }


    .page-subtitle {

        margin: 5px 0 0;

        color: var(--muted);

        font-size: .9rem;

    }


    /* =====================================================
       ALERT
    ===================================================== */

    .page-alert {

        border-radius: 10px;

        border: none;

        font-size: .86rem;

        margin-bottom: 20px;

    }


    /* =====================================================
       TEST CARD
    ===================================================== */

    .tests-card {

        background: var(--surface);

        border: 1px solid var(--border);

        border-radius: 14px;

        box-shadow:
            0 3px 15px
            rgba(15, 23, 42, .04);

        overflow: hidden;

    }


    .tests-card-header {

        padding: 18px 20px;

        border-bottom:
            1px solid var(--border);

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 15px;

    }


    .tests-card-title {

        margin: 0;

        font-size: 1rem;

        font-weight: 700;

        color: #263247;

    }


    .tests-card-subtitle {

        margin: 4px 0 0;

        color: var(--muted);

        font-size: .82rem;

    }


    .test-count {

        display: inline-flex;

        align-items: center;

        justify-content: center;

        min-width: 34px;

        height: 30px;

        padding: 0 10px;

        border-radius: 20px;

        background:
            rgba(67, 97, 238, .1);

        color: var(--primary);

        font-size: .76rem;

        font-weight: 700;

    }


    /* =====================================================
       TABLE
    ===================================================== */

    .tests-table-wrapper {

        width: 100%;

        overflow-x: auto;

        -webkit-overflow-scrolling: touch;

    }


    .tests-table {

        width: 100%;

        min-width: 900px;

        margin: 0;

        vertical-align: middle;

    }


    .tests-table th {

        background: #f8fafc;

        color: #4a5568;

        font-size: .74rem;

        font-weight: 700;

        text-transform: uppercase;

        letter-spacing: .025em;

        white-space: nowrap;

        padding: 13px 16px;

        border-bottom:
            1px solid var(--border);

    }


    .tests-table td {

        padding: 14px 16px;

        color: #334155;

        font-size: .87rem;

        vertical-align: middle;

        border-bottom:
            1px solid #f0f2f6;

    }


    .tests-table tbody tr:last-child td {

        border-bottom: none;

    }


    .tests-table tbody tr:hover {

        background: #fbfcfe;

    }


    /* =====================================================
       TEST TITLE
    ===================================================== */

    .test-title {

        display: block;

        font-weight: 700;

        color: #1f2937;

        margin-bottom: 3px;

    }


    .test-id {

        color: #98a2b3;

        font-size: .7rem;

    }


    /* =====================================================
       CLASS BADGE
    ===================================================== */

    .class-badge {

        display: inline-flex;

        align-items: center;

        gap: 6px;

        padding: 6px 10px;

        border-radius: 7px;

        background:
            rgba(67, 97, 238, .09);

        color: var(--primary);

        font-size: .76rem;

        font-weight: 700;

        white-space: nowrap;

    }


    .class-group {

        display: block;

        margin-top: 4px;

        color: #98a2b3;

        font-size: .7rem;

    }


    /* =====================================================
       SUBJECT
    ===================================================== */

    .subject-badge {

        display: inline-flex;

        align-items: center;

        gap: 6px;

        padding: 6px 9px;

        border-radius: 7px;

        background:
            rgba(13, 110, 253, .08);

        color: #0d6efd;

        font-size: .76rem;

        font-weight: 650;

    }


    /* =====================================================
       YEAR
    ===================================================== */

    .year-badge {

        display: inline-flex;

        align-items: center;

        gap: 5px;

        padding: 5px 8px;

        border-radius: 7px;

        background: #f8fafc;

        border: 1px solid #e7eaf0;

        color: #526071;

        font-size: .75rem;

        font-weight: 650;

        white-space: nowrap;

    }


    /* =====================================================
       DURATION
    ===================================================== */

    .duration-badge {

        display: inline-flex;

        align-items: center;

        gap: 5px;

        padding: 5px 8px;

        border-radius: 7px;

        background: #f1f5f9;

        color: #475569;

        font-size: .76rem;

        font-weight: 650;

        white-space: nowrap;

    }


    /* =====================================================
       ACTIONS

       Fixed-width action area + flex buttons prevents
       Download/Delete overlap.
    ===================================================== */

    .actions-cell {

        width: 210px;

        min-width: 210px;

        white-space: nowrap;

    }


    .action-buttons {

        display: flex;

        align-items: center;

        gap: 8px;

        flex-wrap: nowrap;

    }


    .action-buttons .btn {

        display: inline-flex;

        align-items: center;

        justify-content: center;

        gap: 6px;

        width: 92px;

        min-width: 92px;

        height: 36px;

        padding: 6px 10px;

        border-radius: 7px;

        font-size: .76rem;

        font-weight: 650;

        white-space: nowrap;

    }


    .action-buttons .btn:disabled {

        opacity: .7;

        cursor: not-allowed;

    }


    /* =====================================================
       EMPTY STATE
    ===================================================== */

    .empty-state {

        padding: 65px 25px;

        text-align: center;

        color: var(--muted);

    }


    .empty-icon {

        width: 70px;

        height: 70px;

        margin: 0 auto 18px;

        border-radius: 18px;

        background:
            rgba(67, 97, 238, .08);

        color: var(--primary);

        display: flex;

        align-items: center;

        justify-content: center;

        font-size: 28px;

    }


    .empty-state h4 {

        margin-bottom: 7px;

        color: #263247;

        font-weight: 700;

    }


    .empty-state p {

        margin: 0;

        font-size: .88rem;

    }


    /* =====================================================
       RESPONSIVE
    ===================================================== */

    @media (max-width: 991.98px) {

        .main-content {

            padding-top: 15px;

        }


        .page-header {

            align-items: flex-start;

        }


        .tests-table th,
        .tests-table td {

            padding: 12px 14px;

        }

    }


    @media (max-width: 767.98px) {

        .page-title {

            font-size: 1.35rem;

        }


        .page-subtitle {

            font-size: .82rem;

        }


        .tests-card {

            border-radius: 11px;

        }


        .tests-card-header {

            align-items: flex-start;

            flex-direction: column;

            padding: 16px;

        }


        /*
         * Keep the table wide instead of squeezing
         * the action buttons.
         */

        .tests-table {

            min-width: 900px;

        }


        .actions-cell {

            width: 210px;

            min-width: 210px;

        }


        .action-buttons {

            flex-wrap: nowrap;

        }

    }

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<div class="sidebar">

<div class="sidebar-brand">

    <h3>

        <i class="fas fa-graduation-cap me-2"></i>

        Examcenter

    </h3>


    <div class="admin-info">

        <small>
            Welcome back,
        </small>

        <h6>
            <?= e($teacher['last_name'] ?? '') ?>
        </h6>

    </div>

</div>


<div class="sidebar-menu mt-4">

    <a href="dashboard.php">

        <i class="fas fa-tachometer-alt"></i>

        Dashboard

    </a>


    <a href="add_question.php">

        <i class="fas fa-plus-circle"></i>

        Add Questions

    </a>


    <a href="bank.php">

        <i class="fas fa-database"></i>

        Question Bank

    </a>


    <a href="view_questions.php">

        <i class="fas fa-list"></i>

        View Questions

    </a>


    <a
        href="manage_test.php"
        class="active"
    >

        <i class="fas fa-list-check"></i>

        Manage Test

    </a>


    <a href="view_results.php">

        <i class="fas fa-chart-bar"></i>

        Exam Results

    </a>


    <a href="manage_students.php">

        <i class="fas fa-users"></i>

        Manage Students

    </a>


    <a href="settings.php">

        <i class="fas fa-cog"></i>

        Settings

    </a>


    <a href="my-profile.php">

        <i class="fas fa-user"></i>

        My Profile

    </a>


    <a
        href="logout.php"
        class="logout-btn"
    >

        <i class="fas fa-sign-out-alt"></i>

        Logout

    </a>

</div>

</div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">

<div class="container-fluid px-3 px-lg-4 py-4">


    <!-- =================================================
         HEADER
    ================================================== -->

    <div class="page-header">

        <div>

            <h1 class="page-title">

                Manage Tests

            </h1>


            <p class="page-subtitle">

                View and manage tests associated with your
                assigned subjects.

            </p>

        </div>


        <button
            type="button"
            class="btn btn-primary d-lg-none"
            id="sidebarToggle"
            aria-label="Toggle navigation"
        >

            <i class="fas fa-bars"></i>

        </button>

    </div>


    <!-- =================================================
         ERROR
    ================================================== -->

    <?php if ($error): ?>

        <div
            class="alert alert-danger page-alert"
            role="alert"
        >

            <i class="fas fa-circle-exclamation me-2"></i>

            <?= e($error) ?>

        </div>

    <?php endif; ?>


    <!-- =================================================
         TEST CARD
    ================================================== -->

    <div class="tests-card">


        <div class="tests-card-header">

            <div>

                <h5 class="tests-card-title">

                    Available Tests

                </h5>


                <p class="tests-card-subtitle">

                    Tests available for your assigned
                    subjects.

                </p>

            </div>


            <?php if (!empty($tests)): ?>

                <span class="test-count">

                    <?= number_format(count($tests)) ?>

                    test<?= count($tests) === 1 ? '' : 's' ?>

                </span>

            <?php endif; ?>

        </div>


        <!-- =================================================
             TEST TABLE
        ================================================== -->

        <?php if (!empty($tests)): ?>

            <div class="tests-table-wrapper">

                <table class="table tests-table mb-0">


                    <thead>

                        <tr>

                            <th>
                                Title
                            </th>

                            <th>
                                Class
                            </th>

                            <th>
                                Subject
                            </th>

                            <th>
                                Year
                            </th>

                            <th>
                                Duration
                            </th>

                            <th class="actions-cell">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($tests as $row): ?>

                            <tr>


                                <!-- TITLE -->

                                <td>

                                    <span class="test-title">

                                        <?= e($row['title']) ?>

                                    </span>


                                    <span class="test-id">

                                        Test #<?= (int) $row['id'] ?>

                                    </span>

                                </td>


                                <!-- CLASS -->

                                <td>

                                    <span class="class-badge">

                                        <i class="fas fa-school"></i>

                                        <?= e($row['level_code']) ?>

                                    </span>


                                    <span class="class-group">

                                        <?= e($row['class_group']) ?>

                                    </span>

                                </td>


                                <!-- SUBJECT -->

                                <td>

                                    <span class="subject-badge">

                                        <i class="fas fa-book"></i>

                                        <?= e($row['subject']) ?>

                                    </span>

                                </td>


                                <!-- YEAR -->

                                <td>

                                    <span class="year-badge">

                                        <i class="fas fa-calendar"></i>

                                        <?= e($row['year']) ?>

                                    </span>

                                </td>


                                <!-- DURATION -->

                                <td>

                                    <span class="duration-badge">

                                        <i class="fas fa-clock"></i>

                                        <?= e($row['duration']) ?>

                                        min

                                    </span>

                                </td>


                                <!-- ACTIONS -->

                                <td class="actions-cell">

                                    <div class="action-buttons">


                                        <a
                                            class="btn btn-primary"
                                            href="download.php?class=<?= urlencode($row['level_code']) ?>&subject=<?= urlencode($row['subject']) ?>&title=<?= urlencode($row['title']) ?>"
                                        >

                                            <i class="fas fa-download"></i>

                                            <span>
                                                Download
                                            </span>

                                        </a>


                                        <button
                                            type="button"
                                            class="btn btn-danger delete-test"
                                            data-id="<?= (int) $row['id'] ?>"
                                            data-title="<?= e($row['title']) ?>"
                                        >

                                            <i class="fas fa-trash"></i>

                                            <span>
                                                Delete
                                            </span>

                                        </button>


                                    </div>

                                </td>


                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


        <?php else: ?>


            <!-- =================================================
                 EMPTY STATE
            ================================================== -->

            <div class="empty-state">

                <div class="empty-icon">

                    <i class="fas fa-file-circle-question"></i>

                </div>


                <h4>

                    No Tests Found

                </h4>


                <p>

                    <?php if ($error): ?>

                        We could not load the tests.
                        Please try again or contact your
                        administrator.

                    <?php elseif (empty($assigned_subjects)): ?>

                        No subjects are currently assigned
                        to you.

                    <?php else: ?>

                        There are currently no tests
                        available for your assigned
                        subjects.

                    <?php endif; ?>

                </p>

            </div>


        <?php endif; ?>


    </div>

</div>

</div>

<!-- =========================================================
     SCRIPTS
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /* =============================================
           SIDEBAR TOGGLE
        ============================================== */

        const sidebarToggle =
            document.getElementById(
                'sidebarToggle'
            );


        const sidebar =
            document.querySelector(
                '.sidebar'
            );


        if (
            sidebarToggle &&
            sidebar
        ) {

            sidebarToggle.addEventListener(
                'click',
                function () {

                    sidebar.classList.toggle(
                        'active'
                    );

                }
            );

        }


        /* =============================================
           CLOSE MOBILE SIDEBAR
        ============================================== */

        if (sidebar) {

            const sidebarLinks =
                sidebar.querySelectorAll('a');


            sidebarLinks.forEach(
                function (link) {

                    link.addEventListener(
                        'click',
                        function () {

                            if (
                                window.innerWidth <= 991 &&
                                sidebar.classList.contains('active')
                            ) {

                                sidebar.classList.remove(
                                    'active'
                                );

                            }

                        }
                    );

                }
            );

        }


        /* =============================================
           DELETE TEST
        ============================================== */

        document
            .querySelectorAll('.delete-test')
            .forEach(
                function (button) {


                    button.addEventListener(
                        'click',
                        function () {


                            const testId =
                                this.dataset.id;


                            const testTitle =
                                this.dataset.title;


                            const confirmed =
                                confirm(
                                    'Are you sure you want to delete the test "' +
                                    testTitle +
                                    '"?\n\n' +
                                    'This action cannot be undone.'
                                );


                            if (!confirmed) {

                                return;

                            }


                            this.disabled = true;


                            const originalHtml =
                                this.innerHTML;


                            this.innerHTML =
                                '<i class="fas fa-spinner fa-spin"></i>' +
                                '<span>Deleting...</span>';


                            $.ajax({

                                url:
                                    'delete_test.php',

                                type:
                                    'POST',

                                data: {

                                    id: testId

                                },


                                success:
                                    function (response) {


                                        let res;


                                        try {

                                            res =
                                                typeof response === 'object'
                                                    ? response
                                                    : JSON.parse(response);

                                        } catch (error) {

                                            alert(
                                                'The server returned an invalid response.'
                                            );


                                            button.disabled =
                                                false;


                                            button.innerHTML =
                                                originalHtml;


                                            return;

                                        }


                                        if (res.success) {


                                            alert(
                                                'Test deleted successfully.'
                                            );


                                            window.location.reload();


                                        } else {


                                            alert(
                                                'Error: ' +
                                                (
                                                    res.error ||
                                                    'Unable to delete test.'
                                                )
                                            );


                                            button.disabled =
                                                false;


                                            button.innerHTML =
                                                originalHtml;

                                        }

                                    },


                                error:
                                    function () {


                                        alert(
                                            'An unexpected error occurred while deleting the test.'
                                        );


                                        button.disabled =
                                            false;


                                        button.innerHTML =
                                            originalHtml;

                                    }

                            });

                        }

                    );

                }

            );

    }

);

</script>

</body>

</html>
