<?php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'teacher'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");

    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }


    /*
    |--------------------------------------------------------------------------
    | TEACHER PROFILE
    |--------------------------------------------------------------------------
    */

    $teacher_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT username, last_name
        FROM teachers
        WHERE id = ?
    ");

    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $teacher = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();


    if (!$teacher) {

        error_log(
            "No teacher found for user_id=" . $teacher_id
        );

        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | ASSIGNED SUBJECTS
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT subject
        FROM teacher_subjects
        WHERE teacher_id = ?
    ");

    if (!$stmt) {
        error_log(
            "Prepare failed for assigned subjects: "
            . $conn->error
        );

        die("Database error");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $result = $stmt->get_result();

    $assigned_subjects = [];

    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }

    $stmt->close();


    if (empty($assigned_subjects)) {
        $error = "No subjects assigned to you. Contact your admin.";
    }


    /*
    |--------------------------------------------------------------------------
    | ACADEMIC LEVELS
    |--------------------------------------------------------------------------
    */

    $levels = [];

    $result = $conn->query("
        SELECT id, level_code
        FROM academic_levels
        ORDER BY level_code ASC
    ");

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $levels[] = $row;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FETCH TESTS TEACHER CAN USE
    |--------------------------------------------------------------------------
    */

    $tests = [];

    if (!empty($assigned_subjects)) {

        $test_placeholders = implode(
            ',',
            array_fill(
                0,
                count($assigned_subjects),
                '?'
            )
        );

        $stmt = $conn->prepare("
            SELECT
                t.id,
                t.title,
                t.subject,
                al.level_code
            FROM tests t
            JOIN academic_levels al
                ON al.id = t.academic_level_id
            WHERE t.subject IN ($test_placeholders)
            ORDER BY t.created_at DESC
        ");

        if ($stmt) {

            $types = str_repeat(
                's',
                count($assigned_subjects)
            );

            $stmt->bind_param(
                $types,
                ...$assigned_subjects
            );

            $stmt->execute();

            $tests = $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

            $stmt->close();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CURRENT TEST
    |--------------------------------------------------------------------------
    |
    | The selected test can come from:
    | 1. ?test_id=... when the teacher selects a test
    | 2. The existing session value
    |
    */

    $current_test = null;
    $questions = [];

    $requested_test_id = isset($_GET['test_id'])
        ? (int) $_GET['test_id']
        : 0;

    if ($requested_test_id > 0) {

        $current_test_id = $requested_test_id;

        /*
        * Store the selected test so it remains active
        * on subsequent visits.
        */
        $_SESSION['current_test_id'] = $current_test_id;

    } else {

        $current_test_id = (int) (
            $_SESSION['current_test_id'] ?? 0
        );
}


    if (
        $current_test_id > 0 &&
        !empty($assigned_subjects)
    ) {

        $test_placeholders = implode(
            ',',
            array_fill(
                0,
                count($assigned_subjects),
                '?'
            )
        );

        $sql = "
            SELECT
                t.id,
                t.title,
                t.subject,
                t.duration,
                t.academic_level_id,
                al.level_code
            FROM tests t
            JOIN academic_levels al
                ON al.id = t.academic_level_id
            WHERE t.id = ?
            AND t.subject IN ($test_placeholders)
            LIMIT 1
        ";

        $types =
            'i' .
            str_repeat(
                's',
                count($assigned_subjects)
            );

        $params = [
            $current_test_id
        ];

        foreach ($assigned_subjects as $subject) {
            $params[] = $subject;
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                "Failed to prepare current test query: "
                . $conn->error
            );
        }

        $stmt->bind_param(
            $types,
            ...$params
        );

        if (!$stmt->execute()) {
            throw new Exception(
                "Failed to fetch current test: "
                . $stmt->error
            );
        }

        $current_test = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | QUESTIONS BELONGING TO CURRENT TEST
        |--------------------------------------------------------------------------
        */

        if ($current_test) {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    question_text,
                    question_type,
                    class,
                    subject,
                    created_at
                FROM new_questions
                WHERE test_id = ?
                ORDER BY id ASC
            ");

            if (!$stmt) {
                throw new Exception(
                    "Failed to prepare test questions query: "
                    . $conn->error
                );
            }

            $stmt->bind_param(
                "i",
                $current_test_id
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    "Failed to fetch test questions: "
                    . $stmt->error
                );
            }

            $questions = $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

            $stmt->close();

        } else {

            unset(
                $_SESSION['current_test_id']
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | QUESTION BANK FILTERS
    |--------------------------------------------------------------------------
    */

    $search = trim(
        $_GET['search'] ?? ''
    );

    $class_filter = trim(
        $_GET['class'] ?? ''
    );

    $subject_filter = trim(
        $_GET['subject'] ?? ''
    );

    $type_filter = trim(
        $_GET['type'] ?? ''
    );


    $result = false;
    $stmt = null;


    /*
    |--------------------------------------------------------------------------
    | FETCH QUESTION BANK
    |--------------------------------------------------------------------------
    */

    if (!empty($assigned_subjects)) {

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($assigned_subjects),
                '?'
            )
        );

        $sql = "
            SELECT
                id,
                question_text,
                class,
                subject,
                question_type,
                created_at
            FROM new_questions
            WHERE teacher_id = ?
            AND test_id IS NULL
            AND subject IN ($placeholders)
        ";

        $types =
            'i' .
            str_repeat(
                's',
                count($assigned_subjects)
            );

        $params = [
            $teacher_id
        ];


        foreach ($assigned_subjects as $subject) {
            $params[] = $subject;
        }


        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {

            $sql .= "
                AND question_text LIKE ?
            ";

            $types .= 's';

            $params[] =
                '%' .
                $search .
                '%';
        }


        /*
        |--------------------------------------------------------------------------
        | CLASS FILTER
        |--------------------------------------------------------------------------
        */

        if ($class_filter !== '') {

            $sql .= "
                AND class = ?
            ";

            $types .= 's';

            $params[] =
                $class_filter;
        }


        /*
        |--------------------------------------------------------------------------
        | SUBJECT FILTER
        |--------------------------------------------------------------------------
        */

        if ($subject_filter !== '') {

            if (
                in_array(
                    $subject_filter,
                    $assigned_subjects,
                    true
                )
            ) {

                $sql .= "
                    AND subject = ?
                ";

                $types .= 's';

                $params[] =
                    $subject_filter;

            } else {

                $sql .= "
                    AND 1 = 0
                ";
            }
        }


        /*
        |--------------------------------------------------------------------------
        | QUESTION TYPE FILTER
        |--------------------------------------------------------------------------
        */

        if ($type_filter !== '') {

            $valid_types = [
                'multiple_choice_single',
                'multiple_choice_multiple',
                'true_false',
                'fill_blanks'
            ];

            if (
                in_array(
                    $type_filter,
                    $valid_types,
                    true
                )
            ) {

                $sql .= "
                    AND question_type = ?
                ";

                $types .= 's';

                $params[] =
                    $type_filter;
            }
        }


        $sql .= "
            ORDER BY id DESC
        ";


        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                "Failed to prepare question bank query: "
                . $conn->error
            );
        }

        $stmt->bind_param(
            $types,
            ...$params
        );

        if (!$stmt->execute()) {
            throw new Exception(
                "Failed to fetch question bank: "
                . $stmt->error
            );
        }

        $result =
            $stmt->get_result();
    }

} catch (Exception $e) {

    echo "<pre>";
    echo "ERROR: "
        . htmlspecialchars(
            $e->getMessage()
        );

    echo "\n\n";

    echo htmlspecialchars(
        $e->getTraceAsString()
    );

    echo "</pre>";

    exit();
}

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
        Question Bank - Examcenter
    </title>


    <!-- Bootstrap -->

    <link
        href="../css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Font Awesome -->

    <link
        rel="stylesheet"
        href="../css/all.css"
    >


    <!-- Dashboard CSS -->

    <link
        rel="stylesheet"
        href="../css/admin-dashboard.css"
    >


    <!-- Existing Question CSS -->

    <link
        rel="stylesheet"
        href="../css/add_question.css"
    >


    <!-- Page Specific CSS -->

    <style>

        /*
        |--------------------------------------------------------------------------
        | GENERAL
        |--------------------------------------------------------------------------
        */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            background: #f5f7fb;
            overflow-x: hidden;
        }


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: #212529;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 25px 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-brand h3 {
            margin: 0 0 18px;
            font-weight: 700;
            font-size: 22px;
        }

        .admin-info {
            padding-top: 10px;
        }

        .admin-info small {
            color: rgba(255,255,255,0.65);
        }

        .admin-info h6 {
            margin: 5px 0 0;
            font-size: 15px;
            color: #fff;
        }

        .sidebar-menu {
            padding: 0 12px 20px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.78);
            text-decoration: none;
            padding: 12px 14px;
            margin-bottom: 4px;
            border-radius: 7px;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .sidebar-menu a i {
            width: 20px;
            text-align: center;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.10);
            color: #fff;
        }

        .sidebar-menu a.active {
            background: #0d6efd;
        }

        .sidebar-menu .logout-btn {
            margin-top: 20px;
            color: #ffb3b3;
        }

        .sidebar-menu .logout-btn:hover {
            background: rgba(220,53,69,0.15);
            color: #fff;
        }


        /*
        |--------------------------------------------------------------------------
        | MAIN CONTENT
        |--------------------------------------------------------------------------
        */

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            padding: 25px 30px 40px;
            width: calc(100% - 250px);
        }


        /*
        |--------------------------------------------------------------------------
        | PAGE HEADER
        |--------------------------------------------------------------------------
        */

        .page-header {
            background: #fff;
            border-radius: 10px;
            padding: 18px 22px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #212529;
        }

        .page-header p {
            margin: 4px 0 0;
            color: #6c757d;
            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | STAT CARDS
        |--------------------------------------------------------------------------
        */

        .stat-card {
            background: #fff;
            border: 0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            height: 100%;
        }

        .stat-card .card-body {
            padding: 20px;
        }

        .stat-card h6 {
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .stat-card h2 {
            margin: 0;
            font-weight: 700;
            color: #212529;
        }


        /*
        |--------------------------------------------------------------------------
        | FILTER CARD
        |--------------------------------------------------------------------------
        */

        .filter-card {
            border: 0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .filter-card .card-body {
            padding: 20px;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            min-height: 42px;
        }


        /*
        |--------------------------------------------------------------------------
        | QUESTION BANK CARD
        |--------------------------------------------------------------------------
        */

        .bank-card {
            border: 0;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            overflow: hidden;
        }

        .bank-card .card-header {
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: 18px 20px;
        }

        .bank-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .bank-subtitle {
            margin: 4px 0 0;
            font-size: 13px;
            color: #6c757d;
        }

        .bank-card .card-body {
            padding: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | TABLE
        |--------------------------------------------------------------------------
        */

        .question-table {
            width: 100%;
            margin-bottom: 0;
            vertical-align: middle;
        }

        .question-table thead th {
            white-space: nowrap;
            font-size: 13px;
            vertical-align: middle;
        }

        .question-table tbody td {
            font-size: 13px;
            vertical-align: middle;
        }

        .question-text-cell {
            min-width: 280px;
            max-width: 450px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.5;
        }

        .question-type {
            white-space: nowrap;
        }

        .action-cell {
            min-width: 230px;
            white-space: nowrap;
        }

        .action-cell .btn {
            margin: 2px;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTTOM ACTION BAR
        |--------------------------------------------------------------------------
        */

        .bank-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .selection-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .target-test-area {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .target-test-area .form-select {
            min-width: 280px;
        }


        /*
        |--------------------------------------------------------------------------
        | MODAL
        |--------------------------------------------------------------------------
        */

        .modal-preview {
            background: #f8f9fa;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-preview .card {
            border: 1px solid #e3e6ea;
        }

        .modal-preview .question-text {
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .modal-preview .option-item {
            padding: 10px 12px;
            margin-bottom: 8px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
        }

        .modal-preview .option-item.correct {
            border-color: #198754;
            background: #f0fff7;
        }

        .preview-image {
            max-width: 100%;
            max-height: 250px;
            object-fit: contain;
            display: block;
            margin-bottom: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 45px;
            margin-bottom: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.25s ease;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px 15px 30px;
            }

            .page-header {
                padding: 15px;
            }

            .page-header h2 {
                font-size: 20px;
            }

            .mobile-menu-btn {
                display: inline-flex !important;
            }
        }


        @media (min-width: 992px) {

            .mobile-menu-btn {
                display: none !important;
            }
        }


        @media (max-width: 767.98px) {

            .bank-actions {
                align-items: stretch;
                flex-direction: column;
            }

            .selection-info,
            .target-test-area {
                width: 100%;
            }

            .target-test-area .form-select {
                width: 100%;
                min-width: 0;
            }

            .target-test-area .btn {
                width: 100%;
            }

            .page-header {
                align-items: flex-start;
            }

            .question-text-cell {
                min-width: 220px;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | OVERLAY
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1040;
        }

        .sidebar-overlay.active {
            display: block;
        }

    </style>

</head>


<body>

<?php if (isset($_SESSION['success'])): ?>

    <div class="alert alert-success alert-dismissible fade show shadow-sm m-3" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <strong>Success!</strong>
        <?= htmlspecialchars($_SESSION['success']); ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>

    <?php unset($_SESSION['success']); ?>

<?php endif; ?>


<?php if (isset($_SESSION['error'])): ?>

    <div class="alert alert-danger alert-dismissible fade show shadow-sm m-3" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <strong>Error:</strong>
        <?= htmlspecialchars($_SESSION['error']); ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>

    <?php unset($_SESSION['error']); ?>

<?php endif; ?>
<!-- ============================================================
     SIDEBAR
============================================================ -->

<div class="sidebar" id="sidebar">

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
                <?= htmlspecialchars(
                    $teacher['last_name']
                ); ?>
            </h6>

        </div>

    </div>


    <div class="sidebar-menu mt-4">

        <a href="dashboard.php">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>


        <a href="add_question.php">
            <i class="fas fa-plus-circle"></i>
            <span>Add Questions</span>
        </a>

        <a
            href="bank.php"
            class="active"
        >
            <i class="fas fa-database"></i>
            <span>Question Bank</span>
        </a>


        <a href="view_questions.php">
            <i class="fas fa-list"></i>
            <span>View Questions</span>
        </a>


        <a href="manage_test.php">
            <i class="fas fa-list"></i>
            <span>Manage Test</span>
        </a>


        <a href="view_results.php">
            <i class="fas fa-chart-bar"></i>
            <span>Exam Results</span>
        </a>


        <a href="manage_students.php">
            <i class="fas fa-users"></i>
            <span>Manage Students</span>
        </a>


        <a href="settings.php">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>


        <a href="my-profile.php">
            <i class="fas fa-user"></i>
            <span>My Profile</span>
        </a>


        <a
            href="logout.php"
            class="logout-btn"
        >
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>

    </div>

</div>


<!-- MOBILE OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>



<!-- ============================================================
     MAIN CONTENT
============================================================ -->

<div class="main-content">


    <!-- ========================================================
         PAGE HEADER
    ========================================================= -->

    <div class="page-header">

        <div>

            <h2>
                Question Bank
            </h2>

            <p>
                Store reusable questions that can later be added to tests.
            </p>

        </div>


        <button
            type="button"
            class="btn btn-primary mobile-menu-btn"
            id="sidebarToggle"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>



    <!-- ========================================================
         STATISTICS
    ========================================================= -->

    <div class="row g-3 mb-4">

        <div class="col-md-6 col-lg-3">

            <div class="card stat-card">

                <div class="card-body">

                    <h6>
                        Total Questions
                    </h6>

                    <h2>
                        <?= $result
                            ? $result->num_rows
                            : 0
                        ?>
                    </h2>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-lg-3">

            <div class="card stat-card">

                <div class="card-body">

                    <h6>
                        Subjects
                    </h6>

                    <h2>
                        <?= count(
                            $assigned_subjects
                        ); ?>
                    </h2>

                </div>

            </div>

        </div>

    </div>



    <!-- ========================================================
         FILTERS
    ========================================================= -->

    <div class="card filter-card mb-4">

        <div class="card-body">

            <form method="GET">

                <div class="row g-3">


                    <!-- SEARCH -->

                    <div class="col-md-4">

                        <label
                            class="form-label small fw-semibold"
                        >
                            Search
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            name="search"
                            placeholder="Search question..."
                            value="<?= htmlspecialchars(
                                $search
                            ); ?>"
                        >

                    </div>


                    <!-- CLASS -->

                    <div class="col-md-2">

                        <label
                            class="form-label small fw-semibold"
                        >
                            Class
                        </label>

                        <select
                            class="form-select"
                            name="class"
                        >

                            <option value="">
                                All Classes
                            </option>


                            <?php foreach (
                                $levels
                                as $level
                            ): ?>

                                <option
                                    value="<?= htmlspecialchars(
                                        $level['level_code']
                                    ); ?>"
                                    <?= $class_filter ===
                                        $level['level_code']
                                        ? 'selected'
                                        : ''; ?>
                                >

                                    <?= htmlspecialchars(
                                        $level['level_code']
                                    ); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- SUBJECT -->

                    <div class="col-md-2">

                        <label
                            class="form-label small fw-semibold"
                        >
                            Subject
                        </label>

                        <select
                            class="form-select"
                            name="subject"
                        >

                            <option value="">
                                All Subjects
                            </option>


                            <?php foreach (
                                $assigned_subjects
                                as $sub
                            ): ?>

                                <option
                                    value="<?= htmlspecialchars(
                                        $sub
                                    ); ?>"
                                    <?= $subject_filter ==
                                        $sub
                                        ? 'selected'
                                        : ''; ?>
                                >

                                    <?= htmlspecialchars(
                                        $sub
                                    ); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- TYPE -->

                    <div class="col-md-2">

                        <label
                            class="form-label small fw-semibold"
                        >
                            Type
                        </label>

                        <select
                            class="form-select"
                            name="type"
                        >

                            <option value="">
                                All Types
                            </option>

                            <option
                                value="multiple_choice_single"
                                <?= $type_filter ===
                                    'multiple_choice_single'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Single Choice
                            </option>

                            <option
                                value="multiple_choice_multiple"
                                <?= $type_filter ===
                                    'multiple_choice_multiple'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Multiple Choice
                            </option>

                            <option
                                value="true_false"
                                <?= $type_filter ===
                                    'true_false'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                True / False
                            </option>

                            <option
                                value="fill_blanks"
                                <?= $type_filter ===
                                    'fill_blanks'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Fill Blank
                            </option>

                        </select>

                    </div>


                    <!-- SEARCH BUTTON -->

                    <div class="col-md-1 d-flex align-items-end">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                            title="Search"
                        >
                            <i class="fas fa-search"></i>
                        </button>

                    </div>


                    <!-- CLEAR -->

                    <div class="col-md-1 d-flex align-items-end">

                        <a
                            href="bank.php"
                            class="btn btn-outline-secondary w-100"
                            title="Clear Filters"
                        >
                            <i class="fas fa-times"></i>
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>



    <!-- ========================================================
         QUESTION BANK
    ========================================================= -->

    <div class="card bank-card">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">

                <div>

                    <h5 class="bank-title">
                        Question Bank
                    </h5>

                    <p class="bank-subtitle">
                        Store reusable questions that can later be added to any test.
                    </p>

                </div>


                <a
                    href="add_question.php?mode=bank"
                    class="btn btn-success"
                >

                    <i class="fas fa-plus-circle me-1"></i>

                    Add Question

                </a>

            </div>

        </div>



        <div class="card-body">


            <!-- ====================================================
                 BANK FORM
            ===================================================== -->

            <form
                action="add_questions_to_test.php"
                method="POST"
                id="bankForm"
            >


                <!-- TABLE -->

                <div class="table-responsive">

                    <table
                        class="table table-hover question-table"
                    >

                        <thead class="table-dark">

                            <tr>

                                <th width="45">
                                    <input
                                        type="checkbox"
                                        id="masterCheckbox"
                                    >
                                </th>

                                <th>
                                    #
                                </th>

                                <th>
                                    Question
                                </th>

                                <th>
                                    Class
                                </th>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Type
                                </th>

                                <th>
                                    Date Added
                                </th>

                                <th>
                                    Used In
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php

                        if (
                            $result &&
                            $result->num_rows > 0
                        ):

                            $sn = 1;

                            while (
                                $row =
                                $result->fetch_assoc()
                            ):

                        ?>

                            <tr>

                                <!-- CHECKBOX -->

                                <td>

                                    <input
                                        type="checkbox"
                                        name="questions[]"
                                        value="<?= (int) $row['id']; ?>"
                                        class="questionCheckbox"
                                    >

                                </td>


                                <!-- NUMBER -->

                                <td>
                                    <?= $sn++; ?>
                                </td>


                                <!-- QUESTION -->

                                <td class="question-text-cell">

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $row['question_text']
                                        )
                                    ); ?>

                                </td>


                                <!-- CLASS -->

                                <td>

                                    <?= htmlspecialchars(
                                        $row['class']
                                    ); ?>

                                </td>


                                <!-- SUBJECT -->

                                <td>

                                    <?= htmlspecialchars(
                                        $row['subject']
                                    ); ?>

                                </td>


                                <!-- TYPE -->

                                <td class="question-type">

                                    <span class="badge bg-primary">

                                        <?= htmlspecialchars(
                                            ucwords(
                                                str_replace(
                                                    "_",
                                                    " ",
                                                    $row['question_type']
                                                )
                                            )
                                        ); ?>

                                    </span>

                                </td>


                                <!-- DATE -->

                                <td class="text-nowrap">

                                    <?= date(
                                        "d M Y",
                                        strtotime(
                                            $row['created_at']
                                        )
                                    ); ?>

                                </td>


                                <!-- USED -->

                                <td>

                                    <span class="badge bg-secondary">
                                        Not Used
                                    </span>

                                </td>


                                <!-- ACTION -->

                                <td class="action-cell">

                                    <a
                                        href="view_question.php?id=<?= (int) $row['id']; ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >

                                        <i class="fas fa-eye"></i>

                                        View

                                    </a>


                                    <a
                                        href="edit_question.php?id=<?= (int) $row['id']; ?>"
                                        class="btn btn-sm btn-outline-warning"
                                    >

                                        <i class="fas fa-edit"></i>

                                        Edit

                                    </a>


                                    <a
                                        href="delete_question.php?id=<?= (int) $row['id']; ?>"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Delete this question?')"
                                    >

                                        <i class="fas fa-trash"></i>

                                        Delete

                                    </a>

                                </td>

                            </tr>


                        <?php

                            endwhile;

                        else:

                        ?>

                            <tr>

                                <td
                                    colspan="9"
                                    class="empty-state"
                                >

                                    <i class="fas fa-inbox"></i>

                                    <div>
                                        No questions found.
                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>



                <!-- =================================================
                     ACTION BAR
                ================================================== -->

                <div class="bank-actions">


                    <!-- LEFT -->

                    <div class="selection-info">

                        <button
                            type="button"
                            class="btn btn-secondary"
                            id="selectAllBtn"
                        >
                            Select All
                        </button>


                        <span
                            class="badge bg-secondary fs-6 px-3 py-2"
                        >

                            Selected:

                            <span id="selectedCount">
                                0
                            </span>

                        </span>

                    </div>



                    <!-- RIGHT -->

                    <?php if (!empty($tests)): ?>

                        <div class="target-test-area">

                            <select
                                class="form-select"
                                name="target_test_id"
                                id="targetTestSelect"
                                style="min-width: 260px;"
                                required
                            >
                                <!-- Always require the user to explicitly select a test -->
                                <option value="" selected>Select Test</option>

                                <?php foreach ($tests as $test): ?>

                                    <option
                                        value="<?= (int) $test['id']; ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $test['title'] .
                                            ' (' .
                                            $test['level_code'] .
                                            ' - ' .
                                            $test['subject'] .
                                            ')'
                                        ); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <!-- PREVIEW BUTTON -->

                            <?php if (!empty($current_test)): ?>

                                <a
                                    href="#"
                                    class="btn btn-outline-primary text-nowrap"
                                    data-bs-toggle="modal"
                                    data-bs-target="#previewModal"
                                >
                                    <i class="fas fa-eye me-1"></i>
                                    Preview Test
                                </a>

                            <?php endif; ?>

                            <button
                                type="submit"
                                class="btn btn-success text-nowrap"
                            >
                                <i class="fas fa-plus me-1"></i>
                                Add Selected To Test
                            </button>

                        </div>

                    <?php else: ?>


                        <a
                            href="add_question.php"
                            class="btn btn-warning"
                        >

                            Create a Test First

                        </a>


                    <?php endif; ?>

                </div>


            </form>

        </div>

    </div>

</div>


<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalLabel">Test Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body modal-preview">
                <?php if ($current_test && !empty($questions)): ?>
                    <h6><?php echo htmlspecialchars($current_test['title']); ?> (<?php echo htmlspecialchars($current_test['level_code'] . ' - ' . $current_test['subject']); ?>)</h6>
                    <p><small>Duration: <?php echo (int)$current_test['duration']; ?> minutes</small></p>
                    <hr>
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong>Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question_text']); ?></strong>
                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;" action="handle_question.php">
                                        <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                        <input type="hidden" name="edit_question" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</button>
                                    </form>
                                    <form method="POST" style="display: inline;" action="handle_question.php" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                        <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                        <input type="hidden"  name="question_type" value="<?php echo htmlspecialchars($question['question_type']); ?>">
                                        <input type="hidden" name="delete_question" value="1">
                                        <!-- Tell handle_question.php that this deletion came from the preview modal -->
                                        <input type="hidden"  name="redirect_to" value="bank">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <span class="badge bg-primary ms-2"><?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?></span>
                            <div class="mt-2">
                                <?php
                                switch ($question['question_type']) {
                                    case 'multiple_choice_single':
                                        $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?");
                                        $stmt->bind_param("i", $question['id']);
                                        $stmt->execute();
                                        $options = $stmt->get_result()->fetch_assoc();
                                        $stmt->close();
                                        if ($options['image_path'] && file_exists("../{$options['image_path']}")) {
                                            echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                        } elseif ($options['image_path']) {
                                            echo '<div class="mb-3"><small class="text-muted">Image not found.</small></div>';
                                        }
                                        foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                            echo "<div>" . ($options['correct_answer'] === $options[$opt] ? '<i class="fas fa-check text-success me-2"></i>' : '') .
                                                    htmlspecialchars($options[$opt] ?? '') . "</div>";
                                        }
                                        break;
                                    case 'multiple_choice_multiple':
                                        $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?");
                                        $stmt->bind_param("i", $question['id']);
                                        $stmt->execute();
                                        $options = $stmt->get_result()->fetch_assoc();
                                        $stmt->close();
                                        if ($options['image_path'] && file_exists("../{$options['image_path']}")) {
                                            echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                        } elseif ($options['image_path']) {
                                            echo '<div class="mb-3"><small class="text-muted">Image not found.</small></div>';
                                        }
                                        $correct = explode(',', $options['correct_answers']);
                                        foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                            echo "<div>" . (in_array($options[$opt], $correct) ? '<i class="fas fa-check text-success me-2"></i>' : '') .
                                                    htmlspecialchars($options[$opt] ?? '') . "</div>";
                                        }
                                        break;
                                    case 'true_false':
                                        $stmt = $conn->prepare("SELECT correct_answer FROM true_false_questions WHERE question_id = ?");
                                        $stmt->bind_param("i", $question['id']);
                                        $stmt->execute();
                                        $answer = $stmt->get_result()->fetch_assoc();
                                        $stmt->close();
                                        echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer'] ?? '') . "</div>";
                                        break;
                                    case 'fill_blanks':
                                        $stmt = $conn->prepare("SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?");
                                        $stmt->bind_param("i", $question['id']);
                                        $stmt->execute();
                                        $answer = $stmt->get_result()->fetch_assoc();
                                        $stmt->close();
                                        echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer'] ?? '') . "</div>";
                                        break;
                                }
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center">No questions available to preview.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>



<!-- ============================================================
     JAVASCRIPT
============================================================ -->


<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/chart.min.js"></script>

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>



<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR
        |--------------------------------------------------------------------------
        */

        const sidebar =
            document.getElementById(
                "sidebar"
            );

        const sidebarToggle =
            document.getElementById(
                "sidebarToggle"
            );

        const sidebarOverlay =
            document.getElementById(
                "sidebarOverlay"
            );


        function toggleSidebar() {

            if (!sidebar) {
                return;
            }

            sidebar.classList.toggle(
                "active"
            );

            if (sidebarOverlay) {

                sidebarOverlay.classList.toggle(
                    "active"
                );
            }
        }


        if (sidebarToggle) {

            sidebarToggle.addEventListener(
                "click",
                toggleSidebar
            );
        }


        if (sidebarOverlay) {

            sidebarOverlay.addEventListener(
                "click",
                toggleSidebar
            );
        }

        const url = new URL(window.location.href);

        if (url.searchParams.get("success") === "questions_added") {

            // Remove the success parameter from the URL
            url.searchParams.delete("success");

            window.history.replaceState(
                {},
                document.title,
                url.pathname + url.search
            );
        }

        /*
        |--------------------------------------------------------------------------
        | QUESTION SELECTION
        |--------------------------------------------------------------------------
        */

        const master =
            document.getElementById(
                "masterCheckbox"
            );

        const checkboxes =
            document.querySelectorAll(
                ".questionCheckbox"
            );

        const selectedCount =
            document.getElementById(
                "selectedCount"
            );

        const selectAllBtn =
            document.getElementById(
                "selectAllBtn"
            );


        function updateSelectedCount() {

            let count = 0;


            checkboxes.forEach(
                function (box) {

                    if (box.checked) {
                        count++;
                    }

                }
            );


            if (selectedCount) {

                selectedCount.textContent =
                    count;
            }


            const badge =
                selectedCount
                    ? selectedCount.parentElement
                    : null;


            if (badge) {

                badge.classList.remove(
                    "bg-secondary",
                    "bg-success"
                );


                badge.classList.add(
                    count > 0
                        ? "bg-success"
                        : "bg-secondary"
                );
            }


            if (master) {

                master.checked =
                    count === checkboxes.length &&
                    checkboxes.length > 0;

                master.indeterminate =
                    count > 0 &&
                    count < checkboxes.length;
            }
        }



        /*
        |--------------------------------------------------------------------------
        | MASTER CHECKBOX
        |--------------------------------------------------------------------------
        */

        if (master) {

            master.addEventListener(
                "change",
                function () {

                    checkboxes.forEach(
                        function (box) {

                            box.checked =
                                master.checked;

                        }
                    );


                    updateSelectedCount();

                }
            );
        }



        /*
        |--------------------------------------------------------------------------
        | SELECT ALL BUTTON
        |--------------------------------------------------------------------------
        */

        if (selectAllBtn) {

            selectAllBtn.addEventListener(
                "click",
                function () {

                    const selectAll =
                        Array.from(
                            checkboxes
                        ).some(
                            function (box) {
                                return !box.checked;
                            }
                        );


                    checkboxes.forEach(
                        function (box) {

                            box.checked =
                                selectAll;

                        }
                    );


                    if (master) {

                        master.checked =
                            selectAll;
                    }


                    updateSelectedCount();

                }
            );
        }



        /*
        |--------------------------------------------------------------------------
        | INDIVIDUAL CHECKBOXES
        |--------------------------------------------------------------------------
        */

        checkboxes.forEach(
            function (box) {

                box.addEventListener(
                    "change",
                    function () {

                        updateSelectedCount();

                    }
                );

            }
        );


        updateSelectedCount();


    /*
        |--------------------------------------------------------------------------
        | TEST SELECTION / PREVIEW
        |--------------------------------------------------------------------------
        */

        const targetTestSelect = document.getElementById("targetTestSelect");

        if (targetTestSelect) {

            targetTestSelect.addEventListener("change", function () {

                const testId = this.value;

                if (!testId) {
                    return;
                }

                /*
                * Reload bank.php with the selected test.
                *
                * PHP will:
                * - validate the test
                * - store it in the session
                * - fetch its questions
                * - render the Review/Preview modal
                */

                const url = new URL(window.location.href);

                url.searchParams.set("test_id", testId);
                url.searchParams.delete("success");

                window.location.href = url.toString();
            });
        }


        /*
        |--------------------------------------------------------------------------
        | REVIEW / PREVIEW MODAL
        |--------------------------------------------------------------------------
        */

        const previewModal = document.getElementById("previewModal");

        if (previewModal) {

            /*
                    * When the modal opens, make sure the preview
            * starts at the top.
            */
            previewModal.addEventListener("shown.bs.modal", function () {

                const modalBody =
                    previewModal.querySelector(".modal-preview");

                if (modalBody) {
                    modalBody.scrollTop = 0;
                }

            });


            /*
            * Reset the modal scroll position when it closes.
            */
            previewModal.addEventListener("hidden.bs.modal", function () {

                const modalBody =
                    previewModal.querySelector(".modal-preview");

                if (modalBody) {
                    modalBody.scrollTop = 0;
                }

            });

        }

        /*
        |--------------------------------------------------------------------------
        | BANK FORM VALIDATION
        |--------------------------------------------------------------------------
        */

        const bankForm =
            document.getElementById(
                "bankForm"
            );


        if (bankForm) {
            bankForm.addEventListener("submit", function(e) {

                const anyChecked = [...checkboxes].some(
                    box => box.checked
                );

                if (!anyChecked) {
                    e.preventDefault();
                    alert("Please select at least one question first.");
                    return;
                }

                const targetTest = document.getElementById("targetTestSelect");

                if (targetTest && !targetTest.value) {
                    e.preventDefault();
                    alert("Please select a test first.");
                    targetTest.focus();
                    return;
                }
            });
        }

    }

);



</script>


</body>

</html>

<?php

if (isset($stmt) && $stmt) {
    $stmt->close();
}

$conn->close();

?>