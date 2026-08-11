<?php

session_start();

require_once "../db.php";
require_once "../includes/audit.php";

$conn = Database::connection();

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
        str_contains($action, 'activate')
    ) {
        return 'success';
    }

    if (
        str_contains($action, 'update') ||
        str_contains($action, 'edit') ||
        str_contains($action, 'change')
    ) {
        return 'warning';
    }

    return 'primary';
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$allowedPerPage = [25, 50, 100];

$perPage = isset($_GET['per_page']) && is_numeric($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 25;

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$page = isset($_GET['page']) && is_numeric($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$keyword  = trim($_GET['keyword'] ?? '');
$module   = trim($_GET['module'] ?? '');
$action   = trim($_GET['action'] ?? '');
$admin    = trim($_GET['admin'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

/*
|--------------------------------------------------------------------------
| Build WHERE Clause
|--------------------------------------------------------------------------
*/

$where  = [];
$params = [];
$types  = "";

if ($keyword !== "") {

    $where[] = "
        (
            description LIKE ?
            OR username LIKE ?
            OR ip_address LIKE ?
        )
    ";

    $search = "%{$keyword}%";

    $params[] = $search;
    $params[] = $search;
    $params[] = $search;

    $types .= "sss";
}

if ($module !== "") {

    $where[] = "module = ?";

    $params[] = $module;

    $types .= "s";
}

if ($action !== "") {

    $where[] = "action = ?";

    $params[] = $action;

    $types .= "s";
}

if ($admin !== "") {

    $where[] = "username = ?";

    $params[] = $admin;

    $types .= "s";
}

if ($dateFrom !== "") {

    $where[] = "DATE(created_at) >= ?";

    $params[] = $dateFrom;

    $types .= "s";
}

if ($dateTo !== "") {

    $where[] = "DATE(created_at) <= ?";

    $params[] = $dateTo;

    $types .= "s";
}

$whereSQL = !empty($where)
    ? "WHERE " . implode(" AND ", $where)
    : "";

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalLogs = (int) $conn->query("
    SELECT COUNT(*) AS total
    FROM audit_logs
")->fetch_assoc()['total'];

$todayLogs = (int) $conn->query("
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE DATE(created_at) = CURDATE()
")->fetch_assoc()['total'];

$activeAdmins = (int) $conn->query("
    SELECT COUNT(DISTINCT admin_id) AS total
    FROM audit_logs
")->fetch_assoc()['total'];

$modulesLogged = (int) $conn->query("
    SELECT COUNT(DISTINCT module) AS total
    FROM audit_logs
")->fetch_assoc()['total'];

/*
|--------------------------------------------------------------------------
| Dropdown Data
|--------------------------------------------------------------------------
*/

$modules = $conn->query("
    SELECT DISTINCT module
    FROM audit_logs
    WHERE module IS NOT NULL
    AND module <> ''
    ORDER BY module
");

$actions = $conn->query("
    SELECT DISTINCT action
    FROM audit_logs
    WHERE action IS NOT NULL
    AND action <> ''
    ORDER BY action
");

$admins = $conn->query("
    SELECT DISTINCT username
    FROM audit_logs
    WHERE username IS NOT NULL
    AND username <> ''
    ORDER BY username
");

/*
|--------------------------------------------------------------------------
| Count Matching Records
|--------------------------------------------------------------------------
*/

$countSQL = "
    SELECT COUNT(*) AS total
    FROM audit_logs
    {$whereSQL}
";

$stmt = $conn->prepare($countSQL);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$totalRows = (int) $stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();

$totalPages = max(
    1,
    (int) ceil($totalRows / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Fetch Audit Logs
|--------------------------------------------------------------------------
*/

$listSQL = "
    SELECT *
    FROM audit_logs
    {$whereSQL}
    ORDER BY created_at DESC
    LIMIT ?
    OFFSET ?
";

$stmt = $conn->prepare($listSQL);

$listParams = $params;
$listTypes  = $types . "ii";

$listParams[] = $perPage;
$listParams[] = $offset;

$stmt->bind_param($listTypes, ...$listParams);

$stmt->execute();

$auditLogs = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Filter State
|--------------------------------------------------------------------------
*/

$hasFilters = (
    $keyword !== '' ||
    $module !== '' ||
    $action !== '' ||
    $admin !== '' ||
    $dateFrom !== '' ||
    $dateTo !== ''
);

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

$queryParams = $_GET;

unset($queryParams['page']);

function paginationUrl(int $page, array $params): string
{
    $params['page'] = $page;

    return '?' . http_build_query($params);
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

<title>Audit Logs | Examcenter</title>

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
   ROOT
========================================================= */

:root {

    --primary: #4361ee;
    --primary-dark: #3651d4;

    --success: #16a34a;
    --warning: #f59e0b;
    --danger: #dc2626;

    --dark: #172033;
    --muted: #64748b;

    --border: #e2e8f0;

    --background: #f5f7fb;

    --sidebar-width: 250px;

    --card-radius: 14px;

}

/* =========================================================
   BASE
========================================================= */

* {
    box-sizing: border-box;
}

html {
    min-height: 100%;
}

body {

    margin: 0;

    min-height: 100vh;

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
========================================================= */

.sidebar {

    position: fixed;

    top: 0;
    left: 0;

    width: var(--sidebar-width);

    height: 100vh;

    background: #172033;

    color: #fff;

    z-index: 1100;

    transition:
        transform .25s ease,
        width .25s ease;

    box-shadow:
        5px 0 25px rgba(15, 23, 42, .08);

}

.sidebar-header {

    height: 70px;

    display: flex;

    align-items: center;

    padding: 0 22px;

    border-bottom:
        1px solid rgba(255,255,255,.08);

}

.sidebar-brand {

    display: flex;

    align-items: center;

    gap: 11px;

    color: #fff;

    text-decoration: none;

    font-size: 19px;

    font-weight: 750;

}

.sidebar-brand-icon {

    width: 36px;
    height: 36px;

    border-radius: 9px;

    display: flex;

    align-items: center;
    justify-content: center;

    background: var(--primary);

    font-size: 15px;

}

.sidebar-menu {

    list-style: none;

    padding: 15px 10px;

    margin: 0;

}

.sidebar-menu li {

    margin-bottom: 4px;

}

.sidebar-menu a {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 11px 13px;

    border-radius: 9px;

    color: #cbd5e1;

    text-decoration: none;

    font-size: 14px;

    font-weight: 550;

    transition:
        background .15s ease,
        color .15s ease;

}

.sidebar-menu a i {

    width: 20px;

    text-align: center;

    font-size: 15px;

}

.sidebar-menu a:hover {

    background: rgba(255,255,255,.07);

    color: #fff;

}

.sidebar-menu a.active {

    background: var(--primary);

    color: #fff;

    box-shadow:
        0 5px 14px rgba(67,97,238,.25);

}

/* =========================================================
   SIDEBAR TOGGLE
========================================================= */

.sidebar-toggle {

    position: absolute;

    top: 78px;

    right: -16px;

    width: 32px;
    height: 32px;

    border: 0;

    border-radius: 50%;

    background: #fff;

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    box-shadow:
        0 4px 14px rgba(15,23,42,.18);

    cursor: pointer;

    z-index: 1200;

    transition:
        transform .2s ease,
        background .2s ease;

}

.sidebar-toggle:hover {

    background: var(--primary);

    color: #fff;

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
   TOP BAR
========================================================= */

.topbar {

    height: 70px;

    background: #fff;

    border-bottom:
        1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 28px;

    position: sticky;

    top: 0;

    z-index: 900;

}

.topbar-title {

    font-size: 15px;

    font-weight: 650;

    color: #475569;

}

.topbar-right {

    display: flex;

    align-items: center;

    gap: 10px;

}

.topbar-user {

    display: flex;

    align-items: center;

    gap: 8px;

    color: #475569;

    font-size: 13px;

    font-weight: 600;

}

/* =========================================================
   PAGE
========================================================= */

.page-wrapper {

    padding: 28px;

}

.page-container {

    max-width: 1600px;

    margin: 0 auto;

}

/* =========================================================
   HEADER
========================================================= */

.page-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    margin-bottom: 28px;

}

.page-title {

    display: flex;

    align-items: center;

    gap: 15px;

}

.page-title-icon {

    width: 50px;
    height: 50px;

    border-radius: 13px;

    background: rgba(67,97,238,.12);

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 21px;

}

.page-title h2 {

    margin: 0;

    font-size: 25px;

    font-weight: 700;

    color: var(--dark);

}

.page-title p {

    margin: 4px 0 0;

    color: var(--muted);

    font-size: 14px;

}

.header-actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

}

.header-actions .btn {

    border-radius: 9px;

    padding: 9px 15px;

    font-weight: 600;

}

/* =========================================================
   STATISTICS
========================================================= */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 24px;

}

.stat-card {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: var(--card-radius);

    padding: 20px;

    box-shadow:
        0 3px 12px rgba(15,23,42,.04);

    position: relative;

    overflow: hidden;

    transition: .2s ease;

}

.stat-card:hover {

    transform: translateY(-2px);

    box-shadow:
        0 8px 22px rgba(15,23,42,.08);

}

.stat-card::before {

    content: "";

    position: absolute;

    left: 0;

    top: 0;
    bottom: 0;

    width: 4px;

    background: var(--primary);

}

.stat-card.success::before {
    background: var(--success);
}

.stat-card.warning::before {
    background: var(--warning);
}

.stat-card.danger::before {
    background: var(--danger);
}

.stat-content {

    display: flex;

    justify-content: space-between;

    align-items: center;

}

.stat-label {

    color: var(--muted);

    font-size: 13px;

    font-weight: 600;

    margin-bottom: 6px;

}

.stat-value {

    color: var(--dark);

    font-size: 27px;

    font-weight: 750;

    line-height: 1;

}

.stat-icon {

    width: 46px;
    height: 46px;

    border-radius: 12px;

    display: flex;

    align-items: center;
    justify-content: center;

    background: rgba(67,97,238,.10);

    color: var(--primary);

    font-size: 18px;

}

.stat-card.success .stat-icon {

    background: rgba(22,163,74,.10);

    color: var(--success);

}

.stat-card.warning .stat-icon {

    background: rgba(245,158,11,.12);

    color: var(--warning);

}

.stat-card.danger .stat-icon {

    background: rgba(220,38,38,.10);

    color: var(--danger);

}

/* =========================================================
   PANELS
========================================================= */

.panel {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: var(--card-radius);

    box-shadow:
        0 3px 12px rgba(15,23,42,.04);

    overflow: hidden;

}

/* =========================================================
   FILTER PANEL
========================================================= */

.filter-panel {

    margin-bottom: 24px;

}

.panel-header {

    padding: 17px 20px;

    border-bottom:
        1px solid var(--border);

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

}

.panel-title {

    display: flex;

    align-items: center;

    gap: 10px;

    font-weight: 700;

    color: var(--dark);

}

.panel-title i {

    color: var(--primary);

}

.filter-body {

    padding: 20px;

}

.filter-label {

    display: block;

    font-size: 12px;

    font-weight: 700;

    color: #475569;

    margin-bottom: 7px;

}

.form-control,
.form-select {

    border-color: var(--border);

    border-radius: 9px;

    min-height: 42px;

    font-size: 14px;

    box-shadow: none !important;

}

.form-control:focus,
.form-select:focus {

    border-color: var(--primary);

}

.input-group-text {

    border-color: var(--border);

    border-radius: 9px 0 0 9px;

}

/* =========================================================
   BUTTONS
========================================================= */

.btn-primary {

    background: var(--primary);

    border-color: var(--primary);

}

.btn-primary:hover {

    background: var(--primary-dark);

    border-color: var(--primary-dark);

}

.filter-actions {

    display: flex;

    align-items: end;

    height: 100%;

}

.filter-actions .btn {

    min-height: 42px;

    border-radius: 9px;

    font-weight: 600;

}

/* =========================================================
   RESULTS TOOLBAR
========================================================= */

.results-toolbar {

    padding: 15px 20px;

    border-bottom:
        1px solid var(--border);

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    flex-wrap: wrap;

}

.results-count {

    color: var(--muted);

    font-size: 13px;

}

.results-count strong {

    color: var(--dark);

}

.toolbar-right {

    display: flex;

    align-items: center;

    gap: 8px;

}

.toolbar-right label {

    font-size: 13px;

    color: var(--muted);

}

.per-page-select {

    width: 80px;

    min-height: 36px;

}

/* =========================================================
   TABLE
========================================================= */

.table-container {

    overflow-x: auto;

}

.audit-table {

    min-width: 1050px;

    margin: 0;

}

.audit-table thead th {

    background: #f8fafc;

    color: #64748b;

    font-size: 11px;

    font-weight: 750;

    text-transform: uppercase;

    letter-spacing: .05em;

    border-bottom:
        1px solid var(--border);

    padding: 14px 16px;

    white-space: nowrap;

}

.audit-table tbody td {

    padding: 14px 16px;

    border-bottom:
        1px solid #eef2f7;

    vertical-align: middle;

    font-size: 13px;

}

.audit-table tbody tr {

    transition: background .15s ease;

}

.audit-table tbody tr:hover {

    background: #f8faff;

}

/* =========================================================
   DATE
========================================================= */

.date-cell {

    white-space: nowrap;

}

.date-main {

    display: block;

    font-weight: 600;

    color: #334155;

}

.date-time {

    display: block;

    color: #94a3b8;

    font-size: 11px;

    margin-top: 2px;

}

/* =========================================================
   ADMIN
========================================================= */

.admin-cell {

    display: flex;

    align-items: center;

    gap: 9px;

    min-width: 130px;

}

.admin-avatar {

    width: 32px;
    height: 32px;

    border-radius: 50%;

    background:
        rgba(67,97,238,.10);

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: 700;

    font-size: 12px;

    flex-shrink: 0;

}

.admin-name {

    font-weight: 600;

    color: #334155;

}

/* =========================================================
   BADGES
========================================================= */

.module-badge,
.action-badge {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    padding: 5px 9px;

    border-radius: 7px;

    font-size: 11px;

    font-weight: 700;

    white-space: nowrap;

}

.module-badge {

    background: #ecfeff;

    color: #0e7490;

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

.description-cell {

    max-width: 380px;

}

.description-text {

    color: #475569;

    line-height: 1.5;

    display: -webkit-box;

    -webkit-line-clamp: 2;

    -webkit-box-orient: vertical;

    overflow: hidden;

}

/* =========================================================
   IP
========================================================= */

.ip-cell {

    font-family: monospace;

    color: #64748b;

    font-size: 12px;

    white-space: nowrap;

}

/* =========================================================
   VIEW BUTTON
========================================================= */

.view-btn {

    width: 34px;
    height: 34px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    border-radius: 8px;

}

/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {

    padding: 70px 20px !important;

    text-align: center;

}

.empty-icon {

    width: 62px;
    height: 62px;

    margin: 0 auto 15px;

    border-radius: 50%;

    background: #f1f5f9;

    color: #94a3b8;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 23px;

}

.empty-state h5 {

    margin-bottom: 6px;

    color: #334155;

}

.empty-state p {

    color: #94a3b8;

    margin: 0;

}

/* =========================================================
   PAGINATION
========================================================= */

.pagination-wrapper {

    padding: 18px 20px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    flex-wrap: wrap;

}

.pagination-info {

    color: var(--muted);

    font-size: 13px;

}

.pagination {

    margin: 0;

    gap: 4px;

}

.page-link {

    border: 0;

    border-radius: 7px !important;

    color: #475569;

    min-width: 35px;

    text-align: center;

    font-size: 13px;

}

.page-link:hover {

    background: #eef2ff;

    color: var(--primary);

}

.page-item.active .page-link {

    background: var(--primary);

    color: #fff;

}

/* =========================================================
   MODAL
========================================================= */

.modal-content {

    border: 0;

    border-radius: 14px;

    overflow: hidden;

    box-shadow:
        0 25px 60px rgba(15,23,42,.20);

}

/* =========================================================
   MOBILE OVERLAY
========================================================= */

.sidebar-overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.45);

    z-index: 1050;

}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .stats-grid {

        grid-template-columns:
            repeat(2, 1fr);

    }

}

@media (max-width: 992px) {

    .sidebar {

        transform:
            translateX(-100%);

    }

    .sidebar.show {

        transform:
            translateX(0);

    }

    .main-content {

        margin-left: 0;

    }

    .sidebar-overlay.show {

        display: block;

    }

    .topbar {

        padding: 0 18px;

    }

    .mobile-menu-btn {

        display: inline-flex !important;

    }

}

@media (min-width: 993px) {

    .mobile-menu-btn {

        display: none !important;

    }

}

@media (max-width: 768px) {

    .page-wrapper {

        padding: 16px;

    }

    .page-header {

        align-items: flex-start;

        flex-direction: column;

    }

    .header-actions {

        width: 100%;

    }

    .header-actions .btn {

        flex: 1;

    }

    .stats-grid {

        grid-template-columns: 1fr;

    }

    .filter-actions {

        width: 100%;

    }

    .filter-actions .btn {

        flex: 1;

    }

    .pagination-wrapper {

        justify-content: center;

    }

}

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>


<div class="sidebar-header">

    <a
        href="dashboard.php"
        class="sidebar-brand"
    >

        <span class="sidebar-brand-icon">
            <i class="fas fa-laptop-code"></i>
        </span>

        <span>
            Examcenter
        </span>

    </a>

</div>

<button
    type="button"
    class="sidebar-toggle"
    id="sidebarToggle"
    aria-label="Toggle sidebar"
    title="Toggle sidebar"
>
    <i class="fas fa-chevron-left"></i>
</button>

<ul class="sidebar-menu">

    <li>
        <a href="dashboard.php">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
    <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
    <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
    <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
    <a href="manage_session.php"><i class="fas fa-user-plus"></i>manage session</a>
    <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
    <a href="manage_students.php"><i class="fas fa-users"></i>Manage Student</a>
    <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
    <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
    

    <li>
        <a href="../backup/backup_list.php">
            <i class="fas fa-database"></i>
            <span>Backups</span>
        </a>
    </li>

    <li>
        <a
            href="audit_logs.php"
            class="active"
        >
            <i class="fas fa-history"></i>
            <span>Audit Logs</span>
        </a>
    </li>

    <li>
        <a href="../license/index.php">
            <i class="fas fa-key"></i>
            <span>License</span>
        </a>
    </li>

    <li>
        <a href="settings.php">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </li>

    <li>
        <a href="../logout.php">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </li>

</ul>


</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =========================================================
     MAIN CONTENT
========================================================== -->

<main class="main-content">


<!-- =====================================================
     TOPBAR
====================================================== -->

<header class="topbar">

    <div class="d-flex align-items-center gap-3">

        <button
            type="button"
            class="btn btn-light border mobile-menu-btn"
            id="mobileMenuButton"
            aria-label="Open menu"
        >
            <i class="fas fa-bars"></i>
        </button>

        <div class="topbar-title">
            Administration
        </div>

    </div>

    <div class="topbar-right">

        <div class="topbar-user">

            <i class="fas fa-user-circle"></i>

            <span>
                <?= e($_SESSION['username'] ?? 'Administrator') ?>
            </span>

        </div>

    </div>

</header>

<!-- =====================================================
     PAGE
====================================================== -->

<div class="page-wrapper">

    <div class="page-container">

        <!-- =================================================
             PAGE HEADER
        ================================================== -->

        <div class="page-header">

            <div class="page-title">

                <div class="page-title-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>

                <div>

                    <h2>
                        Audit Logs
                    </h2>

                    <p>
                        Monitor administrator activity and system events
                    </p>

                </div>

            </div>

            <div class="header-actions">

                <a
                    href="dashboard.php"
                    class="btn btn-light border"
                >
                    <i class="fas fa-arrow-left me-1"></i>
                    Dashboard
                </a>

                <a
                    href="export_audit_csv.php?<?= http_build_query($_GET) ?>"
                    class="btn btn-success"
                >
                    <i class="fas fa-file-csv me-1"></i>
                    Export CSV
                </a>

                <a
                    href="export_audit_excel.php"
                    class="btn btn-primary"
                >
                    <i class="fas fa-file-excel me-1"></i>
                    Export Excel
                </a>

            </div>

        </div>

        <!-- =================================================
             STATISTICS
        ================================================== -->

        <div class="stats-grid">

            <div class="stat-card">

                <div class="stat-content">

                    <div>

                        <div class="stat-label">
                            Total Audit Logs
                        </div>

                        <div class="stat-value">
                            <?= number_format($totalLogs) ?>
                        </div>

                    </div>

                    <div class="stat-icon">
                        <i class="fas fa-history"></i>
                    </div>

                </div>

            </div>

            <div class="stat-card success">

                <div class="stat-content">

                    <div>

                        <div class="stat-label">
                            Today's Activity
                        </div>

                        <div class="stat-value">
                            <?= number_format($todayLogs) ?>
                        </div>

                    </div>

                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>

                </div>

            </div>

            <div class="stat-card warning">

                <div class="stat-content">

                    <div>

                        <div class="stat-label">
                            Administrators
                        </div>

                        <div class="stat-value">
                            <?= number_format($activeAdmins) ?>
                        </div>

                    </div>

                    <div class="stat-icon">
                        <i class="fas fa-users-cog"></i>
                    </div>

                </div>

            </div>

            <div class="stat-card danger">

                <div class="stat-content">

                    <div>

                        <div class="stat-label">
                            Modules Tracked
                        </div>

                        <div class="stat-value">
                            <?= number_format($modulesLogged) ?>
                        </div>

                    </div>

                    <div class="stat-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>

                </div>

            </div>

        </div>

        <!-- =================================================
             FILTER PANEL
        ================================================== -->

        <div class="panel filter-panel">

            <div class="panel-header">

                <div class="panel-title">

                    <i class="fas fa-filter"></i>

                    Filter Audit Activity

                </div>

                <?php if ($hasFilters): ?>

                    <a
                        href="audit_logs.php"
                        class="btn btn-sm btn-outline-secondary"
                    >
                        <i class="fas fa-times me-1"></i>
                        Clear Filters
                    </a>

                <?php endif; ?>

            </div>

            <div class="filter-body">

                <form method="GET">

                    <div class="row g-3">

                        <div class="col-lg-3 col-md-6">

                            <label class="filter-label">
                                Search
                            </label>

                            <div class="input-group">

                                <span class="input-group-text bg-white">

                                    <i class="fas fa-search text-muted"></i>

                                </span>

                                <input
                                    type="text"
                                    class="form-control"
                                    name="keyword"
                                    placeholder="Description, admin or IP..."
                                    value="<?= e($keyword) ?>"
                                >

                            </div>

                        </div>

                        <div class="col-lg-2 col-md-6">

                            <label class="filter-label">
                                Module
                            </label>

                            <select
                                class="form-select"
                                name="module"
                            >

                                <option value="">
                                    All Modules
                                </option>

                                <?php while ($row = $modules->fetch_assoc()): ?>

                                    <option
                                        value="<?= e($row['module']) ?>"
                                        <?= $module === $row['module'] ? 'selected' : '' ?>
                                    >
                                        <?= e($row['module']) ?>
                                    </option>

                                <?php endwhile; ?>

                            </select>

                        </div>

                        <div class="col-lg-2 col-md-6">

                            <label class="filter-label">
                                Action
                            </label>

                            <select
                                class="form-select"
                                name="action"
                            >

                                <option value="">
                                    All Actions
                                </option>

                                <?php while ($row = $actions->fetch_assoc()): ?>

                                    <option
                                        value="<?= e($row['action']) ?>"
                                        <?= $action === $row['action'] ? 'selected' : '' ?>
                                    >
                                        <?= e($row['action']) ?>
                                    </option>

                                <?php endwhile; ?>

                            </select>

                        </div>

                        <div class="col-lg-2 col-md-6">

                            <label class="filter-label">
                                Administrator
                            </label>

                            <select
                                class="form-select"
                                name="admin"
                            >

                                <option value="">
                                    All Admins
                                </option>

                                <?php while ($row = $admins->fetch_assoc()): ?>

                                    <option
                                        value="<?= e($row['username']) ?>"
                                        <?= $admin === $row['username'] ? 'selected' : '' ?>
                                    >
                                        <?= e($row['username']) ?>
                                    </option>

                                <?php endwhile; ?>

                            </select>

                        </div>

                        <div class="col-lg-1 col-md-6">

                            <label class="filter-label">
                                From
                            </label>

                            <input
                                type="date"
                                class="form-control"
                                name="date_from"
                                value="<?= e($dateFrom) ?>"
                            >

                        </div>

                        <div class="col-lg-1 col-md-6">

                            <label class="filter-label">
                                To
                            </label>

                            <input
                                type="date"
                                class="form-control"
                                name="date_to"
                                value="<?= e($dateTo) ?>"
                            >

                        </div>

                        <div class="col-lg-1 col-md-6">

                            <label class="filter-label">
                                &nbsp;
                            </label>

                            <div class="filter-actions">

                                <button
                                    type="submit"
                                    class="btn btn-primary w-100"
                                    title="Apply filters"
                                >
                                    <i class="fas fa-search"></i>
                                </button>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

        <!-- =================================================
             AUDIT TABLE
        ================================================== -->

        <div class="panel">

            <div class="results-toolbar">

                <div class="results-count">

                    Showing

                    <strong>
                        <?= $totalRows > 0
                            ? number_format($offset + 1)
                            : 0
                        ?>
                    </strong>

                    to

                    <strong>
                        <?= number_format(
                            min(
                                $offset + $perPage,
                                $totalRows
                            )
                        ) ?>
                    </strong>

                    of

                    <strong>
                        <?= number_format($totalRows) ?>
                    </strong>

                    audit records

                    <?php if ($hasFilters): ?>

                        <span class="ms-1">
                            matching your filters
                        </span>

                    <?php endif; ?>

                </div>

                <div class="toolbar-right">

                    <label for="perPage">
                        Rows:
                    </label>

                    <select
                        id="perPage"
                        class="form-select per-page-select"
                        onchange="changePerPage(this.value)"
                    >

                        <?php foreach ($allowedPerPage as $option): ?>

                            <option
                                value="<?= $option ?>"
                                <?= $perPage === $option
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= $option ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>

            <div class="table-container">

                <table class="table audit-table">

                    <thead>

                        <tr>

                            <th>
                                Date & Time
                            </th>

                            <th>
                                Administrator
                            </th>

                            <th>
                                Module
                            </th>

                            <th>
                                Action
                            </th>

                            <th>
                                Description
                            </th>

                            <th>
                                IP Address
                            </th>

                            <th class="text-center">
                                View
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if ($auditLogs->num_rows === 0): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="empty-state"
                            >

                                <div class="empty-icon">

                                    <i class="fas fa-search"></i>

                                </div>

                                <h5>
                                    No audit records found
                                </h5>

                                <p>
                                    Try adjusting your filters or search criteria.
                                </p>

                                <?php if ($hasFilters): ?>

                                    <a
                                        href="audit_logs.php"
                                        class="btn btn-sm btn-outline-primary mt-3"
                                    >
                                        Clear Filters
                                    </a>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endif; ?>

                    <?php while ($log = $auditLogs->fetch_assoc()): ?>

                        <?php

                        $username = $log['username'] ?? 'Unknown';

                        $trimmedUsername = trim($username);

                        $initial = $trimmedUsername !== ''
                            ? strtoupper(
                                substr(
                                    $trimmedUsername,
                                    0,
                                    1
                                )
                            )
                            : '?';

                        $actionClass = auditActionClass(
                            $log['action'] ?? ''
                        );

                        ?>

                        <tr>

                            <td class="date-cell">

                                <span class="date-main">

                                    <?= date(
                                        "d M Y",
                                        strtotime($log['created_at'])
                                    ) ?>

                                </span>

                                <span class="date-time">

                                    <?= date(
                                        "H:i:s",
                                        strtotime($log['created_at'])
                                    ) ?>

                                </span>

                            </td>

                            <td>

                                <div class="admin-cell">

                                    <div class="admin-avatar">

                                        <?= e($initial) ?>

                                    </div>

                                    <div class="admin-name">

                                        <?= e($username) ?>

                                    </div>

                                </div>

                            </td>

                            <td>

                                <span class="module-badge">

                                    <i class="fas fa-layer-group"></i>

                                    <?= e(
                                        $log['module'] ?? 'System'
                                    ) ?>

                                </span>

                            </td>

                            <td>

                                <span
                                    class="action-badge <?= e($actionClass) ?>"
                                >

                                    <?php if ($actionClass === 'danger'): ?>

                                        <i class="fas fa-exclamation-circle"></i>

                                    <?php elseif ($actionClass === 'success'): ?>

                                        <i class="fas fa-check-circle"></i>

                                    <?php elseif ($actionClass === 'warning'): ?>

                                        <i class="fas fa-edit"></i>

                                    <?php else: ?>

                                        <i class="fas fa-bolt"></i>

                                    <?php endif; ?>

                                    <?= e(
                                        $log['action'] ?? 'Unknown'
                                    ) ?>

                                </span>

                            </td>

                            <td class="description-cell">

                                <div
                                    class="description-text"
                                    title="<?= e(
                                        $log['description'] ?? ''
                                    ) ?>"
                                >

                                    <?= e(
                                        $log['description']
                                        ?? 'No description'
                                    ) ?>

                                </div>

                            </td>

                            <td class="ip-cell">

                                <?= e(
                                    $log['ip_address'] ?? 'N/A'
                                ) ?>

                            </td>

                            <td class="text-center">

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary view-btn viewAudit"
                                    data-id="<?= (int) $log['id'] ?>"
                                    title="View audit details"
                                >

                                    <i class="fas fa-eye"></i>

                                </button>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                    </tbody>

                </table>

            </div>

            <!-- =================================================
                 PAGINATION
            ================================================== -->

            <?php if ($totalRows > 0): ?>

                <div class="pagination-wrapper">

                    <div class="pagination-info">

                        Page

                        <strong>
                            <?= $page ?>
                        </strong>

                        of

                        <strong>
                            <?= $totalPages ?>
                        </strong>

                    </div>

                    <nav>

                        <ul class="pagination">

                            <?php

                            $startPage = max(
                                1,
                                $page - 2
                            );

                            $endPage = min(
                                $totalPages,
                                $page + 2
                            );

                            ?>

                            <!-- Previous -->

                            <li
                                class="page-item
                                <?= $page <= 1
                                    ? 'disabled'
                                    : ''
                                ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page > 1
                                        ? e(
                                            paginationUrl(
                                                $page - 1,
                                                $queryParams
                                            )
                                        )
                                        : '#'
                                    ?>"
                                    aria-label="Previous"
                                >

                                    <i class="fas fa-chevron-left"></i>

                                </a>

                            </li>

                            <?php if ($startPage > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                1,
                                                $queryParams
                                            )
                                        ) ?>"
                                    >
                                        1
                                    </a>

                                </li>

                                <?php if ($startPage > 2): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                            <?php endif; ?>

                            <?php for (
                                $i = $startPage;
                                $i <= $endPage;
                                $i++
                            ): ?>

                                <li
                                    class="page-item
                                    <?= $page === $i
                                        ? 'active'
                                        : ''
                                    ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $i,
                                                $queryParams
                                            )
                                        ) ?>"
                                    >
                                        <?= $i ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>

                                <?php if (
                                    $endPage <
                                    $totalPages - 1
                                ): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            paginationUrl(
                                                $totalPages,
                                                $queryParams
                                            )
                                        ) ?>"
                                    >
                                        <?= $totalPages ?>
                                    </a>

                                </li>

                            <?php endif; ?>

                            <!-- Next -->

                            <li
                                class="page-item
                                <?= $page >= $totalPages
                                    ? 'disabled'
                                    : ''
                                ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= $page < $totalPages
                                        ? e(
                                            paginationUrl(
                                                $page + 1,
                                                $queryParams
                                            )
                                        )
                                        : '#'
                                    ?>"
                                    aria-label="Next"
                                >

                                    <i class="fas fa-chevron-right"></i>

                                </a>

                            </li>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


</main>

<!-- =========================================================
     AUDIT DETAILS MODAL
========================================================== -->

<div
    class="modal fade"
    id="auditModal"
    tabindex="-1"
    aria-hidden="true"
>


<div
    class="modal-dialog modal-lg modal-dialog-centered"
>

    <div class="modal-content">

        <div id="auditContent">

            <div class="p-5 text-center">

                <div
                    class="spinner-border text-primary"
                    role="status"
                ></div>

                <p class="text-muted mt-3 mb-0">
                    Loading audit details...
                </p>

            </div>

        </div>

    </div>

</div>

</div>

<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById("sidebar");

const sidebarToggle =
    document.getElementById("sidebarToggle");

const sidebarOverlay =
    document.getElementById("sidebarOverlay");

const mobileMenuButton =
    document.getElementById("mobileMenuButton");


function toggleSidebar() {

    sidebar.classList.toggle("show");

    sidebarOverlay.classList.toggle("show");

    const icon =
        sidebarToggle.querySelector("i");

    if (sidebar.classList.contains("show")) {

        icon.classList.remove(
            "fa-chevron-left"
        );

        icon.classList.add(
            "fa-chevron-right"
        );

    } else {

        icon.classList.remove(
            "fa-chevron-right"
        );

        icon.classList.add(
            "fa-chevron-left"
        );

    }

}


sidebarToggle.addEventListener(
    "click",
    toggleSidebar
);


if (mobileMenuButton) {

    mobileMenuButton.addEventListener(
        "click",
        toggleSidebar
    );

}


sidebarOverlay.addEventListener(
    "click",
    function () {

        sidebar.classList.remove("show");

        sidebarOverlay.classList.remove("show");

    }
);


/*
|--------------------------------------------------------------------------
| View Audit Details
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(".viewAudit")
    .forEach(function(button) {

        button.addEventListener(
            "click",
            function() {

                const id =
                    this.dataset.id;

                const modalElement =
                    document.getElementById(
                        "auditModal"
                    );

                const content =
                    document.getElementById(
                        "auditContent"
                    );

                content.innerHTML = `

                    <div class="p-5 text-center">

                        <div
                            class="spinner-border text-primary"
                            role="status"
                        ></div>

                        <p class="text-muted mt-3 mb-0">
                            Loading audit details...
                        </p>

                    </div>

                `;

                const modal =
                    bootstrap.Modal
                        .getOrCreateInstance(
                            modalElement
                        );

                modal.show();

                fetch(
                    "audit_details.php?id=" +
                    encodeURIComponent(id),
                    {
                        headers: {
                            "X-Requested-With":
                                "XMLHttpRequest"
                        }
                    }
                )

                .then(function(response) {

                    if (!response.ok) {

                        throw new Error(
                            "Unable to load audit details."
                        );

                    }

                    return response.text();

                })

                .then(function(html) {

                    content.innerHTML =
                        html;

                })

                .catch(function(error) {

                    content.innerHTML = `

                        <div class="modal-header">

                            <h5 class="modal-title">
                                Audit Details
                            </h5>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                            ></button>

                        </div>

                        <div class="modal-body">

                            <div class="alert alert-danger mb-0">

                                <i
                                    class="fas fa-exclamation-circle me-2"
                                ></i>

                                ${error.message}

                            </div>

                        </div>

                    `;

                });

            }

        );

    });


/*
|--------------------------------------------------------------------------
| Rows Per Page
|--------------------------------------------------------------------------
*/

function changePerPage(value) {

    const url =
        new URL(
            window.location.href
        );

    url.searchParams.set(
        "per_page",
        value
    );

    url.searchParams.set(
        "page",
        "1"
    );

    window.location.href =
        url.toString();

}


/*
|--------------------------------------------------------------------------
| Close Mobile Sidebar After Navigation
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(".sidebar-menu a")
    .forEach(function(link) {

        link.addEventListener(
            "click",
            function() {

                if (
                    window.innerWidth <= 992
                ) {

                    sidebar.classList.remove(
                        "show"
                    );

                    sidebarOverlay.classList.remove(
                        "show"
                    );

                }

            }
        );

    });

</script>

</body>

</html>

<?php

$stmt->close();

$conn->close();

?>
