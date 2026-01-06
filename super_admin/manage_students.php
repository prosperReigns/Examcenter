<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check super admin authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
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

// Initialize messages
$success = '';
$errorMsg = '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | Super Admin</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
        <div class="admin-info">
            <small>Welcome back,</small>
            <h6><?php echo htmlspecialchars($super_admin['username']); ?></h6>
        </div>
    </div>
    <div class="sidebar-menu mt-4">
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
        <a href="manage_admins.php"><i class="fas fa-users-cog"></i>Manage Admins</a>
        <a href="manage_classes.php"><i class="fas fa-users-cog"></i>Manage Classes</a>
        <a href="manage_session.php"><i class="fas fa-users-cog"></i>Manage Session</a>
        <a href="manage_students.php" class="active"><i class="fas fa-users-cog"></i>Manage Students</a>
        <a href="manage_subject.php"><i class="fas fa-users-cog"></i>Manage Subject</a>
        <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        <a href="../admin/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</div>

<!-- Main Content -->
<div class="content container mt-5">

    <h2 class="mb-4 fw-bold">Manage Students</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?php echo $errorMsg; ?></div>
    <?php endif; ?>

    </div>
</body>
</html>