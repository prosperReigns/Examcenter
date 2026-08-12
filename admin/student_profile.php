<?php

// ================================================================
// student_profile.php
// ================================================================

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

// ---------------------------------------------------------------
// Development error reporting
// ---------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// ---------------------------------------------------------------
// Page messages
// ---------------------------------------------------------------
$success = '';
$errorMsg = '';

$admin = null;
$student = null;
$studentResults = [];


// ================================================================
// FILTER VALUES
// ================================================================

// Test title filter
$testTitleFilter = trim(
    (string) ($_GET['test_title'] ?? '')
);

// Academic year filter
$academicYearFilter = trim(
    (string) ($_GET['academic_year'] ?? '')
);


// ================================================================
// AUTHENTICATION
// ================================================================

if (!isset($_SESSION['user_id'])) {

    header(
        "Location: /EXAMCENTER/login.php?error=Not logged in"
    );

    exit();
}


try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }


    // ============================================================
    // 1. VERIFY LOGGED-IN ADMIN
    // ============================================================

    $adminId = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT
            id,
            username,
            role
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception(
            "Unable to prepare administrator query: "
            . $conn->error
        );
    }

    $stmt->bind_param("i", $adminId);

    $stmt->execute();

    $result = $stmt->get_result();

    $admin = $result->fetch_assoc();

    $stmt->close();


    if (
        !$admin ||
        strtolower(trim((string) $admin['role'])) !== 'admin'
    ) {

        session_unset();
        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();
    }


    // ============================================================
    // 2. GET STUDENT ID
    // ============================================================

    $studentId = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );


    if (!$studentId || $studentId <= 0) {

        $errorMsg = "Invalid student profile.";

    } else {


        // ========================================================
        // 3. FETCH STUDENT PROFILE
        // ========================================================

        $stmt = $conn->prepare("
            SELECT
                s.id,
                s.full_name,
                s.reg_no,
                s.class AS class_id,
                c.class_name,
                s.email,
                s.phone,
                s.photo,
                s.address,
                s.role,
                s.created_via,
                s.created_at,
                s.updated_at

            FROM students s

            LEFT JOIN classes c
                ON s.class = c.id

            WHERE s.id = ?

            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare student query: "
                . $conn->error
            );
        }

        $stmt->bind_param("i", $studentId);

        $stmt->execute();

        $result = $stmt->get_result();

        $student = $result->fetch_assoc();

        $stmt->close();


        if (!$student) {

            $errorMsg = "Student profile not found.";

        } else {


            // ====================================================
            // 4. FETCH STUDENT EXAMINATION RESULTS
            // ====================================================

            $sql = "
                SELECT

                    r.id AS result_id,
                    r.user_id,
                    r.test_id,
                    r.score,
                    r.total_questions,
                    r.status,
                    r.reattempt_approved,
                    r.created_at AS taken_at,

                    t.title AS exam_title,
                    t.academic_level_id,
                    t.subject,
                    t.year AS academic_year,
                    t.duration

                FROM results r

                INNER JOIN tests t
                    ON r.test_id = t.id

                WHERE r.user_id = ?
            ";


            $types = "i";
            $params = [$studentId];


            // ----------------------------------------------------
            // TEST TITLE FILTER
            // ----------------------------------------------------

            if ($testTitleFilter !== '') {

                $sql .= "
                    AND t.title LIKE ?
                ";

                $types .= "s";

                $params[] =
                    '%' . $testTitleFilter . '%';
            }


            // ----------------------------------------------------
            // ACADEMIC YEAR FILTER
            // ----------------------------------------------------

            if ($academicYearFilter !== '') {

                $sql .= "
                    AND t.year = ?
                ";

                $types .= "s";

                $params[] =
                    $academicYearFilter;
            }


            // ----------------------------------------------------
            // ORDER
            // ----------------------------------------------------

            $sql .= "
                ORDER BY
                    t.year DESC,
                    r.created_at DESC
            ";


            $stmt = $conn->prepare($sql);


            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare student results query: "
                    . $conn->error
                );
            }


            // ----------------------------------------------------
            // BIND DYNAMIC PARAMETERS
            // ----------------------------------------------------

            $stmt->bind_param(
                $types,
                ...$params
            );


            $stmt->execute();

            $result = $stmt->get_result();


            while ($row = $result->fetch_assoc()) {

                $studentResults[] = $row;
            }


            $stmt->close();
        }
    }


} catch (Throwable $e) {

    error_log(
        "student_profile.php error: "
        . $e->getMessage()
    );


    if (empty($errorMsg)) {

        $errorMsg =
            "Unable to load the student profile at this time.";
    }
}


// ================================================================
// FETCH FILTER OPTIONS
// ================================================================
//
// These are fetched independently so that the filter dropdowns
// continue to show all available examinations even when another
// filter is currently active.
// ================================================================

$availableTestTitles = [];
$availableAcademicYears = [];


if ($student && isset($conn)) {

    try {

        // --------------------------------------------------------
        // AVAILABLE TEST TITLES
        // --------------------------------------------------------

        $stmt = $conn->prepare("
            SELECT DISTINCT
                t.title
            FROM results r
            INNER JOIN tests t
                ON r.test_id = t.id
            WHERE r.user_id = ?
              AND t.title IS NOT NULL
              AND TRIM(t.title) <> ''
            ORDER BY t.title ASC
        ");

        if ($stmt) {

            $stmt->bind_param(
                "i",
                $studentId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $availableTestTitles[] =
                    $row['title'];
            }

            $stmt->close();
        }


        // --------------------------------------------------------
        // AVAILABLE ACADEMIC YEARS
        // --------------------------------------------------------

        $stmt = $conn->prepare("
            SELECT DISTINCT
                t.year AS academic_year
            FROM results r
            INNER JOIN tests t
                ON r.test_id = t.id
            WHERE r.user_id = ?
              AND t.year IS NOT NULL
              AND TRIM(t.year) <> ''
            ORDER BY t.year DESC
        ");

        if ($stmt) {

            $stmt->bind_param(
                "i",
                $studentId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $availableAcademicYears[] =
                    $row['academic_year'];
            }

            $stmt->close();
        }


    } catch (Throwable $e) {

        error_log(
            "student_profile.php filter options error: "
            . $e->getMessage()
        );
    }
}


// ================================================================
// HELPER VALUES
// ================================================================

$studentName =
    $student['full_name'] ?? 'Student';


$studentInitial = 'S';


if (!empty($studentName)) {

    $studentInitial = strtoupper(
        substr(
            trim($studentName),
            0,
            1
        )
    );
}


$className = !empty($student['class_name'])
    ? $student['class_name']
    : (
        isset($student['class_id'])
            ? 'Class ID: ' . $student['class_id']
            : 'Not assigned'
    );


$registrationNumber = !empty($student['reg_no'])
    ? $student['reg_no']
    : 'Not assigned';


$email = !empty($student['email'])
    ? $student['email']
    : 'Not provided';


$phone = !empty($student['phone'])
    ? $student['phone']
    : 'Not provided';


$address = !empty($student['address'])
    ? $student['address']
    : 'Not provided';


$createdVia = !empty($student['created_via'])
    ? ucwords(
        str_replace(
            '_',
            ' ',
            $student['created_via']
        )
    )
    : 'Not specified';


$createdAt = !empty($student['created_at'])
    ? date(
        'd M Y, h:i A',
        strtotime($student['created_at'])
    )
    : 'Not available';


$updatedAt = !empty($student['updated_at'])
    ? date(
        'd M Y, h:i A',
        strtotime($student['updated_at'])
    )
    : 'Not available';


$studentRole = !empty($student['role'])
    ? ucfirst(
        strtolower(
            $student['role']
        )
    )
    : 'Student';


// ================================================================
// PHOTO
// ================================================================

$photoPath = '';


if (!empty($student['photo'])) {

    $possiblePhoto = '../' . ltrim(
        $student['photo'],
        '/'
    );

    if (
        file_exists(
            __DIR__ . '/' . $possiblePhoto
        )
    ) {

        $photoPath = $possiblePhoto;
    }
}


// ================================================================
// FILTER STATE
// ================================================================

$filtersActive =
    $testTitleFilter !== '' ||
    $academicYearFilter !== '';


// ================================================================
// FILTER QUERY STRING
// ================================================================

$baseProfileUrl =
    'student_profile.php?id='
    . (int) ($student['id'] ?? $studentId);

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="utf-8">

<title>
    <?php
    echo htmlspecialchars($studentName);
    ?>
    | Student Profile
</title>

<meta
name="viewport"
content="width=device-width, initial-scale=1"

>

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
    href="../css/sidebar.css"
>

<style>

/* ================================================================
   GLOBAL
================================================================ */

* {
    box-sizing: border-box;
}

body {
    background: #f4f7fb;
    color: #212529;
}


/* ================================================================
   MAIN CONTENT
================================================================ */

.main-content {
    padding: 24px;
    min-height: 100vh;
}


/* ================================================================
   HEADER
================================================================ */

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;

    background: #ffffff;

    padding: 20px 22px;

    border-radius: 16px;

    border: 1px solid #e7ebf0;

    box-shadow:
        0 4px 18px rgba(0, 0, 0, 0.04);
}

.header-left {
    display: flex;
    align-items: center;
    gap: 13px;
}

.header-icon {
    width: 48px;
    height: 48px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;

    background: #eaf2ff;

    color: #0d6efd;

    font-size: 20px;
}

.header-title h2 {
    margin: 0;

    font-size: 1.35rem;

    font-weight: 700;
}

.header-title p {
    margin: 4px 0 0;

    color: #6c757d;

    font-size: 0.86rem;
}


/* ================================================================
   ALERTS
================================================================ */

.page-alert {
    margin-top: 18px;

    border: 0;

    border-radius: 12px;

    padding: 14px 17px;

    box-shadow:
        0 3px 12px rgba(0, 0, 0, 0.03);
}


/* ================================================================
   PROFILE LAYOUT
================================================================ */

.profile-layout {
    display: grid;

    grid-template-columns:
        310px
        minmax(0, 1fr);

    gap: 22px;

    margin-top: 22px;
}


/* ================================================================
   CARDS
================================================================ */

.profile-card,
.details-card {
    background: #ffffff;

    border: 1px solid #e7ebf0;

    border-radius: 16px;

    box-shadow:
        0 4px 18px rgba(0, 0, 0, 0.045);
}


/* ================================================================
   PROFILE SUMMARY
================================================================ */

.profile-card {
    padding: 28px 22px;

    text-align: center;

    height: fit-content;
}

.profile-photo {
    width: 112px;
    height: 112px;

    margin: 0 auto 18px;

    border-radius: 50%;

    object-fit: cover;

    border: 5px solid #ffffff;

    box-shadow:
        0 7px 25px rgba(13, 110, 253, 0.18);
}

.profile-avatar {
    width: 112px;
    height: 112px;

    margin: 0 auto 18px;

    border-radius: 50%;

    display: flex;

    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #0d6efd,
            #4f8dfd
        );

    color: #ffffff;

    font-size: 42px;

    font-weight: 700;

    box-shadow:
        0 8px 25px rgba(13, 110, 253, 0.22);
}

.profile-name {
    font-size: 1.2rem;

    font-weight: 700;

    margin-bottom: 7px;

    word-break: break-word;
}

.profile-role {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 13px;

    border-radius: 30px;

    background: #e9f7ef;

    color: #198754;

    font-size: 0.78rem;

    font-weight: 600;
}

.profile-divider {
    height: 1px;

    background: #edf0f3;

    margin: 25px 0;
}


/* ================================================================
   PROFILE STATS
================================================================ */

.profile-stat {
    display: flex;

    align-items: center;

    gap: 12px;

    text-align: left;

    padding: 10px 0;
}

.profile-stat-icon {
    width: 38px;
    height: 38px;

    flex: 0 0 38px;

    border-radius: 10px;

    display: flex;

    align-items: center;
    justify-content: center;

    background: #f1f5f9;

    color: #0d6efd;
}

.profile-stat-label {
    font-size: 0.72rem;

    color: #6c757d;

    margin-bottom: 2px;
}

.profile-stat-value {
    font-size: 0.88rem;

    font-weight: 600;

    word-break: break-word;
}


/* ================================================================
   DETAILS CARD
================================================================ */

.details-card {
    padding: 25px;
}

.section-heading {
    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    margin-bottom: 22px;
}

.section-heading h4 {
    margin: 0;

    font-size: 1.05rem;

    font-weight: 700;
}

.section-heading p {
    margin: 4px 0 0;

    font-size: 0.82rem;

    color: #6c757d;
}


/* ================================================================
   INFO GRID
================================================================ */

.info-grid {
    display: grid;

    grid-template-columns:
        repeat(
            2,
            minmax(0, 1fr)
        );

    gap: 15px;
}

.info-item {
    border: 1px solid #e9edf2;

    background: #fafbfd;

    border-radius: 12px;

    padding: 16px;
}

.info-label {
    display: flex;

    align-items: center;

    gap: 7px;

    color: #6c757d;

    font-size: 0.75rem;

    margin-bottom: 7px;
}

.info-label i {
    color: #0d6efd;

    width: 14px;
}

.info-value {
    font-size: 0.93rem;

    font-weight: 600;

    color: #212529;

    word-break: break-word;
}


/* ================================================================
   ADDRESS
================================================================ */

.address-box {
    margin-top: 15px;

    padding: 17px;

    border-radius: 12px;

    border: 1px solid #e9edf2;

    background: #fafbfd;
}

.address-value {
    margin: 0;

    font-size: 0.9rem;

    line-height: 1.6;

    color: #343a40;

    word-break: break-word;
}


/* ================================================================
   ACCOUNT STATUS
================================================================ */

.account-status {
    margin-top: 18px;

    padding: 17px;

    border-radius: 12px;

    background: #f8fdf9;

    border: 1px solid #d9f0df;
}

.status-title {
    display: flex;

    align-items: center;

    gap: 9px;

    color: #198754;

    font-weight: 700;

    font-size: 0.9rem;

    margin-bottom: 5px;
}

.status-text {
    margin: 0;

    color: #6c757d;

    font-size: 0.81rem;
}


/* ================================================================
   META INFORMATION
================================================================ */

.meta-section {
    margin-top: 22px;

    padding-top: 22px;

    border-top: 1px solid #edf0f3;
}

.meta-grid {
    display: grid;

    grid-template-columns:
        repeat(
            2,
            minmax(0, 1fr)
        );

    gap: 12px;
}

.meta-item {
    padding: 13px;

    background: #f8f9fa;

    border-radius: 10px;
}

.meta-label {
    font-size: 0.7rem;

    color: #6c757d;

    margin-bottom: 4px;
}

.meta-value {
    font-size: 0.82rem;

    font-weight: 600;

    color: #343a40;
}


/* ================================================================
   EXAMINATION RESULTS
================================================================ */

.results-section {
    margin-top: 25px;

    padding-top: 25px;

    border-top: 1px solid #edf0f3;
}

.results-count {
    padding: 6px 11px;

    border-radius: 20px;

    background: #eaf2ff;

    color: #0d6efd;

    font-size: 0.76rem;

    font-weight: 700;

    white-space: nowrap;
}


/* ================================================================
   RESULTS FILTER
================================================================ */

.results-filter {
    margin-bottom: 18px;

    padding: 17px;

    border: 1px solid #e7ebf0;

    border-radius: 12px;

    background: #f8f9fb;
}

.results-filter-title {
    display: flex;

    align-items: center;

    gap: 8px;

    margin-bottom: 13px;

    font-size: 0.82rem;

    font-weight: 700;

    color: #343a40;
}

.results-filter-title i {
    color: #0d6efd;
}

.results-filter-grid {
    display: grid;

    grid-template-columns:
        minmax(0, 1.5fr)
        minmax(0, 1fr)
        auto;

    gap: 12px;

    align-items: end;
}

.results-filter-group label {
    display: block;

    margin-bottom: 6px;

    font-size: 0.72rem;

    color: #6c757d;

    font-weight: 600;
}

.results-filter-group .form-control,
.results-filter-group .form-select {
    min-height: 40px;

    border-radius: 9px;

    border-color: #dfe4ea;

    font-size: 0.82rem;
}

.results-filter-actions {
    display: flex;

    gap: 8px;
}

.results-filter-actions .btn {
    min-height: 40px;

    border-radius: 9px;

    font-size: 0.8rem;

    white-space: nowrap;
}

.active-filter-label {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    margin-top: 12px;

    padding: 5px 9px;

    border-radius: 7px;

    background: #eaf2ff;

    color: #0d6efd;

    font-size: 0.7rem;

    font-weight: 600;
}


/* ================================================================
   RESULTS TABLE
================================================================ */

.results-table-wrapper {
    width: 100%;

    overflow-x: auto;

    border: 1px solid #e7ebf0;

    border-radius: 12px;
}

.results-table {
    width: 100%;

    min-width: 850px;

    border-collapse: collapse;

    background: #ffffff;

    font-size: 0.84rem;
}

.results-table thead th {
    padding: 13px 14px;

    background: #f8f9fa;

    border-bottom: 1px solid #e7ebf0;

    color: #6c757d;

    font-size: 0.72rem;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 0.02em;

    white-space: nowrap;

    text-align: left;
}

.results-table tbody td {
    padding: 14px;

    border-bottom: 1px solid #edf0f3;

    vertical-align: middle;

    color: #343a40;
}

.results-table tbody tr:last-child td {
    border-bottom: 0;
}

.results-table tbody tr:hover {
    background: #fafbfd;
}


/* EXAM TITLE */

.exam-title {
    display: flex;

    align-items: center;

    gap: 10px;

    min-width: 190px;
}

.exam-icon {
    width: 36px;
    height: 36px;

    flex: 0 0 36px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 9px;

    background: #eaf2ff;

    color: #0d6efd;
}

.exam-name {
    font-weight: 700;

    color: #212529;

    margin-bottom: 2px;
}

.exam-title small {
    color: #6c757d;

    font-size: 0.68rem;
}


/* ACADEMIC YEAR */

.academic-year {
    display: inline-block;

    padding: 5px 9px;

    border-radius: 7px;

    background: #f1f5f9;

    color: #495057;

    font-size: 0.76rem;

    font-weight: 600;

    white-space: nowrap;
}


/* SCORE */

.score-badge {
    display: inline-block;

    padding: 5px 9px;

    border-radius: 7px;

    background: #e9f7ef;

    color: #198754;

    font-weight: 700;

    white-space: nowrap;
}


/* PERCENTAGE */

.percentage-value {
    font-weight: 700;

    color: #212529;

    white-space: nowrap;
}


/* DATE */

.result-date {
    white-space: nowrap;

    color: #6c757d;

    font-size: 0.78rem;
}


/* ================================================================
   EMPTY RESULTS
================================================================ */

.no-results {
    display: flex;

    align-items: center;

    gap: 15px;

    padding: 22px;

    border: 1px dashed #dce1e7;

    border-radius: 12px;

    background: #fafbfd;
}

.no-results-icon {
    width: 44px;
    height: 44px;

    flex: 0 0 44px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 11px;

    background: #f1f5f9;

    color: #6c757d;

    font-size: 18px;
}

.no-results h5 {
    margin: 0 0 4px;

    font-size: 0.92rem;

    font-weight: 700;
}

.no-results p {
    margin: 0;

    color: #6c757d;

    font-size: 0.78rem;
}


/* ================================================================
   ACTIONS
================================================================ */

.profile-actions {
    display: flex;

    flex-wrap: wrap;

    gap: 9px;

    margin-top: 22px;
}

.profile-actions .btn {
    border-radius: 9px;

    font-size: 0.84rem;

    padding: 9px 14px;
}


/* ================================================================
   MOBILE
================================================================ */

@media (max-width: 991.98px) {

    .main-content {
        padding: 18px;
    }

    .profile-layout {
        grid-template-columns: 1fr;
    }

    .profile-card {
        max-width: 100%;
    }

    .results-filter-grid {
        grid-template-columns: 1fr 1fr;
    }

    .results-filter-actions {
        grid-column: 1 / -1;
    }

}


@media (max-width: 575.98px) {

    .main-content {
        padding: 12px;
    }

    .page-header {
        padding: 16px;
    }

    .header-title h2 {
        font-size: 1.08rem;
    }

    .header-title p {
        font-size: 0.78rem;
    }

    .header-icon {
        width: 42px;
        height: 42px;

        font-size: 17px;
    }

    .details-card {
        padding: 18px;
    }

    .info-grid,
    .meta-grid {
        grid-template-columns: 1fr;
    }

    .results-section .section-heading {
        align-items: flex-start;
    }

    .results-filter-grid {
        grid-template-columns: 1fr;
    }

    .results-filter-actions {
        grid-column: auto;

        flex-direction: column;
    }

    .results-filter-actions .btn {
        width: 100%;
    }

    .profile-actions {
        flex-direction: column;
    }

    .profile-actions .btn {
        width: 100%;
    }

}

</style>

</head>

<body>

<!-- ============================================================
     SIDEBAR
============================================================= -->

<div class="sidebar">

<div class="sidebar-brand">

<h3>
    <i class="fas fa-graduation-cap me-2"></i>
    Examcenter
</h3>

<div class="admin-info">

    <small>Welcome back,</small>

    <h6>

        <b>

            <?php

            echo htmlspecialchars(
                $admin['username'] ?? 'Admin'
            );

            ?>

        </b>

    </h6>

</div>

</div>

<div class="sidebar-menu mt-4">

<a href="dashboard.php">
    <i class="fas fa-tachometer-alt"></i>
    Dashboard
</a>

<a href="add_teacher.php">
    <i class="fas fa-user-plus"></i>
    Add Teachers
</a>


<a href="view_questions.php">
    <i class="fas fa-list"></i>
    View Questions
</a>

<a href="view_results.php">
    <i class="fas fa-chart-bar"></i>
    Exam Results
</a>

<a href="manage_classes.php">
    <i class="fas fa-users"></i>
    Manage Classes
</a>

<a href="manage_session.php">
    <i class="fas fa-calendar-alt"></i>
    Manage Session
</a>

<a href="manage_subject.php">
    <i class="fas fa-book"></i>
    Manage Subject
</a>

<a
    href="manage_students.php"
    class="active"
>
    <i class="fas fa-user-graduate"></i>
    Manage Student
</a>

<a href="manage_teachers.php">
    <i class="fas fa-chalkboard-teacher"></i>
    Manage Teachers
</a>

<a href="manage_test.php">
    <i class="fas fa-file-alt"></i>
    Manage Tests
</a>

<a href="exam_schedule.php"><i class="fas fa-calendar-check"></i>Timestable</a>
<a href="../license/index.php">
    <i class="fas fa-key"></i>
    License
</a>
<a href="audit_logs.php">
    <i class="fas fa-shield-alt"></i>
    Audit Logs
</a>
<a href="../backup/backup_list.php">
    <i class="fas fa-database"></i>
    Backup
</a>
<a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
<a
    href="logout.php"
    class="logout-btn"
>
    <i class="fas fa-sign-out-alt"></i>
    Logout
</a>

</div>

</div>

<!-- ============================================================
     MAIN CONTENT
============================================================= -->

<div class="main-content">

<!-- ============================================================
     PAGE HEADER
============================================================= -->

<div class="page-header">

<div class="header-left">

    <div class="header-icon">
        <i class="fas fa-user-graduate"></i>
    </div>

    <div class="header-title">

        <h2>
            Student Profile
        </h2>

        <p>
            View student account and academic information
        </p>

    </div>

</div>


<button
    class="btn btn-primary d-lg-none"
    id="sidebarToggle"
    type="button"
    aria-label="Toggle navigation"
>

    <i class="fas fa-bars"></i>

</button>

</div>

<!-- ========================================================
     ERROR
========================================================= -->

<?php if (!empty($errorMsg)): ?>

<div class="alert alert-danger page-alert">

    <i class="fas fa-exclamation-circle me-2"></i>

    <?php

    echo htmlspecialchars(
        $errorMsg
    );

    ?>

</div>

<?php endif; ?>

<?php if ($student): ?>

<!-- ====================================================
     PROFILE LAYOUT
===================================================== -->

<div class="profile-layout">

<!-- =================================================
     LEFT PROFILE SUMMARY
================================================== -->

<div class="profile-card">

<?php if (!empty($photoPath)): ?>

    <img
        src="<?php echo htmlspecialchars($photoPath); ?>"
        alt="Student photo"
        class="profile-photo"
    >

<?php else: ?>

    <div class="profile-avatar">

        <?php

        echo htmlspecialchars(
            $studentInitial
        );

        ?>

    </div>

<?php endif; ?>


<div class="profile-name">

    <?php

    echo htmlspecialchars(
        $studentName
    );

    ?>

</div>


<div class="profile-role">

    <i class="fas fa-user-graduate"></i>

    <?php

    echo htmlspecialchars(
        $studentRole
    );

    ?>

</div>


<div class="profile-divider"></div>


<!-- CLASS -->

<div class="profile-stat">

    <div class="profile-stat-icon">
        <i class="fas fa-school"></i>
    </div>

    <div>

        <div class="profile-stat-label">
            Class
        </div>

        <div class="profile-stat-value">

            <?php

            echo htmlspecialchars(
                $className
            );

            ?>

        </div>

    </div>

</div>


<!-- REGISTRATION NUMBER -->

<div class="profile-stat">

    <div class="profile-stat-icon">
        <i class="fas fa-id-card"></i>
    </div>

    <div>

        <div class="profile-stat-label">
            Registration Number
        </div>

        <div class="profile-stat-value">

            <?php

            echo htmlspecialchars(
                $registrationNumber
            );

            ?>

        </div>

    </div>

</div>


<!-- PHONE -->

<div class="profile-stat">

    <div class="profile-stat-icon">
        <i class="fas fa-phone"></i>
    </div>

    <div>

        <div class="profile-stat-label">
            Phone
        </div>

        <div class="profile-stat-value">

            <?php

            echo htmlspecialchars(
                $phone
            );

            ?>

        </div>

    </div>

</div>


<!-- EXAMS TAKEN -->

<div class="profile-stat">

    <div class="profile-stat-icon">
        <i class="fas fa-file-alt"></i>
    </div>

    <div>

        <div class="profile-stat-label">
            Exams Shown
        </div>

        <div class="profile-stat-value">

            <?php

            echo count($studentResults);

            ?>

        </div>

    </div>

</div>


<!-- ACCOUNT STATUS -->

<div class="profile-stat">

    <div class="profile-stat-icon">
        <i class="fas fa-check-circle"></i>
    </div>

    <div>

        <div class="profile-stat-label">
            Account Status
        </div>

        <div class="profile-stat-value">
            Active
        </div>

    </div>

</div>

</div>

<!-- =================================================
     RIGHT DETAILS
================================================== -->

<div class="details-card">

<div class="section-heading">

    <div>

        <h4>
            Student Information
        </h4>

        <p>
            Personal and academic information
            associated with this student.
        </p>

    </div>

</div>


<!-- =================================================
     PERSONAL / ACADEMIC INFORMATION
================================================== -->

<div class="info-grid">


    <!-- FULL NAME -->

    <div class="info-item">

        <div class="info-label">

            <i class="fas fa-user"></i>

            Full Name

        </div>

        <div class="info-value">

            <?php

            echo htmlspecialchars(
                $studentName
            );

            ?>

        </div>

    </div>


    <!-- REGISTRATION NUMBER -->

    <div class="info-item">

        <div class="info-label">

            <i class="fas fa-id-card"></i>

            Registration Number

        </div>

        <div class="info-value">

            <?php

            echo htmlspecialchars(
                $registrationNumber
            );

            ?>

        </div>

    </div>


    <!-- CLASS -->

    <div class="info-item">

        <div class="info-label">

            <i class="fas fa-users"></i>

            Class

        </div>

        <div class="info-value">

            <?php

            echo htmlspecialchars(
                $className
            );

            ?>

        </div>

    </div>


    <!-- EMAIL -->

    <div class="info-item">

        <div class="info-label">

            <i class="fas fa-envelope"></i>

            Email

        </div>

        <div class="info-value">

            <?php

            echo htmlspecialchars(
                $email
            );

            ?>

        </div>

    </div>


    <!-- PHONE -->

    <div class="info-item">

        <div class="info-label">

            <i class="fas fa-phone"></i>

            Phone

        </div>

        <div class="info-value">

            <?php

            echo htmlspecialchars(
                $phone
            );

            ?>

        </div>

    </div>


    <!-- ROLE -->

    <div class="info-item">

        <div class="info-label">

            <i class="fas fa-user-tag"></i>

            Account Role

        </div>

        <div class="info-value">

            <?php

            echo htmlspecialchars(
                $studentRole
            );

            ?>

        </div>

    </div>


</div>


<!-- =================================================
     ADDRESS
================================================== -->

<div class="address-box">

    <div class="info-label">

        <i class="fas fa-map-marker-alt"></i>

        Address

    </div>

    <p class="address-value">

        <?php

        echo htmlspecialchars(
            $address
        );

        ?>

    </p>

</div>


<!-- =================================================
     ACCOUNT STATUS
================================================== -->

<div class="account-status">

    <div class="status-title">

        <i class="fas fa-check-circle"></i>

        Student account is active

    </div>

    <p class="status-text">

        This student is currently registered
        in the system and can participate in
        examinations assigned to the student's
        class.

    </p>

</div>


<!-- =================================================
     RECORD INFORMATION
================================================== -->

<div class="meta-section">


    <div class="section-heading">

        <div>

            <h4>
                Record Information
            </h4>

            <p>
                System information about this
                student record.
            </p>

        </div>

    </div>


    <div class="meta-grid">


        <!-- STUDENT ID -->

        <div class="meta-item">

            <div class="meta-label">
                Student ID
            </div>

            <div class="meta-value">

                #

                <?php

                echo (int) $student['id'];

                ?>

            </div>

        </div>


        <!-- CREATED VIA -->

        <div class="meta-item">

            <div class="meta-label">
                Created Via
            </div>

            <div class="meta-value">

                <?php

                echo htmlspecialchars(
                    $createdVia
                );

                ?>

            </div>

        </div>


        <!-- CREATED AT -->

        <div class="meta-item">

            <div class="meta-label">
                Created At
            </div>

            <div class="meta-value">

                <?php

                echo htmlspecialchars(
                    $createdAt
                );

                ?>

            </div>

        </div>


        <!-- UPDATED AT -->

        <div class="meta-item">

            <div class="meta-label">
                Last Updated
            </div>

            <div class="meta-value">

                <?php

                echo htmlspecialchars(
                    $updatedAt
                );

                ?>

            </div>

        </div>


    </div>

</div>


<!-- =================================================
     EXAMINATION RESULTS
================================================== -->

<div class="results-section">


    <div class="section-heading">

        <div>

            <h4>

                <i
                    class="fas fa-chart-line me-2 text-primary"
                ></i>

                Examination Results

            </h4>

            <p>

                Examination history and scores
                recorded for this student.

            </p>

        </div>


        <div class="results-count">

            <?php

            echo count(
                $studentResults
            );

            ?>

            <?php

            echo count($studentResults) === 1
                ? 'Exam'
                : 'Exams';

            ?>

        </div>

    </div>


    <!-- =================================================
         RESULT FILTER
    ================================================== -->

    <form
        method="GET"
        action="student_profile.php"
        class="results-filter"
    >

        <input
            type="hidden"
            name="id"
            value="<?php echo (int) $student['id']; ?>"
        >


        <div class="results-filter-title">

            <i class="fas fa-filter"></i>

            Filter Examination Results

        </div>


        <div class="results-filter-grid">


            <!-- TEST TITLE -->

            <div class="results-filter-group">

                <label for="test_title">
                    Test Title
                </label>

                <select
                    class="form-select"
                    id="test_title"
                    name="test_title"
                >

                    <option value="">
                        All Tests
                    </option>


                    <?php foreach (
                        $availableTestTitles
                        as $title
                    ): ?>

                        <option
                            value="<?php echo htmlspecialchars($title); ?>"
                            <?php
                            echo $testTitleFilter === $title
                                ? 'selected'
                                : '';
                            ?>
                        >

                            <?php

                            echo htmlspecialchars(
                                $title
                            );

                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- ACADEMIC YEAR -->

            <div class="results-filter-group">

                <label for="academic_year">
                    Academic Year
                </label>

                <select
                    class="form-select"
                    id="academic_year"
                    name="academic_year"
                >

                    <option value="">
                        All Years
                    </option>


                    <?php foreach (
                        $availableAcademicYears
                        as $year
                    ): ?>

                        <option
                            value="<?php echo htmlspecialchars($year); ?>"
                            <?php
                            echo $academicYearFilter === (string) $year
                                ? 'selected'
                                : '';
                            ?>
                        >

                            <?php

                            echo htmlspecialchars(
                                $year
                            );

                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- ACTIONS -->

            <div class="results-filter-actions">

                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    <i class="fas fa-search me-1"></i>

                    Apply Filter

                </button>


                <?php if ($filtersActive): ?>

                    <a
                        href="<?php echo htmlspecialchars($baseProfileUrl); ?>"
                        class="btn btn-outline-secondary"
                    >

                        <i class="fas fa-times me-1"></i>

                        Clear

                    </a>

                <?php endif; ?>

            </div>


        </div>


        <?php if ($filtersActive): ?>

            <div class="active-filter-label">

                <i class="fas fa-filter"></i>

                Filter active

                <?php if ($testTitleFilter !== ''): ?>

                    <span>
                        • Test:
                        <?php
                        echo htmlspecialchars(
                            $testTitleFilter
                        );
                        ?>
                    </span>

                <?php endif; ?>


                <?php if ($academicYearFilter !== ''): ?>

                    <span>
                        • Year:
                        <?php
                        echo htmlspecialchars(
                            $academicYearFilter
                        );
                        ?>
                    </span>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </form>


    <!-- =================================================
         RESULTS TABLE
    ================================================== -->


    <?php if (!empty($studentResults)): ?>


        <div class="results-table-wrapper">

            <table class="results-table">

                <thead>

                    <tr>

                        <th>
                            Examination
                        </th>

                        <th>
                            Academic Year
                        </th>

                        <th>
                            Subject
                        </th>

                        <th>
                            Score
                        </th>

                        <th>
                            Percentage
                        </th>

                        <th>
                            Date Taken
                        </th>

                    </tr>

                </thead>


                <tbody>


                    <?php foreach (
                        $studentResults
                        as $examResult
                    ): ?>


                        <?php

                        $score = (float) (
                            $examResult['score']
                            ?? 0
                        );


                        $totalQuestions = (int) (
                            $examResult[
                                'total_questions'
                            ]
                            ?? 0
                        );


                        $percentage =
                            $totalQuestions > 0
                                ? (
                                    $score
                                    /
                                    $totalQuestions
                                ) * 100
                                : 0;

                        ?>


                        <tr>


                            <!-- EXAMINATION -->

                            <td>

                                <div class="exam-title">


                                    <div class="exam-icon">

                                        <i
                                            class="fas fa-file-alt"
                                        ></i>

                                    </div>


                                    <div>

                                        <div class="exam-name">

                                            <?php

                                            echo htmlspecialchars(
                                                $examResult[
                                                    'exam_title'
                                                ]
                                                ??
                                                'Untitled Exam'
                                            );

                                            ?>

                                        </div>


                                        <small>

                                            Test #

                                            <?php

                                            echo (int) (
                                                $examResult[
                                                    'test_id'
                                                ]
                                            );

                                            ?>

                                        </small>

                                    </div>


                                </div>

                            </td>


                            <!-- ACADEMIC YEAR -->

                            <td>

                                <span class="academic-year">

                                    <?php

                                    echo htmlspecialchars(
                                        $examResult[
                                            'academic_year'
                                        ]
                                        ??
                                        'Not specified'
                                    );

                                    ?>

                                </span>

                            </td>


                            <!-- SUBJECT -->

                            <td>

                                <?php

                                echo htmlspecialchars(
                                    $examResult[
                                        'subject'
                                    ]
                                    ??
                                    'Not specified'
                                );

                                ?>

                            </td>


                            <!-- SCORE -->

                            <td>

                                <span class="score-badge">

                                    <?php

                                    echo htmlspecialchars(
                                        (string) $score
                                    );

                                    ?>

                                    /

                                    <?php

                                    echo (int)
                                        $totalQuestions;

                                    ?>

                                </span>

                            </td>


                            <!-- PERCENTAGE -->

                            <td>

                                <span
                                    class="percentage-value"
                                >

                                    <?php

                                    echo number_format(
                                        $percentage,
                                        1
                                    );

                                    ?>%

                                </span>

                            </td>


                            <!-- DATE TAKEN -->

                            <td>

                                <span class="result-date">

                                    <?php

                                    echo !empty(
                                        $examResult[
                                            'taken_at'
                                        ]
                                    )

                                        ? htmlspecialchars(
                                            date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $examResult[
                                                        'taken_at'
                                                    ]
                                                )
                                            )
                                        )

                                        : 'Not available';

                                    ?>

                                </span>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                </tbody>

            </table>

        </div>


    <?php else: ?>


        <div class="no-results">


            <div class="no-results-icon">

                <i
                    class="fas fa-clipboard-list"
                ></i>

            </div>


            <div>

                <h5>

                    <?php

                    echo $filtersActive
                        ? 'No matching examination results'
                        : 'No examination results';

                    ?>

                </h5>


                <p>

                    <?php

                    echo $filtersActive

                        ? 'No examination result matches the selected filters. Try another test title or academic year.'

                        : 'This student has not taken any examination yet.';

                    ?>

                </p>

            </div>


        </div>


    <?php endif; ?>


</div>


<!-- =================================================
     ACTIONS
================================================== -->

<div class="profile-actions">


    <a
        href="manage_students.php"
        class="btn btn-outline-secondary"
    >

        <i class="fas fa-arrow-left me-1"></i>

        Back to Students

    </a>


    <a
        href="promote_student.php?student_id=<?php echo (int) $student['id']; ?>"
        class="btn btn-success"
    >

        <i class="fas fa-level-up-alt me-1"></i>

        Promote Student

    </a>


    <button
        type="button"
        class="btn btn-info text-white"
        id="rescheduleExamBtn"
        data-student-id="<?php echo (int) $student['id']; ?>"
    >

        <i class="fas fa-calendar-alt me-1"></i>

        Reschedule Exam

    </button>


</div>

</div>

</div>

<?php endif; ?>

</div>

<!-- ============================================================
     RESCHEDULE MODAL
============================================================= -->

<div
    class="modal fade"
    id="reattemptModal"
    tabindex="-1"
    aria-hidden="true"
>

<div
    class="modal-dialog modal-lg modal-dialog-centered"
>

<div class="modal-content">


    <div class="modal-header">

        <h5 class="modal-title">

            <i class="fas fa-calendar-alt me-2"></i>

            Reschedule Exam

        </h5>


        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Close"
        ></button>

    </div>


    <div
        class="modal-body"
        id="reattemptModalBody"
    >

        <div class="text-center py-4">

            <div
                class="spinner-border text-primary"
                role="status"
            ></div>

            <div class="mt-2 text-muted">

                Loading examinations...

            </div>

        </div>

    </div>


</div>

</div>

</div>

<!-- ============================================================
     JAVASCRIPT
============================================================= -->

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery-3.7.0.min.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        // ========================================================
        // SIDEBAR TOGGLE
        // ========================================================

        const toggle =
            document.getElementById(
                'sidebarToggle'
            );


        const sidebar =
            document.querySelector(
                '.sidebar'
            );


        if (toggle && sidebar) {

            toggle.addEventListener(
                'click',
                function () {

                    sidebar.classList.toggle(
                        'active'
                    );

                }
            );

        }


        // ========================================================
        // RESCHEDULE EXAM
        // ========================================================

        const rescheduleBtn =
            document.getElementById(
                'rescheduleExamBtn'
            );


        if (rescheduleBtn) {


            rescheduleBtn.addEventListener(
                'click',
                function () {


                    const studentId =
                        this.dataset.studentId;


                    if (!studentId) {
                        return;
                    }


                    const modalElement =
                        document.getElementById(
                            'reattemptModal'
                        );


                    const modal =
                        bootstrap.Modal.getOrCreateInstance(
                            modalElement
                        );


                    const modalBody =
                        document.getElementById(
                            'reattemptModalBody'
                        );


                    modalBody.innerHTML = `

                        <div class="text-center py-4">

                            <div
                                class="spinner-border text-primary"
                                role="status"
                            ></div>

                            <div class="mt-2 text-muted">

                                Loading examinations...

                            </div>

                        </div>

                    `;


                    modal.show();


                    // =================================================
                    // LOAD STUDENT EXAMS
                    // =================================================

                    fetch(
                        'fetch_student_tests.php?student_id='
                        + encodeURIComponent(
                            studentId
                        )
                    )


                    .then(
                        response => {

                            if (!response.ok) {

                                throw new Error(
                                    'Unable to load examinations.'
                                );

                            }

                            return response.text();

                        }
                    )


                    .then(
                        html => {

                            modalBody.innerHTML =
                                html;

                        }
                    )


                    .catch(
                        error => {

                            console.error(
                                error
                            );


                            modalBody.innerHTML = `

                                <div class="alert alert-danger">

                                    <i
                                        class="fas fa-exclamation-circle me-2"
                                    ></i>

                                    Unable to load the
                                    student's examinations.

                                </div>

                            `;

                        }
                    );


                }
            );

        }


        // ========================================================
        // AUTO-SUBMIT FILTER ON CHANGE
        // ========================================================
        //
        // This makes the filters feel faster on desktop/mobile.
        // The Apply Filter button remains available as well.
        // ========================================================

        const testTitleSelect =
            document.getElementById(
                'test_title'
            );


        const academicYearSelect =
            document.getElementById(
                'academic_year'
            );


        if (testTitleSelect) {

            testTitleSelect.addEventListener(
                'change',
                function () {

                    this.form.submit();

                }
            );

        }


        if (academicYearSelect) {

            academicYearSelect.addEventListener(
                'change',
                function () {

                    this.form.submit();

                }
            );

        }


    }
);

</script>

</body>

</html>
