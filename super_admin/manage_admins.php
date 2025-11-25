<?php
session_start();
require_once '../db.php';

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

// Handle add admin form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_username'])) {
    $newUsername = trim($_POST['admin_username']);
    $newPassword = $_POST['admin_password'];

    if ($newUsername === '' || $newPassword === '') {
        $errorMsg = "Username and password are required.";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Check duplicate
        $check = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $check->bind_param("s", $newUsername);
        $check->execute();
        $dupResult = $check->get_result();

        if ($dupResult->num_rows > 0) {
            $errorMsg = "Username already exists.";
        } else {
            $insert = $conn->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, 'admin')");
            $insert->bind_param("ss", $newUsername, $hashedPassword);

            if ($insert->execute()) {
                $success = "Admin added successfully!";
            } else {
                $errorMsg = "Database error while adding admin.";
            }
            $insert->close();
        }
        $check->close();
    }
}

// Handle delete admin action
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    // Prevent deleting the logged-in super admin
    if ($delete_id === $user_id) {
        $errorMsg = "You cannot delete yourself!";
    } else {
        $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->bind_param("i", $delete_id);

        if ($stmt->execute()) {
            $success = "Admin deleted successfully!";
        } else {
            $errorMsg = "Database error while deleting admin.";
        }
        $stmt->close();
    }
}

// Fetch all admins
$admins_result = $conn->query("SELECT id, username, role FROM admins ORDER BY id DESC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins | Super Admin</title>
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
        <a href="manage_admins.php" class="active"><i class="fas fa-users-cog"></i>Manage Admins</a>
        <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        <a href="../admin/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</div>

<!-- Main Content -->
<div class="content container mt-5">

    <h2 class="mb-4 fw-bold">Manage Admins</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?php echo $errorMsg; ?></div>
    <?php endif; ?>

    <!-- Add Admin Form -->
    <div class="card p-4 mb-4 shadow-sm">
        <h5 class="mb-3">Add New Admin</h5>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Admin Username</label>
                <input type="text" name="admin_username" class="form-control" placeholder="Enter username" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Admin Password</label>
                <input type="password" name="admin_password" class="form-control" placeholder="Enter password" required>
            </div>

            <button type="submit" class="btn btn-primary">Add Admin</button>
        </form>
    </div>

    <!-- Existing Admins Table -->
    <h4 class="mb-3">Existing Admins</h4>
    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($adm = $admins_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($adm['username']); ?></td>
                    <td><?php echo htmlspecialchars($adm['role']); ?></td>
                    <td>
                        <?php if ($adm['id'] !== $user_id): ?>
                            <a href="?delete_id=<?php echo $adm['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this admin?');">Delete</a>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    $('#sidebarToggle').click(function() {
            $('.sidebar').toggleClass('active');
        });
});
</script>
</body>
</html>
