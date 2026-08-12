<?php

session_start();

require_once "../db.php";
require_once "../includes/audit.php";
require_once __DIR__ . '/../license/license_guard.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, role FROM super_admins WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $super_admin = $result->fetch_assoc();
    $stmt->close();

    if (!$super_admin || strtolower($super_admin['role']) !== 'super_admin') {
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }
} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}

/*
|--------------------------------------------------------------------------
| Validate Audit ID
|--------------------------------------------------------------------------
*/

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit("Invalid audit record.");
}

$id = (int) $_GET['id'];

/*
|--------------------------------------------------------------------------
| Fetch Audit Record
|--------------------------------------------------------------------------
*/

$audit = getAuditById($conn, $id);

if (!$audit) {
    exit("Audit record not found.");
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function auditActionClass(string $action): string
{
    $action = strtolower(trim($action));

    if (
        str_contains($action, 'delete') ||
        str_contains($action, 'remove') ||
        str_contains($action, 'fail') ||
        str_contains($action, 'error')
    ) {
        return 'danger';
    }

    if (
        str_contains($action, 'login') ||
        str_contains($action, 'create') ||
        str_contains($action, 'add') ||
        str_contains($action, 'activate') ||
        str_contains($action, 'success')
    ) {
        return 'success';
    }

    if (
        str_contains($action, 'update') ||
        str_contains($action, 'edit') ||
        str_contains($action, 'change') ||
        str_contains($action, 'restore')
    ) {
        return 'warning';
    }

    return 'primary';
}

function auditActionIcon(string $class): string
{
    return match ($class) {
        'danger'  => 'fa-exclamation-triangle',
        'success' => 'fa-check-circle',
        'warning' => 'fa-edit',
        default   => 'fa-bolt'
    };
}

function getInitial(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return '?';
    }

    return strtoupper(substr($name, 0, 1));
}

/*
|--------------------------------------------------------------------------
| AJAX Detection
|--------------------------------------------------------------------------
*/

$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

$actionClass = auditActionClass(
    $audit['action'] ?? ''
);

$actionIcon = auditActionIcon($actionClass);

$username = $audit['username'] ?? 'Unknown';

$initial = getInitial($username);

$createdAt = !empty($audit['created_at'])
    ? strtotime($audit['created_at'])
    : false;

?>

<?php if (!$isAjax): ?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"

>

<title>Audit Details | Examcenter</title>

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
    href="../assets/fontawesome/css/all.min.css"
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
    href="../css/view_results.css"
>

<link
    rel="stylesheet"
    href="../css/sidebar.css"
>

<style>

/* =========================================================
   VARIABLES
========================================================= */

:root {

    --primary: #4361ee;
    --primary-dark: #3651d4;

    --success: #16a34a;
    --warning: #f59e0b;
    --danger: #dc2626;
    --info: #0891b2;

    --dark: #172033;
    --muted: #64748b;

    --border: #e2e8f0;

    --background: #f5f7fb;

    --sidebar-width: 250px;

    --card-radius: 14px;
}


/* =========================================================
   GLOBAL
========================================================= */

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

    background: var(--background);

    color: #1e293b;

    font-family:
        Inter,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}


/* =========================================================
   SIDEBAR
   Matches the existing Examcenter sidebar structure
========================================================= */

#sidebar {

    position: fixed;

    top: 0;
    left: 0;

    width: var(--sidebar-width);

    height: 100vh;

    background: #212529;

    color: #fff;

    z-index: 1100;

    overflow-y: auto;

    transition:
        transform .25s ease,
        box-shadow .25s ease;
}


/*
|--------------------------------------------------------------------------
| Sidebar Scrollbar
|--------------------------------------------------------------------------
*/

#sidebar::-webkit-scrollbar {
    width: 5px;
}

#sidebar::-webkit-scrollbar-track {
    background: #212529;
}

#sidebar::-webkit-scrollbar-thumb {
    background: #495057;
    border-radius: 10px;
}


/*
|--------------------------------------------------------------------------
| Sidebar Menu
|--------------------------------------------------------------------------
*/

.sidebar-menu {

    list-style: none;

    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    margin: 0;
    padding: 0;
}

.sidebar-menu a {

    display: flex;

    align-items: center;

    gap: 12px;

    color: rgba(255,255,255,.78);

    text-decoration: none;

    padding: 11px 18px;

    font-size: 14px;

    transition:
        background .18s ease,
        color .18s ease;
}

.sidebar-menu a:hover {

    background: rgba(255,255,255,.08);

    color: #fff;
}

.sidebar-menu a.active {

    background: rgba(67,97,238,.95);

    color: #fff;
}

.sidebar-menu a i {

    width: 20px;

    text-align: center;

    font-size: 14px;
}


/*
|--------------------------------------------------------------------------
| Sidebar Overlay
|--------------------------------------------------------------------------
*/

#sidebarOverlay {

    display: none;

    position: fixed;

    inset: 0;

    background: rgba(15,23,42,.55);

    z-index: 1050;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.main-content {

    min-height: 100vh;

    margin-left: var(--sidebar-width);

    transition:
        margin-left .25s ease;
}


/* =========================================================
   HEADER
========================================================= */

.audit-header {

    background: #fff;

    border-bottom: 1px solid var(--border);

    padding: 18px 28px;

    position: sticky;

    top: 0;

    z-index: 900;
}

.audit-header-content {

    max-width: 1500px;

    margin: 0 auto;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;
}


/*
|--------------------------------------------------------------------------
| Header Left
|--------------------------------------------------------------------------
*/

.header-title {

    display: flex;

    align-items: center;

    gap: 13px;
}

.header-title-icon {

    width: 44px;

    height: 44px;

    border-radius: 11px;

    display: flex;

    align-items: center;

    justify-content: center;

    background: rgba(67,97,238,.10);

    color: var(--primary);

    font-size: 18px;
}

.header-title h1 {

    margin: 0;

    font-size: 20px;

    font-weight: 700;

    color: var(--dark);
}

.header-title p {

    margin: 3px 0 0;

    font-size: 12px;

    color: var(--muted);
}


/*
|--------------------------------------------------------------------------
| Right Header Actions
|--------------------------------------------------------------------------
*/

.header-actions {

    display: flex;

    align-items: center;

    gap: 9px;
}


/*
|--------------------------------------------------------------------------
| Sidebar Toggle
|
| IMPORTANT:
| Toggle is intentionally on the RIGHT side.
| It remains visible when sidebar is open.
|--------------------------------------------------------------------------
*/

.sidebar-mobile-toggle {

    width: 42px;

    height: 42px;

    border: 0;

    border-radius: 9px;

    background: #0d6efd;

    color: #fff;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;

    cursor: pointer;

    box-shadow:
        0 4px 12px rgba(13,110,253,.22);

    transition:
        background .18s ease,
        transform .18s ease;
}

.sidebar-mobile-toggle:hover {

    background: #0b5ed7;

    transform: translateY(-1px);
}

.sidebar-mobile-toggle:focus {

    outline: none;

    box-shadow:
        0 0 0 3px rgba(13,110,253,.20);
}


/* =========================================================
   PAGE BODY
========================================================= */

.page-wrapper {

    max-width: 1500px;

    margin: 0 auto;

    padding: 28px;
}


/* =========================================================
   BREADCRUMB / BACK
========================================================= */

.page-navigation {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-bottom: 22px;
}

.back-link {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    color: #64748b;

    text-decoration: none;

    font-size: 13px;

    font-weight: 600;
}

.back-link:hover {

    color: var(--primary);
}


/* =========================================================
   AUDIT HERO
========================================================= */

.audit-hero {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: var(--card-radius);

    box-shadow:
        0 3px 12px rgba(15,23,42,.04);

    padding: 24px;

    margin-bottom: 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;
}

.audit-identity {

    display: flex;

    align-items: center;

    gap: 15px;
}

.audit-icon {

    width: 58px;

    height: 58px;

    border-radius: 15px;

    background: rgba(67,97,238,.10);

    color: var(--primary);

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 23px;

    flex-shrink: 0;
}

.audit-hero-title {

    margin: 0 0 4px;

    font-size: 20px;

    font-weight: 700;

    color: var(--dark);
}

.audit-hero-subtitle {

    margin: 0;

    color: var(--muted);

    font-size: 13px;
}

.audit-record-id {

    text-align: right;
}

.audit-record-id span {

    display: block;

    color: #94a3b8;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .05em;

    font-weight: 700;

    margin-bottom: 3px;
}

.audit-record-id strong {

    color: var(--dark);

    font-size: 16px;
}


/* =========================================================
   CONTENT GRID
========================================================= */

.audit-grid {

    display: grid;

    grid-template-columns:
        minmax(0, 1.7fr)
        minmax(280px, .8fr);

    gap: 20px;

    align-items: start;
}


/* =========================================================
   PANELS
========================================================= */

.audit-panel {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: var(--card-radius);

    box-shadow:
        0 3px 12px rgba(15,23,42,.04);

    overflow: hidden;

    margin-bottom: 20px;
}

.audit-panel:last-child {
    margin-bottom: 0;
}

.audit-panel-header {

    padding: 16px 20px;

    border-bottom: 1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;
}

.audit-panel-title {

    display: flex;

    align-items: center;

    gap: 9px;

    color: var(--dark);

    font-size: 14px;

    font-weight: 700;
}

.audit-panel-title i {

    color: var(--primary);
}


/* =========================================================
   INFORMATION LIST
========================================================= */

.audit-info-list {

    margin: 0;

    padding: 0;

    list-style: none;
}

.audit-info-row {

    display: grid;

    grid-template-columns: 170px minmax(0, 1fr);

    gap: 20px;

    padding: 16px 20px;

    border-bottom: 1px solid #eef2f7;
}

.audit-info-row:last-child {
    border-bottom: 0;
}

.audit-info-label {

    display: flex;

    align-items: center;

    gap: 8px;

    color: #64748b;

    font-size: 12px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .035em;
}

.audit-info-label i {

    width: 17px;

    color: #94a3b8;

    text-align: center;
}

.audit-info-value {

    color: #334155;

    font-size: 13px;

    line-height: 1.6;

    word-break: break-word;
}


/* =========================================================
   ADMINISTRATOR
========================================================= */

.admin-profile {

    display: flex;

    align-items: center;

    gap: 10px;
}

.admin-avatar {

    width: 36px;

    height: 36px;

    border-radius: 50%;

    background: rgba(67,97,238,.10);

    color: var(--primary);

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 13px;

    font-weight: 800;

    flex-shrink: 0;
}

.admin-name {

    color: #334155;

    font-weight: 600;
}


/* =========================================================
   MODULE BADGE
========================================================= */

.module-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 10px;

    border-radius: 7px;

    background: #ecfeff;

    color: #0e7490;

    font-size: 11px;

    font-weight: 700;
}


/* =========================================================
   ACTION BADGE
========================================================= */

.action-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 7px 11px;

    border-radius: 8px;

    font-size: 11px;

    font-weight: 700;
}

.action-badge.primary {

    background: #eef2ff;

    color: #4338ca;
}

.action-badge.success {

    background: #ecfdf5;

    color: #15803d;
}

.action-badge.warning {

    background: #fffbeb;

    color: #b45309;
}

.action-badge.danger {

    background: #fef2f2;

    color: #b91c1c;
}


/* =========================================================
   DESCRIPTION
========================================================= */

.description-box {

    margin: 20px;

    padding: 18px;

    background: #f8fafc;

    border: 1px solid #e2e8f0;

    border-radius: 10px;

    color: #475569;

    font-size: 13px;

    line-height: 1.7;

    white-space: normal;

    overflow-wrap: anywhere;
}


/* =========================================================
   SYSTEM DETAILS
========================================================= */

.system-detail {

    display: flex;

    align-items: flex-start;

    gap: 12px;

    padding: 15px 20px;

    border-bottom: 1px solid #eef2f7;
}

.system-detail:last-child {
    border-bottom: 0;
}

.system-detail-icon {

    width: 35px;

    height: 35px;

    border-radius: 9px;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #f1f5f9;

    color: #64748b;

    flex-shrink: 0;
}

.system-detail-content {

    min-width: 0;
}

.system-detail-label {

    color: #94a3b8;

    font-size: 10px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .05em;

    margin-bottom: 3px;
}

.system-detail-value {

    color: #334155;

    font-size: 13px;

    line-height: 1.5;

    word-break: break-word;
}


/* =========================================================
   TIMESTAMP CARD
========================================================= */

.timestamp-card {

    padding: 20px;
}

.timestamp-icon {

    width: 42px;

    height: 42px;

    border-radius: 10px;

    background: rgba(67,97,238,.10);

    color: var(--primary);

    display: flex;

    align-items: center;

    justify-content: center;

    margin-bottom: 12px;
}

.timestamp-label {

    color: #94a3b8;

    font-size: 11px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .05em;

    margin-bottom: 4px;
}

.timestamp-date {

    color: #334155;

    font-weight: 700;

    font-size: 14px;
}

.timestamp-time {

    color: #64748b;

    font-size: 12px;

    margin-top: 2px;
}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.audit-actions {

    display: flex;

    justify-content: flex-end;

    gap: 9px;

    margin-top: 20px;
}

.audit-actions .btn {

    border-radius: 9px;

    font-size: 13px;

    font-weight: 600;

    padding: 9px 15px;
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
   MODAL / AJAX MODE
========================================================= */

.ajax-audit-wrapper {

    padding: 0;

    background: #fff;
}

.ajax-audit-header {

    padding: 18px 20px;

    border-bottom: 1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: space-between;
}

.ajax-audit-header-title {

    display: flex;

    align-items: center;

    gap: 9px;

    color: var(--dark);

    font-size: 16px;

    font-weight: 700;
}

.ajax-audit-header-title i {

    color: var(--primary);
}

.ajax-audit-body {

    padding: 0;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .audit-grid {

        grid-template-columns: 1fr;
    }

}


@media (max-width: 900px) {

    #sidebar {

        transform: translateX(-100%);

        box-shadow: none;
    }

    #sidebar.sidebar-open {

        transform: translateX(0);

        box-shadow:
            8px 0 30px rgba(15,23,42,.18);
    }

    #sidebarOverlay.sidebar-overlay-visible {

        display: block;
    }

    .main-content {

        margin-left: 0;
    }

}


@media (max-width: 768px) {

    .audit-header {

        padding: 14px 16px;
    }

    .audit-header-content {

        gap: 10px;
    }

    .header-title h1 {

        font-size: 17px;
    }

    .header-title p {

        display: none;
    }

    .header-title-icon {

        width: 40px;
        height: 40px;
    }

    .page-wrapper {

        padding: 16px;
    }

    .audit-hero {

        align-items: flex-start;

        flex-direction: column;

        padding: 20px;
    }

    .audit-record-id {

        text-align: left;
    }

    .audit-info-row {

        grid-template-columns: 1fr;

        gap: 6px;
    }

    .audit-actions {

        justify-content: stretch;

        flex-direction: column;
    }

    .audit-actions .btn {

        width: 100%;
    }

}


@media (max-width: 480px) {

    .header-title-icon {

        display: none;
    }

    .audit-hero {

        padding: 17px;
    }

    .audit-icon {

        width: 48px;
        height: 48px;
        font-size: 19px;
    }

    .audit-hero-title {

        font-size: 17px;
    }

}


/* =========================================================
   PRINT
========================================================= */

@media print {

    #sidebar,
    #sidebarOverlay,
    .sidebar-mobile-toggle,
    .audit-header,
    .page-navigation,
    .audit-actions {

        display: none !important;
    }

    .main-content {

        margin-left: 0 !important;
    }

    .page-wrapper {

        max-width: none;

        padding: 0;
    }

    body {

        background: #fff;
    }

    .audit-panel,
    .audit-hero {

        box-shadow: none;

        border: 1px solid #ddd;
    }

}

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================== -->

<aside id="sidebar">

<!--

    The existing sidebar content should remain consistent
    with the other Examcenter admin pages.

    Keep the same navigation structure/classes used
    throughout the admin dashboard.

-->

<ul class="sidebar-menu">

    <li>
        <a href="dashboard.php">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
    </li>
    <li>
        <a href="manage_admins.php">
            <i class="fas fa-user-shield"></i>
            <span>Manage Admins</span>
        </a>
    </li>
    <li>
        <a href="manage_classes.php">
            <i class="fas fa-users"></i>
            <span>Manage Classes</span>
        </a>
    </li>
    <li>
        <a href="manage_session.php">
            <i class="fas fa-calendar-alt"></i>
            <span>Manage Session</span>
        </a>
    </li>
    <li>
        <a href="manage_subject.php">
            <i class="fas fa-book"></i>
            <span>Manage Subjects</span>
        </a>
    </li>

    <li>
        <a href="audit_logs.php" class="active">
            <i class="fas fa-history"></i>
            <span>Audit Logs</span>
        </a>
    </li>
    <li>
        <a href="backup_list.php" class="active">
            <i class="fas fa-database"></i>
            <span>Backups</span>
        </a>
    </li>
    <li>
        <a href="index.php" class="active">
            <i class="fas fa-history"></i>
            <span>License</span>
        </a>
    </li>
    <li>
        <a href="settings.php" class="active">
            <i class="fas fa-history"></i>
            <span>settings</span>
        </a>
    </li>
    <li>
        <a href="../admin/logout.php" class="active">
            <i class="fas fa-history"></i>
            <span>Logout</span>
        </a>
    </li>

</ul>

</aside>

<!-- =========================================================
     SIDEBAR OVERLAY
========================================================== -->

<div id="sidebarOverlay"></div>

<!-- =========================================================
     MAIN CONTENT
========================================================== -->

<main class="main-content">

<!-- =====================================================
     HEADER
====================================================== -->

<header class="audit-header">

    <div class="audit-header-content">

        <div class="header-title">

            <div class="header-title-icon">

                <i class="fas fa-shield-alt"></i>

            </div>

            <div>

                <h1>Audit Details</h1>

                <p>
                    Review administrator activity and system events
                </p>

            </div>

        </div>


        <div class="header-actions">

            <!--
                RIGHT-SIDE SIDEBAR TOGGLE
            -->

            <button
                type="button"
                class="sidebar-mobile-toggle"
                id="sidebarToggle"
                aria-label="Toggle navigation"
                title="Toggle navigation"
            >
                <i class="fas fa-bars"></i>
            </button>

        </div>

    </div>

</header>


<!-- =====================================================
     PAGE
====================================================== -->

<div class="page-wrapper">


    <!-- =================================================
         NAVIGATION
    ================================================== -->

    <div class="page-navigation">

        <a
            href="audit_logs.php"
            class="back-link"
        >
            <i class="fas fa-arrow-left"></i>

            Back to Audit Logs

        </a>

    </div>


    <!-- =================================================
         AUDIT HERO
    ================================================== -->

    <section class="audit-hero">

        <div class="audit-identity">

            <div class="audit-icon">

                <i class="fas fa-history"></i>

            </div>

            <div>

                <h2 class="audit-hero-title">
                    Audit Record
                </h2>

                <p class="audit-hero-subtitle">
                    Detailed information about this system activity
                </p>

            </div>

        </div>


        <div class="audit-record-id">

            <span>
                Record ID
            </span>

            <strong>
                #<?= (int) $audit['id'] ?>
            </strong>

        </div>

    </section>


    <!-- =================================================
         CONTENT GRID
    ================================================== -->

    <div class="audit-grid">


        <!-- =============================================
             LEFT COLUMN
        ============================================== -->

        <div>


            <!-- =========================================
                 ACTIVITY INFORMATION
            ========================================== -->

            <section class="audit-panel">

                <div class="audit-panel-header">

                    <div class="audit-panel-title">

                        <i class="fas fa-info-circle"></i>

                        Activity Information

                    </div>

                </div>


                <div class="audit-info-list">


                    <!-- Administrator -->

                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            <i class="fas fa-user"></i>

                            Administrator

                        </div>

                        <div class="audit-info-value">

                            <div class="admin-profile">

                                <div class="admin-avatar">

                                    <?= e($initial) ?>

                                </div>

                                <div class="admin-name">

                                    <?= e($username) ?>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- Module -->

                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            <i class="fas fa-layer-group"></i>

                            Module

                        </div>

                        <div class="audit-info-value">

                            <span class="module-badge">

                                <i class="fas fa-layer-group"></i>

                                <?= e($audit['module'] ?? 'System') ?>

                            </span>

                        </div>

                    </div>


                    <!-- Action -->

                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            <i class="fas fa-bolt"></i>

                            Action

                        </div>

                        <div class="audit-info-value">

                            <span
                                class="action-badge <?= e($actionClass) ?>"
                            >

                                <i class="fas <?= e($actionIcon) ?>"></i>

                                <?= e($audit['action'] ?? 'Unknown') ?>

                            </span>

                        </div>

                    </div>


                    <!-- IP -->

                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            <i class="fas fa-network-wired"></i>

                            IP Address

                        </div>

                        <div class="audit-info-value">

                            <?= e($audit['ip_address'] ?? 'N/A') ?>

                        </div>

                    </div>


                </div>

            </section>


            <!-- =========================================
                 DESCRIPTION
            ========================================== -->

            <section class="audit-panel">

                <div class="audit-panel-header">

                    <div class="audit-panel-title">

                        <i class="fas fa-align-left"></i>

                        Activity Description

                    </div>

                </div>


                <div class="description-box">

                    <?= nl2br(
                        e(
                            $audit['description']
                            ?? 'No description available.'
                        )
                    ) ?>

                </div>

            </section>


            <!-- =========================================
                 SYSTEM INFORMATION
            ========================================== -->

            <section class="audit-panel">

                <div class="audit-panel-header">

                    <div class="audit-panel-title">

                        <i class="fas fa-desktop"></i>

                        System Information

                    </div>

                </div>


                <!-- Computer -->

                <div class="system-detail">

                    <div class="system-detail-icon">

                        <i class="fas fa-computer"></i>

                    </div>

                    <div class="system-detail-content">

                        <div class="system-detail-label">

                            Computer Name

                        </div>

                        <div class="system-detail-value">

                            <?= e(
                                $audit['computer_name']
                                ?? 'N/A'
                            ) ?>

                        </div>

                    </div>

                </div>


                <!-- Browser -->

                <div class="system-detail">

                    <div class="system-detail-icon">

                        <i class="fas fa-globe"></i>

                    </div>

                    <div class="system-detail-content">

                        <div class="system-detail-label">

                            Browser / User Agent

                        </div>

                        <div class="system-detail-value">

                            <?= e(
                                $audit['user_agent']
                                ?? 'N/A'
                            ) ?>

                        </div>

                    </div>

                </div>


            </section>


            <!-- =========================================
                 ACTIONS
            ========================================== -->

            <div class="audit-actions">

                <a
                    href="audit_logs.php"
                    class="btn btn-light border"
                >

                    <i class="fas fa-arrow-left me-1"></i>

                    Back to Audit Logs

                </a>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.print();"
                >

                    <i class="fas fa-print me-1"></i>

                    Print Record

                </button>

            </div>


        </div>


        <!-- =============================================
             RIGHT COLUMN
        ============================================== -->

        <div>


            <!-- =========================================
                 DATE & TIME
            ========================================== -->

            <section class="audit-panel">

                <div class="audit-panel-header">

                    <div class="audit-panel-title">

                        <i class="fas fa-clock"></i>

                        Activity Time

                    </div>

                </div>


                <div class="timestamp-card">

                    <div class="timestamp-icon">

                        <i class="fas fa-calendar-alt"></i>

                    </div>

                    <div class="timestamp-label">

                        Recorded On

                    </div>

                    <?php if ($createdAt): ?>

                        <div class="timestamp-date">

                            <?= date(
                                "d M Y",
                                $createdAt
                            ) ?>

                        </div>

                        <div class="timestamp-time">

                            <?= date(
                                "h:i:s A",
                                $createdAt
                            ) ?>

                        </div>

                    <?php else: ?>

                        <div class="timestamp-date">

                            Unknown

                        </div>

                    <?php endif; ?>

                </div>

            </section>


            <!-- =========================================
                 RECORD SUMMARY
            ========================================== -->

            <section class="audit-panel">

                <div class="audit-panel-header">

                    <div class="audit-panel-title">

                        <i class="fas fa-shield-alt"></i>

                        Record Summary

                    </div>

                </div>


                <div class="audit-info-list">


                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            Status

                        </div>

                        <div class="audit-info-value">

                            <span class="action-badge success">

                                <i class="fas fa-check-circle"></i>

                                Recorded

                            </span>

                        </div>

                    </div>


                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            Record

                        </div>

                        <div class="audit-info-value">

                            #<?= (int) $audit['id'] ?>

                        </div>

                    </div>


                    <div class="audit-info-row">

                        <div class="audit-info-label">

                            Module

                        </div>

                        <div class="audit-info-value">

                            <?= e(
                                $audit['module']
                                ?? 'System'
                            ) ?>

                        </div>

                    </div>


                </div>

            </section>


        </div>


    </div>


</div>
```

</main>

<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| Sidebar Toggle
|--------------------------------------------------------------------------
*/

(function () {

    const sidebar =
        document.getElementById("sidebar");

    const overlay =
        document.getElementById("sidebarOverlay");

    const toggle =
        document.getElementById("sidebarToggle");


    if (!sidebar || !overlay || !toggle) {
        return;
    }


    function openSidebar() {

        sidebar.classList.add("sidebar-open");

        overlay.classList.add(
            "sidebar-overlay-visible"
        );

    }


    function closeSidebar() {

        sidebar.classList.remove(
            "sidebar-open"
        );

        overlay.classList.remove(
            "sidebar-overlay-visible"
        );

    }


    toggle.addEventListener(
        "click",
        function () {

            if (
                sidebar.classList.contains(
                    "sidebar-open"
                )
            ) {

                closeSidebar();

            } else {

                openSidebar();

            }

        }
    );


    overlay.addEventListener(
        "click",
        closeSidebar
    );


    /*
    |--------------------------------------------------------------------------
    | Close sidebar after navigation on mobile
    |--------------------------------------------------------------------------
    */

    sidebar
        .querySelectorAll("a")
        .forEach(function (link) {

            link.addEventListener(
                "click",
                function () {

                    if (
                        window.innerWidth <= 900
                    ) {

                        closeSidebar();

                    }

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | Reset mobile state when returning to desktop
    |--------------------------------------------------------------------------
    */

    window.addEventListener(
        "resize",
        function () {

            if (window.innerWidth > 900) {

                sidebar.classList.remove(
                    "sidebar-open"
                );

                overlay.classList.remove(
                    "sidebar-overlay-visible"
                );

            }

        }
    );

})();

</script>

</body>

</html>

<?php endif; ?>

<?php

$conn->close();

?>
