<?php

session_start();

require_once "../db.php";
require_once "backup_functions.php";
require_once "../includes/audit.php";
require_once __DIR__ . '/../license/license_guard.php';

/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'admin'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

/*
|--------------------------------------------------------------------------
| DATABASE INITIALIZATION
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
    | FETCH ADMIN PROFILE
    |--------------------------------------------------------------------------
    */

    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT username FROM admins WHERE id = ?"
    );

    if (!$stmt) {
        error_log(
            "Prepare failed for admin profile: " . $conn->error
        );

        die("Database error");
    }

    $stmt->bind_param("i", $admin_id);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$admin) {

        error_log(
            "No admin found for user_id=" . $admin_id
        );

        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | LOG PAGE ACCESS
    |--------------------------------------------------------------------------
    */

    $ip_address = filter_var(
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        FILTER_VALIDATE_IP
    ) ?: '0.0.0.0';

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $activity =
        "Admin {$admin['username']} accessed backup details page.";

    $stmt = $conn->prepare(
        "INSERT INTO activities_log
        (
            activity,
            admin_id,
            ip_address,
            user_agent,
            created_at
        )
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
        "Page error: " . $e->getMessage()
    );

    die("System error");
}

/*
|--------------------------------------------------------------------------
| VALIDATE BACKUP ID
|--------------------------------------------------------------------------
*/

if (
    !isset($_GET['id']) ||
    !is_numeric($_GET['id'])
) {
    exit("Invalid backup record.");
}

$id = (int) $_GET['id'];

/*
|--------------------------------------------------------------------------
| RETRIEVE BACKUP
|--------------------------------------------------------------------------
*/

$backup = getBackup($conn, $id);

if (!$backup) {
    exit("Backup record not found.");
}

/*
|--------------------------------------------------------------------------
| CHECK PHYSICAL FILE
|--------------------------------------------------------------------------
*/

$file =
    BACKUP_DIRECTORY .
    DIRECTORY_SEPARATOR .
    $backup['filename'];

$fileExists = file_exists($file);

$fileSize = $fileExists
    ? filesize($file)
    : 0;

/*
|--------------------------------------------------------------------------
| VERIFY BACKUP INTEGRITY
|--------------------------------------------------------------------------
*/

$integrityValid = false;

if (
    $fileExists &&
    !empty($backup['checksum'])
) {
    $integrityValid = verifyBackup(
        $file,
        $backup['checksum']
    );
}

/*
|--------------------------------------------------------------------------
| AJAX DETECTION
|--------------------------------------------------------------------------
*/

$isAjax =
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower(
        $_SERVER['HTTP_X_REQUESTED_WITH']
    ) === 'xmlhttprequest';


if (!$isAjax):

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Backup Details | Examcenter</title>

<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/all.css">
<link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../css/admin-dashboard.css">
<link rel="stylesheet" href="../css/dashboard.css">
<link rel="stylesheet" href="../css/view_results.css">
<link rel="stylesheet" href="../css/sidebar.css">

<style>

    /* =========================================================
       GLOBAL
    ========================================================= */

    html,
    body {
        min-height: 100%;
    }

    body {
        margin: 0;
        background: #f4f6f9;
        color: #212529;
        font-family: Arial, Helvetica, sans-serif;
    }


    /* =========================================================
       SIDEBAR
    ========================================================= */

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

        box-shadow:
            4px 0 15px rgba(0, 0, 0, .08);

        transition:
            transform .25s ease;
    }


    /* Sidebar branding */

    .sidebar-brand {
        padding: 22px 20px 18px;

        border-bottom:
            1px solid rgba(255,255,255,.08);
    }

    .sidebar-brand h3 {
        margin: 0;

        font-size: 20px;
        font-weight: 700;

        color: #fff;
    }


    .admin-info {
        margin-top: 18px;
    }

    .admin-info small {
        display: block;

        color:
            rgba(255,255,255,.55);

        font-size: 11px;
    }

    .admin-info h6 {
        margin: 4px 0 0;

        color: #fff;

        font-size: 13px;
    }


    /* =========================================================
       SIDEBAR MENU
    ========================================================= */

    .sidebar-menu {
        padding:
            15px 10px 20px;
    }

    .sidebar-menu a {
        display: flex;

        align-items: center;

        gap: 12px;

        padding:
            11px 13px;

        margin-bottom: 4px;

        color:
            rgba(255,255,255,.78);

        text-decoration: none;

        border-radius: 8px;

        font-size: 13px;

        transition:
            background .2s ease,
            color .2s ease;
    }

    .sidebar-menu a i {
        width: 20px;

        text-align: center;

        font-size: 14px;
    }

    .sidebar-menu a:hover {
        background:
            rgba(255,255,255,.08);

        color: #fff;
    }

    .sidebar-menu a.active {
        background: #0d6efd;
        color: #fff;
    }

    .sidebar-menu .logout-btn {
        margin-top: 20px;
        color: #ffb3b3;
    }

    .sidebar-menu .logout-btn:hover {
        background:
            rgba(220,53,69,.15);

        color: #ff6b6b;
    }


    /* =========================================================
       SIDEBAR OVERLAY
    ========================================================= */

    .sidebar-overlay {
        display: none;

        position: fixed;

        inset: 0;

        background:
            rgba(0,0,0,.45);

        z-index: 1040;
    }

    .sidebar-overlay.active {
        display: block;
    }


    /* =========================================================
       MAIN CONTENT
    ========================================================= */

    .main-content {
        min-height: 100vh;

        margin-left: 250px;
    }


    /* =========================================================
       BACKUP CONTAINER
    ========================================================= */

    .backup-detail-container {
        width: 100%;

        max-width: 1050px;

        margin: 0 auto;

        padding:
            30px 20px 50px;
    }


    /* =========================================================
       BACKUP CARD
    ========================================================= */

    .backup-detail-card {
        border: none;

        border-radius: 16px;

        overflow: hidden;

        background: #fff;

        box-shadow:
            0 6px 24px
            rgba(0,0,0,.07);
    }


    /* =========================================================
       HEADER
    ========================================================= */

    .backup-detail-header {

        padding:
            20px 24px;

        /*
         * Changed from blue so it does not look
         * like the sidebar.
         */

        background: #ffffff;

        color: #212529;

        border-bottom:
            1px solid #e9ecef;
    }


    .backup-header-content {
        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 15px;
    }


    .backup-header-left {
        display: flex;

        align-items: center;

        min-width: 0;
    }


    .backup-header-icon {

        width: 52px;
        height: 52px;

        border-radius: 13px;

        background:
            #eaf2ff;

        color:
            #0d6efd;

        display: flex;

        align-items: center;

        justify-content: center;

        font-size: 22px;

        flex-shrink: 0;
    }


    .backup-header-title {

        margin: 0;

        font-size: 19px;

        font-weight: 700;

        color: #212529;
    }


    .backup-header-subtitle {

        margin-top: 4px;

        font-size: 12px;

        color: #8a9198;
    }


    /* =========================================================
       MOBILE SIDEBAR TOGGLE
    ========================================================= */

    .sidebar-mobile-toggle {

        width: 42px;
        height: 42px;

        padding: 0;

        border: none;

        border-radius: 8px;

        background: #0d6efd;

        color: #fff;

        display: none;

        align-items: center;

        justify-content: center;

        font-size: 18px;

        flex-shrink: 0;

        cursor: pointer;

        box-shadow:
            0 3px 8px
            rgba(13,110,253,.25);

        transition:
            background .2s ease,
            transform .15s ease;
    }


    .sidebar-mobile-toggle:hover {
        background: #0b5ed7;
    }


    .sidebar-mobile-toggle:active {
        transform: scale(.95);
    }


    /* =========================================================
       FILE SUMMARY
    ========================================================= */

    .file-summary {

        padding:
            22px 24px;

        border-bottom:
            1px solid #edf0f2;

        background:
            #fbfcfe;
    }


    .file-summary-inner {

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 20px;
    }


    .file-summary-left {

        display: flex;

        align-items: center;

        gap: 14px;

        min-width: 0;
    }


    .file-summary-icon {

        width: 48px;
        height: 48px;

        border-radius: 11px;

        background: #eaf2ff;

        color: #0d6efd;

        display: flex;

        align-items: center;

        justify-content: center;

        flex-shrink: 0;

        font-size: 20px;
    }


    .file-summary-name {

        font-size: 14px;

        font-weight: 700;

        word-break: break-word;
    }


    .file-summary-meta {

        margin-top: 4px;

        color: #8a9198;

        font-size: 12px;
    }


    /* =========================================================
       STATUS
    ========================================================= */

    .status-panel {

        margin:
            22px 24px 0;

        border-radius: 12px;

        padding:
            17px 18px;

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 15px;
    }


    .status-panel.valid {

        background: #eaf8f0;

        border:
            1px solid #ccebd9;
    }


    .status-panel.invalid {

        background: #fff0f0;

        border:
            1px solid #f3cccc;
    }


    .status-left {

        display: flex;

        align-items: center;

        gap: 13px;
    }


    .status-icon {

        width: 42px;
        height: 42px;

        border-radius: 50%;

        display: flex;

        align-items: center;

        justify-content: center;

        flex-shrink: 0;
    }


    .status-panel.valid .status-icon {

        background: #198754;

        color: #fff;
    }


    .status-panel.invalid .status-icon {

        background: #dc3545;

        color: #fff;
    }


    .status-title {

        font-size: 14px;

        font-weight: 700;
    }


    .status-description {

        margin-top: 3px;

        font-size: 12px;

        color: #6c757d;
    }


    .status-badge {

        border-radius: 20px;

        padding:
            7px 11px;

        font-size: 11px;

        font-weight: 700;

        white-space: nowrap;
    }


    .status-panel.valid .status-badge {

        background: #198754;

        color: #fff;
    }


    .status-panel.invalid .status-badge {

        background: #dc3545;

        color: #fff;
    }


    /* =========================================================
       INFORMATION SECTION
    ========================================================= */

    .detail-section {

        padding: 24px;
    }


    .section-title {

        display: flex;

        align-items: center;

        gap: 9px;

        margin-bottom: 15px;

        font-size: 14px;

        font-weight: 700;

        color: #343a40;
    }


    .section-title-icon {

        color: #0d6efd;
    }


    .detail-grid {

        display: grid;

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        gap: 12px;
    }


    .detail-item {

        padding:
            15px 16px;

        border:
            1px solid #edf0f2;

        border-radius: 10px;

        background: #fff;

        min-width: 0;
    }


    .detail-label {

        display: flex;

        align-items: center;

        gap: 7px;

        color: #8a9198;

        font-size: 11px;

        font-weight: 700;

        text-transform: uppercase;

        letter-spacing: .35px;

        margin-bottom: 7px;
    }


    .detail-label i {

        font-size: 11px;
    }


    .detail-value {

        color: #212529;

        font-size: 13px;

        font-weight: 600;

        word-break: break-word;
    }


    .detail-value.muted {

        color: #8a9198;

        font-weight: 500;
    }


    /* =========================================================
       CHECKSUM
    ========================================================= */

    .checksum-box {

        margin-top: 12px;

        padding:
            15px 16px;

        border:
            1px solid #edf0f2;

        border-radius: 10px;

        background: #f8f9fa;
    }


    .checksum-label {

        color: #8a9198;

        font-size: 11px;

        font-weight: 700;

        text-transform: uppercase;

        letter-spacing: .35px;

        margin-bottom: 8px;
    }


    .checksum-value {

        display: block;

        padding:
            10px 11px;

        border-radius: 7px;

        background: #fff;

        border:
            1px solid #e5e7eb;

        color: #495057;

        font-family: monospace;

        font-size: 11px;

        line-height: 1.5;

        word-break: break-all;
    }


    /* =========================================================
       FOOTER
    ========================================================= */

    .backup-detail-footer {

        padding:
            17px 24px;

        border-top:
            1px solid #edf0f2;

        background:
            #fbfcfe;

        display: flex;

        justify-content: space-between;

        align-items: center;

        gap: 12px;
    }


    .footer-note {

        color: #8a9198;

        font-size: 11px;
    }


    .footer-actions {

        display: flex;

        gap: 8px;
    }


    .footer-actions .btn {

        border-radius: 8px;

        font-size: 12px;

        font-weight: 600;

        padding:
            8px 13px;
    }


    /* =========================================================
       TABLET / MOBILE
    ========================================================= */

    @media (max-width: 991.98px) {

        .sidebar {

            transform:
                translateX(-100%);
        }


        .sidebar.active {

            transform:
                translateX(0);
        }


        .main-content {

            margin-left: 0;
        }


        /*
         * Toggle remains on the RIGHT side.
         */

        .sidebar-mobile-toggle {

            display: flex;
        }

    }


    /* =========================================================
       MOBILE
    ========================================================= */

    @media (max-width: 768px) {

        .backup-detail-container {

            padding:
                15px 10px 30px;
        }


        .backup-detail-header {

            padding:
                16px;
        }


        .backup-header-content {

            align-items: center;
        }


        .backup-header-left {

            min-width: 0;
        }


        .backup-header-icon {

            width: 44px;
            height: 44px;

            font-size: 18px;
        }


        .backup-header-title {

            font-size: 17px;
        }


        .backup-header-subtitle {

            font-size: 11px;
        }


        .file-summary-inner {

            align-items:
                flex-start;

            flex-direction:
                column;
        }


        .status-panel {

            align-items:
                flex-start;

            flex-direction:
                column;
        }


        .detail-grid {

            grid-template-columns:
                1fr;
        }


        .backup-detail-footer {

            align-items:
                flex-start;

            flex-direction:
                column;
        }


        .footer-actions {

            width: 100%;
        }


        .footer-actions .btn {

            flex: 1;
        }

    }

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

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
            <b>
                <?= htmlspecialchars(
                    $admin['username']
                ) ?>
            </b>
        </h6>

    </div>

</div>


<div class="sidebar-menu">

    <a href="dashboard.php">
        <i class="fas fa-tachometer-alt"></i>
        Dashboard
    </a>

    <a
        href="add_question.php"
        style="text-decoration: line-through"
    >
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
        <i class="fas fa-user-plus"></i>
        Manage Session
    </a>

    <a href="manage_subject.php">
        <i class="fas fa-users"></i>
        Manage Subject
    </a>

    <a href="manage_teachers.php">
        <i class="fas fa-users"></i>
        Manage Teachers
    </a>

    <a href="manage_test.php">
        <i class="fas fa-users"></i>
        Manage Tests
    </a>
    <a href="backup_list.php">
        <i class="fas fa-database"></i>
        backup
    </a>

    <a href="settings.php">
        <i class="fas fa-cog"></i>
        Settings
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
     SIDEBAR OVERLAY
========================================================= -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">

<div class="backup-detail-container">

    <div class="backup-detail-card">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="backup-detail-header">

            <div class="backup-header-content">


                <!-- LEFT -->

                <div class="backup-header-left">

                    <div class="backup-header-icon me-3">

                        <i class="fas fa-database"></i>

                    </div>

                    <div>

                        <h5 class="backup-header-title">

                            Backup Details

                        </h5>

                        <div class="backup-header-subtitle">

                            Database backup information and
                            integrity verification

                        </div>

                    </div>

                </div>


                <!-- RIGHT / MOBILE TOGGLE -->

                <button
                    type="button"
                    class="sidebar-mobile-toggle"
                    id="sidebarToggle"
                    aria-label="Open navigation"
                    aria-expanded="false"
                >

                    <i class="fas fa-bars"></i>

                </button>


            </div>

        </div>


        <!-- =================================================
             FILE SUMMARY
        ================================================== -->

        <div class="file-summary">

            <div class="file-summary-inner">


                <div class="file-summary-left">

                    <div class="file-summary-icon">

                        <i class="fas fa-file-code"></i>

                    </div>

                    <div>

                        <div class="file-summary-name">

                            <?= htmlspecialchars(
                                $backup['filename']
                            ) ?>

                        </div>

                        <div class="file-summary-meta">

                            SQL Database Backup

                            ·

                            <?= $fileExists
                                ? formatFileSize($fileSize)
                                : 'File unavailable'
                            ?>

                        </div>

                    </div>

                </div>


                <div>

                    <span
                        class="badge bg-primary px-3 py-2"
                    >

                        <i
                            class="fas fa-<?=
                                strtolower(
                                    $backup['backup_type']
                                ) === 'automatic'
                                    ? 'clock'
                                    : 'hand-pointer'
                            ?> me-1"
                        ></i>

                        <?= htmlspecialchars(
                            ucfirst(
                                $backup['backup_type']
                            )
                        ) ?>

                    </span>

                </div>


            </div>

        </div>


        <!-- =================================================
             INTEGRITY STATUS
        ================================================== -->

        <?php if ($integrityValid): ?>

            <div class="status-panel valid">

                <div class="status-left">

                    <div class="status-icon">

                        <i class="fas fa-shield-alt"></i>

                    </div>

                    <div>

                        <div class="status-title">

                            Backup integrity verified

                        </div>

                        <div class="status-description">

                            The backup file exists and its
                            SHA-256 checksum matches the value
                            stored in the database.

                        </div>

                    </div>

                </div>


                <span class="status-badge">

                    <i class="fas fa-check me-1"></i>

                    VALID & VERIFIED

                </span>

            </div>


        <?php elseif (!$fileExists): ?>

            <div class="status-panel invalid">

                <div class="status-left">

                    <div class="status-icon">

                        <i class="fas fa-file-circle-xmark"></i>

                    </div>

                    <div>

                        <div class="status-title">

                            Backup file is missing

                        </div>

                        <div class="status-description">

                            The database record exists, but the
                            physical backup file could not be found.

                        </div>

                    </div>

                </div>


                <span class="status-badge">

                    <i class="fas fa-times me-1"></i>

                    FILE MISSING

                </span>

            </div>


        <?php else: ?>

            <div class="status-panel invalid">

                <div class="status-left">

                    <div class="status-icon">

                        <i class="fas fa-shield-alt"></i>

                    </div>

                    <div>

                        <div class="status-title">

                            Backup integrity verification failed

                        </div>

                        <div class="status-description">

                            The backup file exists, but its
                            checksum does not match the checksum
                            stored in the database.

                        </div>

                    </div>

                </div>


                <span class="status-badge">

                    <i class="fas fa-triangle-exclamation me-1"></i>

                    INTEGRITY FAILED

                </span>

            </div>

        <?php endif; ?>


        <!-- =================================================
             BACKUP INFORMATION
        ================================================== -->

        <div class="detail-section">

            <div class="section-title">

                <i
                    class="fas fa-circle-info section-title-icon"
                ></i>

                Backup Information

            </div>


            <div class="detail-grid">


                <!-- Backup ID -->

                <div class="detail-item">

                    <div class="detail-label">

                        <i class="fas fa-hashtag"></i>

                        Backup ID

                    </div>

                    <div class="detail-value">

                        #<?= (int) $backup['id'] ?>

                    </div>

                </div>


                <!-- File Size -->

                <div class="detail-item">

                    <div class="detail-label">

                        <i class="fas fa-hard-drive"></i>

                        File Size

                    </div>

                    <div class="detail-value">

                        <?php if ($fileExists): ?>

                            <?= formatFileSize($fileSize) ?>

                        <?php else: ?>

                            <span class="text-danger">

                                File unavailable

                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- Created By -->

                <div class="detail-item">

                    <div class="detail-label">

                        <i class="fas fa-user"></i>

                        Created By

                    </div>

                    <div class="detail-value">

                        <?= htmlspecialchars(
                            $backup['username'] ?? 'Unknown'
                        ) ?>

                    </div>

                </div>


                <!-- Created At -->

                <div class="detail-item">

                    <div class="detail-label">

                        <i class="fas fa-calendar"></i>

                        Created At

                    </div>

                    <div class="detail-value">

                        <?= date(
                            "d M Y, h:i:s A",
                            strtotime(
                                $backup['created_at']
                            )
                        ) ?>

                    </div>

                </div>


                <!-- Restore Count -->

                <div class="detail-item">

                    <div class="detail-label">

                        <i class="fas fa-rotate-left"></i>

                        Restore Count

                    </div>

                    <div class="detail-value">

                        <span class="badge bg-secondary">

                            <?= (int) (
                                $backup['restore_count'] ?? 0
                            ) ?>

                        </span>

                    </div>

                </div>


                <!-- Last Restored -->

                <div class="detail-item">

                    <div class="detail-label">

                        <i class="fas fa-clock-rotate-left"></i>

                        Last Restored

                    </div>

                    <div class="detail-value">

                        <?php if (
                            !empty(
                                $backup['restored_at']
                            )
                        ): ?>

                            <?= date(
                                "d M Y, h:i:s A",
                                strtotime(
                                    $backup['restored_at']
                                )
                            ) ?>

                        <?php else: ?>

                            <span
                                class="detail-value muted"
                            >
                                Never restored
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


            </div>


            <!-- =================================================
                 CHECKSUM
            ================================================== -->

            <div class="checksum-box">

                <div class="checksum-label">

                    <i
                        class="fas fa-fingerprint me-1"
                    ></i>

                    SHA-256 Checksum

                </div>

                <code class="checksum-value">

                    <?= htmlspecialchars(
                        $backup['checksum']
                        ?? 'Not available'
                    ) ?>

                </code>

            </div>

        </div>


        <!-- =================================================
             FOOTER
        ================================================== -->

        <div class="backup-detail-footer">


            <div class="footer-note">

                <i
                    class="fas fa-shield-alt me-1"
                ></i>

                Backup integrity is checked using SHA-256.

            </div>


            <div class="footer-actions">


                <?php if (
                    $fileExists &&
                    $integrityValid
                ): ?>

                    <a
                        href="download_backup.php?id=<?= (int) $backup['id'] ?>"
                        class="btn btn-primary"
                    >

                        <i
                            class="fas fa-download me-1"
                        ></i>

                        Download Backup

                    </a>

                <?php endif; ?>


                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >

                    <i
                        class="fas fa-times me-1"
                    ></i>

                    Close

                </button>


            </div>

        </div>


    </div>

</div>

</main>

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const sidebar =
            document.getElementById('sidebar');

        const sidebarToggle =
            document.getElementById('sidebarToggle');

        const sidebarOverlay =
            document.getElementById('sidebarOverlay');


        /*
        |--------------------------------------------------------------------------
        | OPEN / CLOSE SIDEBAR
        |--------------------------------------------------------------------------
        */

        sidebarToggle.addEventListener(
            'click',
            function () {

                const isOpen =
                    sidebar.classList.contains('active');

                if (isOpen) {

                    sidebar.classList.remove('active');

                    sidebarOverlay.classList.remove(
                        'active'
                    );

                    sidebarToggle.setAttribute(
                        'aria-expanded',
                        'false'
                    );

                } else {

                    sidebar.classList.add('active');

                    sidebarOverlay.classList.add(
                        'active'
                    );

                    sidebarToggle.setAttribute(
                        'aria-expanded',
                        'true'
                    );

                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | CLOSE WHEN OVERLAY IS CLICKED
        |--------------------------------------------------------------------------
        */

        sidebarOverlay.addEventListener(
            'click',
            function () {

                sidebar.classList.remove('active');

                sidebarOverlay.classList.remove(
                    'active'
                );

                sidebarToggle.setAttribute(
                    'aria-expanded',
                    'false'
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | CLOSE SIDEBAR AFTER NAVIGATION ON MOBILE
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll('.sidebar-menu a')
            .forEach(function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            window.innerWidth <= 991
                        ) {

                            sidebar.classList.remove(
                                'active'
                            );

                            sidebarOverlay.classList.remove(
                                'active'
                            );

                            sidebarToggle.setAttribute(
                                'aria-expanded',
                                'false'
                            );

                        }

                    }
                );

            });


        /*
        |--------------------------------------------------------------------------
        | RESET SIDEBAR WHEN SCREEN BECOMES DESKTOP
        |--------------------------------------------------------------------------
        */

        window.addEventListener(
            'resize',
            function () {

                if (window.innerWidth > 991) {

                    sidebar.classList.remove(
                        'active'
                    );

                    sidebarOverlay.classList.remove(
                        'active'
                    );

                    sidebarToggle.setAttribute(
                        'aria-expanded',
                        'false'
                    );

                }

            }
        );

    }
);

</script>

</body>

</html>

<?php endif; ?>
