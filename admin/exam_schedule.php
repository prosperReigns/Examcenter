<?php
// =========================================================
// exam_schedule.php
// =========================================================

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

// ---------------------------------------------------------
// Error Reporting
// ---------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// ---------------------------------------------------------
// Authentication
// ---------------------------------------------------------
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'admin'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");

    header("Location: ../login.php?error=Not logged in");
    exit();
}

// ---------------------------------------------------------
// Database + Admin Profile
// ---------------------------------------------------------
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed");
    }

    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT username, role FROM admins WHERE id = ?"
    );

    if (!$stmt) {
        throw new Exception(
            "Failed to prepare admin profile query: " . $conn->error
        );
    }

    $stmt->bind_param("i", $admin_id);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin || strtolower($admin['role']) !== 'admin') {
        error_log(
            "Unauthorized access attempt by user_id={$admin_id}, role=" .
            ($admin['role'] ?? 'none')
        );

        session_destroy();

        header("Location: ../login.php?error=Unauthorized");
        exit();
    }

    // -----------------------------------------------------
    // Activity Log - Page Access
    // -----------------------------------------------------
    $ip_address = filter_var(
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        FILTER_VALIDATE_IP
    ) ?: '0.0.0.0';

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $activity =
        "Admin {$admin['username']} accessed exam scheduling page.";

    $stmt = $conn->prepare(
        "INSERT INTO activities_log
        (activity, admin_id, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, NOW())"
    );

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

    error_log(
        "Exam scheduling page error: " .
        $e->getMessage()
    );

    die("System error");
}

// ---------------------------------------------------------
// Variables
// ---------------------------------------------------------
$error = '';
$success = '';

$exam_date = date('Y-m-d');

$selected_subjects = [];

$all_subjects = [];
$active_subjects = [];

// ---------------------------------------------------------
// Fetch Available Subjects
// ---------------------------------------------------------
try {

    $stmt = $conn->prepare(
        "SELECT DISTINCT LCASE(subject_name) AS subject_name
         FROM subjects
         ORDER BY subject_name ASC"
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $all_subjects[] = $row['subject_name'];
    }

    $stmt->close();

} catch (Exception $e) {

    error_log(
        "Error fetching subjects: " .
        $e->getMessage()
    );

    $error = "Failed to load subjects.";
}

// ---------------------------------------------------------
// Handle Exam Schedule Submission
// ---------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_daily_subjects'])
) {

    $exam_date = trim(
        $_POST['exam_date'] ?? ''
    );

    $selected_subjects = $_POST['subjects'] ?? [];

    if (!is_array($selected_subjects)) {
        $selected_subjects = [];
    }

    $selected_subjects = array_unique(
        array_map(
            'strtolower',
            $selected_subjects
        )
    );

    // -----------------------------------------------------
    // Validate Date
    // -----------------------------------------------------
    if (
        empty($exam_date) ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $exam_date
        )
    ) {

        $error = "Please provide a valid exam date.";

    } elseif (empty($selected_subjects)) {

        $error = "Select at least one subject.";

    } else {

        try {

            // -------------------------------------------------
            // Only allow subjects that actually exist
            // -------------------------------------------------
            $valid_subjects = array_values(
                array_intersect(
                    $selected_subjects,
                    $all_subjects
                )
            );

            if (empty($valid_subjects)) {
                throw new Exception(
                    "No valid subjects were selected."
                );
            }

            $conn->begin_transaction();

            // -------------------------------------------------
            // Remove Previous Schedule For This Date
            // -------------------------------------------------
            $stmt = $conn->prepare(
                "DELETE FROM active_exams
                 WHERE exam_date = ?"
            );

            if (!$stmt) {
                throw new Exception(
                    "Failed to prepare schedule cleanup query."
                );
            }

            $stmt->bind_param(
                "s",
                $exam_date
            );

            $stmt->execute();
            $stmt->close();

            // -------------------------------------------------
            // Insert New Schedule
            // -------------------------------------------------
            $stmt = $conn->prepare(
                "INSERT INTO active_exams
                (subject, is_active, exam_date)
                VALUES (?, 1, ?)"
            );

            if (!$stmt) {
                throw new Exception(
                    "Failed to prepare schedule insert query."
                );
            }

            foreach ($valid_subjects as $subject) {

                $stmt->bind_param(
                    "ss",
                    $subject,
                    $exam_date
                );

                $stmt->execute();
            }

            $stmt->close();

            $conn->commit();

            // -------------------------------------------------
            // Activity Log
            // -------------------------------------------------
            $activity =
                "Admin {$admin['username']} updated exam schedule for " .
                "{$exam_date}: " .
                implode(', ', $valid_subjects);

            $stmt = $conn->prepare(
                "INSERT INTO activities_log
                (activity, admin_id, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, NOW())"
            );

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

            $success =
                "Exam schedule saved successfully.";

            $selected_subjects = [];

        } catch (Exception $e) {

            if ($conn->in_transaction) {
                $conn->rollback();
            }

            error_log(
                "Error updating exam schedule: " .
                $e->getMessage()
            );

            $error =
                "Failed to update exam schedule.";
        }
    }
}

// ---------------------------------------------------------
// Fetch Current Schedule
// ---------------------------------------------------------
try {

    $stmt = $conn->prepare(
        "SELECT subject, exam_date
         FROM active_exams
         WHERE is_active = 1
         ORDER BY exam_date DESC, subject ASC"
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $active_subjects[$row['exam_date']][] =
            $row['subject'];
    }

    $stmt->close();

} catch (Exception $e) {

    error_log(
        "Error fetching active exam schedule: " .
        $e->getMessage()
    );

    $error =
        "Failed to load current exam schedule.";
}

$conn->close();


// ---------------------------------------------------------
// Helper Values
// ---------------------------------------------------------
$total_subjects = count($all_subjects);
$total_schedule_days = count($active_subjects);

$today_schedule = $active_subjects[date('Y-m-d')] ?? [];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Exam Schedule | Examcenter</title>

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

    <!-- Existing Dashboard Styles -->
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
        href="../css/sidebar.css"
    >

    <style>

        /* =================================================
           GLOBAL
        ================================================= */

        :root {
            --primary: #4361ee;
            --primary-dark: #3451d1;
            --primary-soft: #eef1ff;
            --success: #198754;
            --success-soft: #eaf7ef;
            --danger: #dc3545;
            --warning: #f59f00;

            --page-bg: #f5f7fb;
            --card-bg: #ffffff;
            --border: #e9edf3;

            --text: #1f2937;
            --muted: #6b7280;

            --radius: 14px;
        }

        body {
            background: var(--page-bg);
            color: var(--text);
        }

        .main-content {
            min-height: 100vh;
        }


        /* =================================================
           PAGE HEADER
        ================================================= */

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;

            background: #fff;

            padding: 20px 24px;

            border-radius: var(--radius);

            border: 1px solid rgba(0, 0, 0, 0.03);

            box-shadow:
                0 4px 20px rgba(15, 23, 42, 0.05);

            margin-bottom: 22px;
        }

        .page-heading {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-heading-icon {
            width: 48px;
            height: 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: var(--primary-soft);

            color: var(--primary);

            border-radius: 12px;

            font-size: 1.15rem;
        }

        .page-title {
            font-size: 1.45rem;
            font-weight: 700;

            margin: 0 0 3px;
        }

        .page-subtitle {
            color: var(--muted);

            font-size: 0.88rem;

            margin: 0;
        }

        .mobile-sidebar-button {
            width: 42px;
            height: 42px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 10px;
        }


        /* =================================================
           STAT CARDS
        ================================================= */

        .stat-card {
            height: 100%;

            display: flex;
            align-items: center;

            gap: 14px;

            padding: 18px;

            background: #fff;

            border: 1px solid rgba(0, 0, 0, 0.035);

            border-radius: 13px;

            box-shadow:
                0 4px 18px rgba(15, 23, 42, 0.04);
        }

        .stat-icon {
            width: 44px;
            height: 44px;

            flex: 0 0 44px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 11px;

            background: var(--primary-soft);

            color: var(--primary);
        }

        .stat-icon.success {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-value {
            font-size: 1.25rem;

            line-height: 1.1;

            font-weight: 700;

            color: var(--text);
        }

        .stat-label {
            margin-top: 4px;

            font-size: 0.78rem;

            color: var(--muted);
        }


        /* =================================================
           CARDS
        ================================================= */

        .schedule-card {
            background: var(--card-bg);

            border: 0;

            border-radius: var(--radius);

            box-shadow:
                0 4px 20px rgba(15, 23, 42, 0.05);

            overflow: hidden;
        }

        .schedule-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 15px;

            padding: 20px 24px;

            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: 1rem;

            font-weight: 700;

            margin: 0;
        }

        .card-subtitle {
            margin: 4px 0 0;

            color: var(--muted);

            font-size: 0.82rem;
        }

        .schedule-card-body {
            padding: 24px;
        }


        /* =================================================
           FORM
        ================================================= */

        .form-label {
            font-size: 0.84rem;

            font-weight: 600;

            color: #374151;

            margin-bottom: 7px;
        }

        .form-control,
        .form-select {

            min-height: 44px;

            border-radius: 9px;

            border-color: #dfe4ea;

            font-size: 0.88rem;
        }

        .form-control:focus,
        .form-select:focus {

            border-color: var(--primary);

            box-shadow:
                0 0 0 3px rgba(67, 97, 238, 0.10);
        }

        .subject-select {

            min-height: 250px;

            padding: 8px;
        }

        .subject-select option {

            padding: 9px 10px;

            border-radius: 6px;

            margin-bottom: 2px;
        }

        .subject-help {

            display: flex;
            align-items: center;

            gap: 6px;

            margin-top: 7px;

            color: var(--muted);

            font-size: 0.78rem;
        }


        /* =================================================
           SUBJECT SELECT HEADER
        ================================================= */

        .selection-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 8px;
        }

        .selection-count {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 28px;

            height: 25px;

            padding: 0 8px;

            background: var(--primary-soft);

            color: var(--primary);

            border-radius: 20px;

            font-size: 0.75rem;

            font-weight: 700;
        }


        /* =================================================
           FORM ACTION
        ================================================= */

        .form-actions {

            display: flex;

            align-items: center;

            justify-content: flex-end;

            gap: 10px;

            margin-top: 22px;

            padding-top: 20px;

            border-top: 1px solid var(--border);
        }

        .btn-primary {

            background: var(--primary);

            border-color: var(--primary);
        }

        .btn-primary:hover,
        .btn-primary:focus {

            background: var(--primary-dark);

            border-color: var(--primary-dark);
        }


        /* =================================================
           CURRENT DAY
        ================================================= */

        .today-panel {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            padding: 17px 18px;

            margin-top: 20px;

            border-radius: 11px;

            background: #f8f9fc;

            border: 1px solid var(--border);
        }

        .today-title {

            display: flex;

            align-items: center;

            gap: 11px;
        }

        .today-icon {

            width: 38px;
            height: 38px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 9px;

            background: var(--primary-soft);

            color: var(--primary);
        }

        .today-title strong {

            display: block;

            font-size: 0.88rem;
        }

        .today-title span {

            display: block;

            margin-top: 2px;

            color: var(--muted);

            font-size: 0.75rem;
        }


        /* =================================================
           TABLE
        ================================================= */

        .table-wrapper {

            overflow-x: auto;
        }

        .schedule-table {

            width: 100%;

            min-width: 650px;

            border-collapse: separate;

            border-spacing: 0;
        }

        .schedule-table thead th {

            padding: 13px 16px;

            background: #f8f9fb;

            color: #6b7280;

            font-size: 0.72rem;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 0.04em;

            border-bottom: 1px solid var(--border);
        }

        .schedule-table tbody td {

            padding: 16px;

            border-bottom: 1px solid #edf0f4;

            vertical-align: middle;

            font-size: 0.86rem;
        }

        .schedule-table tbody tr:last-child td {

            border-bottom: 0;
        }

        .schedule-table tbody tr:hover {

            background: #fafbff;
        }

        .date-cell {

            font-weight: 650;

            color: var(--text);

            white-space: nowrap;
        }

        .date-main {

            display: block;
        }

        .date-day {

            display: block;

            margin-top: 3px;

            color: var(--muted);

            font-size: 0.73rem;
        }


        /* =================================================
           SUBJECT BADGES
        ================================================= */

        .subject-badges {

            display: flex;

            flex-wrap: wrap;

            gap: 5px;
        }

        .subject-badge {

            display: inline-flex;

            align-items: center;

            gap: 5px;

            padding: 6px 10px;

            border-radius: 20px;

            background: var(--primary-soft);

            color: #3446b8;

            font-size: 0.76rem;

            font-weight: 600;
        }

        .subject-badge i {

            font-size: 0.62rem;
        }


        /* =================================================
           STATUS BADGE
        ================================================= */

        .status-badge {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            padding: 6px 9px;

            border-radius: 20px;

            background: var(--success-soft);

            color: var(--success);

            font-size: 0.74rem;

            font-weight: 600;
        }

        .status-dot {

            width: 6px;
            height: 6px;

            border-radius: 50%;

            background: currentColor;
        }


        /* =================================================
           EMPTY STATE
        ================================================= */

        .empty-state {

            text-align: center;

            padding: 55px 20px;

            color: var(--muted);
        }

        .empty-state-icon {

            width: 70px;
            height: 70px;

            display: flex;

            align-items: center;

            justify-content: center;

            margin: 0 auto 17px;

            border-radius: 50%;

            background: #f0f2f5;

            color: #9ca3af;

            font-size: 1.6rem;
        }

        .empty-state h5 {

            margin-bottom: 6px;

            color: #374151;

            font-weight: 700;
        }

        .empty-state p {

            max-width: 400px;

            margin: 0 auto 18px;

            font-size: 0.84rem;
        }


        /* =================================================
           ALERTS
        ================================================= */

        .alert {

            border: 0;

            border-radius: 11px;

            box-shadow:
                0 3px 12px rgba(15, 23, 42, 0.04);
        }


        /* =================================================
           SIDEBAR COMPATIBILITY
        ================================================= */

        .admin-info small,
        .admin-info h6 {

            color: #fff;
        }

        .sidebar-menu a i {

            width: 22px;

            text-align: center;
        }


        /* =================================================
           MOBILE
        ================================================= */

        @media (max-width: 991.98px) {

            .main-content {

                padding-top: 18px;
            }

            .page-header {

                padding: 17px;

                border-radius: 12px;
            }

            .page-heading-icon {

                width: 42px;
                height: 42px;
            }

            .page-title {

                font-size: 1.2rem;
            }

            .page-subtitle {

                font-size: 0.78rem;
            }

            .schedule-card-body {

                padding: 18px;
            }

            .schedule-card-header {

                padding: 17px 18px;
            }

            .today-panel {

                align-items: flex-start;

                flex-direction: column;
            }

            .form-actions {

                flex-direction: column;

                align-items: stretch;
            }

            .form-actions .btn {

                width: 100%;
            }
        }

        @media (max-width: 575.98px) {

            .page-heading-icon {

                display: none;
            }

            .page-header {

                gap: 10px;
            }

            .page-title {

                font-size: 1.08rem;
            }

            .stat-card {

                padding: 15px;
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
                <?php
                echo htmlspecialchars(
                    $admin['username']
                );
                ?>
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

        <a href="view_questions.php">
            <i class="fas fa-list"></i>
            View Questions
        </a>

        <a href="view_results.php">
            <i class="fas fa-chart-bar"></i>
            Exam Results
        </a>

        <a href="add_teacher.php">
            <i class="fas fa-user-plus"></i>
            Add Teachers
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
            <i class="fas fa-users"></i>
            Manage Teachers
        </a>

        <a href="manage_test.php">
            <i class="fas fa-file-alt"></i>
            Manage Tests
        </a>

        <a
            href="exam_schedule.php"
            class="active"
        >
            <i class="fas fa-calendar-check"></i>
            Exam Schedule
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

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="page-header">

        <div class="page-heading">

            <div class="page-heading-icon">
                <i class="fas fa-calendar-check"></i>
            </div>

            <div>

                <h1 class="page-title">
                    Exam Schedule
                </h1>

                <p class="page-subtitle">
                    Schedule the subjects students will take on each exam date.
                </p>

            </div>

        </div>


        <button
            type="button"
            class="btn btn-primary d-lg-none mobile-sidebar-button"
            id="sidebarToggle"
            aria-label="Toggle navigation"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>


    <!-- =====================================================
         ALERTS
    ====================================================== -->

    <?php if ($error): ?>

        <div
            class="alert alert-danger alert-dismissible fade show mb-4"
            role="alert"
        >

            <i class="fas fa-exclamation-circle me-2"></i>

            <?php
            echo htmlspecialchars($error);
            ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($success): ?>

        <div
            class="alert alert-success alert-dismissible fade show mb-4"
            role="alert"
        >

            <i class="fas fa-check-circle me-2"></i>

            <?php
            echo htmlspecialchars($success);
            ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-12 col-md-4">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>

                <div>

                    <div class="stat-value">
                        <?php echo $total_subjects; ?>
                    </div>

                    <div class="stat-label">
                        Available Subjects
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-4">

            <div class="stat-card">

                <div class="stat-icon success">
                    <i class="fas fa-calendar-alt"></i>
                </div>

                <div>

                    <div class="stat-value">
                        <?php echo $total_schedule_days; ?>
                    </div>

                    <div class="stat-label">
                        Scheduled Exam Days
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-4">

            <div class="stat-card">

                <div class="stat-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>

                <div>

                    <div class="stat-value">
                        <?php echo count($today_schedule); ?>
                    </div>

                    <div class="stat-label">
                        Subjects Scheduled Today
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         SCHEDULE CONFIGURATION
    ====================================================== -->

    <div class="schedule-card mb-4">

        <div class="schedule-card-header">

            <div>

                <h2 class="card-title">
                    <i class="fas fa-calendar-plus text-primary me-2"></i>
                    Create Exam Schedule
                </h2>

                <p class="card-subtitle">
                    Select a date and assign the subjects that will be examined.
                </p>

            </div>

            <span class="badge bg-light text-dark">
                <?php echo $total_subjects; ?> subjects available
            </span>

        </div>


        <div class="schedule-card-body">

            <form
                method="POST"
                id="scheduleForm"
            >

                <div class="row g-4">

                    <!-- DATE -->

                    <div class="col-12 col-lg-5">

                        <label
                            for="exam_date"
                            class="form-label"
                        >
                            Exam Date
                        </label>

                        <input
                            type="date"
                            name="exam_date"
                            id="exam_date"
                            class="form-control"
                            value="<?php echo htmlspecialchars($exam_date); ?>"
                            required
                        >

                        <div class="subject-help">

                            <i class="fas fa-info-circle"></i>

                            Choose the day students will write the selected subjects.

                        </div>

                    </div>


                    <!-- SUBJECTS -->

                    <div class="col-12 col-lg-7">

                        <div class="selection-header">

                            <label
                                for="subjects"
                                class="form-label mb-0"
                            >
                                Subjects
                            </label>

                            <span
                                class="selection-count"
                                id="selectionCount"
                            >
                                0 selected
                            </span>

                        </div>

                        <?php if (!empty($all_subjects)): ?>

                            <select
                                name="subjects[]"
                                id="subjects"
                                class="form-select subject-select"
                                multiple
                                required
                            >

                                <?php foreach ($all_subjects as $subject): ?>

                                    <option
                                        value="<?php echo htmlspecialchars($subject); ?>"
                                        <?php
                                        echo in_array(
                                            $subject,
                                            $selected_subjects,
                                            true
                                        )
                                            ? 'selected'
                                            : '';
                                        ?>
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            ucwords($subject)
                                        );
                                        ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <div class="subject-help">

                                <i class="fas fa-mouse-pointer"></i>

                                Hold Ctrl/Cmd to select multiple subjects.

                            </div>

                        <?php else: ?>

                            <div class="alert alert-light border mb-0">

                                <i class="fas fa-info-circle me-2"></i>

                                No subjects are available. Add subjects first.

                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- ACTIONS -->

                <div class="form-actions">

                    <button
                        type="reset"
                        class="btn btn-light border"
                        id="resetSchedule"
                    >
                        <i class="fas fa-undo me-2"></i>
                        Reset
                    </button>

                    <button
                        type="submit"
                        name="save_daily_subjects"
                        class="btn btn-primary px-4"
                        <?php
                        echo empty($all_subjects)
                            ? 'disabled'
                            : '';
                        ?>
                    >
                        <i class="fas fa-save me-2"></i>
                        Save Schedule
                    </button>

                </div>

            </form>


            <!-- =================================================
                 TODAY'S SCHEDULE
            ================================================== -->

            <div class="today-panel">

                <div class="today-title">

                    <div class="today-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>

                    <div>

                        <strong>
                            Today's Schedule
                        </strong>

                        <span>
                            <?php
                            echo date(
                                'l, F j, Y'
                            );
                            ?>
                        </span>

                    </div>

                </div>


                <?php if (!empty($today_schedule)): ?>

                    <div class="subject-badges">

                        <?php foreach ($today_schedule as $subject): ?>

                            <span class="subject-badge">

                                <i class="fas fa-circle"></i>

                                <?php
                                echo htmlspecialchars(
                                    ucwords($subject)
                                );
                                ?>

                            </span>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <span class="text-muted small">
                        No subjects scheduled for today.
                    </span>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CURRENT SCHEDULE
    ====================================================== -->

    <div class="schedule-card">

        <div class="schedule-card-header">

            <div>

                <h2 class="card-title">

                    <i class="fas fa-list-alt text-primary me-2"></i>

                    Current Exam Schedule

                </h2>

                <p class="card-subtitle">

                    Review the subjects currently assigned to each exam date.

                </p>

            </div>

            <?php if (!empty($active_subjects)): ?>

                <span class="status-badge">

                    <span class="status-dot"></span>

                    Schedule Active

                </span>

            <?php endif; ?>

        </div>


        <?php if (!empty($active_subjects)): ?>

            <div class="table-wrapper">

                <table class="schedule-table">

                    <thead>

                        <tr>

                            <th>
                                Exam Date
                            </th>

                            <th>
                                Subjects
                            </th>

                            <th>
                                Subjects Count
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($active_subjects as $date => $subjects): ?>

                            <tr>

                                <td class="date-cell">

                                    <span class="date-main">

                                        <?php
                                        echo htmlspecialchars(
                                            date(
                                                'M j, Y',
                                                strtotime($date)
                                            )
                                        );
                                        ?>

                                    </span>

                                    <span class="date-day">

                                        <?php
                                        echo htmlspecialchars(
                                            date(
                                                'l',
                                                strtotime($date)
                                            )
                                        );
                                        ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="subject-badges">

                                        <?php foreach ($subjects as $subject): ?>

                                            <span class="subject-badge">

                                                <i class="fas fa-book-open"></i>

                                                <?php
                                                echo htmlspecialchars(
                                                    ucwords($subject)
                                                );
                                                ?>

                                            </span>

                                        <?php endforeach; ?>

                                    </div>

                                </td>


                                <td>

                                    <span class="fw-semibold">

                                        <?php
                                        echo count($subjects);
                                        ?>

                                    </span>

                                    <span class="text-muted small">

                                        subject<?php
                                        echo count($subjects) === 1
                                            ? ''
                                            : 's';
                                        ?>

                                    </span>

                                </td>


                                <td>

                                    <span class="status-badge">

                                        <span class="status-dot"></span>

                                        Active

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <div class="empty-state-icon">

                    <i class="fas fa-calendar-times"></i>

                </div>

                <h5>
                    No Exam Schedule Yet
                </h5>

                <p>
                    No subjects have been scheduled for any exam date.
                    Use the form above to create the first schedule.
                </p>

                <?php if (!empty($all_subjects)): ?>

                    <button
                        type="button"
                        class="btn btn-primary"
                        id="scrollToForm"
                    >
                        <i class="fas fa-calendar-plus me-2"></i>
                        Create Schedule
                    </button>

                <?php endif; ?>

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

    // =====================================================
    // SIDEBAR TOGGLE
    // =====================================================

    const sidebarToggle =
        document.getElementById('sidebarToggle');

    const sidebar =
        document.querySelector('.sidebar');

    if (sidebarToggle && sidebar) {

        sidebarToggle.addEventListener(
            'click',
            function () {

                sidebar.classList.toggle('active');

            }
        );

    }


    // =====================================================
    // SUBJECT SELECTION COUNTER
    // =====================================================

    const subjects =
        document.getElementById('subjects');

    const selectionCount =
        document.getElementById('selectionCount');

    function updateSelectionCount() {

        if (!subjects || !selectionCount) {
            return;
        }

        const count =
            Array.from(
                subjects.selectedOptions
            ).length;

        selectionCount.textContent =
            count + (
                count === 1
                    ? ' selected'
                    : ' selected'
            );
    }


    if (subjects) {

        subjects.addEventListener(
            'change',
            updateSelectionCount
        );

        updateSelectionCount();

    }


    // =====================================================
    // RESET FORM
    // =====================================================

    const scheduleForm =
        document.getElementById('scheduleForm');

    const resetSchedule =
        document.getElementById('resetSchedule');

    if (resetSchedule && scheduleForm) {

        resetSchedule.addEventListener(
            'click',
            function () {

                setTimeout(
                    updateSelectionCount,
                    0
                );

            }
        );

    }


    // =====================================================
    // FORM SUBMISSION PROTECTION
    // =====================================================

    if (scheduleForm) {

        scheduleForm.addEventListener(
            'submit',
            function (event) {

                if (!subjects) {
                    return;
                }

                const selected =
                    Array.from(
                        subjects.selectedOptions
                    );

                if (selected.length === 0) {

                    event.preventDefault();

                    alert(
                        'Please select at least one subject.'
                    );

                    subjects.focus();

                    return;
                }

            }
        );

    }


    // =====================================================
    // SCROLL TO FORM
    // =====================================================

    const scrollToForm =
        document.getElementById('scrollToForm');

    if (scrollToForm) {

        scrollToForm.addEventListener(
            'click',
            function () {

                const form =
                    document.getElementById(
                        'scheduleForm'
                    );

                if (form) {

                    form.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });

                }

            }
        );

    }


    // =====================================================
    // AUTO-HIDE ALERTS
    // =====================================================

    const alerts =
        document.querySelectorAll(
            '.alert.alert-dismissible'
        );

    if (alerts.length) {

        setTimeout(
            function () {

                alerts.forEach(
                    function (alertElement) {

                        const alert =
                            bootstrap.Alert
                                .getOrCreateInstance(
                                    alertElement
                                );

                        alert.close();

                    }
                );

            },
            6000
        );

    }

});

</script>

</body>
</html>
