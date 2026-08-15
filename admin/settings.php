<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';

// =========================================================
// ERROR REPORTING
// =========================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// =========================================================
// AUTHENTICATION
// =========================================================
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'admin'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");

    header("Location: ../login.php?error=Not logged in");
    exit();
}

// =========================================================
// DATABASE + ADMIN PROFILE
// =========================================================
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed");
    }

    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT username, password, role
         FROM admins
         WHERE id = ?"
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
            "Unauthorized access attempt by user_id=" .
            $admin_id .
            ", role=" .
            ($admin['role'] ?? 'none')
        );

        session_destroy();

        header("Location: ../login.php?error=Unauthorized");
        exit();
    }

    // =====================================================
    // PAGE ACCESS ACTIVITY
    // =====================================================
    $ip_address = filter_var(
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        FILTER_VALIDATE_IP
    ) ?: '0.0.0.0';

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $activity =
        "Admin {$admin['username']} accessed settings page.";

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
    error_log("Settings page error: " . $e->getMessage());
    die("System error");
}

// =========================================================
// VARIABLES
// =========================================================
$error = '';
$success = '';

$current_password = '';
$new_password = '';
$confirm_password = '';

$settings = [];

// =========================================================
// FETCH SYSTEM SETTINGS
// =========================================================
try {
    $stmt = $conn->prepare(
        "SELECT setting_name, setting_value
         FROM settings
         WHERE setting_name = 'show_results_immediately'"
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_name']] = $row['setting_value'];
    }

    $stmt->close();

} catch (Exception $e) {
    error_log("Error fetching system settings: " . $e->getMessage());
    $error = "Failed to load system settings.";
}

// =========================================================
// HANDLE PASSWORD CHANGE
// =========================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change_password'])
) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (
        $current_password === '' ||
        $new_password === '' ||
        $confirm_password === ''
    ) {
        $error = "All password fields are required.";

    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";

    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters.";

    } elseif (
        !preg_match('/[A-Za-z]/', $new_password) ||
        !preg_match('/[0-9]/', $new_password)
    ) {
        $error =
            "Password must contain at least one letter and one number.";

    } elseif (
        password_verify(
            $current_password,
            $admin['password']
        )
    ) {
        try {
            $hashed_password = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare(
                "UPDATE admins
                 SET password = ?
                 WHERE id = ?"
            );

            if (!$stmt) {
                throw new Exception(
                    "Failed to prepare password update query."
                );
            }

            $stmt->bind_param(
                "si",
                $hashed_password,
                $admin_id
            );

            $stmt->execute();
            $stmt->close();

            $success = "Password changed successfully!";

            $current_password = '';
            $new_password = '';
            $confirm_password = '';

            // ---------------------------------------------
            // ACTIVITY LOG
            // ---------------------------------------------
            $activity =
                "Admin {$admin['username']} changed their password.";

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
                "Error updating password: " . $e->getMessage()
            );

            $error = "Failed to update password.";
        }

    } else {
        $error = "Current password is incorrect.";
    }
}

// =========================================================
// HANDLE SYSTEM SETTINGS UPDATE
// =========================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_settings'])
) {
    $show_results = isset($_POST['show_results']) ? 1 : 0;

    try {
        $setting_name = 'show_results_immediately';

        $stmt = $conn->prepare(
            "INSERT INTO settings
            (setting_name, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
            setting_value = ?"
        );

        if (!$stmt) {
            throw new Exception(
                "Failed to prepare settings update query."
            );
        }

        $stmt->bind_param(
            "sii",
            $setting_name,
            $show_results,
            $show_results
        );

        $stmt->execute();
        $stmt->close();

        $settings['show_results_immediately'] = $show_results;

        $success = "System settings updated successfully!";

        // ---------------------------------------------
        // ACTIVITY LOG
        // ---------------------------------------------
        $activity =
            "Admin {$admin['username']} updated system settings: " .
            "show_results_immediately={$show_results}";

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
            "Error updating system settings: " . $e->getMessage()
        );

        $error = "Failed to update system settings.";
    }
}

$conn->close();
?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Settings | Examcenter</title>

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
    href="../css/sidebar.css"
>

<style>
    /* =====================================================
       PAGE FOUNDATION
    ===================================================== */

    body {
        background: #f5f7fb;
        color: #1f2937;
    }

    .main-content {
        min-height: 100vh;
        padding-bottom: 40px;
    }

    /* =====================================================
       PAGE HEADER
    ===================================================== */

    .page-header {
        position: relative;
        background: #ffffff;
        border: 1px solid #edf0f5;
        border-radius: 18px;
        padding: 24px 26px;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
    }

    .page-header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
    }

    .page-heading-wrap {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .page-icon {
        width: 52px;
        height: 52px;
        flex: 0 0 52px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background: #eef2ff;
        color: #4361ee;
        font-size: 1.2rem;
    }

    .page-title {
        margin: 0;
        font-size: 1.55rem;
        font-weight: 750;
        color: #172033;
        letter-spacing: -0.02em;
    }

    .page-subtitle {
        margin: 5px 0 0;
        color: #7a8496;
        font-size: 0.92rem;
    }

    /* =====================================================
       ALERTS
    ===================================================== */

    .page-alert {
        border: 0;
        border-radius: 13px;
        padding: 14px 16px;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
    }

    .page-alert .alert-icon {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        margin-right: 10px;
    }

    .alert-danger .alert-icon {
        background: rgba(220, 53, 69, 0.10);
        color: #dc3545;
    }

    .alert-success .alert-icon {
        background: rgba(25, 135, 84, 0.10);
        color: #198754;
    }

    /* =====================================================
       SETTINGS CONTAINER
    ===================================================== */

    .settings-card {
        background: #ffffff;
        border: 1px solid #edf0f5;
        border-radius: 18px;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }

    /* =====================================================
       SETTINGS NAVIGATION
    ===================================================== */

    .settings-nav {
        display: flex;
        gap: 8px;
        padding: 12px;
        border-bottom: 1px solid #edf0f5;
        background: #fbfcfe;
    }

    .settings-nav .nav-link {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: #667085;
        background: transparent;
        border: 0;
        border-radius: 11px;
        padding: 11px 16px;
        font-size: 0.9rem;
        font-weight: 650;
        transition:
            background 0.2s ease,
            color 0.2s ease,
            transform 0.2s ease;
    }

    .settings-nav .nav-link:hover {
        color: #344054;
        background: #f1f4f9;
    }

    .settings-nav .nav-link.active {
        color: #4361ee;
        background: #eef2ff;
    }

    .settings-nav .nav-link i {
        font-size: 0.85rem;
    }

    /* =====================================================
       TAB CONTENT
    ===================================================== */

    .settings-body {
        padding: 30px;
    }

    .settings-section {
        width: 100%;
        max-width: 880px;
    }

    .section-top {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 26px;
    }

    .section-icon {
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: #f1f4ff;
        color: #4361ee;
    }

    .section-heading {
        margin: 0;
        color: #1d2939;
        font-size: 1.05rem;
        font-weight: 720;
    }

    .section-description {
        margin: 5px 0 0;
        color: #7a8496;
        font-size: 0.9rem;
        line-height: 1.55;
    }

    /* =====================================================
       FORM
    ===================================================== */

    .form-group-card {
        background: #fbfcfe;
        border: 1px solid #edf0f5;
        border-radius: 14px;
        padding: 20px;
    }

    .form-label {
        color: #344054;
        font-size: 0.88rem;
        font-weight: 650;
        margin-bottom: 8px;
    }

    .input-wrapper {
        position: relative;
    }

    .input-wrapper .form-control {
        padding-right: 46px;
    }

    .form-control {
        min-height: 46px;
        border: 1px solid #dce1e9;
        border-radius: 10px;
        background: #ffffff;
        color: #1f2937;
        padding: 10px 13px;
        font-size: 0.9rem;
        transition:
            border-color 0.2s ease,
            box-shadow 0.2s ease;
    }

    .form-control::placeholder {
        color: #a1a9b7;
    }

    .form-control:hover {
        border-color: #c7ceda;
    }

    .form-control:focus {
        border-color: #4361ee;
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.11);
    }

    .password-toggle {
        position: absolute;
        top: 50%;
        right: 13px;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #98a2b3;
        padding: 3px;
        cursor: pointer;
        transition: color 0.2s ease;
    }

    .password-toggle:hover {
        color: #4361ee;
    }

    /* =====================================================
       PASSWORD STRENGTH
    ===================================================== */

    .password-strength-wrap {
        margin-top: 10px;
    }

    .password-strength-track {
        height: 5px;
        overflow: hidden;
        border-radius: 10px;
        background: #e9edf3;
    }

    .password-strength {
        width: 0;
        height: 100%;
        border-radius: inherit;
        transition:
            width 0.25s ease,
            background 0.25s ease;
    }

    .strength-weak {
        width: 30%;
        background: #dc3545;
    }

    .strength-medium {
        width: 65%;
        background: #f59e0b;
    }

    .strength-strong {
        width: 100%;
        background: #198754;
    }

    .password-strength-text {
        margin-top: 6px;
        color: #98a2b3;
        font-size: 0.76rem;
    }

    .form-help {
        display: block;
        margin-top: 7px;
        color: #98a2b3;
        font-size: 0.78rem;
    }

    /* =====================================================
       PASSWORD REQUIREMENTS
    ===================================================== */

    .password-requirements {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 13px;
    }

    .requirement {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 9px;
        border-radius: 7px;
        background: #f2f4f7;
        color: #98a2b3;
        font-size: 0.75rem;
        transition: all 0.2s ease;
    }

    .requirement.valid {
        background: #ecfdf3;
        color: #198754;
    }

    .requirement i {
        font-size: 0.68rem;
    }

    /* =====================================================
       FORM ACTIONS
    ===================================================== */

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 9px;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #edf0f5;
    }

    .btn {
        border-radius: 9px;
        font-size: 0.86rem;
        font-weight: 650;
        padding: 10px 15px;
    }

    .btn-primary {
        background: #4361ee;
        border-color: #4361ee;
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.18);
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: #3651d4;
        border-color: #3651d4;
    }

    .btn-light {
        background: #f5f6f8;
        border-color: #e6e8ec;
        color: #475467;
    }

    .btn-light:hover {
        background: #eaecf0;
        border-color: #d9dde5;
    }

    /* =====================================================
       SYSTEM SETTING
    ===================================================== */

    .setting-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 25px;
        padding: 20px;
        background: #fbfcfe;
        border: 1px solid #e7ebf1;
        border-radius: 14px;
        transition:
            border-color 0.2s ease,
            box-shadow 0.2s ease;
    }

    .setting-option:hover {
        border-color: #d9dfeb;
        box-shadow: 0 5px 18px rgba(15, 23, 42, 0.04);
    }

    .setting-content {
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }

    .setting-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        background: #eef2ff;
        color: #4361ee;
    }

    .setting-option-title {
        margin-bottom: 4px;
        color: #344054;
        font-size: 0.92rem;
        font-weight: 680;
    }

    .setting-option-description {
        max-width: 650px;
        margin: 0;
        color: #7a8496;
        font-size: 0.82rem;
        line-height: 1.55;
    }

    .setting-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 8px;
        color: #98a2b3;
        font-size: 0.75rem;
    }

    .setting-status.active {
        color: #198754;
    }

    .setting-status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    /* =====================================================
       SWITCH
    ===================================================== */

    .setting-switch {
        flex: 0 0 auto;
    }

    .form-switch {
        padding-left: 0;
    }

    .form-switch .form-check-input {
        width: 3.25em;
        height: 1.7em;
        margin: 0;
        cursor: pointer;
        background-color: #d0d5dd;
        border: 0;
        box-shadow: none;
        transition:
            background-color 0.2s ease,
            box-shadow 0.2s ease;
    }

    .form-switch .form-check-input:focus {
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.12);
    }

    .form-switch .form-check-input:checked {
        background-color: #4361ee;
    }

    /* =====================================================
       SIDEBAR
    ===================================================== */

    .admin-info small,
    .admin-info h6 {
        color: #ffffff;
    }

    /* =====================================================
       MOBILE OVERLAY
    ===================================================== */

    .sidebar-overlay {
        display: none;
    }

    /* =====================================================
       RESPONSIVE
    ===================================================== */

    @media (max-width: 991.98px) {
        .page-header {
            padding: 19px;
        }

        .page-heading-wrap {
            gap: 12px;
        }

        .page-icon {
            width: 46px;
            height: 46px;
            flex-basis: 46px;
        }

        .page-title {
            font-size: 1.3rem;
        }

        .settings-body {
            padding: 22px;
        }
    }

    @media (max-width: 767.98px) {
        .main-content {
            padding-top: 15px;
        }

        .page-header {
            border-radius: 14px;
            padding: 16px;
        }

        .page-header-content {
            align-items: flex-start;
        }

        .page-icon {
            display: none;
        }

        .page-title {
            font-size: 1.18rem;
        }

        .page-subtitle {
            font-size: 0.82rem;
            line-height: 1.45;
        }

        .settings-card {
            border-radius: 14px;
        }

        .settings-nav {
            flex-direction: column;
            padding: 10px;
        }

        .settings-nav .nav-link {
            width: 100%;
            justify-content: flex-start;
        }

        .settings-body {
            padding: 18px;
        }

        .section-top {
            margin-bottom: 20px;
        }

        .form-group-card {
            padding: 16px;
        }

        .setting-option {
            align-items: flex-start;
            padding: 16px;
        }

        .setting-content {
            flex-direction: column;
            gap: 10px;
        }

        .setting-icon {
            width: 38px;
            height: 38px;
            flex-basis: 38px;
        }

        .form-actions {
            flex-direction: column-reverse;
        }

        .form-actions .btn {
            width: 100%;
        }

        .password-requirements {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 420px) {
        .page-title {
            font-size: 1.08rem;
        }

        .settings-body {
            padding: 15px;
        }

        .setting-option {
            gap: 14px;
        }

        .setting-option-description {
            font-size: 0.78rem;
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
            <?php echo htmlspecialchars($admin['username']); ?>
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

    <a href="manage_teachers.php">
        <i class="fas fa-users"></i>
        Manage Teachers
    </a>

    <a href="manage_test.php">
        <i class="fas fa-file-alt"></i>
        Manage Tests
    </a>

    <a href="exam_schedule.php">
        <i class="fas fa-calendar-check"></i>
        Exam Schedule
    </a>

    <a href="settings.php" class="active">
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

<div class="page-header mb-4">

    <div class="page-header-content">

        <div class="page-heading-wrap">

            <div class="page-icon">
                <i class="fas fa-cog"></i>
            </div>

            <div>
                <h2 class="page-title">
                    System Settings
                </h2>

                <p class="page-subtitle">
                    Manage your administrator account and examination system configuration.
                </p>
            </div>

        </div>

        <button
            class="btn btn-primary d-lg-none"
            id="sidebarToggle"
            type="button"
            aria-label="Toggle navigation"
            aria-expanded="false"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>

</div>

<!-- ALERTS -->

<?php if ($error): ?>

    <div
        class="alert alert-danger page-alert alert-dismissible fade show mb-4"
        role="alert"
    >
        <span class="alert-icon">
            <i class="fas fa-exclamation-circle"></i>
        </span>

        <?php echo htmlspecialchars($error); ?>

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
        class="alert alert-success page-alert alert-dismissible fade show mb-4"
        role="alert"
    >
        <span class="alert-icon">
            <i class="fas fa-check-circle"></i>
        </span>

        <?php echo htmlspecialchars($success); ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Close"
        ></button>
    </div>

<?php endif; ?>

<!-- =====================================================
     SETTINGS CARD
====================================================== -->

<div class="settings-card">

    <!-- SETTINGS NAVIGATION -->

    <ul
        class="nav settings-nav"
        id="settingsTabs"
        role="tablist"
    >

        <li
            class="nav-item"
            role="presentation"
        >

            <button
                class="nav-link active"
                id="password-tab"
                data-bs-toggle="tab"
                data-bs-target="#password"
                type="button"
                role="tab"
                aria-controls="password"
                aria-selected="true"
            >
                <i class="fas fa-lock"></i>
                Change Password
            </button>

        </li>

        <li
            class="nav-item"
            role="presentation"
        >

            <button
                class="nav-link"
                id="system-tab"
                data-bs-toggle="tab"
                data-bs-target="#system-settings"
                type="button"
                role="tab"
                aria-controls="system-settings"
                aria-selected="false"
            >
                <i class="fas fa-sliders-h"></i>
                System Settings
            </button>

        </li>

    </ul>

    <!-- TAB CONTENT -->

    <div
        class="tab-content settings-body"
        id="settingsTabsContent"
    >

        <!-- =================================================
             PASSWORD TAB
        ================================================== -->

        <div
            class="tab-pane fade show active"
            id="password"
            role="tabpanel"
            aria-labelledby="password-tab"
        >

            <div class="settings-section">

                <div class="section-top">

                    <div class="section-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>

                    <div>
                        <h5 class="section-heading">
                            Change Password
                        </h5>

                        <p class="section-description">
                            Update the password used to access your administrator account.
                        </p>
                    </div>

                </div>

                <form
                    method="POST"
                    id="passwordForm"
                    novalidate
                >

                    <div class="form-group-card">

                        <!-- CURRENT PASSWORD -->

                        <div class="mb-4">

                            <label
                                class="form-label"
                                for="current-password"
                            >
                                Current Password
                            </label>

                            <div class="input-wrapper">

                                <input
                                    type="password"
                                    class="form-control"
                                    id="current-password"
                                    name="current_password"
                                    autocomplete="current-password"
                                    placeholder="Enter your current password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="current-password"
                                    aria-label="Show current password"
                                >
                                    <i class="fas fa-eye"></i>
                                </button>

                            </div>

                        </div>

                        <!-- NEW + CONFIRM PASSWORD -->

                        <div class="row g-4">

                            <div class="col-md-6">

                                <label
                                    class="form-label"
                                    for="new-password"
                                >
                                    New Password
                                </label>

                                <div class="input-wrapper">

                                    <input
                                        type="password"
                                        class="form-control"
                                        id="new-password"
                                        name="new_password"
                                        autocomplete="new-password"
                                        placeholder="Create a new password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-toggle"
                                        data-target="new-password"
                                        aria-label="Show new password"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>

                                </div>

                                <div class="password-strength-wrap">

                                    <div class="password-strength-track">
                                        <div
                                            class="password-strength"
                                            id="password-strength"
                                        ></div>
                                    </div>

                                    <div
                                        class="password-strength-text"
                                        id="password-strength-text"
                                    >
                                        Password strength
                                    </div>

                                </div>

                                <div class="password-requirements">

                                    <span
                                        class="requirement"
                                        id="requirement-length"
                                    >
                                        <i class="fas fa-circle"></i>
                                        8+ characters
                                    </span>

                                    <span
                                        class="requirement"
                                        id="requirement-letter"
                                    >
                                        <i class="fas fa-circle"></i>
                                        One letter
                                    </span>

                                    <span
                                        class="requirement"
                                        id="requirement-number"
                                    >
                                        <i class="fas fa-circle"></i>
                                        One number
                                    </span>

                                </div>

                            </div>

                            <div class="col-md-6">

                                <label
                                    class="form-label"
                                    for="confirm-password"
                                >
                                    Confirm New Password
                                </label>

                                <div class="input-wrapper">

                                    <input
                                        type="password"
                                        class="form-control"
                                        id="confirm-password"
                                        name="confirm_password"
                                        autocomplete="new-password"
                                        placeholder="Repeat your new password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-toggle"
                                        data-target="confirm-password"
                                        aria-label="Show confirmed password"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>

                                </div>

                                <small
                                    class="form-help"
                                    id="password-match-message"
                                >
                                    Make sure both passwords match.
                                </small>

                            </div>

                        </div>

                        <!-- ACTIONS -->

                        <div class="form-actions">

                            <button
                                type="reset"
                                class="btn btn-light"
                                id="clearPasswordForm"
                            >
                                <i class="fas fa-undo me-2"></i>
                                Clear
                            </button>

                            <button
                                type="submit"
                                name="change_password"
                                class="btn btn-primary"
                                id="changePasswordButton"
                            >
                                <i class="fas fa-key me-2"></i>
                                Change Password
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <!-- =================================================
             SYSTEM SETTINGS TAB
        ================================================== -->

        <div
            class="tab-pane fade"
            id="system-settings"
            role="tabpanel"
            aria-labelledby="system-tab"
        >

            <div class="settings-section">

                <div class="section-top">

                    <div class="section-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>

                    <div>
                        <h5 class="section-heading">
                            System Configuration
                        </h5>

                        <p class="section-description">
                            Control how the examination system behaves after students complete an exam.
                        </p>
                    </div>

                </div>

                <form
                    method="POST"
                    id="systemSettingsForm"
                >

                    <div class="setting-option">

                        <div class="setting-content">

                            <div class="setting-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>

                            <div>

                                <div class="setting-option-title">
                                    Show Results Immediately
                                </div>

                                <p class="setting-option-description">
                                    Allow students to see their examination results immediately after completing an exam.
                                </p>

                                <div
                                    class="setting-status <?php echo (($settings['show_results_immediately'] ?? '0') ? 'active' : ''); ?>"
                                    id="settingStatus"
                                >
                                    <span class="setting-status-dot"></span>

                                    <span id="settingStatusText">
                                        <?php
                                        echo (
                                            ($settings['show_results_immediately'] ?? '0')
                                                ? 'Currently enabled'
                                                : 'Currently disabled'
                                        );
                                        ?>
                                    </span>
                                </div>

                            </div>

                        </div>

                        <div class="setting-switch">

                            <div class="form-check form-switch">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    name="show_results"
                                    id="showResults"
                                    <?php
                                    echo (
                                        ($settings['show_results_immediately'] ?? '0')
                                            ? 'checked'
                                            : ''
                                    );
                                    ?>
                                >

                            </div>

                        </div>

                    </div>

                    <div class="form-actions">

                        <button
                            type="submit"
                            name="update_settings"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-save me-2"></i>
                            Save Settings
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>

</div>

<!-- =========================================================
     SCRIPTS
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function () {

    // =====================================================
    // SIDEBAR TOGGLE
    // =====================================================

    $('#sidebarToggle').on('click', function () {
        const sidebar = $('.sidebar');
        const isActive = sidebar.toggleClass('active').hasClass('active');

        $(this).attr('aria-expanded', isActive ? 'true' : 'false');

        const icon = $(this).find('i');

        if (isActive) {
            icon.removeClass('fa-bars').addClass('fa-times');
        } else {
            icon.removeClass('fa-times').addClass('fa-bars');
        }
    });


    // =====================================================
    // PASSWORD VISIBILITY TOGGLE
    // =====================================================

    $('.password-toggle').on('click', function () {

        const targetId = $(this).data('target');
        const input = $('#' + targetId);
        const icon = $(this).find('i');

        if (!input.length) {
            return;
        }

        if (input.attr('type') === 'password') {

            input.attr('type', 'text');

            icon
                .removeClass('fa-eye')
                .addClass('fa-eye-slash');

            $(this).attr(
                'aria-label',
                'Hide password'
            );

        } else {

            input.attr('type', 'password');

            icon
                .removeClass('fa-eye-slash')
                .addClass('fa-eye');

            $(this).attr(
                'aria-label',
                'Show password'
            );
        }
    });


    // =====================================================
    // PASSWORD STRENGTH
    // =====================================================

    function checkPasswordStrength(password) {

        const indicator = $('#password-strength');
        const strengthText = $('#password-strength-text');

        const lengthRequirement =
            $('#requirement-length');

        const letterRequirement =
            $('#requirement-letter');

        const numberRequirement =
            $('#requirement-number');

        indicator.removeClass(
            'strength-weak strength-medium strength-strong'
        );

        lengthRequirement.removeClass('valid');
        letterRequirement.removeClass('valid');
        numberRequirement.removeClass('valid');

        const hasLength = password.length >= 8;
        const hasLetter = /[A-Za-z]/.test(password);
        const hasNumber = /[0-9]/.test(password);

        if (hasLength) {
            lengthRequirement
                .addClass('valid')
                .find('i')
                .removeClass('fa-circle')
                .addClass('fa-check');
        } else {
            lengthRequirement
                .find('i')
                .removeClass('fa-check')
                .addClass('fa-circle');
        }

        if (hasLetter) {
            letterRequirement
                .addClass('valid')
                .find('i')
                .removeClass('fa-circle')
                .addClass('fa-check');
        } else {
            letterRequirement
                .find('i')
                .removeClass('fa-check')
                .addClass('fa-circle');
        }

        if (hasNumber) {
            numberRequirement
                .addClass('valid')
                .find('i')
                .removeClass('fa-circle')
                .addClass('fa-check');
        } else {
            numberRequirement
                .find('i')
                .removeClass('fa-check')
                .addClass('fa-circle');
        }

        if (password.length === 0) {
            strengthText.text('Password strength');
            return;
        }

        if (password.length < 6) {

            indicator.addClass('strength-weak');
            strengthText.text('Weak password');

        } else if (
            password.length < 8 ||
            !hasLetter ||
            !hasNumber
        ) {

            indicator.addClass('strength-medium');
            strengthText.text('Almost there');

        } else {

            indicator.addClass('strength-strong');
            strengthText.text('Strong password');
        }
    }


    $('#new-password').on(
        'input',
        function () {
            checkPasswordStrength($(this).val());
            checkPasswordMatch();
        }
    );


    // =====================================================
    // PASSWORD MATCH
    // =====================================================

    function checkPasswordMatch() {

        const newPassword =
            $('#new-password').val();

        const confirmPassword =
            $('#confirm-password').val();

        const message =
            $('#password-match-message');

        if (confirmPassword.length === 0) {

            message
                .text('Make sure both passwords match.')
                .css('color', '');

            return false;
        }

        if (newPassword === confirmPassword) {

            message
                .text('Passwords match.')
                .css('color', '#198754');

            return true;
        }

        message
            .text('Passwords do not match.')
            .css('color', '#dc3545');

        return false;
    }


    $('#confirm-password').on(
        'input',
        checkPasswordMatch
    );


    // =====================================================
    // PASSWORD FORM VALIDATION
    // =====================================================

    $('#passwordForm').on(
        'submit',
        function (event) {

            const currentPassword =
                $('#current-password').val();

            const newPassword =
                $('#new-password').val();

            const confirmPassword =
                $('#confirm-password').val();

            if (!currentPassword) {

                event.preventDefault();

                $('#current-password').focus();

                return;
            }

            if (newPassword.length < 8) {

                event.preventDefault();

                alert(
                    'Password must be at least 8 characters.'
                );

                $('#new-password').focus();

                return;
            }

            if (
                !/[A-Za-z]/.test(newPassword) ||
                !/[0-9]/.test(newPassword)
            ) {

                event.preventDefault();

                alert(
                    'Password must contain at least one letter and one number.'
                );

                $('#new-password').focus();

                return;
            }

            if (newPassword !== confirmPassword) {

                event.preventDefault();

                alert(
                    'New passwords do not match.'
                );

                $('#confirm-password').focus();

                return;
            }

            const button =
                $('#changePasswordButton');

            button
                .prop('disabled', true)
                .html(
                    '<span class="spinner-border spinner-border-sm me-2"></span>' +
                    'Changing Password...'
                );
        }
    );


    // =====================================================
    // RESET PASSWORD FORM
    // =====================================================

    $('#clearPasswordForm').on(
        'click',
        function () {

            setTimeout(function () {

                $('#password-strength')
                    .removeClass(
                        'strength-weak strength-medium strength-strong'
                    );

                $('#password-strength-text')
                    .text('Password strength');

                $('.requirement')
                    .removeClass('valid');

                $('.requirement i')
                    .removeClass('fa-check')
                    .addClass('fa-circle');

                $('#password-match-message')
                    .text('Make sure both passwords match.')
                    .css('color', '');

            }, 0);
        }
    );


    // =====================================================
    // SYSTEM SETTING STATUS
    // =====================================================

    $('#showResults').on(
        'change',
        function () {

            const enabled = $(this).is(':checked');

            const status =
                $('#settingStatus');

            const statusText =
                $('#settingStatusText');

            if (enabled) {

                status.addClass('active');

                statusText.text(
                    'Currently enabled'
                );

            } else {

                status.removeClass('active');

                statusText.text(
                    'Currently disabled'
                );
            }
        }
    );


    // =====================================================
    // SYSTEM SETTINGS FORM
    // =====================================================

    $('#systemSettingsForm').on(
        'submit',
        function () {

            const button =
                $(this).find('button[type="submit"]');

            button
                .prop('disabled', true)
                .html(
                    '<span class="spinner-border spinner-border-sm me-2"></span>' +
                    'Saving...'
                );
        }
    );


    // =====================================================
    // AUTO-HIDE ALERTS
    // =====================================================

    setTimeout(function () {

        $('.alert-dismissible').each(
            function () {

                const alertElement =
                    bootstrap.Alert.getOrCreateInstance(
                        this
                    );

                alertElement.close();
            }
        );

    }, 5000);


    // =====================================================
    // CLOSE MOBILE SIDEBAR WHEN LINK IS SELECTED
    // =====================================================

    $('.sidebar a').on(
        'click',
        function () {

            if (
                window.innerWidth <= 991 &&
                $('.sidebar').hasClass('active')
            ) {
                $('.sidebar').removeClass('active');

                $('#sidebarToggle')
                    .attr('aria-expanded', 'false')
                    .find('i')
                    .removeClass('fa-times')
                    .addClass('fa-bars');
            }
        }
    );

});
</script>

</body>
</html>
