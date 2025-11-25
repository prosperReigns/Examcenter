<?php
session_start();
require_once '../db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check admin authentication
if (!isset($_SESSION['user_id'])) {
    error_log("Redirecting to login: No user_id in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, role FROM admins WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'admin') {
        error_log("Unauthorized access attempt by user_id=$user_id, role=" . ($user['role'] ?? 'none'));
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch admin profile
    $admin_id = $user_id;
    $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin) {
        error_log("No admin found for user_id=$admin_id");
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

// Handle form submission for adding an academic year
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_year'])) {
    $newYear = trim($_POST['new_year']);

    if ($newYear == '') {
        $errorMsg = "Academic year cannot be empty.";
    } else {
        $stmt = $conn->prepare("INSERT INTO academic_years (year) VALUES (?)");
        if (!$stmt) {
            $errorMsg = "Database error.";
        } else {
            $stmt->bind_param("s", $newYear);
            if ($stmt->execute()) {
                $success = "Academic year added successfully!";
            } else {
                $errorMsg = "Academic year already exists!";
            }
            $stmt->close();
        }
    }
}

// Handle deletion of an academic year
if (isset($_GET['delete_year'])) {
    $delete_year = $_GET['delete_year'];

    $stmt = $conn->prepare("DELETE FROM academic_years WHERE year = ?");
    if ($stmt) {
        $stmt->bind_param("s", $delete_year);
        if ($stmt->execute()) {
            $success = "Academic year '$delete_year' deleted successfully!";
        } else {
            $errorMsg = "Database error while deleting the academic year.";
        }
        $stmt->close();
    } else {
        $errorMsg = "Database error.";
    }
}

// Fetch existing academic years
$years_result = $conn->query("SELECT year FROM academic_years ORDER BY year ASC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Session | Admin</title>
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
            <h6><?php echo htmlspecialchars($admin['username']); ?></h6>
        </div>
    </div>
    <div class="sidebar-menu mt-4">
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
        <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
        <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
        <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
        <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
        <a href="manage_session.php" class="active"><i class="fas fa-user-plus"></i>Manage Session</a>
        <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
        <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
        <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</div>

<div class="content">
<div class="container mt-5">
    <h2 class="mb-4">Manage Academic Session</h2>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger"><?php echo $errorMsg; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-bold">Add New Academic Year</label>
            <input type="text" name="new_year" class="form-control" placeholder="E.g. 2030/2031" required>
        </div>
        <button type="submit" class="btn btn-primary">Add Year</button>
    </form>

    <hr class="my-4">

    <h4>Existing Academic Years</h4>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Academic Year</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($y = $years_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($y['year']); ?></td>
                    <td>
                        <a href="?delete_year=<?php echo urlencode($y['year']); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this academic year?');">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>

<script>
    $(document).ready(function() {
        $('#sidebarToggle').click(function() {
            $('.sidebar').toggleClass('active');
        });
    });
</script>
</body>
</html>
