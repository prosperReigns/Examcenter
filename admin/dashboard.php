<?php
session_start();
require_once '../db.php';

// Check if admin is logged in
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Admin';

// Count total questions in the system
$questions_query = "SELECT COUNT(*) as total FROM new_questions";
$questions_result = $conn->query($questions_query)->fetch_assoc();
$total_questions = $questions_result['total'] ?? 0;

// Count total teachers
$teachers_query = "SELECT COUNT(*) as total FROM teachers";
$teachers_result = $conn->query($teachers_query)->fetch_assoc();
$total_teachers = $teachers_result['total'] ?? 0;

// Count total students
$students_query = "SELECT COUNT(*) as total FROM students WHERE role = 'student'";
$students_result = $conn->query($students_query)->fetch_assoc();
$total_students = $students_result['total'] ?? 0;

// Count total tests
$tests_query = "SELECT COUNT(*) as total FROM tests";
$tests_result = $conn->query($tests_query)->fetch_assoc();
$total_tests = $tests_result['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/admin-dashboard.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <script src="../js/chart.min.js"></script>
</head>
<body>
    <!-- Navigation bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <button class="btn btn-primary btn-sm toggle-sidebar" id="sidebarToggle">
                <i class="bi bi-list"></i>☰
            </button>
            <a class="navbar-brand" href="dashboard.php">Admin Portal</a>
            <div class="navbar-nav ms-auto">
                <span class="nav-link">Welcome, <?php echo htmlspecialchars($admin_name); ?></span>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar col-md-3 col-lg-2" id="sidebar">
                <div class="p-3">
                    <h5 class="sidebar-heading">Admin Menu</h5>
                    <ul class="nav flex-column">
                        <!-- <li class="nav-item">
                            <a class="nav-link active" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li> -->
                        <li class="nav-item">
                            <a class="nav-link" href="add_teacher.php">
                                <i class="fas fa-chalkboard-teacher"></i> Manage Teachers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="view_questions.php">
                                <i class="fas fa-list"></i> View Questions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="view_results.php">
                                <i class="fas fa-chart-bar"></i> View Results
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="settings.php">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <div class="main-content col-md-9 col-lg-10 p-4">
                <h2 class="mb-4">Admin Dashboard</h2>
                
                <!-- Stats cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Total Questions</h5>
                                <p class="card-text display-4"><?php echo $total_questions; ?></p>
                                <a href="view_questions.php" class="btn btn-sm btn-primary">View All</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Total Teachers</h5>
                                <p class="card-text display-4"><?php echo $total_teachers; ?></p>
                                <a href="manage_teachers.php" class="btn btn-sm btn-primary">Manage</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Total Students</h5>
                                <p class="card-text display-4"><?php echo $total_students; ?></p>
                                <a href="view_students.php" class="btn btn-sm btn-primary">View All</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Total Tests</h5>
                                <p class="card-text display-4"><?php echo $total_tests; ?></p>
                                <a href="view_tests.php" class="btn btn-sm btn-primary">View All</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent activity -->
                <div class="card">
                    <div class="card-header">
                        <h5>Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get recent activities from log
                        $activity_query = "SELECT * FROM activities_log ORDER BY created_at DESC LIMIT 10";
                        $activity_result = $conn->query($activity_query);
                        
                        if ($activity_result && $activity_result->num_rows > 0) {
                            echo '<ul class="list-group">';
                            while ($activity = $activity_result->fetch_assoc()) {
                                echo '<li class="list-group-item">';
                                echo '<small class="text-muted">' . htmlspecialchars($activity['created_at']) . '</small><br>';
                                echo htmlspecialchars($activity['activity']);
                                echo '</li>';
                            }
                            echo '</ul>';
                        } else {
                            echo '<p>No recent activities found.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/sidebar.js"></script>
</body>
</html>