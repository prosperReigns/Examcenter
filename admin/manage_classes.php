<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/universal_architecture.php';
//require_once '../license/license_guard.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?error=Unauthorized");
    exit();
}

$database = Database::getInstance();
$conn = $database->getConnection();

// Fetch admin info
$admin_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role FROM admins WHERE id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || strtolower($admin['role']) !== 'admin') {
    session_destroy();
    header("Location: ../login.php?error=Unauthorized");
    exit();
}

$error = '';
$success = '';
$class_groups = ['PRIMARY', 'JSS', 'SS'];
$universal_schema_available = false;
$institution_id = null;
$unit_types = [];
$units = [];
$courses = [];

try {
    $universal_schema_available = examcenterUniversalTableExists($conn, 'institutions')
        && examcenterUniversalTableExists($conn, 'organizational_unit_types')
        && examcenterUniversalTableExists($conn, 'organizational_units')
        && examcenterUniversalTableExists($conn, 'courses');

    if ($universal_schema_available) {
        $institution_id = examcenterActiveInstitutionId($conn);
        if (!$institution_id) {
            $universal_schema_available = false;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'legacy_class';

        if ($action === 'universal_unit' || $action === 'course') {
            if (!$universal_schema_available) {
                throw new RuntimeException('Universal structure tables are not installed. Apply the approved schema migration first.');
            }

            $name = trim($_POST['name'] ?? '');
            $code = strtoupper(trim($_POST['code'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('A name is required.');
            }

            $conn->begin_transaction();
            if ($action === 'course') {
                $course_type = trim($_POST['course_type'] ?? 'course') ?: 'course';
                $stmt = $conn->prepare("INSERT INTO courses (institution_id, course_code, course_name, course_type) VALUES (?, NULLIF(?, ''), ?, ?)");
                $stmt->bind_param('isss', $institution_id, $code, $name, $course_type);
                $stmt->execute();
                $stmt->close();
                $success = 'Course created successfully.';
            } else {
                $type_code = strtoupper(trim($_POST['unit_type'] ?? 'DEPARTMENT'));
                $parent_id = (int)($_POST['parent_id'] ?? 0);
                $stmt = $conn->prepare("SELECT id FROM organizational_unit_types WHERE type_code = ? LIMIT 1");
                $stmt->bind_param('s', $type_code);
                $stmt->execute();
                $type = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$type) {
                    $type_name = ucwords(strtolower(str_replace('_', ' ', $type_code)));
                    $stmt = $conn->prepare("INSERT INTO organizational_unit_types (type_code, type_name) VALUES (?, ?)");
                    $stmt->bind_param('ss', $type_code, $type_name);
                    $stmt->execute();
                    $type_id = $stmt->insert_id;
                    $stmt->close();
                } else {
                    $type_id = (int)$type['id'];
                }
                $stmt = $conn->prepare("INSERT INTO organizational_units (institution_id, unit_type_id, parent_unit_id, unit_code, unit_name) VALUES (?, ?, NULLIF(?, 0), NULLIF(?, ''), ?)");
                $stmt->bind_param('iiiss', $institution_id, $type_id, $parent_id, $code, $name);
                $stmt->execute();
                $stmt->close();
                $success = 'Organizational unit created successfully.';
            }
            $conn->commit();
        } elseif ($action === 'legacy_class') {
            $class_id = $_POST['class_id'] ?? null;
            $class_group = strtoupper(trim($_POST['class_group'] ?? ''));
            $level_code = strtoupper(trim($_POST['level_code'] ?? ''));
            $stream_name = ucfirst(strtolower(trim($_POST['stream_name'] ?? '')));
            if (!$class_group || !$level_code || !$stream_name) {
                throw new InvalidArgumentException('All class fields are required.');
            }
            $valid = ($class_group === 'JSS' && str_starts_with($level_code, 'JSS'))
                || ($class_group === 'SS' && str_starts_with($level_code, 'SS'))
                || ($class_group === 'PRIMARY' && str_starts_with($level_code, 'PRY'));
            if (!$valid) {
                throw new InvalidArgumentException("Level Code '$level_code' does not match Class Group '$class_group'.");
            }
            $conn->begin_transaction();
            $stmt = $conn->prepare("SELECT id FROM academic_levels WHERE level_code=? AND class_group=?");
            $stmt->bind_param('ss', $level_code, $class_group);
            $stmt->execute();
            $level = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($level) {
                $academic_level_id = (int)$level['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO academic_levels(level_code,class_group) VALUES(?,?)");
                $stmt->bind_param('ss', $level_code, $class_group);
                $stmt->execute();
                $academic_level_id = $stmt->insert_id;
                $stmt->close();
            }
            $stmt = $conn->prepare("SELECT id FROM streams WHERE stream_name=?");
            $stmt->bind_param('s', $stream_name);
            $stmt->execute();
            $stream = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($stream) {
                $stream_id = (int)$stream['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO streams(stream_name) VALUES(?)");
                $stmt->bind_param('s', $stream_name);
                $stmt->execute();
                $stream_id = $stmt->insert_id;
                $stmt->close();
            }
            $class_name = $level_code . ' ' . $stream_name;
            if ($class_id) {
                $stmt = $conn->prepare("UPDATE classes SET academic_level_id=?, stream_id=?, class_name=? WHERE id=?");
                $stmt->bind_param('iisi', $academic_level_id, $stream_id, $class_name, $class_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO classes(academic_level_id,stream_id,class_name) VALUES(?,?,?)");
                $stmt->bind_param('iis', $academic_level_id, $stream_id, $class_name);
            }
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            $success = $class_id ? 'Class updated successfully.' : 'Class added successfully.';
        }
    } elseif (isset($_GET['toggle'])) {
        $id = (int)$_GET['toggle'];
        $stmt = $conn->prepare("UPDATE classes SET is_active=IF(is_active=1,0,1) WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $success = 'Class status updated.';
    }
} catch (Throwable $e) {
    @$conn->rollback();
    $error = 'Operation failed: ' . $e->getMessage();
}

$classes = [];
$result = $conn->query("SELECT c.id, c.is_active, c.class_name, al.level_code, s.stream_name FROM classes c JOIN academic_levels al ON c.academic_level_id = al.id JOIN streams s ON c.stream_id = s.id ORDER BY al.level_code, s.stream_name");
if ($result) {
    while ($row = $result->fetch_assoc()) $classes[] = $row;
}

if ($universal_schema_available) {
    $result = $conn->query("SELECT ou.id, ou.unit_name, ou.unit_code, ou.status, COALESCE(ut.type_name, 'Unit') AS type_name, p.unit_name AS parent_name FROM organizational_units ou JOIN organizational_unit_types ut ON ut.id = ou.unit_type_id LEFT JOIN organizational_units p ON p.id = ou.parent_unit_id WHERE ou.institution_id = " . (int)$institution_id . " ORDER BY ou.unit_name");
    if ($result) while ($row = $result->fetch_assoc()) $units[] = $row;
    $result = $conn->query("SELECT id, course_code, course_name, course_type, status FROM courses WHERE institution_id = " . (int)$institution_id . " ORDER BY course_name");
    if ($result) while ($row = $result->fetch_assoc()) $courses[] = $row;
    $result = $conn->query("SELECT DISTINCT type_code, type_name FROM organizational_unit_types ORDER BY type_name");
    if ($result) while ($row = $result->fetch_assoc()) $unit_types[] = $row;
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
    <style>
        .card{
            border:none;
            border-radius:12px;
            box-shadow:0 .125rem .5rem rgba(0,0,0,.08);
        }
        .card-header{
            font-weight:600;
        }
        .table td{
            vertical-align:middle;
        }
        .btn{
            border-radius:8px;
        }
        .badge{
            padding:.55em .9em;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<?php require __DIR__ . '/sidebar.php'; ?>

<div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">Manage Classes</h2>
    <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
        <div class="row mb-4">

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Total Classes</small>
                    <h2><?= count($classes) ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Active Classes</small>
                    <h2><?= count(array_filter($classes, fn($c)=>$c['is_active'])) ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Inactive Classes</small>
                    <h2><?= count(array_filter($classes, fn($c)=>!$c['is_active'])) ?></h2>
                </div>
            </div>
        </div>

    </div>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white"><i class="fas fa-school me-2"></i>Add / Edit Class</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="legacy_class">
            <div class="mb-3">
                    <label class="mb-3">Class Group</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-layer-group"></i>
                        </span>
                        <select id="class_group" name="class_group" class="form-select" required>
                            <option value="">-- Select Group --</option>
                            <?php foreach($class_groups as $cg): ?>
                                <option value="<?= htmlspecialchars($cg) ?>"><?= htmlspecialchars($cg) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="input-group mb-3">
                     <span class="input-group-text me-3">
                        <i class="fas fa-code"></i>
                    </span>

                    <label class="me-3">Level Code</label>
                    <input type="text" id="level_code" name="level_code" class="form-control" placeholder="-- JSS1 --" required>
                </div>

                <div class="input-group">
                    <span class="input-group-text me-3">
                        <i class="fas fa-users"></i>
                    </span>
                    <label class="me-3">Stream Name</label>
                    <input type="text" id="stream_name" name="stream_name" class="form-control" placeholder="-- Gold --" required>
                </div>


                <button class="btn btn-success px-4 mt-3"><i class="fas fa-save me-2"></i>Save Class</button>
                <button type="reset" class="btn btn-outline-secondary px-4 mt-3" onclick="resetForm()"><i class="fas fa-times me-2"></i>Cancel</button>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <i class="fas fa-sitemap me-2"></i>Universal Structure
        </div>
        <div class="card-body">
            <?php if (!$universal_schema_available): ?>
                <div class="alert alert-warning mb-0">
                    Universal structure management is unavailable because the proposed
                    institutions, organizational units, and courses tables are not
                    installed. Legacy class management remains available.
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <h5>Create Department, Faculty, Team or Other Unit</h5>
                        <form method="post">
                            <input type="hidden" name="action" value="universal_unit">
                            <div class="mb-3">
                                <label class="form-label" for="unit_name">Name</label>
                                <input class="form-control" id="unit_name" name="name" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="unit_type">Unit Type</label>
                                    <input class="form-control" id="unit_type" name="unit_type" list="unitTypes" value="DEPARTMENT" required>
                                    <datalist id="unitTypes">
                                        <?php foreach ($unit_types as $type): ?>
                                            <option value="<?= htmlspecialchars($type['type_code']) ?>"><?= htmlspecialchars($type['type_name']) ?></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="unit_code">Code</label>
                                    <input class="form-control" id="unit_code" name="code" placeholder="Optional">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="parent_id">Parent Unit ID</label>
                                <input class="form-control" id="parent_id" name="parent_id" type="number" min="0" value="0">
                                <small class="text-muted">Leave 0 for a top-level unit.</small>
                            </div>
                            <button class="btn btn-success" type="submit"><i class="fas fa-plus me-2"></i>Create Unit</button>
                        </form>
                    </div>
                    <div class="col-lg-6">
                        <h5>Create Course</h5>
                        <form method="post">
                            <input type="hidden" name="action" value="course">
                            <div class="mb-3">
                                <label class="form-label" for="course_name">Course Name</label>
                                <input class="form-control" id="course_name" name="name" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="course_code">Course Code</label>
                                    <input class="form-control" id="course_code" name="code" placeholder="Optional">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="course_type">Course Type</label>
                                    <input class="form-control" id="course_type" name="course_type" value="course" required>
                                </div>
                            </div>
                            <button class="btn btn-success" type="submit"><i class="fas fa-plus me-2"></i>Create Course</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($universal_schema_available): ?>
        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header bg-dark text-white"><i class="fas fa-sitemap me-2"></i>Organizational Units</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead><tr><th>Name</th><th>Type</th><th>Code</th><th>Parent</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($units as $unit): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($unit['unit_name']) ?></td>
                                        <td><?= htmlspecialchars($unit['type_name']) ?></td>
                                        <td><?= htmlspecialchars($unit['unit_code'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($unit['parent_name'] ?: 'Top level') ?></td>
                                        <td><?= htmlspecialchars($unit['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$units): ?><tr><td colspan="5" class="text-muted">No organizational units found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header bg-dark text-white"><i class="fas fa-book me-2"></i>Courses</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead><tr><th>Name</th><th>Code</th><th>Type</th></tr></thead>
                                <tbody>
                                <?php foreach ($courses as $course): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($course['course_name']) ?></td>
                                        <td><?= htmlspecialchars($course['course_code'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($course['course_type']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$courses): ?><tr><td colspan="3" class="text-muted">No courses found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-dark text-white d-flex justify-content-between">
            <span>
                <i class="fas fa-list me-2"></i>
                Classes
            </span>

            <span class="badge bg-light text-dark">
                <?= count($classes) ?> Total
            </span>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Status</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($classes)): ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-5">
                            <i class="fas fa-school fa-3x mb-3"></i>
                            <h5>No Classes Found</h5>
                            <p>Create your first class to get started.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($classes as $c): ?>
                        <tr>
                        <td><?= htmlspecialchars($c['level_code'] . ' ' . $c['stream_name']) ?></td>
                            <td>
                                <?= $c['is_active'] ? 
                                    '<span class="badge bg-success">Active</span>' : 
                                    '<span class="badge bg-secondary">Inactive</span>' ?>
                            </td>
                            <td class="text-center">
                                <button
                                    class="btn btn-warning btn-sm"
                                    disabled
                                    title="Coming Soon">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <a href="?toggle=<?= $c['id'] ?>"
                                class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                onclick="return confirm('Change class status?')">
                                <i class="fas <?= $c['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i><?= $c['is_active'] ? ' Disable' : ' Enable' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
<script>
    $(function(){
        $('#sidebarToggle').click(function(){
            $('.sidebar').toggleClass('active');
        });

        $('table').DataTable({
            pageLength:10,
            responsive:true,
            ordering:true,
            lengthChange:false
        });
    });
</script>
    
</body>
</html>