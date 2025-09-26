<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php'; // Adjust path if PHPWord is elsewhere

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

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

    // Fetch assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
    if (!$stmt) {
        error_log("Prepare failed for assigned subjects: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    if (empty($assigned_subjects)) {
        $error = "No subjects assigned to you. Contact your admin.";
    }

    // Define subjects by category
    $jss_subjects = [
        'Mathematics', 'English', 'ICT', 'Agriculture', 'History',
        'Civic Education', 'Basic Science', 'Basic Technology',
        'Business studies', 'Physical Health Edu',
        'Cultural and Creative Art', 'Social Studies', 'Security Edu',
        'Yoruba', 'French', 'Coding and Robotics', 'C.R.S', 'I.R.S', 'Chess'
    ];
    $ss_subjects = [
        'Mathematics', 'English', 'Civic Edu', 'Data Processing', 'Economics',
        'Government', 'Commerce', 'Accounting',
        'Dyeing and Bleaching', 'Physics', 'Chemistry', 'Biology',
        'Agriculture', 'Geography', 'Technical Drawing', 'Yoruba',
        'French', 'Further Maths', 'Literature in English', 'C.R.S', 'I.R.S'
    ];
    $result = $conn->query("SELECT * FROM tests ORDER BY id DESC");
} catch (Exception $e) {
    error_log("View results error: " . $e->getMessage());
    die("System error");
}
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Download Tests</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/add_question.css"> 
    <!-- <link rel="stylesheet" href="../css/sidebar.css"> -->
</head>
<body class="container py-5">
<div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($teacher['last_name']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="add_question.php">
                <i class="fas fa-plus-circle"></i>
                Add Questions
            </a>
            <a href="view_questions.php">
                <i class="fas fa-list"></i>
                View Questions
            </a>
            <a href="manage_test.php" class="active">
                <i class="fas fa-list"></i>
                Manage Test
            </a>
            <a href="view_results.php">
                <i class="fas fa-chart-bar"></i>
                Exam Results
            </a>
            <a href="manage_students.php" style="text-decoration: line-through">
                <i class="fas fa-users"></i>
                Manage Students
            </a>
            <a href="settings.php">
                <i class="fas fa-cog"></i>
                Settings
            </a>
            <a href="my-profile.php">
                <i class="fas fa-user"></i>
                My Profile
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Manage Test</h2>
            <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

    <h2 class="mb-4">Available Tests</h2>
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Title</th>
                <th>Class</th>
                <th>Subject</th>
                <th>Duration (mins)</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['class']) ?></td>
                <td><?= htmlspecialchars($row['subject']) ?></td>
                <td><?= htmlspecialchars($row['duration']) ?></td>
               <td>
                    <a class="btn btn-sm btn-primary" 
                    href="download.php?class=<?= urlencode($row['class']) ?>&subject=<?= urlencode($row['subject']) ?>&title=<?= urlencode($row['title']) ?>">
                    Download
                    </a>
                    <button class="btn btn-sm btn-danger delete-test" 
                            data-id="<?= $row['id'] ?>" 
                            data-title="<?= htmlspecialchars($row['title']) ?>">
                        Delete
                    </button>
                </td>

            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle sidebar on mobile
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });
        });
    </script>
    <script>
$(document).ready(function() {
    // Delete test handler
    $('.delete-test').click(function() {
        const testId = $(this).data('id');
        const testTitle = $(this).data('title');
        if (confirm(`Are you sure you want to delete the test "${testTitle}"? This action cannot be undone.`)) {
            $.ajax({
                url: 'delete_test.php',
                type: 'POST',
                data: { id: testId },
                success: function(response) {
                    const res = JSON.parse(response);
                    if (res.success) {
                        alert('Test deleted successfully.');
                        location.reload();
                    } else {
                        alert('Error: ' + res.error);
                    }
                },
                error: function() {
                    alert('An unexpected error occurred.');
                }
            });
        }
    });
});
</script>

</body>
</html>
