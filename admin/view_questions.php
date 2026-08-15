<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

header('Content-Type: text/html; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'admin'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: ../login.php?error=Not logged in");
    exit();
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare admin query: " . $conn->error);
    }

    $stmt->bind_param("i", $admin_id);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin) {
        error_log("No admin found for user_id={$admin_id}");
        session_destroy();
        header("Location: ../login.php?error=Unauthorized");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | Activity logging
    |--------------------------------------------------------------------------
    */
    $ip_address = filter_var(
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        FILTER_VALIDATE_IP
    ) ?: '0.0.0.0';

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $activity = "Admin {$admin['username']} accessed view questions page.";

    $stmt = $conn->prepare("
        INSERT INTO activities_log
        (activity, admin_id, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");

    if ($stmt) {
        $stmt->bind_param(
            "siss",
            $activity,
            $admin_id,
            $ip_address,
            $user_agent
        );
        $stmt->execute();
        $stmt->close();
    }

} catch (Exception $e) {
    error_log("View questions page error: " . $e->getMessage());
    die("System error");
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/
$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Delete Question
|--------------------------------------------------------------------------
*/
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['delete_question'])
) {
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $question_type = trim($_POST['question_type'] ?? '');

    $table_map = [
        'multiple_choice_single'   => 'single_choice_questions',
        'multiple_choice_multiple' => 'multiple_choice_questions',
        'true_false'               => 'true_false_questions',
        'fill_blanks'               => 'fill_blank_questions',
    ];

    if (!$question_id || !$question_type || !isset($table_map[$question_type])) {

        $error = "Invalid question information.";

    } else {

        $table = $table_map[$question_type];

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Remove associated image
            |--------------------------------------------------------------------------
            */
            $stmt = $conn->prepare("
                SELECT image_path
                FROM {$table}
                WHERE question_id = ?
            ");

            $stmt->bind_param("i", $question_id);
            $stmt->execute();

            $question_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($question_data['image_path'])) {

                $file_path = '../' . $question_data['image_path'];

                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Delete question type record
            |--------------------------------------------------------------------------
            */
            $stmt = $conn->prepare("
                DELETE FROM {$table}
                WHERE question_id = ?
            ");

            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | Delete main question record
            |--------------------------------------------------------------------------
            */
            $stmt = $conn->prepare("
                DELETE FROM new_questions
                WHERE id = ?
            ");

            $stmt->bind_param("i", $question_id);
            $stmt->execute();

            if ($stmt->affected_rows < 1) {
                throw new Exception("Question could not be deleted.");
            }

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | Activity log
            |--------------------------------------------------------------------------
            */
            $activity = "Admin deleted question ID: {$question_id} ({$question_type})";

            $stmt = $conn->prepare("
                INSERT INTO activities_log
                (activity, admin_id, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");

            if ($stmt) {
                $stmt->bind_param(
                    "siss",
                    $activity,
                    $admin_id,
                    $ip_address,
                    $user_agent
                );
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            $success = "Question deleted successfully.";

        } catch (Exception $e) {

            $conn->rollback();

            error_log(
                "Question deletion failed: " .
                $e->getMessage()
            );

            $error = "Unable to delete the question. Please try again.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$class_filter   = trim($_GET['class'] ?? '');
$subject_filter = trim($_GET['subject'] ?? '');
$search_term    = trim($_GET['search'] ?? '');

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/
$questions_per_page = 10;

$current_page = isset($_GET['page']) && is_numeric($_GET['page'])
    ? (int) $_GET['page']
    : 1;

$current_page = max(1, $current_page);

$offset = ($current_page - 1) * $questions_per_page;

/*
|--------------------------------------------------------------------------
| Base Queries
|--------------------------------------------------------------------------
*/
$count_query = "
    SELECT COUNT(*) AS total
    FROM new_questions q
    JOIN tests t
        ON q.test_id = t.id
    JOIN classes c
        ON t.academic_level_id = c.academic_level_id
    WHERE 1=1
";

$select_query = "
    SELECT
        q.*,
        t.title AS test_title,
        c.class_name AS class,
        t.subject
    FROM new_questions q
    JOIN tests t
        ON q.test_id = t.id
    JOIN classes c
        ON t.academic_level_id = c.academic_level_id
    WHERE 1=1
";

$params = [];
$types = '';

/*
|--------------------------------------------------------------------------
| Class Filter
|--------------------------------------------------------------------------
*/
if ($class_filter !== '') {

    $count_query .= " AND q.class = ?";
    $select_query .= " AND q.class = ?";

    $params[] = $class_filter;
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Subject Filter
|--------------------------------------------------------------------------
*/
if ($subject_filter !== '') {

    $count_query .= " AND LOWER(t.subject) = ?";
    $select_query .= " AND LOWER(t.subject) = ?";

    $params[] = strtolower($subject_filter);
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/
if ($search_term !== '') {

    $count_query .= "
        AND (
            q.question_text LIKE ?
            OR t.title LIKE ?
        )
    ";

    $select_query .= "
        AND (
            q.question_text LIKE ?
            OR t.title LIKE ?
        )
    ";

    $search_value = "%{$search_term}%";

    $params[] = $search_value;
    $params[] = $search_value;

    $types .= 'ss';
}

/*
|--------------------------------------------------------------------------
| Count Questions
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare($count_query);

if (!$stmt) {
    die("Database query error.");
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$total_questions = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();

$total_pages = max(
    1,
    (int) ceil($total_questions / $questions_per_page)
);

if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $questions_per_page;
}

/*
|--------------------------------------------------------------------------
| Fetch Questions
|--------------------------------------------------------------------------
*/
$select_query .= "
    ORDER BY q.class, t.subject, q.id DESC
    LIMIT ? OFFSET ?
";

$select_params = $params;
$select_types = $types . 'ii';

$select_params[] = $questions_per_page;
$select_params[] = $offset;

$stmt = $conn->prepare($select_query);

if (!$stmt) {
    die("Database query error.");
}

$stmt->bind_param($select_types, ...$select_params);
$stmt->execute();

$questions = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/*
|--------------------------------------------------------------------------
| Classes
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        c.id,
        c.class_name
    FROM classes c
    JOIN academic_levels al
        ON c.academic_level_id = al.id
    ORDER BY
        al.level_code,
        c.class_name
");

$stmt->execute();

$classes = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/*
|--------------------------------------------------------------------------
| Subjects
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        s.subject_name,
        sl.class_level
    FROM subjects s
    JOIN subject_levels sl
        ON s.id = sl.subject_id
    ORDER BY
        sl.class_level,
        s.subject_name
");

$stmt->execute();

$subjects = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/
function questionTypeLabel(string $type): string
{
    return ucwords(
        str_replace('_', ' ', $type)
    );
}

function buildPageUrl(
    int $page,
    string $class,
    string $subject,
    string $search
): string {

    $params = [
        'page' => $page
    ];

    if ($class !== '') {
        $params['class'] = $class;
    }

    if ($subject !== '') {
        $params['subject'] = $subject;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    return '?' . http_build_query($params);
}

$start_question = $total_questions > 0
    ? $offset + 1
    : 0;

$end_question = min(
    $offset + $questions_per_page,
    $total_questions
);

$has_filters = (
    $class_filter !== '' ||
    $subject_filter !== '' ||
    $search_term !== ''
);

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Question Bank | Examcenter</title>

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
    href="../css/dashboard.css"
>

<link
    rel="stylesheet"
    href="../css/view_questions.css"
>

<link
    rel="stylesheet"
    href="../css/sidebar.css"
>

<style>

    :root {
        --primary: #4361ee;
        --primary-dark: #3451d1;
        --text-dark: #172033;
        --text-muted: #6b7280;
        --border: #e8ebf2;
        --surface: #ffffff;
        --background: #f5f7fb;
        --success: #198754;
        --danger: #dc3545;
    }

    body {
        background: var(--background);
    }

    .main-content {
        min-height: 100vh;
        padding-bottom: 40px;
    }

    /* =========================================================
       HEADER
    ========================================================= */

    .page-header {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 22px 24px;
        margin-bottom: 22px;
        box-shadow: 0 4px 18px rgba(25, 35, 60, .04);
    }

    .page-title {
        margin: 0;
        color: var(--text-dark);
        font-size: 1.55rem;
        font-weight: 700;
    }

    .page-subtitle {
        margin: 5px 0 0;
        color: var(--text-muted);
        font-size: .9rem;
    }

    .header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .btn-modern {
        border-radius: 10px;
        padding: 9px 15px;
        font-weight: 600;
    }

    .btn-primary {
        background: var(--primary);
        border-color: var(--primary);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
    }

    /* =========================================================
       STAT
    ========================================================= */

    .question-summary {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 18px;
    }

    .summary-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(67, 97, 238, .1);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }

    .summary-number {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .summary-label {
        color: var(--text-muted);
        font-size: .82rem;
    }

    /* =========================================================
       FILTER
    ========================================================= */

    .filter-card {
        border: 1px solid var(--border) !important;
        border-radius: 16px !important;
        overflow: hidden;
    }

    .filter-card .card-header {
        padding: 18px 20px 5px;
    }

    .filter-card .card-body {
        padding: 18px 20px 20px;
    }

    .filter-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .form-label {
        color: #344054;
        font-size: .84rem;
        margin-bottom: 7px;
    }

    .form-control,
    .form-select {
        min-height: 44px;
        border: 1px solid #dfe3eb;
        border-radius: 10px;
        font-size: .9rem;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 .2rem rgba(67, 97, 238, .1);
    }

    .filter-actions {
        display: flex;
        gap: 8px;
        align-items: end;
    }

    /* =========================================================
       ALERTS
    ========================================================= */

    .alert {
        border: 0;
        border-radius: 12px;
        box-shadow: 0 3px 12px rgba(20, 30, 50, .04);
    }

    /* =========================================================
       QUESTION CARD / TABLE
    ========================================================= */

    .questions-card {
        border: 1px solid var(--border) !important;
        border-radius: 16px !important;
        overflow: hidden;
    }

    .questions-header {
        padding: 18px 20px;
        border-bottom: 1px solid var(--border);
        background: #fff;
    }

    .questions-header h5 {
        margin: 0;
        color: var(--text-dark);
        font-size: 1rem;
        font-weight: 700;
    }

    .questions-header small {
        color: var(--text-muted);
    }

    .table-wrapper {
        overflow-x: auto;
    }

    #questionsTable {
        margin: 0 !important;
        min-width: 1050px;
    }

    #questionsTable thead th {
        background: #f8f9fc;
        color: #667085;
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 700;
        border-bottom: 1px solid var(--border);
        padding: 13px 14px;
        white-space: nowrap;
    }

    #questionsTable tbody td {
        padding: 15px 14px;
        vertical-align: top;
        border-color: #edf0f5;
        color: #344054;
        font-size: .88rem;
    }

    #questionsTable tbody tr:hover {
        background: #fafbff;
    }

    /* =========================================================
       BADGES
    ========================================================= */

    .badge-class,
    .badge-subject {
        display: inline-flex;
        align-items: center;
        border-radius: 7px;
        padding: 6px 9px;
        font-size: .74rem;
        font-weight: 600;
    }

    .badge-class {
        background: #eef2ff;
        color: #3f51b5;
    }

    .badge-subject {
        background: #ecfdf3;
        color: #137a46;
    }

    .type-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 9px;
        border-radius: 7px;
        background: #f1f5f9;
        color: #475569;
        font-size: .73rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* =========================================================
       QUESTION
    ========================================================= */

    .question-content {
        max-width: 330px;
        line-height: 1.55;
        color: #1f2937;
    }

    .test-title {
        font-weight: 600;
        color: #344054;
    }

    .options-container {
        min-width: 190px;
    }

    .option-item {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 7px;
        padding: 7px 9px;
        margin-bottom: 5px;
        border-radius: 7px;
        background: #f8fafc;
        color: #475569;
        line-height: 1.4;
    }

    .option-letter {
        flex: 0 0 auto;
        font-size: .72rem;
        font-weight: 700;
        color: #64748b;
    }

    .option-item.correct-option {
        background: #ecfdf3;
        color: #146c43;
        border: 1px solid #ccebd9;
    }

    .correct-icon {
        margin-left: auto;
        color: var(--success);
        font-size: .72rem;
    }

    .question-image {
        max-width: 150px;
        max-height: 90px;
        object-fit: contain;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: #fff;
        margin-bottom: 8px;
    }

    /* =========================================================
       ACTIONS
    ========================================================= */

    .action-group {
        display: flex;
        gap: 6px;
        flex-wrap: nowrap;
    }

    .action-btn {
        width: 36px;
        height: 36px;
        padding: 0;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* =========================================================
       PAGINATION
    ========================================================= */

    .pagination-area {
        padding: 16px 20px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }

    .pagination-info {
        color: var(--text-muted);
        font-size: .82rem;
    }

    .page-link {
        border-radius: 8px !important;
        margin: 0 2px;
        border: 1px solid var(--border);
        color: #475569;
    }

    .page-item.active .page-link {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }

    /* =========================================================
       EMPTY STATE
    ========================================================= */

    .empty-state {
        padding: 65px 20px;
        text-align: center;
        color: var(--text-muted);
    }

    .empty-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 18px;
        border-radius: 18px;
        background: #eef2ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
    }

    .empty-state h4 {
        color: var(--text-dark);
        font-weight: 700;
    }

    /* =========================================================
       MOBILE
    ========================================================= */

    @media (max-width: 991px) {

        .main-content {
            padding-left: 15px;
            padding-right: 15px;
        }

        .page-header {
            padding: 18px;
        }

        .page-title {
            font-size: 1.3rem;
        }

        .header-actions .btn-modern {
            display: none;
        }

        .header-actions #sidebarToggle {
            display: inline-flex !important;
        }

    }

    @media (max-width: 576px) {

        .page-header {
            border-radius: 13px;
        }

        .page-subtitle {
            font-size: .8rem;
        }

        .filter-card .card-body,
        .questions-header,
        .pagination-area {
            padding-left: 14px;
            padding-right: 14px;
        }

        .pagination-area {
            justify-content: center;
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
            <b>
                <?= htmlspecialchars($admin['username']) ?>
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

    <a href="view_questions.php" class="active">
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

    <a href="manage_teachers.php">
        <i class="fas fa-chalkboard-teacher"></i>
        Manage Teachers
    </a>

    <a href="manage_test.php">
        <i class="fas fa-file-alt"></i>
        Manage Tests
    </a>

    <a href="exam_schedule.php">
        <i class="fas fa-calendar-check"></i>
        Timestable
    </a>

    <a href="settings.php">
        <i class="fas fa-cog"></i>
        Settings
    </a>

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

    <a href="logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        Logout
    </a>

</div>

</div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">

<!-- PAGE HEADER -->

<div class="page-header">

    <div class="d-flex justify-content-between align-items-center gap-3">

        <div>

            <h1 class="page-title">
                Question Bank
            </h1>

            <p class="page-subtitle">
                View, search and manage questions available in your examination system.
            </p>

        </div>

        <div class="header-actions">

            <a
                href="add_question.php"
                class="btn btn-primary btn-modern"
            >
                <i class="fas fa-plus me-2"></i>
                Add Question
            </a>

            <button
                type="button"
                class="btn btn-primary d-lg-none"
                id="sidebarToggle"
                aria-label="Toggle navigation"
            >
                <i class="fas fa-bars"></i>
            </button>

        </div>

    </div>

</div>

<!-- SUMMARY -->

<div class="question-summary">

    <div class="summary-icon">
        <i class="fas fa-layer-group"></i>
    </div>

    <div>

        <div class="summary-number">
            <?= number_format($total_questions) ?>
        </div>

        <div class="summary-label">
            <?= $has_filters ? 'Questions matching your filters' : 'Total questions in the system' ?>
        </div>

    </div>

</div>

<!-- ALERTS -->

<?php if ($error): ?>

    <div
        class="alert alert-danger alert-dismissible fade show"
        role="alert"
    >
        <i class="fas fa-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>

<?php if ($success): ?>

    <div
        class="alert alert-success alert-dismissible fade show"
        role="alert"
    >
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($success) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>

<!-- =====================================================
     FILTER CARD
====================================================== -->

<div class="card bg-white shadow-sm filter-card mb-4">

    <div class="card-header bg-white border-0">

        <div class="d-flex align-items-center gap-2">

            <div class="summary-icon" style="width:36px;height:36px;border-radius:9px;">
                <i class="fas fa-filter"></i>
            </div>

            <div>

                <div class="filter-title">
                    Filter Questions
                </div>

                <small class="text-muted">
                    Narrow the question list by class, subject or keyword.
                </small>

            </div>

        </div>

    </div>

    <div class="card-body">

        <form
            method="GET"
            action="view_questions.php"
            id="filterForm"
        >

            <div class="row g-3">

                <!-- CLASS -->

                <div class="col-lg-3 col-md-6">

                    <label
                        class="form-label fw-semibold"
                        for="classFilter"
                    >
                        Class
                    </label>

                    <select
                        class="form-select"
                        name="class"
                        id="classFilter"
                    >

                        <option value="">
                            All Classes
                        </option>

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?= htmlspecialchars($class['class_name']) ?>"
                                <?= $class_filter === $class['class_name'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($class['class_name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- SUBJECT -->

                <div class="col-lg-3 col-md-6">

                    <label
                        class="form-label fw-semibold"
                        for="subjectFilter"
                    >
                        Subject
                    </label>

                    <select
                        class="form-select"
                        name="subject"
                        id="subjectFilter"
                    >

                        <option value="">
                            All Subjects
                        </option>

                        <?php foreach ($subjects as $subject): ?>

                            <option
                                value="<?= htmlspecialchars($subject['subject_name']) ?>"
                                <?= strtolower($subject_filter) === strtolower($subject['subject_name']) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($subject['subject_name']) ?>
                                (<?= htmlspecialchars($subject['class_level']) ?>)
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- SEARCH -->

                <div class="col-lg-4 col-md-8">

                    <label
                        class="form-label fw-semibold"
                        for="searchFilter"
                    >
                        Search
                    </label>

                    <div class="input-group">

                        <span class="input-group-text bg-white">
                            <i class="fas fa-search text-muted"></i>
                        </span>

                        <input
                            type="text"
                            class="form-control"
                            name="search"
                            id="searchFilter"
                            placeholder="Search question or test title..."
                            maxlength="100"
                            value="<?= htmlspecialchars($search_term) ?>"
                        >

                    </div>

                </div>

                <!-- ACTION -->

                <div class="col-lg-2 col-md-4">

                    <label class="form-label d-none d-md-block">
                        &nbsp;
                    </label>

                    <div class="filter-actions">

                        <button
                            type="submit"
                            class="btn btn-primary btn-modern flex-grow-1"
                        >
                            <i class="fas fa-filter me-1"></i>
                            Apply
                        </button>

                        <?php if ($has_filters): ?>

                            <a
                                href="view_questions.php"
                                class="btn btn-outline-secondary btn-modern"
                                title="Clear filters"
                            >
                                <i class="fas fa-times"></i>
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     QUESTIONS
====================================================== -->

<div class="card bg-white shadow-sm questions-card">

    <div class="questions-header">

        <div class="d-flex justify-content-between align-items-center gap-3">

            <div>

                <h5>
                    <i class="fas fa-list-ul me-2 text-primary"></i>
                    Questions
                </h5>

                <small>
                    <?php if ($total_questions > 0): ?>

                        Showing
                        <?= number_format($start_question) ?>
                        –
                        <?= number_format($end_question) ?>
                        of
                        <?= number_format($total_questions) ?>

                    <?php else: ?>

                        No questions found

                    <?php endif; ?>
                </small>

            </div>

            <?php if ($has_filters): ?>

                <span class="badge bg-light text-dark border">
                    <i class="fas fa-filter me-1"></i>
                    Filtered
                </span>

            <?php endif; ?>

        </div>

    </div>

    <?php if (!empty($questions)): ?>

        <div class="table-wrapper">

            <table
                id="questionsTable"
                class="table table-hover align-middle"
            >

                <thead>

                    <tr>

                        <th>Class</th>

                        <th>Subject</th>

                        <th>Test</th>

                        <th>Question</th>

                        <th>Type</th>

                        <th>Options / Answer</th>

                        <th>Actions</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($questions as $question): ?>

                    <?php

                    $options = [];

                    $question_type = $question['question_type'];

                    switch ($question_type) {

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
                            ");

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
                            ");

                            break;

                        case 'true_false':

                            $stmt = $conn->prepare("
                                SELECT
                                    correct_answer,
                                    image_path
                                FROM true_false_questions
                                WHERE question_id = ?
                            ");

                            break;

                        case 'fill_blanks':

                            $stmt = $conn->prepare("
                                SELECT
                                    correct_answer,
                                    image_path
                                FROM fill_blank_questions
                                WHERE question_id = ?
                            ");

                            break;

                        default:

                            $stmt = null;
                    }

                    if ($stmt) {

                        $stmt->bind_param(
                            "i",
                            $question['id']
                        );

                        $stmt->execute();

                        $options = $stmt
                            ->get_result()
                            ->fetch_assoc() ?: [];

                        $stmt->close();
                    }

                    ?>

                    <tr>

                        <!-- CLASS -->

                        <td>

                            <span class="badge-class">

                                <i class="fas fa-users me-1"></i>

                                <?= htmlspecialchars($question['class']) ?>

                            </span>

                        </td>

                        <!-- SUBJECT -->

                        <td>

                            <span class="badge-subject">

                                <?= htmlspecialchars($question['subject']) ?>

                            </span>

                        </td>

                        <!-- TEST -->

                        <td>

                            <div class="test-title">

                                <?= htmlspecialchars($question['test_title']) ?>

                            </div>

                        </td>

                        <!-- QUESTION -->

                        <td>

                            <div class="question-content">

                                <?= nl2br(
                                    htmlspecialchars(
                                        $question['question_text']
                                    )
                                ) ?>

                            </div>

                        </td>

                        <!-- TYPE -->

                        <td>

                            <span class="type-badge">

                                <i class="fas fa-tag"></i>

                                <?= htmlspecialchars(
                                    questionTypeLabel($question_type)
                                ) ?>

                            </span>

                        </td>

                        <!-- OPTIONS -->

                        <td class="options-container">

                            <?php if (!empty($options['image_path'])): ?>

                                <img
                                    src="../<?= htmlspecialchars($options['image_path']) ?>"
                                    alt="Question image"
                                    class="question-image"
                                >

                            <?php endif; ?>

                            <?php

                            if (
                                $question_type === 'multiple_choice_single'
                                && $options
                            ):

                                for ($i = 1; $i <= 4; $i++):

                                    $option_value =
                                        $options['option' . $i] ?? '';

                                    if ($option_value === '') {
                                        continue;
                                    }

                                    $is_correct =
                                        ((string)$options['correct_answer'] === (string)$i);

                            ?>

                                    <div
                                        class="option-item <?= $is_correct ? 'correct-option' : '' ?>"
                                    >

                                        <span class="option-letter">
                                            <?= chr(64 + $i) ?>.
                                        </span>

                                        <span>
                                            <?= htmlspecialchars($option_value) ?>
                                        </span>

                                        <?php if ($is_correct): ?>

                                            <i class="fas fa-check-circle correct-icon"></i>

                                        <?php endif; ?>

                                    </div>

                            <?php

                                endfor;

                            elseif (
                                $question_type === 'multiple_choice_multiple'
                                && $options
                            ):

                                $correct_answers = array_map(
                                    'trim',
                                    explode(
                                        ',',
                                        $options['correct_answers'] ?? ''
                                    )
                                );

                                for ($i = 1; $i <= 4; $i++):

                                    $option_value =
                                        $options['option' . $i] ?? '';

                                    if ($option_value === '') {
                                        continue;
                                    }

                                    $is_correct =
                                        in_array(
                                            (string)$i,
                                            $correct_answers,
                                            true
                                        );

                            ?>

                                    <div
                                        class="option-item <?= $is_correct ? 'correct-option' : '' ?>"
                                    >

                                        <span class="option-letter">
                                            <?= chr(64 + $i) ?>.
                                        </span>

                                        <span>
                                            <?= htmlspecialchars($option_value) ?>
                                        </span>

                                        <?php if ($is_correct): ?>

                                            <i class="fas fa-check-circle correct-icon"></i>

                                        <?php endif; ?>

                                    </div>

                            <?php

                                endfor;

                            elseif (
                                $question_type === 'true_false'
                                && $options
                            ):

                            ?>

                                <div class="option-item correct-option">

                                    <span class="option-letter">
                                        <i class="fas fa-check"></i>
                                    </span>

                                    <span>
                                        <?= htmlspecialchars(
                                            $options['correct_answer']
                                        ) ?>
                                    </span>

                                </div>

                            <?php

                            elseif (
                                $question_type === 'fill_blanks'
                                && $options
                            ):

                            ?>

                                <div class="option-item correct-option">

                                    <span class="option-letter">
                                        <i class="fas fa-check"></i>
                                    </span>

                                    <span>
                                        <?= htmlspecialchars(
                                            $options['correct_answer']
                                        ) ?>
                                    </span>

                                </div>

                            <?php else: ?>

                                <span class="text-muted">
                                    No answer data
                                </span>

                            <?php endif; ?>

                        </td>

                        <!-- ACTIONS -->

                        <td>

                            <div class="action-group">

                                <a
                                    href="add_question.php?edit=<?= (int)$question['id'] ?>"
                                    class="btn btn-outline-primary action-btn"
                                    title="Edit question"
                                >
                                    <i class="fas fa-edit"></i>
                                </a>

                                <form
                                    method="POST"
                                    class="delete-form"
                                >

                                    <input
                                        type="hidden"
                                        name="question_id"
                                        value="<?= (int)$question['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="question_type"
                                        value="<?= htmlspecialchars($question_type) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="delete_question"
                                        value="1"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-outline-danger action-btn"
                                        title="Delete question"
                                    >
                                        <i class="fas fa-trash"></i>
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->

        <?php if ($total_pages > 1): ?>

            <div class="pagination-area">

                <div class="pagination-info">

                    Page
                    <strong><?= $current_page ?></strong>
                    of
                    <strong><?= $total_pages ?></strong>

                </div>

                <nav aria-label="Question pagination">

                    <ul class="pagination mb-0">

                        <li
                            class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>"
                        >

                            <a
                                class="page-link"
                                href="<?= $current_page > 1
                                    ? htmlspecialchars(
                                        buildPageUrl(
                                            $current_page - 1,
                                            $class_filter,
                                            $subject_filter,
                                            $search_term
                                        )
                                    )
                                    : '#'
                                ?>"
                            >
                                <i class="fas fa-chevron-left"></i>
                            </a>

                        </li>

                        <?php

                        $start_page = max(
                            1,
                            $current_page - 2
                        );

                        $end_page = min(
                            $total_pages,
                            $current_page + 2
                        );

                        for (
                            $page = $start_page;
                            $page <= $end_page;
                            $page++
                        ):

                        ?>

                            <li
                                class="page-item <?= $page === $current_page ? 'active' : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= htmlspecialchars(
                                        buildPageUrl(
                                            $page,
                                            $class_filter,
                                            $subject_filter,
                                            $search_term
                                        )
                                    ) ?>"
                                >
                                    <?= $page ?>
                                </a>

                            </li>

                        <?php endfor; ?>

                        <li
                            class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>"
                        >

                            <a
                                class="page-link"
                                href="<?= $current_page < $total_pages
                                    ? htmlspecialchars(
                                        buildPageUrl(
                                            $current_page + 1,
                                            $class_filter,
                                            $subject_filter,
                                            $search_term
                                        )
                                    )
                                    : '#'
                                ?>"
                            >
                                <i class="fas fa-chevron-right"></i>
                            </a>

                        </li>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    <?php else: ?>

        <!-- EMPTY STATE -->

        <div class="empty-state">

            <div class="empty-icon">

                <i class="fas fa-question-circle"></i>

            </div>

            <h4>
                No Questions Found
            </h4>

            <p class="mb-4">

                <?php if ($has_filters): ?>

                    No questions match the filters you selected.
                    Try changing your filters or clear them.

                <?php else: ?>

                    Your question bank is currently empty.
                    Add your first question to get started.

                <?php endif; ?>

            </p>

            <div class="d-flex justify-content-center gap-2">

                <?php if ($has_filters): ?>

                    <a
                        href="view_questions.php"
                        class="btn btn-outline-secondary btn-modern"
                    >
                        <i class="fas fa-times me-2"></i>
                        Clear Filters
                    </a>

                <?php endif; ?>

                <a
                    href="add_question.php"
                    class="btn btn-primary btn-modern"
                >
                    <i class="fas fa-plus me-2"></i>
                    Add Question
                </a>

            </div>

        </div>

    <?php endif; ?>

</div>

</div>

<!-- =========================================================
     SCRIPTS
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Sidebar Toggle
    |--------------------------------------------------------------------------
    */
    const sidebarToggle =
        document.getElementById('sidebarToggle');

    const sidebar =
        document.querySelector('.sidebar');

    if (sidebarToggle && sidebar) {

        sidebarToggle.addEventListener('click', function () {

            sidebar.classList.toggle('active');

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Delete Confirmation
    |--------------------------------------------------------------------------
    */
    document.querySelectorAll('.delete-form').forEach(function (form) {

        form.addEventListener('submit', function (event) {

            const confirmed = window.confirm(
                'Are you sure you want to delete this question?\n\n' +
                'This action cannot be undone.'
            );

            if (!confirmed) {
                event.preventDefault();
            }

        });

    });

    /*
    |--------------------------------------------------------------------------
    | Auto-hide alerts
    |--------------------------------------------------------------------------
    */
    setTimeout(function () {

        document.querySelectorAll(
            '.alert.alert-dismissible'
        ).forEach(function (alert) {

            const instance =
                bootstrap.Alert.getOrCreateInstance(alert);

            instance.close();

        });

    }, 5000);

    /*
    |--------------------------------------------------------------------------
    | Search Enter Shortcut
    |--------------------------------------------------------------------------
    */
    const searchInput =
        document.getElementById('searchFilter');

    if (searchInput) {

        searchInput.addEventListener(
            'keydown',
            function (event) {

                if (event.key === 'Enter') {

                    event.preventDefault();

                    document
                        .getElementById('filterForm')
                        .submit();

                }

            }
        );

    }

});

</script>

</body>
</html>
