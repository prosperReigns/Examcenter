<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// 
header('Content-Type: text/html; charset=UTF-8');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

// Initialize database connection
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

      // Initialize variables
      $error = $success = '';
    // Fetch teacher profile and assigned subjects
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Get teacher's assigned class IDs
    $stmt = $conn->prepare("
    SELECT class_id 
    FROM teacher_classes 
    WHERE teacher_id = ?
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_class_ids = [];
    while ($row = $result->fetch_assoc()) {
    $assigned_class_ids[] = $row['class_id'];
    }
    $stmt->close();

    // If no classes assigned
    if (empty($assigned_class_ids)) {
    $error = "You are not assigned to any class yet.";
    }

    // Fetch students for the assigned classes
    $students = [];
    if (!empty($assigned_class_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_class_ids), '?'));
    $types = str_repeat('i', count($assigned_class_ids));

    $sql = "SELECT s.id, s.name, s.class, c.class_name 
    FROM students s
    JOIN classes c ON s.class = c.id
    WHERE s.class IN ($placeholders)
    ORDER BY c.class_name, s.name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$assigned_class_ids);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    }
} catch (Exception $e) {
    error_log("Manage student error: ". $e->getMessage());
     echo "<pre>System error: " . $e->getMessage() . "</pre>";
    die("Unable to fetch student details. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($teacher['last_name']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" class="active"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="manage_test.php"><i class="fas fa-list"></i>Manage Test</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="manage_students.php" style="text-decoration: line-through"><i class="fas fa-users"></i>Manage Students</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="my-profile.php"><i class="fas fa-user"></i>My Profile</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Manage Students</h2>
            <div class="header-actions">
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="container mt-4">
            <?php if (!empty($students)): ?>
                <table class="table table-bordered table-striped mt-3" id="studentsTable">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                        <tr class="student-name" data-id="<?php echo (int)$student['id']; ?>">
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                            <td>
                                <a href="student_profile.php?id=<?php echo $student['id']; ?>" class="btn btn-primary btn-sm">
                                    View Profile
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No students found for your assigned classes.</p>
            <?php endif; ?>
        </div>

    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle sidebar on mobile
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Make the student name clickable
            $('#studentsTable tbody').on('click', 'td.student-name', function() {
                const studentId = $(this).data('id');
                if (studentId) {
                    window.location.href = `student_profile.php?id=${studentId}`;
                }
            });

            // Optional: highlight row on hover
            $('#studentsTable tbody tr').hover(
                function() { $(this).css('background-color', '#f0f0f0'); },
                function() { $(this).css('background-color', ''); }
            );
        });

    </script>
</body>
</html>