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

$error = '';
$success = '';

// ---------- ADD / UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_name = trim($_POST['class_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $class_id = $_POST['class_id'] ?? null;

    if ($class_name === '') {
        $error = "Class name is required.";
    } else {
        if ($class_id) {
            // UPDATE
            $stmt = $conn->prepare(
                "UPDATE classes SET class_name=?, description=? WHERE id=?"
            );
            $stmt->bind_param("ssi", $class_name, $description, $class_id);
            $stmt->execute();
            $stmt->close();
            $success = "Class updated successfully.";
        } else {
            // ADD
            $stmt = $conn->prepare(
                "INSERT INTO classes (class_name, description) VALUES (?, ?)"
            );
            if (!$stmt) {
                $error = "Class already exists.";
            } else {
                $stmt->bind_param("ss", $class_name, $description);
                $stmt->execute();
                $stmt->close();
                $success = "Class added successfully.";
            }
        }
    }
}

// ---------- DELETE / TOGGLE ----------
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $stmt = $conn->prepare(
        "UPDATE classes SET is_active = IF(is_active=1,0,1) WHERE id=?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $success = "Class status updated.";
}

// ---------- FETCH CLASSES ----------
$classes = [];
$result = $conn->query("SELECT * FROM classes ORDER BY created_at DESC");
while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
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
        <a href="manage_classes.php" class="active"><i class="fas fa-users"></i>Manage Classes</a>
        <a href="manage_session.php"><i class="fas fa-users"></i>Manage Session</a>
        <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
        <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
        <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
        <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</div>

<div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">Manage Classes</h2>
    <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><strong>Add / Edit Class</strong></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="class_id" id="class_id">

                <div class="mb-3">
                    <label class="form-label">Class Name</label>
                    <input type="text" name="class_name" id="class_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control"></textarea>
                </div>

                <button class="btn btn-success">Save Class</button>
                <button type="reset" class="btn btn-secondary" onclick="resetForm()">Cancel</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Classes</strong></div>
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['class_name']) ?></td>
                        <td><?= htmlspecialchars($c['description']) ?></td>
                        <td>
                            <?= $c['is_active'] ? 
                                '<span class="badge bg-success">Active</span>' : 
                                '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning"
                                onclick="editClass(
                                    '<?= $c['id'] ?>',
                                    '<?= htmlspecialchars($c['class_name'], ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($c['description'], ENT_QUOTES) ?>'
                                )">
                                Edit
                            </button>

                            <a href="?toggle=<?= $c['id'] ?>"
                            class="btn btn-sm btn-danger"
                            onclick="return confirm('Change class status?')">
                            <?= $c['is_active'] ? 'Disable' : 'Enable' ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
    function editClass(id, name, desc) {
        document.getElementById('class_id').value = id;
        document.getElementById('class_name').value = name;
        document.getElementById('description').value = desc;
    }

    function resetForm() {
        document.getElementById('class_id').value = '';
    }
</script>

</body>
</html>