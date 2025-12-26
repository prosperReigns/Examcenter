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

// Fetch class groups dynamically from academic_levels
// Fixed class groups (ENUM-backed)
$class_groups = ['PRIMARY', 'JSS', 'SS'];

$conn->begin_transaction();
try {
    // ---------- ADD CLASS ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $class_id = $_POST['class_id'] ?? null;
        $class_group = strtoupper(trim($_POST['class_group'] ?? ''));
        $level_code  = strtoupper(trim($_POST['level_code'] ?? ''));
        $stream_name = ucfirst(strtolower(trim($_POST['stream_name'] ?? '')));


        if (!$class_group || !$level_code || !$stream_name) {
            $error = "All fields are required.";
        } else {

            // ✅ VALIDATE FIRST — BEFORE ANY DB OPERATION
            $group_upper = strtoupper($class_group);
            $level_upper = strtoupper($level_code);
        
            $valid = false;
        
            if ($group_upper === 'JSS' && str_starts_with($level_upper, 'JSS')) $valid = true;
            elseif ($group_upper === 'SS' && str_starts_with($level_upper, 'SS')) $valid = true;
            elseif ($group_upper === 'PRIMARY' && str_starts_with($level_upper, 'PRY')) $valid = true;
        
            if (!$valid) {
                $error = "Level Code '$level_code' does not match Class Group '$class_group'.";
                // ⛔ STOP EXECUTION HERE
            } else {
        
                // --- Step 1: Academic Level ---
                $stmt = $conn->prepare(
                    "SELECT id FROM academic_levels WHERE level_code=? AND class_group=?"
                );
                $stmt->bind_param("ss", $level_code, $class_group);
                $stmt->execute();
                $result = $stmt->get_result();
        
                if ($result->num_rows > 0) {
                    $academic_level_id = $result->fetch_assoc()['id'];
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO academic_levels(level_code,class_group) VALUES(?,?)"
                    );
                    $stmt->bind_param("ss", $level_code, $class_group);
                    $stmt->execute();
                    $academic_level_id = $stmt->insert_id;
                }
        
                // --- Step 2: Stream ---
                $stmt = $conn->prepare("SELECT id FROM streams WHERE stream_name=?");
                $stmt->bind_param("s", $stream_name);
                $stmt->execute();
                $result = $stmt->get_result();
        
                if ($result->num_rows > 0) {
                    $stream_id = $result->fetch_assoc()['id'];
                } else {
                    $stmt = $conn->prepare("INSERT INTO streams(stream_name) VALUES(?)");
                    $stmt->bind_param("s", $stream_name);
                    $stmt->execute();
                    $stream_id = $stmt->insert_id;
                }
        
                // --- Step 3: Class ---
                $class_name = $level_code . ' ' . $stream_name;
        
                if ($class_id) {
                    $stmt = $conn->prepare(
                        "UPDATE classes SET academic_level_id=?, stream_id=?, class_name=? WHERE id=?"
                    );
                    $stmt->bind_param("iisi", $academic_level_id, $stream_id, $class_name, $class_id);
                    $stmt->execute();
                    $success = "Class updated successfully.";
                } else {
                    $stmt = $conn->prepare(
                        "SELECT id FROM classes WHERE academic_level_id=? AND stream_id=?"
                    );
                    $stmt->bind_param("ii", $academic_level_id, $stream_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
        
                    if ($result->num_rows > 0) {
                        $error = "Class already exists.";
                    } else {
                        $stmt = $conn->prepare(
                            "INSERT INTO classes(academic_level_id,stream_id,class_name) VALUES(?,?,?)"
                        );
                        $stmt->bind_param("iis", $academic_level_id, $stream_id, $class_name);
                        $stmt->execute();
                        $success = "Class added successfully.";
                    }
                }
            }
        }
    } elseif (isset($_GET['toggle'])) {
        $id = (int)$_GET['toggle'];
        $stmt = $conn->prepare("UPDATE classes SET is_active=IF(is_active=1,0,1) WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $success = "Class status updated.";
    }
    if ($error) {
        $conn->rollback();
    } else {
        $conn->commit();
    }    
} catch (Exception $e) {
    $conn->rollback();
    $error = "Operation failed: " . $e->getMessage();
} 

// ---------- FETCH CLASSES ----------
$classes = [];
$result = $conn->query("
    SELECT c.id, c.is_active, c.class_name, al.level_code, s.stream_name
    FROM classes c
    JOIN academic_levels al ON c.academic_level_id = al.id
    JOIN streams s ON c.stream_id = s.id
    ORDER BY al.level_code, s.stream_name
");
while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}
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
        <a href="manage_admins.php"><i class="fas fa-users-cog"></i>Manage Admins</a>
        <a href="manage_classes.php" class="active"><i class="fas fa-users-cog"></i>Manage Classes</a>
        <a href="manage_session.php"><i class="fas fa-users-cog"></i>Manage Session</a>
        <a href="manage_students.php"><i class="fas fa-users-cog"></i>Manage Students</a>
        <a href="manage_subject.php"><i class="fas fa-users-cog"></i>Manage Subject</a>
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

    <div class="card mb-4">
        <div class="card-header"><strong>Add / Edit Class</strong></div>
        <div class="card-body">
            <form method="post">
            <div class="mb-3">
                    <label>Class Group</label>
                    <div class="input-group">
                        <select id="class_group" name="class_group" class="form-control" required>
                            <option value="">-- Select Group --</option>
                            <?php foreach($class_groups as $cg): ?>
                                <option value="<?= htmlspecialchars($cg) ?>"><?= htmlspecialchars($cg) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-primary" id="addGroupBtn" disabled>+</button>
                    </div>
                </div>

                <div class="mb-3">
                    <label>Level Code</label>
                    <input type="text" id="level_code" name="level_code" class="form-control" placeholder="-- JSS1 --" required>
                </div>

                <div class="mb-3">
                    <label>Stream Name</label>
                    <input type="text" id="stream_name" name="stream_name" class="form-control" placeholder="-- Gold --" required>
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
                        <th>Status</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $c): ?>
                    <tr>
                    <td><?= htmlspecialchars($c['level_code'] . ' ' . $c['stream_name']) ?></td>
                        <td>
                            <?= $c['is_active'] ? 
                                '<span class="badge bg-success">Active</span>' : 
                                '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning" disabled>
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
    $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            })
        });
</script>
    
<script>
    // Add new class group
    document.getElementById('addGroupBtn').addEventListener('click', () => {
        const newGroup = prompt("Enter new Class Group (e.g., Primary, JSS, SS):");
        if (newGroup) {
            const select = document.getElementById('class_group');
            const option = document.createElement('option');
            option.value = newGroup;
            option.text = newGroup;
            option.selected = true;
            select.add(option);
        }
    });

</script>
</body>
</html>