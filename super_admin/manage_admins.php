<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';

/*
|--------------------------------------------------------------------------
| Error Reporting
|--------------------------------------------------------------------------
*/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$success = '';
$errorMsg = '';
$super_admin = null;
$admins = [];

try {

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    */
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Super Admin
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT username, role
        FROM super_admins
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Failed to prepare super admin query: " . $conn->error);
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $super_admin = $result->fetch_assoc();

    $stmt->close();

    if (
        !$super_admin ||
        !isset($super_admin['role']) ||
        strtolower($super_admin['role']) !== 'super_admin'
    ) {
        session_destroy();

        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Add Admin
    |--------------------------------------------------------------------------
    */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_username'])) {

        $newUsername = trim($_POST['admin_username'] ?? '');
        $newPassword = $_POST['admin_password'] ?? '';

        if ($newUsername === '' || $newPassword === '') {

            $errorMsg = "Username and password are required.";

        } elseif (strlen($newUsername) < 3) {

            $errorMsg = "Username must contain at least 3 characters.";

        } elseif (strlen($newPassword) < 6) {

            $errorMsg = "Password must contain at least 6 characters.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | Check Duplicate Username
            |--------------------------------------------------------------------------
            */
            $check = $conn->prepare("
                SELECT id
                FROM admins
                WHERE username = ?
                LIMIT 1
            ");

            if (!$check) {
                throw new Exception(
                    "Failed to prepare duplicate username query: " . $conn->error
                );
            }

            $check->bind_param("s", $newUsername);
            $check->execute();

            $dupResult = $check->get_result();

            if ($dupResult->num_rows > 0) {

                $errorMsg = "Username already exists.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | Create Admin
                |--------------------------------------------------------------------------
                */
                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                $insert = $conn->prepare("
                    INSERT INTO admins
                        (username, password, role)
                    VALUES
                        (?, ?, 'admin')
                ");

                if (!$insert) {
                    throw new Exception(
                        "Failed to prepare admin insert: " . $conn->error
                    );
                }

                $insert->bind_param(
                    "ss",
                    $newUsername,
                    $hashedPassword
                );

                if ($insert->execute()) {
                    $success = "Admin account created successfully.";
                } else {
                    $errorMsg = "Database error while creating admin.";
                }

                $insert->close();
            }

            $check->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Delete Admin
    |--------------------------------------------------------------------------
    */
    if (isset($_GET['delete_id'])) {

        $delete_id = (int) $_GET['delete_id'];

        if ($delete_id <= 0) {

            $errorMsg = "Invalid administrator selected.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | Confirm Admin Exists
            |--------------------------------------------------------------------------
            */
            $checkAdmin = $conn->prepare("
                SELECT id, username
                FROM admins
                WHERE id = ?
                LIMIT 1
            ");

            if (!$checkAdmin) {
                throw new Exception(
                    "Failed to prepare admin lookup: " . $conn->error
                );
            }

            $checkAdmin->bind_param("i", $delete_id);
            $checkAdmin->execute();

            $adminResult = $checkAdmin->get_result();
            $adminToDelete = $adminResult->fetch_assoc();

            $checkAdmin->close();

            if (!$adminToDelete) {

                $errorMsg = "Administrator not found.";

            } else {

                $stmt = $conn->prepare("
                    DELETE FROM admins
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new Exception(
                        "Failed to prepare admin deletion: " . $conn->error
                    );
                }

                $stmt->bind_param("i", $delete_id);

                if ($stmt->execute()) {
                    $success = "Admin account deleted successfully.";
                } else {
                    $errorMsg = "Database error while deleting admin.";
                }

                $stmt->close();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch All Admins
    |--------------------------------------------------------------------------
    */
    $adminsQuery = $conn->query("
        SELECT id, username, role
        FROM admins
        ORDER BY id DESC
    ");

    if (!$adminsQuery) {
        throw new Exception(
            "Failed to fetch administrators: " . $conn->error
        );
    }

    while ($row = $adminsQuery->fetch_assoc()) {
        $admins[] = $row;
    }

} catch (Exception $e) {

    error_log("Manage admins error: " . $e->getMessage());

    if ($success === '') {
        $errorMsg = "An unexpected system error occurred.";
    }

} finally {

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function role_label($role)
{
    $role = strtolower(trim((string) $role));

    if ($role === 'admin') {
        return 'Administrator';
    }

    if ($role === 'super_admin') {
        return 'Super Admin';
    }

    return ucfirst($role);
}

function role_class($role)
{
    $role = strtolower(trim((string) $role));

    if ($role === 'admin') {
        return 'role-admin';
    }

    if ($role === 'super_admin') {
        return 'role-super';
    }

    return 'role-default';
}

$adminCount = count($admins);

?>

<!DOCTYPE html>

<html lang="en">

<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Manage Admins | Examcenter</title>

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
    href="../css/dataTables.bootstrap5.min.css"
>

<style>

    /*
    |--------------------------------------------------------------------------
    | Base
    |--------------------------------------------------------------------------
    */

    :root {
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --sidebar: #0f172a;
        --sidebar-light: #1e293b;
        --background: #f5f7fb;
        --card: #ffffff;
        --text: #0f172a;
        --muted: #64748b;
        --border: #e2e8f0;
        --success: #16a34a;
        --danger: #dc2626;
        --warning: #d97706;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: var(--background);
        color: var(--text);
        font-family:
            Inter,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;
    }

    a {
        text-decoration: none;
    }

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 260px;
        height: 100vh;
        background: var(--sidebar);
        color: #fff;
        z-index: 1100;
        transition: transform .3s ease;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }

    .sidebar-header {
        padding: 25px 22px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .brand-icon {
        width: 42px;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--primary);
        border-radius: 12px;
        font-size: 20px;
    }

    .brand h4 {
        margin: 0;
        font-size: 20px;
        font-weight: 700;
    }

    .brand small {
        color: #94a3b8;
        font-size: 11px;
    }

    .admin-profile {
        margin-top: 22px;
        padding: 13px;
        border-radius: 12px;
        background: rgba(255,255,255,.06);
    }

    .admin-profile-label {
        color: #94a3b8;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
    }

    .admin-profile-name {
        margin-top: 4px;
        font-weight: 600;
        font-size: 14px;
        word-break: break-word;
    }

    .sidebar-nav {
        padding: 20px 12px;
        flex: 1;
    }

    .nav-section-title {
        padding: 0 12px;
        margin: 12px 0 8px;
        color: #64748b;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: 13px;
        padding: 12px 13px;
        margin-bottom: 4px;
        border-radius: 10px;
        color: #cbd5e1;
        font-size: 14px;
        transition: all .2s ease;
    }

    .sidebar-link i {
        width: 20px;
        text-align: center;
        font-size: 15px;
    }

    .sidebar-link:hover {
        background: rgba(255,255,255,.07);
        color: #fff;
    }

    .sidebar-link.active {
        background: var(--primary);
        color: #fff;
        box-shadow: 0 5px 15px rgba(37,99,235,.25);
    }

    .sidebar-link.logout {
        color: #fca5a5;
    }

    .sidebar-link.logout:hover {
        background: rgba(220,38,38,.12);
        color: #fecaca;
    }

    /*
    |--------------------------------------------------------------------------
    | Main Content
    |--------------------------------------------------------------------------
    */

    .main-content {
        margin-left: 260px;
        min-height: 100vh;
        transition: margin-left .3s ease;
    }

    .topbar {
        height: 78px;
        background: #fff;
        border-bottom: 1px solid var(--border);
        padding: 0 30px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 900;
    }

    .page-title h1 {
        margin: 0;
        font-size: 22px;
        font-weight: 700;
    }

    .page-title p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 13px;
    }

    .topbar-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .toggle-btn {
        width: 43px;
        height: 43px;
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 10px;
        color: var(--text);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all .2s ease;
    }

    .toggle-btn:hover {
        background: #f8fafc;
        color: var(--primary);
        border-color: #bfdbfe;
    }

    .content-wrapper {
        padding: 30px;
        max-width: 1600px;
        margin: 0 auto;
    }

    /*
    |--------------------------------------------------------------------------
    | Welcome Banner
    |--------------------------------------------------------------------------
    */

    .welcome-banner {
        background: linear-gradient(
            135deg,
            #1d4ed8,
            #2563eb,
            #3b82f6
        );
        color: #fff;
        border-radius: 18px;
        padding: 26px 28px;
        margin-bottom: 25px;
        position: relative;
        overflow: hidden;
    }

    .welcome-banner::after {
        content: "";
        position: absolute;
        width: 220px;
        height: 220px;
        right: -70px;
        top: -100px;
        background: rgba(255,255,255,.08);
        border-radius: 50%;
    }

    .welcome-banner h2 {
        margin: 0 0 5px;
        font-size: 21px;
        font-weight: 700;
    }

    .welcome-banner p {
        margin: 0;
        opacity: .88;
        font-size: 14px;
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    .stat-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        height: 100%;
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(15,23,42,.07);
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 19px;
    }

    .stat-icon.blue {
        background: #eff6ff;
        color: #2563eb;
    }

    .stat-icon.green {
        background: #f0fdf4;
        color: #16a34a;
    }

    .stat-icon.purple {
        background: #faf5ff;
        color: #9333ea;
    }

    .stat-value {
        font-size: 22px;
        font-weight: 750;
        line-height: 1.1;
    }

    .stat-label {
        color: var(--muted);
        font-size: 12px;
        margin-top: 4px;
    }

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    */

    .dashboard-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 3px 12px rgba(15,23,42,.025);
    }

    .card-heading {
        padding: 20px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .card-heading h5 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
    }

    .card-heading p {
        margin: 4px 0 0;
        color: var(--muted);
        font-size: 12px;
    }

    .card-body-custom {
        padding: 22px;
    }

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    .form-label {
        font-size: 13px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 7px;
    }

    .form-control-custom {
        height: 46px;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 0 13px;
        width: 100%;
        outline: none;
        font-size: 14px;
        transition: border-color .2s, box-shadow .2s;
    }

    .form-control-custom:focus {
        border-color: #60a5fa;
        box-shadow: 0 0 0 3px rgba(37,99,235,.1);
    }

    .password-wrapper {
        position: relative;
    }

    .password-wrapper .form-control-custom {
        padding-right: 45px;
    }

    .password-toggle {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #64748b;
        cursor: pointer;
    }

    .btn-primary-custom {
        height: 46px;
        border: 0;
        border-radius: 10px;
        padding: 0 20px;
        background: var(--primary);
        color: #fff;
        font-weight: 600;
        font-size: 13px;
        transition: background .2s, transform .2s;
    }

    .btn-primary-custom:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    */

    .custom-alert {
        border-radius: 12px;
        border: 0;
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        font-size: 13px;
    }

    .custom-alert.success {
        background: #f0fdf4;
        color: #166534;
    }

    .custom-alert.error {
        background: #fef2f2;
        color: #991b1b;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Table
    |--------------------------------------------------------------------------
    */

    .table-wrapper {
        overflow-x: auto;
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
    }

    .admin-table thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .5px;
        font-weight: 700;
        padding: 14px 18px;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .admin-table tbody td {
        padding: 15px 18px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        font-size: 13px;
    }

    .admin-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .admin-table tbody tr:hover {
        background: #fafcff;
    }

    .admin-avatar {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: #eff6ff;
        color: var(--primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        margin-right: 10px;
    }

    .admin-name {
        font-weight: 600;
        color: #1e293b;
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .role-admin {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .role-super {
        background: #faf5ff;
        color: #7e22ce;
    }

    .role-default {
        background: #f1f5f9;
        color: #475569;
    }

    .delete-btn {
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 9px;
        background: #fef2f2;
        color: var(--danger);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all .2s;
    }

    .delete-btn:hover {
        background: var(--danger);
        color: #fff;
    }

    /*
    |--------------------------------------------------------------------------
    | Quick / Study Links
    |--------------------------------------------------------------------------
    */

    .quick-link {
        display: flex;
        align-items: center;
        gap: 13px;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 12px;
        color: #334155;
        transition: all .2s ease;
        height: 100%;
    }

    .quick-link:hover {
        border-color: #bfdbfe;
        background: #eff6ff;
        color: var(--primary);
        transform: translateY(-2px);
    }

    .quick-link-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary);
    }

    .quick-link-title {
        font-weight: 600;
        font-size: 13px;
    }

    .quick-link-description {
        color: var(--muted);
        font-size: 11px;
        margin-top: 2px;
    }

    /*
    |--------------------------------------------------------------------------
    | Empty State
    |--------------------------------------------------------------------------
    */

    .empty-state {
        padding: 50px 20px;
        text-align: center;
        color: var(--muted);
    }

    .empty-state-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto 15px;
        border-radius: 50%;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }

    /*
    |--------------------------------------------------------------------------
    | Overlay
    |--------------------------------------------------------------------------
    */

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15,23,42,.45);
        z-index: 1050;
    }

    /*
    |--------------------------------------------------------------------------
    | Responsive
    |--------------------------------------------------------------------------
    */

    @media (max-width: 991.98px) {

        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .sidebar-overlay.show {
            display: block;
        }

        .main-content {
            margin-left: 0;
        }

        .topbar {
            padding: 0 20px;
        }

        .content-wrapper {
            padding: 20px;
        }

        .topbar-right {
            gap: 5px;
        }
    }

    @media (max-width: 575.98px) {

        .topbar {
            height: 70px;
        }

        .page-title h1 {
            font-size: 18px;
        }

        .page-title p {
            display: none;
        }

        .content-wrapper {
            padding: 15px;
        }

        .welcome-banner {
            padding: 22px;
            border-radius: 14px;
        }

        .welcome-banner h2 {
            font-size: 18px;
        }

        .card-heading {
            padding: 17px;
        }

        .card-body-custom {
            padding: 17px;
        }
    }

</style>
```

</head>

<body>

<!--
|--------------------------------------------------------------------------
| Sidebar Overlay
|--------------------------------------------------------------------------
-->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!--
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
-->

<aside
    class="sidebar"
    id="sidebar"
>

```
<div class="sidebar-header">

    <div class="brand">

        <div class="brand-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>

        <div>
            <h4>Examcenter</h4>
            <small>Management System</small>
        </div>

    </div>

    <div class="admin-profile">

        <div class="admin-profile-label">
            Signed in as
        </div>

        <div class="admin-profile-name">
            <?php echo e($super_admin['username']); ?>
        </div>

    </div>

</div>


<nav class="sidebar-nav">

    <div class="nav-section-title">
        Main Menu
    </div>

    <a
        href="dashboard.php"
        class="sidebar-link"
    >
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="manage_admins.php"
        class="sidebar-link active"
    >
        <i class="fas fa-user-shield"></i>
        <span>Manage Admins</span>
    </a>

    <a
        href="manage_classes.php"
        class="sidebar-link"
    >
        <i class="fas fa-school"></i>
        <span>Manage Classes</span>
    </a>

    <a
        href="manage_students.php"
        class="sidebar-link"
    >
        <i class="fas fa-user-graduate"></i>
        <span>Manage Students</span>
    </a>

    <a
        href="manage_subject.php"
        class="sidebar-link"
    >
        <i class="fas fa-book"></i>
        <span>Manage Subjects</span>
    </a>

    <a
        href="manage_session.php"
        class="sidebar-link"
    >
        <i class="fas fa-calendar-alt"></i>
        <span>Manage Sessions</span>
    </a>


    <div class="nav-section-title">
        System
    </div>

    <a
        href="settings.php"
        class="sidebar-link"
    >
        <i class="fas fa-cog"></i>
        <span>Settings</span>
    </a>

    <a
        href="../admin/logout.php"
        class="sidebar-link logout"
    >
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>

</nav>
```

</aside>

<!--
|--------------------------------------------------------------------------
| Main Content
|--------------------------------------------------------------------------
-->

<main class="main-content">

```
<!--
|--------------------------------------------------------------------------
| Topbar
|--------------------------------------------------------------------------
-->
<header class="topbar">

    <div class="page-title">

        <h1>
            Manage Admins
        </h1>

        <p>
            Create and manage administrator accounts
        </p>

    </div>

    <div class="topbar-right">

        <button
            type="button"
            class="toggle-btn"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            title="Toggle sidebar"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>

</header>


<!--
|--------------------------------------------------------------------------
| Page Content
|--------------------------------------------------------------------------
-->
<div class="content-wrapper">


    <!-- Welcome -->
    <section class="welcome-banner">

        <h2>
            Administrator Management
        </h2>

        <p>
            Manage the administrator accounts that have access
            to the Examcenter administration system.
        </p>

    </section>


    <!--
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    -->

    <?php if ($success): ?>

        <div class="custom-alert success">

            <i class="fas fa-check-circle"></i>

            <span>
                <?php echo e($success); ?>
            </span>

        </div>

    <?php endif; ?>


    <?php if ($errorMsg): ?>

        <div class="custom-alert error">

            <i class="fas fa-exclamation-circle"></i>

            <span>
                <?php echo e($errorMsg); ?>
            </span>

        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="stat-card">

                <div class="stat-icon blue">
                    <i class="fas fa-users-cog"></i>
                </div>

                <div>

                    <div class="stat-value">
                        <?php echo $adminCount; ?>
                    </div>

                    <div class="stat-label">
                        Total Administrators
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="stat-card">

                <div class="stat-icon green">
                    <i class="fas fa-user-check"></i>
                </div>

                <div>

                    <div class="stat-value">
                        <?php echo e($super_admin['username']); ?>
                    </div>

                    <div class="stat-label">
                        Current Super Admin
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="stat-card">

                <div class="stat-icon purple">
                    <i class="fas fa-shield-alt"></i>
                </div>

                <div>

                    <div class="stat-value">
                        Active
                    </div>

                    <div class="stat-label">
                        System Access
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Add Admin
    |--------------------------------------------------------------------------
    -->

    <div class="dashboard-card mb-4">

        <div class="card-heading">

            <div>

                <h5>
                    <i class="fas fa-user-plus me-2 text-primary"></i>
                    Add New Administrator
                </h5>

                <p>
                    Create an account for a system administrator.
                </p>

            </div>

        </div>


        <div class="card-body-custom">

            <form
                method="POST"
                autocomplete="off"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-lg-5">

                        <label
                            for="admin_username"
                            class="form-label"
                        >
                            Admin Username
                        </label>

                        <input
                            type="text"
                            id="admin_username"
                            name="admin_username"
                            class="form-control-custom"
                            placeholder="Enter administrator username"
                            minlength="3"
                            required
                        >

                    </div>


                    <div class="col-lg-5">

                        <label
                            for="admin_password"
                            class="form-label"
                        >
                            Admin Password
                        </label>

                        <div class="password-wrapper">

                            <input
                                type="password"
                                id="admin_password"
                                name="admin_password"
                                class="form-control-custom"
                                placeholder="Enter secure password"
                                minlength="6"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                id="passwordToggle"
                                aria-label="Show password"
                            >
                                <i class="fas fa-eye"></i>
                            </button>

                        </div>

                    </div>


                    <div class="col-lg-2">

                        <button
                            type="submit"
                            class="btn-primary-custom w-100"
                        >
                            <i class="fas fa-plus me-2"></i>
                            Add Admin
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Existing Administrators
    |--------------------------------------------------------------------------
    -->

    <div class="dashboard-card mb-4">

        <div class="card-heading">

            <div>

                <h5>
                    <i class="fas fa-users me-2 text-primary"></i>
                    Existing Administrators
                </h5>

                <p>
                    Accounts currently registered in Examcenter.
                </p>

            </div>

            <span class="badge bg-light text-dark">
                <?php echo $adminCount; ?> Accounts
            </span>

        </div>


        <?php if (!empty($admins)): ?>

            <div class="table-wrapper">

                <table
                    id="adminsTable"
                    class="admin-table"
                >

                    <thead>

                        <tr>

                            <th>
                                Administrator
                            </th>

                            <th>
                                Role
                            </th>

                            <th>
                                Account ID
                            </th>

                            <th class="text-end">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($admins as $admin): ?>

                            <?php

                            $username = (string) $admin['username'];

                            $initial = strtoupper(
                                substr($username, 0, 1)
                            );

                            ?>

                            <tr>

                                <td>

                                    <div class="d-flex align-items-center">

                                        <span class="admin-avatar">
                                            <?php echo e($initial); ?>
                                        </span>

                                        <span class="admin-name">
                                            <?php echo e($username); ?>
                                        </span>

                                    </div>

                                </td>


                                <td>

                                    <span
                                        class="role-badge <?php echo e(role_class($admin['role'])); ?>"
                                    >

                                        <i class="fas fa-shield-alt"></i>

                                        <?php
                                        echo e(
                                            role_label($admin['role'])
                                        );
                                        ?>

                                    </span>

                                </td>


                                <td>

                                    <span class="text-muted">
                                        #<?php echo e($admin['id']); ?>
                                    </span>

                                </td>


                                <td class="text-end">

                                    <button
                                        type="button"
                                        class="delete-btn"
                                        data-delete-url="?delete_id=<?php echo (int) $admin['id']; ?>"
                                        data-username="<?php echo e($username); ?>"
                                        title="Delete administrator"
                                    >
                                        <i class="fas fa-trash-alt"></i>
                                    </button>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <div class="empty-state-icon">
                    <i class="fas fa-users-slash"></i>
                </div>

                <h6>
                    No administrators found
                </h6>

                <p class="mb-0">
                    Create the first administrator using the form above.
                </p>

            </div>

        <?php endif; ?>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Quick / Study Links
    |--------------------------------------------------------------------------
    -->

    <div class="dashboard-card">

        <div class="card-heading">

            <div>

                <h5>
                    <i class="fas fa-compass me-2 text-primary"></i>
                    Quick Links
                </h5>

                <p>
                    Quickly navigate to commonly used areas.
                </p>

            </div>

        </div>


        <div class="card-body-custom">

            <div class="row g-3">


                <div class="col-md-6 col-xl-3">

                    <a
                        href="dashboard.php"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>

                        <div>

                            <div class="quick-link-title">
                                Dashboard
                            </div>

                            <div class="quick-link-description">
                                View system overview
                            </div>

                        </div>

                    </a>

                </div>


                <div class="col-md-6 col-xl-3">

                    <a
                        href="manage_students.php"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>

                        <div>

                            <div class="quick-link-title">
                                Students
                            </div>

                            <div class="quick-link-description">
                                Manage student accounts
                            </div>

                        </div>

                    </a>

                </div>


                <div class="col-md-6 col-xl-3">

                    <a
                        href="manage_classes.php"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            <i class="fas fa-school"></i>
                        </div>

                        <div>

                            <div class="quick-link-title">
                                Classes
                            </div>

                            <div class="quick-link-description">
                                Manage school classes
                            </div>

                        </div>

                    </a>

                </div>


                <div class="col-md-6 col-xl-3">

                    <a
                        href="settings.php"
                        class="quick-link"
                    >

                        <div class="quick-link-icon">
                            <i class="fas fa-cog"></i>
                        </div>

                        <div>

                            <div class="quick-link-title">
                                Settings
                            </div>

                            <div class="quick-link-description">
                                Configure system settings
                            </div>

                        </div>

                    </a>

                </div>


            </div>

        </div>

    </div>


</div>
```

</main>

<!--
|--------------------------------------------------------------------------
| Delete Confirmation Modal
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="deleteAdminModal"
    tabindex="-1"
    aria-hidden="true"
>

```
<div class="modal-dialog modal-dialog-centered">

    <div class="modal-content border-0 shadow">

        <div class="modal-header">

            <h5 class="modal-title">
                <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                Delete Administrator
            </h5>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="modal"
                aria-label="Close"
            ></button>

        </div>

        <div class="modal-body">

            <p class="mb-2">
                Are you sure you want to delete this administrator?
            </p>

            <div
                class="alert alert-light border"
                id="deleteAdminName"
            ></div>

            <small class="text-muted">
                This action cannot be undone.
            </small>

        </div>

        <div class="modal-footer">

            <button
                type="button"
                class="btn btn-light"
                data-bs-dismiss="modal"
            >
                Cancel
            </button>

            <a
                href="#"
                id="confirmDelete"
                class="btn btn-danger"
            >
                <i class="fas fa-trash-alt me-2"></i>
                Delete Admin
            </a>

        </div>

    </div>

</div>
```

</div>

<!--
|--------------------------------------------------------------------------
| JavaScript
|--------------------------------------------------------------------------
-->

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {

        if (window.innerWidth <= 991) {

            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('show');

        } else {

            const isHidden =
                sidebar.style.transform === 'translateX(-100%)';

            if (isHidden) {

                sidebar.style.transform = 'translateX(0)';
                document.querySelector('.main-content').style.marginLeft = '260px';

            } else {

                sidebar.style.transform = 'translateX(-100%)';
                document.querySelector('.main-content').style.marginLeft = '0';

            }

        }

    }

    sidebarToggle.addEventListener('click', toggleSidebar);


    sidebarOverlay.addEventListener('click', function () {

        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('show');

    });


    /*
    |--------------------------------------------------------------------------
    | Reset Desktop Sidebar After Resize
    |--------------------------------------------------------------------------
    */

    window.addEventListener('resize', function () {

        if (window.innerWidth > 991) {

            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('show');

            sidebar.style.transform = '';
            document.querySelector('.main-content').style.marginLeft = '';

        } else {

            sidebar.style.transform = '';

            document.querySelector('.main-content').style.marginLeft = '';

        }

    });


    /*
    |--------------------------------------------------------------------------
    | Password Visibility
    |--------------------------------------------------------------------------
    */

    const passwordInput =
        document.getElementById('admin_password');

    const passwordToggle =
        document.getElementById('passwordToggle');

    if (passwordInput && passwordToggle) {

        passwordToggle.addEventListener('click', function () {

            const icon =
                this.querySelector('i');

            if (passwordInput.type === 'password') {

                passwordInput.type = 'text';

                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');

                this.setAttribute(
                    'aria-label',
                    'Hide password'
                );

            } else {

                passwordInput.type = 'password';

                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');

                this.setAttribute(
                    'aria-label',
                    'Show password'
                );

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | DataTable
    |--------------------------------------------------------------------------
    */

    if (typeof jQuery !== 'undefined' &&
        document.getElementById('adminsTable')) {

        $('#adminsTable').DataTable({

            pageLength: 10,

            lengthMenu: [
                [5, 10, 25, 50],
                [5, 10, 25, 50]
            ],

            order: [
                [2, 'desc']
            ],

            columnDefs: [
                {
                    orderable: false,
                    targets: 3
                }
            ],

            language: {

                search: '',

                searchPlaceholder:
                    'Search administrators...',

                lengthMenu:
                    'Show _MENU_',

                info:
                    'Showing _START_ to _END_ of _TOTAL_ administrators',

                emptyTable:
                    'No administrators found',

                zeroRecords:
                    'No matching administrators found'

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | Delete Confirmation
    |--------------------------------------------------------------------------
    */

    const deleteModalElement =
        document.getElementById('deleteAdminModal');

    const deleteName =
        document.getElementById('deleteAdminName');

    const confirmDelete =
        document.getElementById('confirmDelete');

    if (deleteModalElement) {

        const deleteModal =
            new bootstrap.Modal(deleteModalElement);

        document.querySelectorAll('.delete-btn').forEach(function (button) {

            button.addEventListener('click', function () {

                const username =
                    this.getAttribute('data-username');

                const deleteUrl =
                    this.getAttribute('data-delete-url');

                deleteName.textContent =
                    username;

                confirmDelete.href =
                    deleteUrl;

                deleteModal.show();

            });

        });

    }


    /*
    |--------------------------------------------------------------------------
    | Auto Hide Alerts
    |--------------------------------------------------------------------------
    */

    setTimeout(function () {

        document.querySelectorAll('.custom-alert').forEach(function (alert) {

            alert.style.transition = 'opacity .4s ease';

            alert.style.opacity = '0';

            setTimeout(function () {

                alert.remove();

            }, 400);

        });

    }, 5000);

});

</script>

</body>
</html>
