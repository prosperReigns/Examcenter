<?php

session_start();

require_once "../db.php";
require_once "backup_functions.php";
require_once "../includes/audit.php";


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
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

} catch (Exception $e) {

    error_log("Database connection error: " . $e->getMessage());
    die("System error.");

}


/*
|--------------------------------------------------------------------------
| Retrieve Admin Profile
|--------------------------------------------------------------------------
*/

$admin_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT username FROM admins WHERE id = ?"
);

if (!$stmt) {
    error_log("Admin profile prepare failed: " . $conn->error);
    die("Database error.");
}

$stmt->bind_param("i", $admin_id);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();

$stmt->close();


if (!$admin) {

    session_destroy();

    header("Location: ../login.php?error=Unauthorized");
    exit;

}


/*
|--------------------------------------------------------------------------
| Retrieve Backups
|--------------------------------------------------------------------------
*/

$result = getAllBackups($conn);

$totalBackups = 0;
$totalStorage = 0;
$backups = [];


if ($result) {

    while ($row = $result->fetch_assoc()) {

        $backups[] = $row;

        $totalBackups++;

        $totalStorage += (int) ($row['file_size'] ?? 0);

    }

}


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function backupTypeBadge(string $type): string
{
    return match (strtolower($type)) {

        'manual'    => 'primary',
        'automatic' => 'success',
        'scheduled' => 'info',
        'emergency' => 'warning',

        default     => 'secondary'

    };
}


/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

$successMessage = $_SESSION['success'] ?? null;
$errorMessage   = $_SESSION['error'] ?? null;

unset(
    $_SESSION['success'],
    $_SESSION['error']
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

<title>Database Backups</title>


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
   PAGE
========================================================= */

html,
body {
    min-height: 100%;
}

body {

    margin: 0;

    background: #f4f6f9;

    color: #212529;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

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

}


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

    color: rgba(255,255,255,.55);

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

    padding: 15px 10px 20px;

}


.sidebar-menu a {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 11px 13px;

    margin-bottom: 4px;

    color: rgba(255,255,255,.78);

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


/* =========================================================
   MAIN CONTENT
========================================================= */

.main-content {

    min-height: 100vh;

    margin-left: 250px;

    transition:
        margin-left .25s ease;

}


/* =========================================================
   MOBILE / DESKTOP TOGGLE
========================================================= */

.sidebar-toggle {

    width: 42px;

    height: 42px;

    padding: 0;

    border: none;

    border-radius: 9px;

    background: #0d6efd;

    color: #fff;

    display: none;

    align-items: center;

    justify-content: center;

    font-size: 19px;

    box-shadow:
        0 3px 8px rgba(13,110,253,.25);

    cursor: pointer;

    transition:
        background .2s ease,
        transform .2s ease,
        box-shadow .2s ease;

}


.sidebar-toggle:hover {

    background: #0b5ed7;

    transform: translateY(-1px);

    box-shadow:
        0 5px 12px rgba(13,110,253,.30);

}


.sidebar-toggle:active {

    transform: translateY(0);

}


/* =========================================================
   BACKUP CONTAINER
========================================================= */

.backup-container {

    max-width: 1500px;

    margin: 0 auto;

    padding: 28px 20px 50px;

}


/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    margin-bottom: 28px;

}


.page-header-left {

    display: flex;

    align-items: center;

    gap: 15px;

}


.page-icon {

    width: 52px;

    height: 52px;

    border-radius: 14px;

    background: #e8f0ff;

    color: #0d6efd;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 22px;

}


.page-title {

    margin: 0;

    font-size: 25px;

    font-weight: 700;

}


.page-subtitle {

    margin: 5px 0 0;

    color: #6c757d;

    font-size: 14px;

}


.page-header-right {

    display: flex;

    align-items: center;

    gap: 10px;

}


.create-btn {

    border-radius: 9px;

    padding: 10px 17px;

    font-weight: 600;

    box-shadow:
        0 3px 8px rgba(25, 135, 84, .18);

}


/* =========================================================
   BACKUP FLASH MESSAGES
========================================================= */

.backup-alert {

    border-radius: 12px;

    padding: 16px 18px;

    margin-bottom: 24px;

    animation:
        slideDown .35s ease;

}


.alert-icon {

    width: 42px;

    height: 42px;

    min-width: 42px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;

}


.backup-alert .fw-bold {

    font-size: 15px;

}


.backup-alert .small {

    margin-top: 2px;

    color: #495057;

}


@keyframes slideDown {

    from {

        opacity: 0;

        transform:
            translateY(-10px);

    }

    to {

        opacity: 1;

        transform:
            translateY(0);

    }

}


/* =========================================================
   ALERTS
========================================================= */

.alert {

    border: none;

    border-radius: 10px;

    box-shadow:
        0 2px 8px rgba(0,0,0,.04);

}


/* =========================================================
   STAT CARDS
========================================================= */

.stat-card {

    border: none;

    border-radius: 14px;

    background: #fff;

    box-shadow:
        0 3px 14px rgba(0,0,0,.05);

    transition:
        all .2s ease;

    height: 100%;

}


.stat-card:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 7px 20px rgba(0,0,0,.08);

}


.stat-card .card-body {

    padding: 21px;

}


.stat-label {

    color: #6c757d;

    font-size: 13px;

    font-weight: 600;

    margin-bottom: 7px;

}


.stat-value {

    font-size: 25px;

    font-weight: 700;

    margin: 0;

}


.stat-description {

    color: #8a9198;

    font-size: 12px;

    margin-top: 5px;

}


.stat-icon {

    width: 46px;

    height: 46px;

    border-radius: 12px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 19px;

}


.icon-blue {

    background: #e8f0ff;

    color: #0d6efd;

}


.icon-green {

    background: #e7f7ef;

    color: #198754;

}


.icon-orange {

    background: #fff3df;

    color: #f59f00;

}


/* =========================================================
   BACKUP CARD
========================================================= */

.backup-card {

    border: none;

    border-radius: 14px;

    background: #fff;

    box-shadow:
        0 3px 14px rgba(0,0,0,.05);

    overflow: hidden;

}


.backup-card-header {

    padding: 18px 20px;

    border-bottom:
        1px solid #edf0f2;

    background: #fff;

}


.backup-card-title {

    font-size: 16px;

    font-weight: 700;

    margin: 0;

}


.backup-card-subtitle {

    color: #8a9198;

    font-size: 12px;

    margin-top: 3px;

}


/* =========================================================
   TABLE
========================================================= */

.backup-table {

    margin: 0;

}


.backup-table thead th {

    background: #f8f9fa;

    color: #6c757d;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .4px;

    font-weight: 700;

    padding: 13px 15px;

    border-bottom:
        1px solid #e9ecef;

    white-space: nowrap;

}


.backup-table tbody td {

    padding: 15px;

    border-bottom:
        1px solid #f0f1f3;

    vertical-align: middle;

}


.backup-table tbody tr:last-child td {

    border-bottom: none;

}


.backup-table tbody tr {

    transition:
        background .15s ease;

}


.backup-table tbody tr:hover {

    background: #fafbfc;

}


/* =========================================================
   FILENAME
========================================================= */

.filename-wrapper {

    display: flex;

    align-items: center;

    gap: 11px;

    min-width: 260px;

}


.file-icon {

    width: 38px;

    height: 38px;

    border-radius: 9px;

    background: #f0f4ff;

    color: #0d6efd;

    display: flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    transition:
        all .15s ease;

}


.filename {

    font-size: 13px;

    font-weight: 600;

    color: #212529;

    word-break: break-word;

}


.backup-record-link {

    text-decoration: none;

    color: inherit;

    display: block;

    cursor: pointer;

}


.backup-record-link:hover .filename {

    color: #0d6efd;

}


.backup-record-link:hover .file-icon {

    background: #e8f0ff;

    transform:
        scale(1.05);

}


.file-extension {

    display: block;

    color: #8a9198;

    font-size: 11px;

    margin-top: 2px;

}


/* =========================================================
   BADGES
========================================================= */

.backup-badge {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    border-radius: 20px;

    padding: 5px 9px;

    font-size: 11px;

    font-weight: 600;

}


/* =========================================================
   CHECKSUM
========================================================= */

.checksum-wrapper {

    display: flex;

    align-items: center;

    gap: 6px;

}


.checksum {

    font-family: monospace;

    font-size: 11px;

    color: #6c757d;

    background: #f8f9fa;

    padding: 5px 7px;

    border-radius: 5px;

}


/* =========================================================
   USER / DATE
========================================================= */

.created-user {

    font-size: 13px;

    font-weight: 600;

}


.created-date {

    font-size: 12px;

    color: #6c757d;

}


/* =========================================================
   ACTIONS
========================================================= */

.actions {

    display: flex;

    gap: 5px;

    align-items: center;

    white-space: nowrap;

}


.action-btn {

    width: 34px;

    height: 34px;

    padding: 0;

    border-radius: 8px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: none;

    transition:
        all .15s ease;

}


.action-btn:hover {

    transform:
        translateY(-1px);

}


.btn-view {

    background: #e8f4ff;

    color: #0d6efd;

}


.btn-download {

    background: #e8f0ff;

    color: #0d6efd;

}


.btn-restore {

    background: #fff3df;

    color: #f59f00;

}


.btn-delete {

    background: #fdeaea;

    color: #dc3545;

}


.action-form {

    display: inline;

    margin: 0;

}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {

    padding: 70px 20px;

    text-align: center;

}


.empty-icon {

    width: 70px;

    height: 70px;

    margin: 0 auto 18px;

    border-radius: 50%;

    background: #f0f2f5;

    color: #adb5bd;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 27px;

}


.empty-title {

    font-size: 17px;

    font-weight: 700;

    margin-bottom: 6px;

}


.empty-text {

    color: #8a9198;

    font-size: 13px;

}


/* =========================================================
   MODAL
========================================================= */

.modal-content {

    border: none;

    border-radius: 15px;

    overflow: hidden;

    box-shadow:
        0 15px 50px rgba(0,0,0,.15);

}


.modal-header {

    border-bottom:
        1px solid #edf0f2;

}


/* =========================================================
   RESPONSIVE SIDEBAR
========================================================= */

@media (max-width: 991.98px) {

    .sidebar {

        transform:
            translateX(-100%);

        transition:
            transform .25s ease;

    }


    .sidebar.active {

        transform:
            translateX(0);

    }


    .sidebar-overlay.active {

        display: block;

    }


    .main-content {

        margin-left: 0;

    }


    .sidebar-toggle {

        display: inline-flex;

    }

}


/* =========================================================
   TABLE RESPONSIVENESS
========================================================= */

@media (max-width: 992px) {

    .backup-table {

        min-width: 1000px;

    }

}


/* =========================================================
   SMALL SCREENS
========================================================= */

@media (max-width: 768px) {

    .backup-container {

        padding:
            20px
            12px
            40px;

    }


    .page-header {

        align-items: flex-start;

        flex-direction: column;

    }


    .page-header-right {

        width: 100%;

        justify-content: flex-end;

    }


    .create-btn {

        flex: 1;

    }


    .page-title {

        font-size: 21px;

    }


    .sidebar-toggle {

        width: 42px;

        height: 42px;

    }

}

</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<div
    class="sidebar"
    id="sidebar"
>

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
                    <?= htmlspecialchars($admin['username']) ?>
                </b>
            </h6>

        </div>

    </div>


    <div class="sidebar-menu mt-4">

        <a href="../admin/dashboard.php">

            <i class="fas fa-tachometer-alt"></i>

            Dashboard

        </a>


        <a
            href="../admin/add_question.php"
            style="text-decoration: line-through"
        >

            <i class="fas fa-plus-circle"></i>

            Add Questions

        </a>


        <a href="../admin/view_questions.php">

            <i class="fas fa-list"></i>

            View Questions

        </a>


        <a href="../admin/view_results.php">

            <i class="fas fa-chart-bar"></i>

            Exam Results

        </a>


        <a href="../admin/add_teacher.php">

            <i class="fas fa-user-plus"></i>

            Add Teachers

        </a>


        <a href="../admin/manage_classes.php">

            <i class="fas fa-users"></i>

            Manage Classes

        </a>


        <a href="../admin/manage_session.php">

            <i class="fas fa-calendar"></i>

            Manage Session

        </a>


        <a href="../admin/manage_subject.php">

            <i class="fas fa-book"></i>

            Manage Subject

        </a>


        <a href="../admin/manage_teachers.php">

            <i class="fas fa-users"></i>

            Manage Teachers

        </a>


        <a href="../admin/manage_test.php">

            <i class="fas fa-file-alt"></i>

            Manage Tests

        </a>
        <a href="backup_list.php" class="active">
            <i class="fas fa-database"></i>
            backup
        </a>

        <a href="../admin/settings.php">

            <i class="fas fa-cog"></i>

            Settings

        </a>


        <a
            href="../admin/logout.php"
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


<div class="backup-container">


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="page-header">


    <div class="page-header-left">

        <div class="page-icon">

            <i class="fas fa-database"></i>

        </div>


        <div>

            <h1 class="page-title">

                Database Backups

            </h1>


            <p class="page-subtitle">

                Manage, restore and download your database backups.

            </p>

        </div>

    </div>


    <!-- =====================================================
         RIGHT SIDE CONTROLS
    ====================================================== -->

    <div class="page-header-right">


        <a
            href="create_backup.php"
            class="btn btn-success create-btn"
        >

            <i class="fas fa-plus me-1"></i>

            Create Backup

        </a>


        <!-- =================================================
             SIDEBAR TOGGLE

             This is intentionally on the RIGHT.
        ================================================== -->

        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Open navigation"
            aria-expanded="false"
        >

            <i class="fas fa-bars"></i>

        </button>

    </div>

</div>


<!-- =========================================================
     FLASH MESSAGES
========================================================= -->


<?php if ($successMessage): ?>

<div
    class="alert alert-success border-0 shadow-sm alert-dismissible fade show backup-alert"
    role="alert"
>

    <div class="d-flex align-items-center">

        <div class="alert-icon bg-success text-white">

            <i class="fas fa-check"></i>

        </div>


        <div class="ms-3">

            <div class="fw-bold">

                Backup Successful

            </div>


            <div class="small">

                <?= htmlspecialchars(strip_tags($successMessage)) ?>

            </div>

        </div>

    </div>


    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<?php if ($errorMessage): ?>

<div
    class="alert alert-danger border-0 shadow-sm alert-dismissible fade show backup-alert"
    role="alert"
>

    <div class="d-flex align-items-center">

        <div class="alert-icon bg-danger text-white">

            <i class="fas fa-exclamation-triangle"></i>

        </div>


        <div class="ms-3">

            <div class="fw-bold">

                Backup Failed

            </div>


            <div class="small">

                <?= htmlspecialchars(strip_tags($errorMessage)) ?>

            </div>

        </div>

    </div>


    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<!-- =========================================================
     STATISTICS
========================================================= -->

<div class="row g-3 mb-4">


    <!-- TOTAL BACKUPS -->

    <div class="col-lg-4 col-md-6">

        <div class="card stat-card">

            <div class="card-body">

                <div class="d-flex justify-content-between align-items-start">

                    <div>

                        <div class="stat-label">

                            TOTAL BACKUPS

                        </div>


                        <h2 class="stat-value">

                            <?= $totalBackups ?>

                        </h2>


                        <div class="stat-description">

                            Backup files currently available

                        </div>

                    </div>


                    <div class="stat-icon icon-blue">

                        <i class="fas fa-copy"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- STORAGE -->

    <div class="col-lg-4 col-md-6">

        <div class="card stat-card">

            <div class="card-body">

                <div class="d-flex justify-content-between align-items-start">

                    <div>

                        <div class="stat-label">

                            STORAGE USED

                        </div>


                        <h2 class="stat-value">

                            <?= formatFileSize($totalStorage) ?>

                        </h2>


                        <div class="stat-description">

                            Total disk space occupied

                        </div>

                    </div>


                    <div class="stat-icon icon-green">

                        <i class="fas fa-hard-drive"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- LATEST BACKUP -->

    <div class="col-lg-4 col-md-12">

        <div class="card stat-card">

            <div class="card-body">

                <div class="d-flex justify-content-between align-items-start">

                    <div>

                        <div class="stat-label">

                            LATEST BACKUP

                        </div>


                        <h2 class="stat-value">

                            <?= $totalBackups
                                ? date(
                                    "d M Y",
                                    strtotime(
                                        $backups[0]['created_at']
                                    )
                                )
                                : "None"
                            ?>

                        </h2>


                        <div class="stat-description">

                            <?= $totalBackups
                                ? date(
                                    "H:i",
                                    strtotime(
                                        $backups[0]['created_at']
                                    )
                                ) . " — Most recent backup"
                                : "No backups created yet"
                            ?>

                        </div>

                    </div>


                    <div class="stat-icon icon-orange">

                        <i class="fas fa-clock"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     BACKUPS TABLE
========================================================= -->

<div class="backup-card">


    <div class="backup-card-header">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <h5 class="backup-card-title">

                    <i class="fas fa-box-archive text-primary me-2"></i>

                    Available Backups

                </h5>


                <div class="backup-card-subtitle">

                    View and manage stored database backups

                </div>

            </div>


            <?php if ($totalBackups > 0): ?>

                <span class="badge bg-light text-dark border">

                    <?= $totalBackups ?>

                    <?= $totalBackups === 1
                        ? 'Backup'
                        : 'Backups'
                    ?>

                </span>

            <?php endif; ?>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table backup-table">

            <thead>

                <tr>

                    <th width="50">#</th>

                    <th>Backup File</th>

                    <th>Type</th>

                    <th>Size</th>

                    <th>Checksum</th>

                    <th>Created By</th>

                    <th>Date</th>

                    <th width="175">Actions</th>

                </tr>

            </thead>


            <tbody>


            <?php if (empty($backups)): ?>

                <tr>

                    <td colspan="8">

                        <div class="empty-state">

                            <div class="empty-icon">

                                <i class="fas fa-database"></i>

                            </div>


                            <div class="empty-title">

                                No backups available

                            </div>


                            <div class="empty-text">

                                Create your first database backup to protect your data.

                            </div>


                            <a
                                href="create_backup.php"
                                class="btn btn-primary btn-sm mt-3"
                            >

                                <i class="fas fa-plus me-1"></i>

                                Create Backup

                            </a>

                        </div>

                    </td>

                </tr>


            <?php else: ?>


                <?php $count = 1; ?>


                <?php foreach ($backups as $backup): ?>


                    <tr>


                        <!-- NUMBER -->

                        <td class="text-muted fw-semibold">

                            <?= $count++ ?>

                        </td>


                        <!-- FILENAME -->

                        <td>

                            <a
                                href="backup_detail.php?id=<?= (int) $backup['id'] ?>"
                                class="backup-record-link viewBackup"
                                data-id="<?= (int) $backup['id'] ?>"
                                title="View backup details"
                            >

                                <div class="filename-wrapper">


                                    <div class="file-icon">

                                        <i class="fas fa-file-code"></i>

                                    </div>


                                    <div>

                                        <div class="filename">

                                            <?= htmlspecialchars(
                                                $backup['filename']
                                            ) ?>

                                        </div>


                                        <span class="file-extension">

                                            SQL Database Backup · Click to view details

                                        </span>

                                    </div>

                                </div>

                            </a>

                        </td>


                        <!-- TYPE -->

                        <td>

                            <?php

                            $badgeColor =
                                backupTypeBadge(
                                    $backup['backup_type']
                                );

                            ?>


                            <span
                                class="backup-badge bg-<?= $badgeColor ?> bg-opacity-10 text-<?= $badgeColor ?>"
                            >

                                <i
                                    class="fas fa-circle"
                                    style="font-size:5px"
                                ></i>


                                <?= ucfirst(
                                    htmlspecialchars(
                                        $backup['backup_type']
                                    )
                                ) ?>

                            </span>

                        </td>


                        <!-- SIZE -->

                        <td>

                            <span class="fw-semibold">

                                <?= formatFileSize(
                                    (int) $backup['file_size']
                                ) ?>

                            </span>

                        </td>


                        <!-- CHECKSUM -->

                        <td>

                            <div class="checksum-wrapper">

                                <span
                                    class="checksum"
                                    title="<?= htmlspecialchars(
                                        $backup['checksum']
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        substr(
                                            $backup['checksum'],
                                            0,
                                            16
                                        )
                                    ) ?>...

                                </span>


                                <button
                                    type="button"
                                    class="btn btn-sm p-0 text-muted copyChecksum"
                                    data-checksum="<?= htmlspecialchars(
                                        $backup['checksum']
                                    ) ?>"
                                    title="Copy checksum"
                                >

                                    <i class="fas fa-copy"></i>

                                </button>

                            </div>

                        </td>


                        <!-- CREATED BY -->

                        <td>

                            <div class="created-user">

                                <i class="fas fa-user text-muted me-1"></i>

                                <?= htmlspecialchars(
                                    $backup['username']
                                    ?? 'Unknown'
                                ) ?>

                            </div>

                        </td>


                        <!-- DATE -->

                        <td>

                            <div class="created-user">

                                <?= date(
                                    "d M Y",
                                    strtotime(
                                        $backup['created_at']
                                    )
                                ) ?>

                            </div>


                            <div class="created-date">

                                <?= date(
                                    "H:i",
                                    strtotime(
                                        $backup['created_at']
                                    )
                                ) ?>

                            </div>

                        </td>


                        <!-- ACTIONS -->

                        <td>

                            <div class="actions">


                                <!-- VIEW -->

                                <button
                                    type="button"
                                    class="action-btn btn-view viewBackup"
                                    data-id="<?= (int) $backup['id'] ?>"
                                    title="View Details"
                                >

                                    <i class="fas fa-eye"></i>

                                </button>


                                <!-- DOWNLOAD -->

                                <a
                                    href="download_backup.php?id=<?= (int) $backup['id'] ?>"
                                    class="action-btn btn-download"
                                    title="Download Backup"
                                >

                                    <i class="fas fa-download"></i>

                                </a>


                                <!-- RESTORE -->

                                <form
                                    action="restore_backup.php"
                                    method="post"
                                    class="action-form"
                                >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $backup['id'] ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="action-btn btn-restore"
                                        title="Restore Backup"
                                        onclick="return confirm(
                                            'Restore this backup? Current database will be overwritten.'
                                        )"
                                    >

                                        <i class="fas fa-rotate-left"></i>

                                    </button>

                                </form>


                                <!-- DELETE -->

                                <form
                                    action="delete_backup.php"
                                    method="POST"
                                    class="action-form"
                                >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $backup['id'] ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="action-btn btn-delete"
                                        title="Delete Backup"
                                        onclick="return confirm(
                                            'Delete this backup permanently?'
                                        )"
                                    >

                                        <i class="fas fa-trash"></i>

                                    </button>

                                </form>


                            </div>

                        </td>

                    </tr>


                <?php endforeach; ?>


            <?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


</div>

</main>


<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>


<script>

/*
|--------------------------------------------------------------------------
| Sidebar Toggle
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const sidebar =
            document.getElementById("sidebar");

        const sidebarToggle =
            document.getElementById("sidebarToggle");

        const sidebarOverlay =
            document.getElementById("sidebarOverlay");


        function openSidebar() {

            sidebar.classList.add("active");

            sidebarOverlay.classList.add("active");

            sidebarToggle.setAttribute(
                "aria-expanded",
                "true"
            );

        }


        function closeSidebar() {

            sidebar.classList.remove("active");

            sidebarOverlay.classList.remove("active");

            sidebarToggle.setAttribute(
                "aria-expanded",
                "false"
            );

        }


        function toggleSidebar() {

            if (
                sidebar.classList.contains("active")
            ) {

                closeSidebar();

            } else {

                openSidebar();

            }

        }


        sidebarToggle.addEventListener(
            "click",
            function (event) {

                event.preventDefault();

                event.stopPropagation();

                toggleSidebar();

            }
        );


        sidebarOverlay.addEventListener(
            "click",
            function () {

                closeSidebar();

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Close Sidebar When Menu Item Is Clicked
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll(".sidebar-menu a")
            .forEach(function (link) {

                link.addEventListener(
                    "click",
                    function () {

                        if (
                            window.innerWidth <= 991
                        ) {

                            closeSidebar();

                        }

                    }
                );

            });


        /*
        |--------------------------------------------------------------------------
        | Keep Sidebar Closed When Returning To Desktop
        |--------------------------------------------------------------------------
        */

        window.addEventListener(
            "resize",
            function () {

                if (
                    window.innerWidth > 991
                ) {

                    sidebar.classList.remove("active");

                    sidebarOverlay.classList.remove("active");

                    sidebarToggle.setAttribute(
                        "aria-expanded",
                        "false"
                    );

                }

            }
        );

    }
);


/*
|--------------------------------------------------------------------------
| Bootstrap Tooltips
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('[title]')
    .forEach(function (el) {

        new bootstrap.Tooltip(el);

    });


/*
|--------------------------------------------------------------------------
| Copy Checksum
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(".copyChecksum")
    .forEach(function (button) {

        button.addEventListener(
            "click",
            function () {

                const checksum =
                    this.dataset.checksum;


                navigator.clipboard
                    .writeText(checksum)
                    .then(() => {

                        const icon =
                            this.querySelector("i");


                        icon.classList.remove(
                            "fa-copy"
                        );

                        icon.classList.add(
                            "fa-check"
                        );


                        setTimeout(
                            () => {

                                icon.classList.remove(
                                    "fa-check"
                                );

                                icon.classList.add(
                                    "fa-copy"
                                );

                            },
                            1500
                        );

                    });

            }
        );

    });


/*
|--------------------------------------------------------------------------
| Backup Details Modal
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    function () {


        const modalElement =
            document.getElementById(
                "backupModal"
            );


        const modal =
            new bootstrap.Modal(
                modalElement
            );


        const backupContent =
            document.getElementById(
                "backupContent"
            );


        document
            .querySelectorAll(".viewBackup")
            .forEach(function (button) {


                button.addEventListener(
                    "click",
                    function (event) {

                        event.preventDefault();


                        const backupId =
                            this.dataset.id;


                        /*
                        |--------------------------------------------------------------------------
                        | Reset Modal
                        |--------------------------------------------------------------------------
                        */

                        backupContent.innerHTML = `

                            <div class="text-center p-5">

                                <div class="spinner-border text-primary"></div>

                                <p class="mt-3 text-muted mb-0">

                                    Loading backup details...

                                </p>

                            </div>

                        `;


                        /*
                        |--------------------------------------------------------------------------
                        | Show Modal
                        |--------------------------------------------------------------------------
                        */

                        modal.show();


                        /*
                        |--------------------------------------------------------------------------
                        | Load Backup Details
                        |--------------------------------------------------------------------------
                        */

                        fetch(
                            "backup_detail.php?id=" +
                            encodeURIComponent(
                                backupId
                            ),
                            {
                                headers: {
                                    "X-Requested-With":
                                        "XMLHttpRequest"
                                }
                            }
                        )

                        .then(
                            function (response) {

                                if (!response.ok) {

                                    throw new Error(
                                        "Unable to load backup details."
                                    );

                                }

                                return response.text();

                            }
                        )

                        .then(
                            function (html) {

                                backupContent.innerHTML =
                                    html;

                            }
                        )

                        .catch(
                            function (error) {

                                backupContent.innerHTML = `

                                    <div class="modal-header">

                                        <h5 class="modal-title text-danger">

                                            <i class="fas fa-exclamation-triangle me-2"></i>

                                            Error

                                        </h5>


                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                        ></button>

                                    </div>


                                    <div class="modal-body">

                                        <div class="alert alert-danger mb-0">

                                            ${error.message}

                                        </div>

                                    </div>

                                `;

                            }
                        );

                    }
                );

            });

    }
);

</script>


</body>

</html>