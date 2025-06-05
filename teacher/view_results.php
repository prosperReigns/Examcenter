<?php
session_start();
require_once '../db.php';

// Check if teacher is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
$teacher_id = $_SESSION['user_id'];

// Get results for tests created by this teacher
$results_query = "SELECT r.id, r.user_id, r.test_id, r.score, r.total_questions, r.created_at, 
                 t.title as test_title, t.class, t.subject, s.name as student_name 
                 FROM results r 
                 JOIN tests t ON r.test_id = t.id 
                 JOIN students s ON r.user_id = s.id 
                 WHERE t.created_by = ? 
                 ORDER BY r.created_at DESC";

$stmt = $conn->prepare($results_query);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/view_results.css" rel="stylesheet">
    <link href="../css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body>
    <!-- navigation bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <button class="btn btn-primary btn-sm toggle-sidebar" id="sidebarToggle">
                <i class="bi bi-list"></i>☰
            </button>
            <a class="navbar-brand" href="dashboard.php">Teacher Portal</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar col-md-3 col-lg-2" id="sidebar">
                <div class="p-3">
                    <h5 class="sidebar-heading">Teacher Menu</h5>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="add_question.php">
                                <i class="fas fa-question-circle"></i> Add Questions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="view_questions.php">
                                <i class="fas fa-list"></i> View Questions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="view_results.php">
                                <i class="fas fa-chart-bar"></i> View Results
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profile.php">
                                <i class="fas fa-user"></i> My Profile
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <div class="main-content col-md-9 col-lg-10 p-4">
                <h2 class="mb-4">View Test Results</h2>
                
                <div class="card">
                    <div class="card-header">
                        <h5>Results for Your Tests</h5>
                    </div>
                    <div class="card-body">
                        <table id="resultsTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Test</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($results as $result): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['test_title']); ?></td>
                                        <td><?php echo htmlspecialchars($result['class']); ?></td>
                                        <td><?php echo htmlspecialchars($result['subject']); ?></td>
                                        <td><?php echo $result['score'] . '/' . $result['total_questions']; ?></td>
                                        <td>
                                            <?php 
                                            $percentage = ($result['score'] / $result['total_questions']) * 100;
                                            echo number_format($percentage, 1) . '%';
                                            ?>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($result['created_at'])); ?></td>
                                        <td>
                                            <a href="view_result_detail.php?id=<?php echo $result['id']; ?>" class="btn btn-sm btn-info">Details</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery.dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/sidebar.js"></script>
    <script>
        $(document).ready(function() {
            $('#resultsTable').DataTable({
                "order": [[6, "desc"]]
            });
        });
    </script>
</body>
</html>