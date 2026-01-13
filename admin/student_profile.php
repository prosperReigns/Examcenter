<?php
// manage_session.php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Enable error reporting (dev)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// --- Authentication (admin) ---
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, role FROM admins WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'admin') {
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch admin profile
    $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Student Profile | Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/all.css">
<link rel="stylesheet" href="../css/sidebar.css">
<link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
<style>
/* Simple two-column layout: vertical left years, right workspace */
.page-wrap { display:flex; gap:1rem; padding:1rem; }
.left-col { width:260px; }  
.year-list { max-height:70vh; overflow:auto; }
.year-item { display:flex; align-items:center; justify-content:space-between; gap:0.5rem; margin-bottom:0.5rem; }
.year-item .actions { display:flex; gap:0.35rem; }
.right-col { flex:1; min-height:70vh; background:#fff; padding:1rem; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
.section { margin-bottom:1rem; }
.result-box { padding:1rem; border-radius:6px; background:#f8f9fa; }
.radio-list { display:flex; flex-direction:column; gap:0.5rem; }
.small-muted { font-size:0.9rem; color:#6c757d; }
.moveable-content.sidebar-active {
    margin-left: 250px; /* same as sidebar width */
    transition: margin-left 0.3s ease;
}

/* Optional: make the selection box narrower when sidebar opens */
.moveable-content.sidebar-active .right-col .result-box {
    max-width: 90%; /* or adjust as needed */
}
</style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><b><?php echo htmlspecialchars($admin['username']); ?></b></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
            <a href="manage_session.php" class="active"><i class="fas fa-users"></i>Manage Session</a>
            <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
            <a href="manage_students.php"><i class="fas fa-users"></i>Manage Student</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

<div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Manage Academic Sessions</h2>
        <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>
</div>

</body>
</html>