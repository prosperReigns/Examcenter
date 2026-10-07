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
    header("Location: ../login.php?error=Unauthorized");
    exit();
}

$error = $success = '';
$course_schema_available = false;
$institutions = [];
$courses = [];

$course_schema_available = examcenterUniversalTableExists($conn, 'courses')
    && examcenterActiveInstitutionId($conn) !== null;

if ($course_schema_available) {
    $result = $conn->query(
        "SELECT id, name
         FROM institutions
         WHERE is_active = 1
         ORDER BY name"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $institutions[] = $row;
        }
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_course'])) {
        if (!$course_schema_available) {
            $error = 'Course management is unavailable because the universal course schema is not installed.';
        } else {
            $institution_id = (int)($_POST['institution_id'] ?? 0);
            $course_name = trim($_POST['course_name'] ?? '');
            $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
            $course_type = trim($_POST['course_type'] ?? 'subject') ?: 'subject';
            $description = trim($_POST['course_description'] ?? '');
            $legacy_subject_id = (int)($_POST['legacy_subject_id'] ?? 0);

            if ($institution_id <= 0 || $course_name === '') {
                $error = 'Institution and course name are required.';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO courses (
                        institution_id, course_code, course_name, course_type,
                        description, legacy_subject_id
                    ) VALUES (?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, 0))"
                );
                $stmt->bind_param(
                    'issssi',
                    $institution_id,
                    $course_code,
                    $course_name,
                    $course_type,
                    $description,
                    $legacy_subject_id
                );
                if ($stmt->execute()) {
                    $success = 'Course created successfully.';
                } else {
                    $error = 'Unable to create course. Course code may already exist for this institution.';
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['deactivate_course'])) {
        if (!$course_schema_available) {
            $error = 'Course management is unavailable because the universal course schema is not installed.';
        } else {
            $course_id = (int)($_POST['course_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE courses SET status = 'inactive' WHERE id = ?");
            $stmt->bind_param('i', $course_id);
            $success = $stmt->execute() ? 'Course deactivated successfully.' : 'Unable to deactivate course.';
            $stmt->close();
        }
    } elseif (isset($_POST['add_subject'])) {
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

if ($course_schema_available) {
    $result = $conn->query(
        "SELECT c.id, c.course_code, c.course_name, c.course_type, c.description,
                c.status, i.name, s.subject_name
         FROM courses c
         INNER JOIN institutions i ON i.id = c.institution_id
         LEFT JOIN subjects s ON s.id = c.legacy_subject_id
         ORDER BY i.name, c.course_name"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
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
<?php require __DIR__ . '/sidebar.php'; ?>

<div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">Manage Subjects and Courses</h2>
    <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    </div>

    <?php
        $totalSubjects = count($subjects);

        $jssCount = 0;
        $ssCount = 0;
        $primaryCount = 0;

        foreach ($subjects as $s) {
            if (strpos($s['class_levels'], 'JSS') !== false) $jssCount++;
            if (strpos($s['class_levels'], 'SS') !== false) $ssCount++;
            if (strpos($s['class_levels'], 'PRIMARY') !== false) $primaryCount++;
        }
        ?>

        <div class="row mb-4">

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">Subjects</small>
                        <h2><?= $totalSubjects ?></h2>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">JSS</small>
                        <h2><?= $jssCount ?></h2>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">SS</small>
                        <h2><?= $ssCount ?></h2>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">Primary</small>
                        <h2><?= $primaryCount ?></h2>
                    </div>
                </div>
            </div>

        </div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($success); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white">
            <i class="fas fa-book-open me-2"></i>Create Institution Course
        </div>
        <div class="card-body">
            <?php if (!$course_schema_available): ?>
                <div class="alert alert-warning mb-0">
                    Course management is unavailable because the proposed
                    institutions and courses tables are not installed.
                    Legacy subject management remains available.
                </div>
            <?php elseif (!$institutions): ?>
                <div class="alert alert-warning mb-0">
                    Create an active institution before adding courses.
                </div>
            <?php else: ?>
                <form method="POST" action="" class="row g-3">
                    <div class="col-md-4">
                        <label for="institution_id" class="form-label">Institution</label>
                        <select class="form-select" id="institution_id" name="institution_id" required>
                            <option value="">Select institution</option>
                            <?php foreach ($institutions as $institution): ?>
                                <option value="<?= (int)$institution['id'] ?>">
                                    <?= htmlspecialchars($institution['institution_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="course_name" class="form-label">Course Name</label>
                        <input type="text" class="form-control" id="course_name" name="course_name" required>
                    </div>
                    <div class="col-md-2">
                        <label for="course_code" class="form-label">Code</label>
                        <input type="text" class="form-control" id="course_code" name="course_code" placeholder="Optional">
                    </div>
                    <div class="col-md-2">
                        <label for="course_type" class="form-label">Type</label>
                        <input type="text" class="form-control" id="course_type" name="course_type" value="subject" required>
                    </div>
                    <div class="col-md-6">
                        <label for="course_description" class="form-label">Description</label>
                        <textarea class="form-control" id="course_description" name="course_description" rows="2"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label for="legacy_subject_id" class="form-label">Legacy Subject Mapping</label>
                        <select class="form-select" id="legacy_subject_id" name="legacy_subject_id">
                            <option value="0">None</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int)$subject['id'] ?>">
                                    <?= htmlspecialchars($subject['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="add_course" class="btn btn-success w-100">
                            <i class="fas fa-plus me-2"></i>Add Course
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($course_schema_available): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-layer-group me-2"></i>Institution Courses
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="coursesTable">
                        <thead>
                            <tr>
                                <th>Institution</th>
                                <th>Course</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Legacy Subject</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $course): ?>
                                <tr>
                                    <td><?= htmlspecialchars($course['institution_name']) ?></td>
                                    <td><?= htmlspecialchars($course['course_name']) ?></td>
                                    <td><?= htmlspecialchars($course['course_code'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($course['course_type']) ?></td>
                                    <td><?= htmlspecialchars($course['subject_name'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($course['status']) ?></td>
                                    <td>
                                        <?php if ($course['status'] === 'active'): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Deactivate this course?');">
                                                <input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">
                                                <button type="submit" name="deactivate_course" class="btn btn-outline-danger btn-sm">
                                                    <i class="fas fa-ban"></i> Deactivate
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$courses): ?>
                                <tr><td colspan="7" class="text-center text-muted">No courses found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-plus-circle me-2"></i>
                    Add New Subject
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="subject_name" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="subject_name" name="subject_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="class_level" class="form-label">Class Level</label>
                            <select class="form-select" size="3" id="class_level" name="class_level[]" multiple required>
                                <?php foreach($available_level as $cl): ?>
                                    <option value="<?= htmlspecialchars($cl) ?>"><?= htmlspecialchars($cl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple levels</small>
                        </div>

                        <button type="submit" name="add_subject" class="btn btn-primary w-100"> <i class="fas fa-plus-circle me-2"></i>Add Subject</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-book me-2"></i>
                    Existing Subjects
                </div>
                <div class="card-body">
                    <table class="table table-striped" id="subjectsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject Name</th>
                                <th>Class Level</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $index=>$subject): ?>
                                <tr>
                                    <td><?= $index+1 ?></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td>
                                    <?php
                                    if (!empty($subject['class_levels'])) {
                                        foreach (explode(',', $subject['class_levels']) as $level) {
                                            $level = trim($level);

                                            echo "<span class='badge bg-primary me-1'>{$level}</span>";
                                        }
                                    } else {
                                        echo "<span class='badge bg-secondary'>Not Linked</span>";
                                    }
                                    ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this subject?');">
                                            <input type="hidden" name="subject_id" value="<?php echo (int)$subject['id']; ?>">
                                            <button type="submit" name="delete_subject" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i>Delete</button>
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
        language:{
        search:"Search Subject:",
        searchPlaceholder:"Mathematics..."
        },
        "pageLength": 10,
        "lengthChange": false,
        "ordering": true,
        "columnDefs": [
            { "orderable": false, "targets": 2 } // Disable ordering on action column
        ]
    });
    if ($('#coursesTable').length) {
        $('#coursesTable').DataTable({
            pageLength: 10,
            lengthChange: false,
            ordering: true
        });
    }
});
</script>

</body>
</html>
