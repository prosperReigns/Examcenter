<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

/*
|--------------------------------------------------------------------------
| PAGE MODE
|--------------------------------------------------------------------------
*/
$isBankMode = isset($_GET['mode']) && $_GET['mode'] === 'bank';

/*
|--------------------------------------------------------------------------
| ERROR LOGGING
|--------------------------------------------------------------------------
*/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

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
    header("Location: ../login.php?error=Not logged in");
    exit();
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function selected($value, $expected): string
{
    return (string)$value === (string)$expected ? 'selected' : '';
}

/*
|--------------------------------------------------------------------------
| INITIAL STATE
|--------------------------------------------------------------------------
*/
$error = '';
$success = '';

$current_test = null;
$questions = [];
$total_questions = 0;
$tests = [];

$teacher = null;
$assigned_subjects = [];
$levels = [];
$test_titles = [];
$academic_years = [];

$edit_question = null;

$edit_data = [
    'options' => [],
    'question_type' => '',
    'correct_answers' => []
];

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/
try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("Database connection failed.");
    }

    /*
    |--------------------------------------------------------------------------
    | TEACHER
    |--------------------------------------------------------------------------
    */
    $teacher_id = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT username, last_name
        FROM teachers
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare teacher query.");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $teacher = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$teacher) {
        session_destroy();
        header("Location: ../login.php?error=Unauthorized");
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
        ORDER BY subject ASC
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare subject query.");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $result = $stmt->get_result();

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
    $result = $conn->query("
        SELECT id, level_code, class_group
        FROM academic_levels
        ORDER BY
            CASE
                WHEN level_code REGEXP '^[A-Za-z]+[0-9]+$'
                THEN CAST(REGEXP_REPLACE(level_code, '[^0-9]', '') AS UNSIGNED)
                ELSE 999
            END,
            level_code ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $levels[] = $row;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TEST TITLES
    |--------------------------------------------------------------------------
    */
    $result = $conn->query("
        SELECT CONCAT_WS(' ', session, exam_title) AS title
        FROM academic_years
        WHERE status = 'active'
          AND session IS NOT NULL
          AND exam_title IS NOT NULL
        ORDER BY id DESC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty(trim($row['title']))) {
                $test_titles[] = $row['title'];
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACADEMIC YEARS
    |--------------------------------------------------------------------------
    */
    $result = $conn->query("
        SELECT DISTINCT year
        FROM academic_years
        WHERE year IS NOT NULL
        ORDER BY year DESC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $academic_years[] = $row['year'];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FETCH AVAILABLE TESTS
    |--------------------------------------------------------------------------
    */
    if (!empty($assigned_subjects)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($assigned_subjects), '?')
        );

        $stmt = $conn->prepare("
            SELECT
                t.id,
                t.title,
                t.subject,
                t.year,
                t.duration,
                t.created_at,
                al.level_code,
                al.class_group
            FROM tests t
            INNER JOIN academic_levels al
                ON al.id = t.academic_level_id
            WHERE t.subject IN ($placeholders)
            ORDER BY t.created_at DESC
        ");

        if (!$stmt) {
            throw new Exception("Unable to fetch tests.");
        }

        $types = str_repeat('s', count($assigned_subjects));

        $stmt->bind_param(
            $types,
            ...$assigned_subjects
        );

        $stmt->execute();

        $tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT TEST
    |--------------------------------------------------------------------------
    */
    if (isset($_SESSION['current_test_id']) && !empty($assigned_subjects)) {

        $test_id = (int)$_SESSION['current_test_id'];

        $placeholders = implode(
            ',',
            array_fill(0, count($assigned_subjects), '?')
        );

        $stmt = $conn->prepare("
            SELECT
                t.id,
                t.title,
                t.subject,
                t.year,
                t.duration,
                t.created_at,
                al.level_code,
                al.class_group
            FROM tests t
            INNER JOIN academic_levels al
                ON al.id = t.academic_level_id
            WHERE t.id = ?
              AND t.subject IN ($placeholders)
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception("Unable to load selected test.");
        }

        $params = array_merge(
            [$test_id],
            $assigned_subjects
        );

        $types = 'i' . str_repeat(
            's',
            count($assigned_subjects)
        );

        $stmt->bind_param(
            $types,
            ...$params
        );

        $stmt->execute();

        $current_test = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($current_test) {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    question_text,
                    question_type
                FROM new_questions
                WHERE test_id = ?
                ORDER BY id ASC
            ");

            if (!$stmt) {
                throw new Exception("Unable to load questions.");
            }

            $stmt->bind_param("i", $test_id);
            $stmt->execute();

            $questions = $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

            $total_questions = count($questions);

            $stmt->close();

        } else {

            unset($_SESSION['current_test_id']);

            $error = "Selected test is invalid or unauthorized.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION MESSAGES
    |--------------------------------------------------------------------------
    */
    if (isset($_SESSION['error'])) {
        $error = $_SESSION['error'];
        unset($_SESSION['error']);
    }

    if (isset($_SESSION['success'])) {
        $success = $_SESSION['success'];
        unset($_SESSION['success']);
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT QUESTION
    |--------------------------------------------------------------------------
    */
    if (isset($_SESSION['edit_question'])) {

        $edit_question = $_SESSION['edit_question'];

        unset($_SESSION['edit_question']);

        $edit_data = [
            'options' => $edit_question['options'] ?? [],
            'question_type' => $edit_question['question_type'] ?? '',
            'correct_answers' => []
        ];

        if (
            isset($edit_question['options']['correct_answers']) &&
            $edit_question['options']['correct_answers'] !== ''
        ) {

            $edit_data['correct_answers'] =
                array_map(
                    'intval',
                    explode(
                        ',',
                        $edit_question['options']['correct_answers']
                    )
                );
        }
    }

} catch (Throwable $e) {

    error_log(
        "Add question error: " . $e->getMessage()
    );

    $error = "Unable to load the page. Please try again.";
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
        <?= $edit_question ? 'Edit Question' : 'Add Question' ?>
        | Examcenter
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

    <link
        rel="stylesheet"
        href="../css/add_question.css"
    >

    <style>

        :root {
            --aq-primary: #4361ee;
            --aq-primary-dark: #3046c9;
            --aq-bg: #f5f7fb;
            --aq-card: #ffffff;
            --aq-border: #e6eaf0;
            --aq-text: #172033;
            --aq-muted: #718096;
            --aq-success: #16a34a;
            --aq-danger: #dc2626;
            --aq-shadow:
                0 10px 30px rgba(15, 23, 42, .06);
        }

        body {
            background: var(--aq-bg);
        }

        .main-content {
            min-height: 100vh;
        }

        /* ---------------------------------------------------------
           HEADER
        --------------------------------------------------------- */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-heading-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: rgba(67, 97, 238, .1);
            color: var(--aq-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .page-heading h2 {
            margin: 0;
            color: var(--aq-text);
            font-size: 25px;
            font-weight: 750;
        }

        .page-heading p {
            margin: 4px 0 0;
            color: var(--aq-muted);
            font-size: 13px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-actions .btn {
            min-height: 42px;
            border-radius: 10px;
            font-weight: 600;
        }

        #sidebarToggle {
            display: none;
        }

        /* ---------------------------------------------------------
           CARDS
        --------------------------------------------------------- */

        .aq-card {
            background: var(--aq-card);
            border: 1px solid var(--aq-border);
            border-radius: 16px;
            box-shadow: var(--aq-shadow);
            overflow: hidden;
        }

        .aq-card-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--aq-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .aq-card-title {
            margin: 0;
            color: var(--aq-text);
            font-size: 16px;
            font-weight: 750;
        }

        .aq-card-subtitle {
            margin: 4px 0 0;
            color: var(--aq-muted);
            font-size: 12px;
        }

        .aq-card-body {
            padding: 22px;
        }

        /* ---------------------------------------------------------
           FORM
        --------------------------------------------------------- */

        .form-section {
            margin-bottom: 24px;
        }

        .form-section:last-child {
            margin-bottom: 0;
        }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 15px;
            color: var(--aq-text);
            font-size: 14px;
            font-weight: 750;
        }

        .form-section-title i {
            color: var(--aq-primary);
        }

        .form-label {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 43px;
            border-radius: 9px;
            border-color: #dce2eb;
            font-size: 13px;
            box-shadow: none;
        }

        textarea.form-control {
            min-height: 125px;
            resize: vertical;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--aq-primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, .1);
        }

        .field-help {
            margin-top: 5px;
            color: var(--aq-muted);
            font-size: 11px;
        }

        /* ---------------------------------------------------------
           TEST SETUP
        --------------------------------------------------------- */

        .setup-grid {
            display: grid;
            grid-template-columns:
                repeat(12, minmax(0, 1fr));
            gap: 16px;
        }

        .setup-col-2 {
            grid-column: span 2;
        }

        .setup-col-3 {
            grid-column: span 3;
        }

        .setup-col-4 {
            grid-column: span 4;
        }

        .setup-col-6 {
            grid-column: span 6;
        }

        .setup-col-12 {
            grid-column: span 12;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid var(--aq-border);
        }

        .btn {
            border-radius: 9px;
            font-size: 13px;
            font-weight: 650;
        }

        /* ---------------------------------------------------------
           OPTION CARDS
        --------------------------------------------------------- */

        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .option-card {
            padding: 14px;
            border: 1px solid var(--aq-border);
            border-radius: 12px;
            background: #fafbfe;
            transition: .2s ease;
        }

        .option-card:focus-within {
            border-color: rgba(67, 97, 238, .45);
            background: #fff;
            box-shadow:
                0 0 0 3px rgba(67, 97, 238, .07);
        }

        .option-number {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 8px;
            color: #475569;
            font-size: 11px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .option-number span {
            width: 23px;
            height: 23px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(67, 97, 238, .1);
            color: var(--aq-primary);
        }

        /* ---------------------------------------------------------
           IMAGE
        --------------------------------------------------------- */

        .image-panel {
            display: none;
            margin-top: 12px;
            padding: 14px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
        }

        .image-panel.is-visible {
            display: block;
        }

        .image-toggle {
            border-radius: 8px;
            font-size: 12px;
        }

        .current-image {
            max-width: 180px;
            max-height: 120px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid var(--aq-border);
            padding: 4px;
            background: #fff;
        }

        /* ---------------------------------------------------------
           CHECKBOXES
        --------------------------------------------------------- */

        .correct-answer-box {
            padding: 15px;
            border-radius: 11px;
            background: #f8fafc;
            border: 1px solid var(--aq-border);
        }

        .correct-option {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 10px;
            border-radius: 8px;
            transition: .15s ease;
        }

        .correct-option:hover {
            background: #eef2ff;
        }

        .correct-option label {
            cursor: pointer;
            font-size: 13px;
            color: #334155;
            margin: 0;
        }

        /* ---------------------------------------------------------
           UPLOAD
        --------------------------------------------------------- */

        .upload-panel {
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            border-radius: 12px;
            padding: 18px;
        }

        .upload-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(67, 97, 238, .1);
            color: var(--aq-primary);
            margin-bottom: 10px;
        }

        .upload-title {
            font-size: 13px;
            font-weight: 750;
            color: var(--aq-text);
            margin-bottom: 4px;
        }

        .upload-description {
            color: var(--aq-muted);
            font-size: 11px;
            margin-bottom: 14px;
        }

        /* ---------------------------------------------------------
           OVERVIEW
        --------------------------------------------------------- */

        .overview-card {
            position: sticky;
            top: 20px;
        }

        .test-summary {
            padding: 16px;
            border-radius: 12px;
            background:
                linear-gradient(
                    135deg,
                    rgba(67, 97, 238, .1),
                    rgba(67, 97, 238, .03)
                );
            border: 1px solid rgba(67, 97, 238, .13);
        }

        .test-summary-title {
            color: var(--aq-text);
            font-weight: 750;
            font-size: 15px;
            margin-bottom: 8px;
        }

        .test-summary-meta {
            color: var(--aq-muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .stat-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid var(--aq-border);
        }

        .stat-box:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: var(--aq-muted);
            font-size: 12px;
        }

        .stat-value {
            color: var(--aq-text);
            font-size: 14px;
            font-weight: 750;
        }

        /* ---------------------------------------------------------
           PREVIEW
        --------------------------------------------------------- */

        .preview-question {
            padding: 16px;
            border: 1px solid var(--aq-border);
            border-radius: 12px;
            margin-bottom: 14px;
            background: #fff;
        }

        .preview-question:last-child {
            margin-bottom: 0;
        }

        .preview-question-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
        }

        .preview-question-text {
            flex: 1;
            color: var(--aq-text);
            font-size: 13px;
            line-height: 1.65;
            font-weight: 650;
        }

        .preview-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            flex-shrink: 0;
        }

        .preview-actions form {
            margin: 0;
        }

        .preview-option {
            padding: 6px 0;
            color: #475569;
            font-size: 12px;
        }

        .preview-option.correct {
            color: var(--aq-success);
            font-weight: 650;
        }

        /* ---------------------------------------------------------
           ALERTS
        --------------------------------------------------------- */

        .aq-alert {
            border: none;
            border-radius: 11px;
            font-size: 12px;
            box-shadow: 0 5px 15px rgba(15, 23, 42, .04);
        }

        /* ---------------------------------------------------------
           RESPONSIVE
        --------------------------------------------------------- */

        @media (max-width: 1199px) {

            .setup-col-2 {
                grid-column: span 4;
            }

            .setup-col-3 {
                grid-column: span 4;
            }

            .setup-col-4 {
                grid-column: span 6;
            }
        }

        @media (max-width: 991px) {

            #sidebarToggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .overview-card {
                position: static;
            }

            .setup-col-2,
            .setup-col-3,
            .setup-col-4,
            .setup-col-6 {
                grid-column: span 6;
            }

            .page-header {
                align-items: flex-start;
            }
        }

        @media (max-width: 767px) {

            .page-header {
                flex-direction: column;
                align-items: stretch;
            }

            .header-actions {
                justify-content: flex-end;
            }

            .setup-grid {
                grid-template-columns: 1fr;
            }

            .setup-col-2,
            .setup-col-3,
            .setup-col-4,
            .setup-col-6,
            .setup-col-12 {
                grid-column: span 1;
            }

            .options-grid {
                grid-template-columns: 1fr;
            }

            .aq-card-body {
                padding: 17px;
            }

            .aq-card-header {
                padding: 17px;
            }

            .page-heading h2 {
                font-size: 21px;
            }

            .preview-question-header {
                flex-direction: column;
            }

            .preview-actions {
                width: 100%;
            }

            .preview-actions .btn {
                flex: 1;
            }
        }

        @media (max-width: 575px) {

            .page-heading-icon {
                width: 42px;
                height: 42px;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .btn {
                flex: 1;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .form-actions .btn {
                width: 100%;
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

            <small>Welcome back,</small>

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

        <a
            href="add_question.php"
            class="active"
        >
            <i class="fas fa-plus-circle"></i>
            Add Questions
        </a>

        <a href="bank.php">
            <i class="fas fa-database"></i>
            Question Bank
        </a>

        <a href="view_results.php">
            <i class="fas fa-chart-bar"></i>
            Exam Results
        </a>

        <a href="view_questions.php">
            <i class="fas fa-list"></i>
            View Questions
        </a>

        <a href="manage_test.php">
            <i class="fas fa-file-alt"></i>
            Manage Test
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

    <!-- HEADER -->

    <div class="page-header">

        <div class="page-heading">

            <div class="page-heading-icon">
                <i class="fas fa-plus-circle"></i>
            </div>

            <div>

                <h2>
                    <?= $edit_question ? 'Edit Question' : 'Add Questions' ?>
                </h2>

                <p>
                    <?= $isBankMode
                        ? 'Build and manage reusable questions in your question bank.'
                        : 'Create tests and add questions to your examination.'
                    ?>
                </p>

            </div>

        </div>

        <div class="header-actions">

            <button
                type="button"
                class="btn btn-primary"
                id="sidebarToggle"
                aria-label="Toggle sidebar"
            >
                <i class="fas fa-bars"></i>
            </button>

            <?php if (!$isBankMode): ?>

                <button
                    type="button"
                    class="btn btn-primary <?= !$current_test ? 'disabled' : '' ?>"
                    id="previewButton"
                    <?= !$current_test ? 'disabled' : '' ?>
                    data-bs-toggle="modal"
                    data-bs-target="#previewModal"
                >
                    <i class="fas fa-eye me-2"></i>
                    Preview
                </button>

            <?php endif; ?>

        </div>

    </div>


    <!-- ALERTS -->

    <?php if ($error): ?>

        <div
            class="alert alert-danger aq-alert alert-dismissible fade show mb-4"
            role="alert"
        >

            <i class="fas fa-exclamation-circle me-2"></i>

            <?= e($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($success): ?>

        <div
            class="alert alert-success aq-alert alert-dismissible fade show mb-4"
            role="alert"
        >

            <i class="fas fa-check-circle me-2"></i>

            <?= e($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <div class="row g-4">

        <!-- =====================================================
             LEFT COLUMN
        ====================================================== -->

        <div class="<?= $isBankMode ? 'col-12' : 'col-lg-8' ?>">

            <div class="aq-card">

                <!-- =================================================
                     TEST SETUP
                ================================================== -->

                <?php if (!$current_test && !$isBankMode): ?>

                    <div class="aq-card-header">

                        <div>

                            <h5 class="aq-card-title">
                                <i class="fas fa-sliders-h me-2 text-primary"></i>
                                Test Setup
                            </h5>

                            <p class="aq-card-subtitle">
                                Create a new test before adding questions.
                            </p>

                        </div>

                    </div>

                    <div class="aq-card-body">

                        <form
                            method="POST"
                            id="testForm"
                            action="handle_test.php"
                        >

                            <div class="setup-grid">

                                <div class="setup-col-3">

                                    <label
                                        class="form-label"
                                        for="year"
                                    >
                                        Academic Year
                                    </label>

                                    <select
                                        class="form-select"
                                        name="year"
                                        id="year"
                                        required
                                    >

                                        <option value="">
                                            Select Academic Year
                                        </option>

                                        <?php foreach ($academic_years as $year): ?>

                                            <option
                                                value="<?= e($year) ?>"
                                            >
                                                <?= e($year) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <div class="setup-col-3">

                                    <label
                                        class="form-label"
                                        for="test_title"
                                    >
                                        Test Title
                                    </label>

                                    <select
                                        class="form-select"
                                        name="test_title"
                                        id="test_title"
                                        required
                                    >

                                        <option value="">
                                            Select Test Title
                                        </option>

                                        <?php foreach ($test_titles as $title): ?>

                                            <option
                                                value="<?= e($title) ?>"
                                            >
                                                <?= e($title) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <div class="setup-col-2">

                                    <label
                                        class="form-label"
                                        for="classSelect"
                                    >
                                        Class
                                    </label>

                                    <select
                                        class="form-select"
                                        name="academic_level_id"
                                        id="classSelect"
                                        required
                                    >

                                        <option value="">
                                            Select Class
                                        </option>

                                        <?php foreach ($levels as $level): ?>

                                            <option
                                                value="<?= (int)$level['id'] ?>"
                                            >
                                                <?= e($level['level_code']) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <div class="setup-col-2">

                                    <label
                                        class="form-label"
                                        for="subjectSelect"
                                    >
                                        Subject
                                    </label>

                                    <select
                                        class="form-select"
                                        name="subject"
                                        id="subjectSelect"
                                        required
                                    >

                                        <option value="">
                                            Select Subject
                                        </option>

                                        <?php foreach ($assigned_subjects as $subject): ?>

                                            <option
                                                value="<?= e($subject) ?>"
                                            >
                                                <?= e($subject) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <div class="setup-col-2">

                                    <label
                                        class="form-label"
                                        for="duration"
                                    >
                                        Duration
                                    </label>

                                    <input
                                        type="number"
                                        class="form-control"
                                        name="duration"
                                        id="duration"
                                        min="1"
                                        required
                                        placeholder="Minutes"
                                    >

                                </div>

                            </div>


                            <div class="form-actions">

                                <button
                                    type="submit"
                                    name="create_test"
                                    class="btn btn-primary px-4"
                                >

                                    <i class="fas fa-plus me-2"></i>

                                    Create Test

                                </button>

                            </div>

                        </form>


                        <!-- =================================================
                             UPLOAD TEST
                        ================================================== -->

                        <div class="form-section mt-5">

                            <div class="form-section-title">

                                <i class="fas fa-file-upload"></i>

                                Upload Existing Test

                            </div>

                            <div class="upload-panel">

                                <div class="upload-icon">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>

                                <div class="upload-title">
                                    Import a test from DOCX
                                </div>

                                <div class="upload-description">
                                    Upload a properly formatted Word document
                                    containing your examination questions.
                                </div>

                                <form
                                    method="POST"
                                    id="uploadForm"
                                    enctype="multipart/form-data"
                                    action="upload.php"
                                >

                                    <div class="mb-3">

                                        <label
                                            class="form-label"
                                            for="upload_year"
                                        >
                                            Academic Year
                                        </label>

                                        <select
                                            class="form-select"
                                            name="year"
                                            id="upload_year"
                                            required
                                        >

                                            <option value="">
                                                Select Academic Year
                                            </option>

                                            <?php foreach ($academic_years as $year): ?>

                                                <option
                                                    value="<?= e($year) ?>"
                                                >
                                                    <?= e($year) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <div class="mb-3">

                                        <label
                                            class="form-label"
                                            for="test_file"
                                        >
                                            Test File
                                        </label>

                                        <input
                                            type="file"
                                            class="form-control"
                                            name="test_file"
                                            id="test_file"
                                            accept=".docx"
                                            required
                                        >

                                        <div class="field-help">
                                            DOCX files only.
                                        </div>

                                    </div>


                                    <button
                                        type="submit"
                                        class="btn btn-outline-primary"
                                    >

                                        <i class="fas fa-upload me-2"></i>

                                        Upload Test

                                    </button>

                                </form>

                            </div>

                        </div>


                        <!-- =================================================
                             EXISTING TESTS
                        ================================================== -->

                        <?php if (!empty($tests)): ?>

                            <div class="form-section mt-5">

                                <div class="form-section-title">

                                    <i class="fas fa-folder-open"></i>

                                    Existing Tests

                                </div>

                                <form
                                    method="POST"
                                    id="selectTestForm"
                                    action="handle_test.php"
                                >

                                    <label
                                        class="form-label"
                                        for="testIdSelect"
                                    >
                                        Select a Test
                                    </label>

                                    <select
                                        class="form-select"
                                        name="test_id"
                                        id="testIdSelect"
                                        required
                                    >

                                        <option value="">
                                            Select a test to continue
                                        </option>

                                        <?php foreach ($tests as $test): ?>

                                            <option
                                                value="<?= (int)$test['id'] ?>"
                                            >

                                                <?= e(
                                                    $test['title']
                                                    . ' — '
                                                    . $test['level_code']
                                                    . ' / '
                                                    . $test['subject']
                                                    . ' / '
                                                    . $test['year']
                                                ) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <div class="form-actions">

                                        <button
                                            type="submit"
                                            name="select_test"
                                            class="btn btn-primary"
                                        >

                                            <i class="fas fa-check me-2"></i>

                                            Select Test

                                        </button>

                                    </div>

                                </form>

                            </div>

                        <?php endif; ?>


                    </div>


                <!-- =================================================
                     QUESTION FORM
                ================================================== -->

                <?php elseif ($current_test || $isBankMode): ?>

                    <div class="aq-card-header">

                        <div>

                            <h5 class="aq-card-title">

                                <i class="fas fa-<?= $edit_question ? 'edit' : 'plus-circle' ?> me-2 text-primary"></i>

                                <?= $edit_question
                                    ? 'Edit Question'
                                    : 'Add Question'
                                ?>

                            </h5>

                            <p class="aq-card-subtitle">

                                <?= $isBankMode
                                    ? 'Create a reusable question for your question bank.'
                                    : 'Add a question to the selected test.'
                                ?>

                            </p>

                        </div>

                    </div>


                    <div class="aq-card-body">

                        <form
                            method="POST"
                            id="questionForm"
                            enctype="multipart/form-data"
                            action="handle_question.php"
                        >

                            <input
                                type="hidden"
                                name="bank_mode"
                                value="<?= $isBankMode ? '1' : '0' ?>"
                            >

                            <input
                                type="hidden"
                                name="question_id"
                                value="<?= (int)($edit_question['id'] ?? 0) ?>"
                            >


                            <?php if ($isBankMode): ?>

                                <!-- BANK DETAILS -->

                                <div class="form-section">

                                    <div class="form-section-title">

                                        <i class="fas fa-database"></i>

                                        Question Bank Details

                                    </div>

                                    <div class="row g-3">

                                        <div class="col-md-4">

                                            <label
                                                class="form-label"
                                                for="bankClass"
                                            >
                                                Class
                                            </label>

                                            <select
                                                class="form-select"
                                                name="academic_level_id"
                                                id="bankClass"
                                                required
                                            >

                                                <?php foreach ($levels as $level): ?>

                                                    <option
                                                        value="<?= (int)$level['id'] ?>"
                                                        <?= selected(
                                                            $edit_question['class'] ?? '',
                                                            $level['level_code']
                                                        ) ?>
                                                    >
                                                        <?= e($level['level_code']) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                class="form-label"
                                                for="bankSubject"
                                            >
                                                Subject
                                            </label>

                                            <select
                                                class="form-select"
                                                name="subject"
                                                id="bankSubject"
                                                required
                                            >

                                                <?php foreach ($assigned_subjects as $subj): ?>

                                                    <option
                                                        value="<?= e($subj) ?>"
                                                        <?= selected(
                                                            $edit_question['subject'] ?? '',
                                                            $subj
                                                        ) ?>
                                                    >
                                                        <?= e($subj) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                class="form-label"
                                                for="topic"
                                            >
                                                Topic
                                                <span class="text-muted">
                                                    (Optional)
                                                </span>
                                            </label>

                                            <input
                                                type="text"
                                                class="form-control"
                                                name="topic"
                                                id="topic"
                                                placeholder="e.g. Algebra"
                                                value="<?= e(
                                                    $edit_question['topic'] ?? ''
                                                ) ?>"
                                            >

                                        </div>

                                    </div>

                                </div>

                            <?php endif; ?>


                            <!-- QUESTION TYPE -->

                            <div class="form-section">

                                <div class="form-section-title">

                                    <i class="fas fa-list-alt"></i>

                                    Question Details

                                </div>


                                <div class="mb-4">

                                    <label
                                        class="form-label"
                                        for="questionType"
                                    >
                                        Question Type
                                    </label>

                                    <select
                                        class="form-select"
                                        name="question_type"
                                        id="questionType"
                                        required
                                    >

                                        <option
                                            value="multiple_choice_single"
                                            <?= (
                                                !$edit_question ||
                                                empty($edit_question['question_type']) ||
                                                $edit_question['question_type'] === 'multiple_choice_single'
                                            ) ? 'selected' : '' ?>
                                        >
                                            Single Choice Question
                                        </option>

                                        <option
                                            value="multiple_choice_multiple"
                                            <?= (
                                                $edit_question &&
                                                ($edit_question['question_type'] ?? '') === 'multiple_choice_multiple'
                                            ) ? 'selected' : '' ?>
                                        >
                                            Multiple Choice Question
                                        </option>

                                        <option
                                            value="true_false"
                                            <?= (
                                                $edit_question &&
                                                ($edit_question['question_type'] ?? '') === 'true_false'
                                            ) ? 'selected' : '' ?>
                                        >
                                            True / False
                                        </option>

                                        <option
                                            value="fill_blanks"
                                            <?= (
                                                $edit_question &&
                                                ($edit_question['question_type'] ?? '') === 'fill_blanks'
                                            ) ? 'selected' : '' ?>
                                        >
                                            Fill in the Blank
                                        </option>

                                    </select>

                                </div>


                                <!-- QUESTION -->

                                <div class="mb-4">

                                    <label
                                        class="form-label"
                                        for="question"
                                    >
                                        Question Text
                                    </label>

                                    <textarea
                                        class="form-control"
                                        name="question"
                                        id="question"
                                        rows="5"
                                        required
                                        placeholder="Enter your question here..."
                                    ><?= e(
                                        $edit_question['question_text'] ?? ''
                                    ) ?></textarea>

                                </div>


                                <!-- DYNAMIC OPTIONS -->

                                <div id="optionsContainer"></div>

                            </div>


                            <!-- ACTIONS -->

                            <div class="form-actions">

                                <button
                                    type="reset"
                                    class="btn btn-light border"
                                >
                                    <i class="fas fa-undo me-2"></i>
                                    Clear
                                </button>

                                <button
                                    type="submit"
                                    class="btn btn-primary px-4"
                                >

                                    <i class="fas fa-<?= $edit_question ? 'save' : 'plus' ?> me-2"></i>

                                    <?= $edit_question
                                        ? 'Update Question'
                                        : 'Add Question'
                                    ?>

                                </button>

                            </div>

                        </form>


                        <!-- =================================================
                             BANK BULK UPLOAD
                        ================================================== -->

                        <?php if ($isBankMode && !$edit_question): ?>

                            <div class="form-section mt-5 pt-4 border-top">

                                <div class="form-section-title">

                                    <i class="fas fa-file-word"></i>

                                    Bulk Upload

                                </div>

                                <div class="upload-panel">

                                    <div class="upload-icon">
                                        <i class="fas fa-file-upload"></i>
                                    </div>

                                    <div class="upload-title">
                                        Upload Questions to Question Bank
                                    </div>

                                    <div class="upload-description">

                                        Upload a
                                        <code>.docx</code>
                                        file using the same format as a test
                                        upload.

                                        Questions will be saved to the bank
                                        and will not be attached to a test.

                                        Use the class short code such as
                                        <code>Class: JSS1</code>.

                                    </div>

                                    <form
                                        method="POST"
                                        id="bankUploadForm"
                                        enctype="multipart/form-data"
                                        action="upload.php"
                                    >

                                        <input
                                            type="hidden"
                                            name="bank_mode"
                                            value="1"
                                        >

                                        <div class="mb-3">

                                            <label
                                                class="form-label"
                                                for="bank_test_file"
                                            >
                                                DOCX File
                                            </label>

                                            <input
                                                type="file"
                                                class="form-control"
                                                name="test_file"
                                                id="bank_test_file"
                                                accept=".docx"
                                                required
                                            >

                                        </div>

                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                        >

                                            <i class="fas fa-upload me-2"></i>

                                            Upload to Bank

                                        </button>

                                    </form>

                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- =====================================================
             RIGHT COLUMN
        ====================================================== -->

        <?php if (!$isBankMode): ?>

            <div class="col-lg-4">

                <div class="aq-card overview-card">

                    <div class="aq-card-header">

                        <div>

                            <h5 class="aq-card-title">
                                <i class="fas fa-clipboard-list me-2 text-primary"></i>
                                Test Overview
                            </h5>

                            <p class="aq-card-subtitle">
                                Current test information
                            </p>

                        </div>

                    </div>

                    <div class="aq-card-body">

                        <?php if ($current_test): ?>

                            <div class="test-summary mb-3">

                                <div class="test-summary-title">
                                    <?= e($current_test['title']) ?>
                                </div>

                                <div class="test-summary-meta">

                                    <div>
                                        <i class="fas fa-school me-2"></i>
                                        <?= e($current_test['level_code']) ?>
                                    </div>

                                    <div>
                                        <i class="fas fa-book me-2"></i>
                                        <?= e($current_test['subject']) ?>
                                    </div>

                                    <div>
                                        <i class="fas fa-calendar me-2"></i>
                                        <?= e($current_test['year']) ?>
                                    </div>

                                    <div>
                                        <i class="fas fa-clock me-2"></i>
                                        <?= (int)$current_test['duration'] ?>
                                        minutes
                                    </div>

                                </div>

                            </div>


                            <div class="stat-box">

                                <span class="stat-label">
                                    Total Questions
                                </span>

                                <span class="stat-value">
                                    <?= $total_questions ?>
                                </span>

                            </div>


                            <form
                                method="POST"
                                action="handle_test.php"
                                class="mt-3"
                            >

                                <button
                                    type="submit"
                                    name="clear_test"
                                    class="btn btn-outline-danger w-100"
                                >

                                    <i class="fas fa-times me-2"></i>

                                    Clear Test Selection

                                </button>

                            </form>

                        <?php else: ?>

                            <div class="text-center py-4">

                                <div class="upload-icon mx-auto">

                                    <i class="fas fa-file-alt"></i>

                                </div>

                                <h6 class="fw-bold">
                                    No Test Selected
                                </h6>

                                <p class="text-muted small mb-0">

                                    Create a new test or select an existing
                                    test to start adding questions.

                                </p>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     PREVIEW MODAL
========================================================= -->

<?php if (!$isBankMode): ?>

<div
    class="modal fade"
    id="previewModal"
    tabindex="-1"
    aria-labelledby="previewModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header">

                <div>

                    <h5
                        class="modal-title fw-bold"
                        id="previewModalLabel"
                    >
                        <i class="fas fa-eye me-2 text-primary"></i>
                        Test Preview
                    </h5>

                    <?php if ($current_test): ?>

                        <small class="text-muted">

                            <?= e($current_test['title']) ?>
                            ·
                            <?= e($current_test['level_code']) ?>
                            ·
                            <?= e($current_test['subject']) ?>

                        </small>

                    <?php endif; ?>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <?php if ($current_test && !empty($questions)): ?>

                    <div class="mb-4">

                        <div class="d-flex flex-wrap gap-3">

                            <span class="badge bg-light text-dark border">

                                <i class="fas fa-clock me-1"></i>

                                <?= (int)$current_test['duration'] ?>
                                minutes

                            </span>

                            <span class="badge bg-light text-dark border">

                                <i class="fas fa-question-circle me-1"></i>

                                <?= $total_questions ?>
                                questions

                            </span>

                        </div>

                    </div>


                    <?php foreach ($questions as $index => $question): ?>

                        <div class="preview-question">

                            <div class="preview-question-header">

                                <div class="preview-question-text">

                                    <span class="text-primary me-1">
                                        <?= $index + 1 ?>.
                                    </span>

                                    <?= e($question['question_text']) ?>

                                    <div class="mt-2">

                                        <span class="badge bg-primary-subtle text-primary">

                                            <?= e(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $question['question_type']
                                                    )
                                                )
                                            ) ?>

                                        </span>

                                    </div>

                                </div>


                                <div class="preview-actions">

                                    <form
                                        method="POST"
                                        action="handle_question.php"
                                    >

                                        <input
                                            type="hidden"
                                            name="question_id"
                                            value="<?= (int)$question['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="edit_question"
                                            value="1"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-primary"
                                        >

                                            <i class="fas fa-edit me-1"></i>

                                            Edit

                                        </button>

                                    </form>


                                    <form
                                        method="POST"
                                        action="handle_question.php"
                                        onsubmit="return confirm('Are you sure you want to delete this question?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="question_id"
                                            value="<?= (int)$question['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="question_type"
                                            value="<?= e($question['question_type']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="delete_question"
                                            value="1"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                        >

                                            <i class="fas fa-trash me-1"></i>

                                            Delete

                                        </button>

                                    </form>

                                </div>

                            </div>


                            <div class="mt-3">

                                <?php

                                switch ($question['question_type']) {

                                    case 'multiple_choice_single':

                                        $stmt = $conn->prepare("
                                            SELECT
                                                option1,
                                                option2,
                                                option3,
                                                option4,
                                                correct_answer,
                                                image_path
                                            FROM single_choice_questions
                                            WHERE question_id = ?
                                            LIMIT 1
                                        ");

                                        $stmt->bind_param(
                                            "i",
                                            $question['id']
                                        );

                                        $stmt->execute();

                                        $options =
                                            $stmt
                                                ->get_result()
                                                ->fetch_assoc();

                                        $stmt->close();

                                        if (!empty($options['image_path'])):

                                            $imagePath =
                                                "../" . ltrim(
                                                    $options['image_path'],
                                                    '/'
                                                );

                                            if (file_exists($imagePath)):

                                        ?>

                                                <img
                                                    src="<?= e($imagePath) ?>"
                                                    class="img-fluid rounded mb-3"
                                                    style="max-height:220px;"
                                                    alt="Question image"
                                                >

                                        <?php

                                            endif;

                                        endif;


                                        foreach (
                                            ['option1', 'option2', 'option3', 'option4']
                                            as $opt
                                        ):

                                            $isCorrect =
                                                isset($options['correct_answer']) &&
                                                $options['correct_answer'] ===
                                                $options[$opt];

                                        ?>

                                            <div
                                                class="preview-option <?= $isCorrect ? 'correct' : '' ?>"
                                            >

                                                <?php if ($isCorrect): ?>

                                                    <i class="fas fa-check-circle me-2"></i>

                                                <?php else: ?>

                                                    <i class="far fa-circle me-2"></i>

                                                <?php endif; ?>

                                                <?= e($options[$opt] ?? '') ?>

                                            </div>

                                        <?php endforeach;

                                        break;


                                    case 'multiple_choice_multiple':

                                        $stmt = $conn->prepare("
                                            SELECT
                                                option1,
                                                option2,
                                                option3,
                                                option4,
                                                correct_answers,
                                                image_path
                                            FROM multiple_choice_questions
                                            WHERE question_id = ?
                                            LIMIT 1
                                        ");

                                        $stmt->bind_param(
                                            "i",
                                            $question['id']
                                        );

                                        $stmt->execute();

                                        $options =
                                            $stmt
                                                ->get_result()
                                                ->fetch_assoc();

                                        $stmt->close();

                                        $correct = !empty($options['correct_answers'])
                                            ? explode(
                                                ',',
                                                $options['correct_answers']
                                            )
                                            : [];

                                        if (!empty($options['image_path'])):

                                            $imagePath =
                                                "../" . ltrim(
                                                    $options['image_path'],
                                                    '/'
                                                );

                                            if (file_exists($imagePath)):

                                        ?>

                                                <img
                                                    src="<?= e($imagePath) ?>"
                                                    class="img-fluid rounded mb-3"
                                                    style="max-height:220px;"
                                                    alt="Question image"
                                                >

                                        <?php

                                            endif;

                                        endif;


                                        foreach (
                                            ['option1', 'option2', 'option3', 'option4']
                                            as $opt
                                        ):

                                            $isCorrect =
                                                in_array(
                                                    $options[$opt] ?? '',
                                                    $correct,
                                                    true
                                                );

                                        ?>

                                            <div
                                                class="preview-option <?= $isCorrect ? 'correct' : '' ?>"
                                            >

                                                <?php if ($isCorrect): ?>

                                                    <i class="fas fa-check-circle me-2"></i>

                                                <?php else: ?>

                                                    <i class="far fa-circle me-2"></i>

                                                <?php endif; ?>

                                                <?= e($options[$opt] ?? '') ?>

                                            </div>

                                        <?php endforeach;

                                        break;


                                    case 'true_false':

                                        $stmt = $conn->prepare("
                                            SELECT correct_answer
                                            FROM true_false_questions
                                            WHERE question_id = ?
                                            LIMIT 1
                                        ");

                                        $stmt->bind_param(
                                            "i",
                                            $question['id']
                                        );

                                        $stmt->execute();

                                        $answer =
                                            $stmt
                                                ->get_result()
                                                ->fetch_assoc();

                                        $stmt->close();

                                        ?>

                                        <div class="preview-option correct">

                                            <i class="fas fa-check-circle me-2"></i>

                                            Correct Answer:
                                            <?= e(
                                                $answer['correct_answer'] ?? ''
                                            ) ?>

                                        </div>

                                        <?php

                                        break;


                                    case 'fill_blanks':

                                        $stmt = $conn->prepare("
                                            SELECT correct_answer
                                            FROM fill_blank_questions
                                            WHERE question_id = ?
                                            LIMIT 1
                                        ");

                                        $stmt->bind_param(
                                            "i",
                                            $question['id']
                                        );

                                        $stmt->execute();

                                        $answer =
                                            $stmt
                                                ->get_result()
                                                ->fetch_assoc();

                                        $stmt->close();

                                        ?>

                                        <div class="preview-option correct">

                                            <i class="fas fa-check-circle me-2"></i>

                                            Correct Answer:
                                            <?= e(
                                                $answer['correct_answer'] ?? ''
                                            ) ?>

                                        </div>

                                        <?php

                                        break;
                                }

                                ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="text-center py-5">

                        <div class="upload-icon mx-auto">

                            <i class="fas fa-question"></i>

                        </div>

                        <h6 class="fw-bold">
                            No Questions Yet
                        </h6>

                        <p class="text-muted small mb-0">
                            Add questions to this test before previewing it.
                        </p>

                    </div>

                <?php endif; ?>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

            </div>

        </div>

    </div>

</div>

<?php endif; ?>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>


<script>

const editData =
    <?= json_encode(
        $edit_data,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    ) ?>;


/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    if (value === null || value === undefined) {
        return '';
    }

    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


/*
|--------------------------------------------------------------------------
| QUESTION TEMPLATES
|--------------------------------------------------------------------------
*/

const questionTemplates = {

    multiple_choice_single: `

        <div class="form-section">

            <div class="d-flex align-items-center justify-content-between mb-3">

                <div class="form-section-title mb-0">

                    <i class="fas fa-list-ul"></i>

                    Answer Options

                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary image-toggle"
                    id="toggleImageBtn"
                >
                    <i class="fas fa-image me-1"></i>
                    Add Image
                </button>

            </div>


            <div
                class="image-panel"
                id="imageUploadContainer"
            >

                <label class="form-label">
                    Question Image
                </label>

                <input
                    type="file"
                    class="form-control"
                    name="question_image"
                    accept="image/*"
                >

                ${
                    editData.options?.image_path
                    ? `
                        <div class="mt-3">

                            <div class="small text-muted mb-2">
                                Current Image
                            </div>

                            <img
                                src="../${escapeHtml(editData.options.image_path)}"
                                class="current-image"
                                alt="Current question image"
                            >

                            <div class="form-check mt-2">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="remove_image"
                                    id="removeImage"
                                >

                                <label
                                    class="form-check-label small"
                                    for="removeImage"
                                >
                                    Remove current image
                                </label>

                            </div>

                        </div>
                    `
                    : ''
                }

                <div class="field-help">
                    Optional. Maximum size: 2MB.
                    JPG, PNG or GIF.
                </div>

            </div>


            <div class="options-grid">

                ${[1,2,3,4].map(i => `

                    <div class="option-card">

                        <div class="option-number">

                            <span>${i}</span>

                            Option ${i}

                        </div>

                        <input
                            type="text"
                            class="form-control"
                            name="option${i}"
                            required
                            placeholder="Enter option ${i}"
                            value="${escapeHtml(
                                editData.options?.['option' + i] || ''
                            )}"
                        >

                    </div>

                `).join('')}

            </div>


            <div class="mt-4">

                <label class="form-label">
                    Correct Answer
                </label>

                <select
                    class="form-select"
                    name="correct_answer"
                    required
                >

                    <option value="">
                        Select the correct option
                    </option>

                    ${[1,2,3,4].map(i => `

                        <option
                            value="${i}"
                            ${
                                editData.options?.correct_answer &&
                                editData.options[
                                    'option' +
                                    editData.options.correct_answer
                                ] ===
                                editData.options[
                                    'option' + i
                                ]
                                ? 'selected'
                                : ''
                            }
                        >
                            Option ${i}
                        </option>

                    `).join('')}

                </select>

            </div>

        </div>

    `,


    multiple_choice_multiple: `

        <div class="form-section">

            <div class="d-flex align-items-center justify-content-between mb-3">

                <div class="form-section-title mb-0">

                    <i class="fas fa-list-ul"></i>

                    Answer Options

                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary image-toggle"
                    id="toggleImageBtn"
                >
                    <i class="fas fa-image me-1"></i>
                    Add Image
                </button>

            </div>


            <div
                class="image-panel"
                id="imageUploadContainer"
            >

                <label class="form-label">
                    Question Image
                </label>

                <input
                    type="file"
                    class="form-control"
                    name="question_image"
                    accept="image/*"
                >

                ${
                    editData.options?.image_path
                    ? `
                        <div class="mt-3">

                            <div class="small text-muted mb-2">
                                Current Image
                            </div>

                            <img
                                src="../${escapeHtml(editData.options.image_path)}"
                                class="current-image"
                                alt="Current question image"
                            >

                            <div class="form-check mt-2">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="remove_image"
                                    id="removeImage"
                                >

                                <label
                                    class="form-check-label small"
                                    for="removeImage"
                                >
                                    Remove current image
                                </label>

                            </div>

                        </div>
                    `
                    : ''
                }

                <div class="field-help">
                    Optional. Maximum size: 2MB.
                    JPG, PNG or GIF.
                </div>

            </div>


            <div class="options-grid">

                ${[1,2,3,4].map(i => `

                    <div class="option-card">

                        <div class="option-number">

                            <span>${i}</span>

                            Option ${i}

                        </div>

                        <input
                            type="text"
                            class="form-control"
                            name="option${i}"
                            required
                            placeholder="Enter option ${i}"
                            value="${escapeHtml(
                                editData.options?.['option' + i] || ''
                            )}"
                        >

                    </div>

                `).join('')}

            </div>


            <div class="mt-4">

                <label class="form-label">
                    Correct Answers
                </label>

                <div class="correct-answer-box">

                    ${[1,2,3,4].map(i => `

                        <div class="correct-option">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="correct_answers[]"
                                value="${i}"
                                id="correct${i}"
                                ${
                                    Array.isArray(
                                        editData.correct_answers
                                    ) &&
                                    editData.correct_answers.includes(i)
                                    ? 'checked'
                                    : ''
                                }
                            >

                            <label for="correct${i}">
                                Option ${i}
                            </label>

                        </div>

                    `).join('')}

                </div>

                <div class="field-help">
                    Select every option that is correct.
                </div>

            </div>

        </div>

    `,


    true_false: `

        <div class="form-section">

            <div class="form-section-title">

                <i class="fas fa-check-double"></i>

                Correct Answer

            </div>

            <select
                class="form-select"
                name="correct_answer"
                required
            >

                <option value="">
                    Select Correct Answer
                </option>

                <option
                    value="True"
                    ${
                        editData.options?.correct_answer === 'True'
                        ? 'selected'
                        : ''
                    }
                >
                    True
                </option>

                <option
                    value="False"
                    ${
                        editData.options?.correct_answer === 'False'
                        ? 'selected'
                        : ''
                    }
                >
                    False
                </option>

            </select>

        </div>

    `,


    fill_blanks: `

        <div class="form-section">

            <div class="form-section-title">

                <i class="fas fa-keyboard"></i>

                Correct Answer

            </div>

            <input
                type="text"
                class="form-control"
                name="correct_answer"
                required
                placeholder="Enter the correct answer"
                value="${escapeHtml(
                    editData.options?.correct_answer || ''
                )}"
            >

            <div class="field-help">
                Enter the answer students are expected to provide.
            </div>

        </div>

    `
};


/*
|--------------------------------------------------------------------------
| RENDER OPTIONS
|--------------------------------------------------------------------------
*/

function updateOptionsContainer() {

    const typeSelect =
        document.getElementById('questionType');

    const container =
        document.getElementById('optionsContainer');

    if (!typeSelect || !container) {
        return;
    }

    const type =
        typeSelect.value ||
        'multiple_choice_single';

    container.innerHTML =
        questionTemplates[type] || '';

    initializeImageToggle();
}


/*
|--------------------------------------------------------------------------
| IMAGE TOGGLE
|--------------------------------------------------------------------------
*/

function initializeImageToggle() {

    const button =
        document.getElementById('toggleImageBtn');

    const container =
        document.getElementById('imageUploadContainer');

    if (!button || !container) {
        return;
    }

    button.addEventListener('click', function () {

        container.classList.toggle('is-visible');

        const visible =
            container.classList.contains('is-visible');

        button.innerHTML = visible
            ? '<i class="fas fa-times me-1"></i> Hide Image'
            : '<i class="fas fa-image me-1"></i> Add Image';

    });

}


/*
|--------------------------------------------------------------------------
| DOCUMENT READY
|--------------------------------------------------------------------------
*/

$(function () {

    /*
    |--------------------------------------------------------------------------
    | SIDEBAR
    |--------------------------------------------------------------------------
    */

    $('#sidebarToggle').on('click', function () {

        $('.sidebar').toggleClass('active');

    });


    /*
    |--------------------------------------------------------------------------
    | QUESTION TYPE
    |--------------------------------------------------------------------------
    */

    const questionType =
        document.getElementById('questionType');

    if (questionType) {

        if (
            !editData.question_type ||
            !String(editData.question_type).trim()
        ) {

            questionType.value =
                'multiple_choice_single';

        }

        updateOptionsContainer();

        $(questionType).on(
            'change',
            updateOptionsContainer
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RESET QUESTION FORM
    |--------------------------------------------------------------------------
    */

    $('#questionForm').on('reset', function () {

        setTimeout(function () {

            if (questionType) {

                questionType.value =
                    'multiple_choice_single';

                editData.options = {};
                editData.correct_answers = [];

                updateOptionsContainer();
            }

        }, 0);

    });


    /*
    |--------------------------------------------------------------------------
    | TEST FORM VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($('#testForm').length) {

        $('#testForm').validate({

            rules: {

                year: {
                    required: true
                },

                test_title: {
                    required: true
                },

                academic_level_id: {
                    required: true
                },

                subject: {
                    required: true
                },

                duration: {
                    required: true,
                    number: true,
                    min: 1
                }

            },

            messages: {

                duration: {
                    min: 'Duration must be at least 1 minute.'
                }

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | TEST UPLOAD VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($('#uploadForm').length) {

        $('#uploadForm').validate({

            rules: {

                year: {
                    required: true
                },

                test_file: {
                    required: true,
                    extension: 'docx'
                }

            },

            messages: {

                test_file: {
                    required: 'Please select a DOCX file.',
                    extension: 'Please upload a valid .docx file.'
                }

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | BANK UPLOAD
    |--------------------------------------------------------------------------
    */

    if ($('#bankUploadForm').length) {

        $('#bankUploadForm').validate({

            rules: {

                test_file: {
                    required: true,
                    extension: 'docx'
                }

            },

            messages: {

                test_file: {
                    required: 'Please select a DOCX file.',
                    extension: 'Please upload a valid .docx file.'
                }

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | SELECT TEST
    |--------------------------------------------------------------------------
    */

    if ($('#selectTestForm').length) {

        $('#selectTestForm').validate({

            rules: {

                test_id: {
                    required: true
                }

            },

            messages: {

                test_id:
                    'Please select a test.'

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | QUESTION VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($('#questionForm').length) {

        $('#questionForm').validate({

            rules: {

                question_type: {
                    required: true
                },

                question: {
                    required: true
                },

                option1: {

                    required: {

                        depends: function () {

                            return $('#questionType')
                                .val()
                                .startsWith(
                                    'multiple_choice'
                                );

                        }

                    }

                },

                option2: {

                    required: {

                        depends: function () {

                            return $('#questionType')
                                .val()
                                .startsWith(
                                    'multiple_choice'
                                );

                        }

                    }

                },

                option3: {

                    required: {

                        depends: function () {

                            return $('#questionType')
                                .val()
                                .startsWith(
                                    'multiple_choice'
                                );

                        }

                    }

                },

                option4: {

                    required: {

                        depends: function () {

                            return $('#questionType')
                                .val()
                                .startsWith(
                                    'multiple_choice'
                                );

                        }

                    }

                },

                correct_answer: {

                    required: {

                        depends: function () {

                            return $('#questionType')
                                .val() !==
                                'multiple_choice_multiple';

                        }

                    }

                }

            },

            messages: {

                question:
                    'Please enter the question.',

                option1:
                    'Please enter option 1.',

                option2:
                    'Please enter option 2.',

                option3:
                    'Please enter option 3.',

                option4:
                    'Please enter option 4.',

                correct_answer:
                    'Please select the correct answer.'

            },

            errorClass: 'text-danger small mt-1',

            errorPlacement: function (
                error,
                element
            ) {

                error.insertAfter(element);

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | PREVIEW
    |--------------------------------------------------------------------------
    */

    $('#previewButton').on(
        'click',
        function (event) {

            if ($(this).prop('disabled')) {

                event.preventDefault();

                return false;

            }

        }
    );

});

</script>

</body>

</html>

<?php

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

?>