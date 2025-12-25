<?php
session_start();
require_once '../db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

$database = Database::getInstance();
$conn = $database->getConnection();

// Fetch admin info from DB
$admin_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role FROM admins WHERE id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if (!$admin || strtolower($admin['role']) !== 'admin') {
    session_destroy();
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

$error = $success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subject'])) {
        $subject_name = trim($_POST['subject_name']);
        $class_levels = $_POST['class_level'] ?? [];

        if (!empty($subject_name) && !empty($class_levels)) {
            // 1. Insert subject if it doesn't exist
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ?");
            $stmt->bind_param("s", $subject_name);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $subject_id = $result->fetch_assoc()['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO subjects (subject_name) VALUES (?)");
                $stmt->bind_param("s", $subject_name);
                $stmt->execute();
                $subject_id = $stmt->insert_id;
            }
            $stmt->close();

            // 2. Link subject to all selected class levels
            $added = 0;
            foreach ($class_levels as $level) {
                $stmt = $conn->prepare("INSERT IGNORE INTO subject_levels (subject_id, class_level) VALUES (?, ?)");
                $stmt->bind_param("is", $subject_id, $level);
                if ($stmt->execute()) $added++;
                $stmt->close();
            }

            if ($added > 0) {
                $success = "Subject linked to selected class levels successfully.";
            } else {
                $error = "Subject already exists for the selected levels.";
            }
        } else {
            $error = "Subject name and class level are required.";
        }

    }

    if (isset($_POST['delete_subject'])) {
        $subject_id = (int)$_POST['subject_id'];
        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->bind_param("i", $subject_id);
        if ($stmt->execute()) {
            $success = "Subject deleted successfully.";
        } else {
            $error = "Error deleting subject.";
        }
        $stmt->close();
    }
}

$available_level = ["JSS", 'SS', "PRIMARY"];
// Fetch all subjects
$subjects = [];
$result = $conn->query("
   SELECT 
        s.id,
        s.subject_name,
        GROUP_CONCAT(sl.class_level ORDER BY sl.class_level SEPARATOR ', ') AS class_levels
    FROM subjects s
    LEFT JOIN subject_levels sl ON s.id = sl.subject_id
    GROUP BY s.id, s.subject_name
    ORDER BY s.subject_name
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects | Admin</title>
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
        <a href="manage_session.php"><i class="fas fa-users"></i>Manage Session</a>
        <a href="manage_subject.php" class="active"><i class="fas fa-users"></i>Manage Subject</a>
        <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
        <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
        <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</div>

<div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">Manage Subjects</h2>
    <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5>Add New Subject</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="subject_name" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="subject_name" name="subject_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="class_level" class="form-label">Class Level</label>
                            <select class="form-select" id="class_level" name="class_level[]" multiple required>
                                <?php foreach($available_level as $cl): ?>
                                    <option value="<?= htmlspecialchars($cl) ?>"><?= htmlspecialchars($cl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple levels</small>
                        </div>

                        <button type="submit" name="add_subject" class="btn btn-primary">Add Subject</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Existing Subjects</h5>
                </div>
                <div class="card-body">
                    <table class="table table-striped" id="subjectsTable">
                        <thead>
                            <tr>
                                <th>Subject Name</th>
                                <th>Class Level</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($subject['class_levels'] ?? "not linked")?></td>
                                    <td>
                                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this subject?');">
                                            <input type="hidden" name="subject_id" value="<?php echo (int)$subject['id']; ?>">
                                            <button type="submit" name="delete_subject" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
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
<script src="../js/dataTables.min.js"></script>
<script src="../js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#sidebarToggle').click(function() {
            $('.sidebar').toggleClass('active');
        });
    $('#subjectsTable').DataTable({
        "pageLength": 10,
        "lengthChange": false,
        "ordering": true,
        "columnDefs": [
            { "orderable": false, "targets": 3 } // Disable ordering on action column
        ]
    });
});
</script>

</body>
</html>
