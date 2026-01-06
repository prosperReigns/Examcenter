<?php
session_start();
require_once '../db.php';

/* ================= ERROR REPORTING ================= */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

/* ================= AUTH CHECK ================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

$database = Database::getInstance();
$conn = $database->getConnection();
$user_id = (int)$_SESSION['user_id'];

// Verify super_admin role
$stmt = $conn->prepare("SELECT role FROM super_admins WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || strtolower($admin['role']) !== 'super_admin') {
    session_destroy();
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

$class_groups = ['PRIMARY', 'JSS', 'SS'];
$success = false;
$error = '';

/* ================= FORM HANDLING ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();

        /* ---------- STEP 1: ADD SCHOOL ---------- */
        if (isset($_POST['add_school'])) {
            $school_name = trim($_POST['school_name']);
            if ($school_name === '') throw new Exception("School name cannot be empty.");

            $stmt = $conn->prepare("SELECT id FROM schools WHERE school_name = ?");
            $stmt->bind_param("s", $school_name);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $stmt->close();
                $stmt = $conn->prepare("INSERT INTO schools (school_name) VALUES (?)");
                $stmt->bind_param("s", $school_name);
                $stmt->execute();
            }
            $stmt->close();

            $_SESSION['setup_step'] = 2;
            $conn->commit();
            $success = true;
        }

        /* ---------- STEP 2: ADD ACADEMIC YEAR ---------- */
        if (isset($_POST['add_year'])) {
            $year = trim($_POST['new_year']);
            if ($year === '') throw new Exception("Academic year cannot be empty.");

            $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year = ?");
            $stmt->bind_param("s", $year);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $stmt->close();
                $status = 'inactive';
                $stmt = $conn->prepare("INSERT INTO academic_years (year, status) VALUES (?, ?)");
                $stmt->bind_param("ss", $year, $status);
                $stmt->execute();
            }
            $stmt->close();

            $_SESSION['setup_step'] = 3;
            $conn->commit();
            $success = true;
        }

        /* ---------- STEP 3: ADD CLASS ---------- */
        if (isset($_POST['add_class'])) {
            $class_group = strtoupper(trim($_POST['class_group'] ?? ''));
            $level_code  = strtoupper(trim($_POST['level_code'] ?? ''));
            $stream_name = ucfirst(strtolower(trim($_POST['stream_name'] ?? '')));

            if (!$class_group || !$level_code || !$stream_name) throw new Exception("All fields are required.");

            // Validate level_code matches group
            $valid = false;
            if ($class_group === 'JSS' && str_starts_with($level_code, 'JSS')) $valid = true;
            elseif ($class_group === 'SS' && str_starts_with($level_code, 'SS')) $valid = true;
            elseif ($class_group === 'PRIMARY' && str_starts_with($level_code, 'PRY')) $valid = true;

            if (!$valid) throw new Exception("Level Code '$level_code' does not match Class Group '$class_group'.");

            // Academic Level
            $stmt = $conn->prepare("SELECT id FROM academic_levels WHERE level_code=? AND class_group=?");
            $stmt->bind_param("ss", $level_code, $class_group);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $academic_level_id = $result->fetch_assoc()['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO academic_levels(level_code,class_group) VALUES(?,?)");
                $stmt->bind_param("ss", $level_code, $class_group);
                $stmt->execute();
                $academic_level_id = $stmt->insert_id;
            }

            // Stream
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

            // Class
            $class_name = $level_code . ' ' . $stream_name;
            $stmt = $conn->prepare("SELECT id FROM classes WHERE academic_level_id=? AND stream_id=?");
            $stmt->bind_param("ii", $academic_level_id, $stream_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) throw new Exception("Class already exists.");
            else {
                $stmt = $conn->prepare("INSERT INTO classes(academic_level_id,stream_id,class_name) VALUES(?,?,?)");
                $stmt->bind_param("iis", $academic_level_id, $stream_id, $class_name);
                $stmt->execute();
            }

            $_SESSION['setup_step'] = 4;
            $conn->commit();
            $success = true;
        }

        /* ---------- STEP 4: ADD SUBJECT ---------- */
        if (isset($_POST['add_subject'])) {
            $subject_name = trim($_POST['subject_name']);
            $class_level  = $_POST['class_level'];
            if ($subject_name === '' || $class_level === '') throw new Exception("Subject and class level required.");

            $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name=?");
            $stmt->bind_param("s", $subject_name);
            $stmt->execute();
            $result = $stmt->get_result();
            $subject = $result->fetch_assoc();
            $stmt->close();

            if ($subject) $subject_id = $subject['id'];
            else {
                $stmt = $conn->prepare("INSERT INTO subjects (subject_name) VALUES (?)");
                $stmt->bind_param("s", $subject_name);
                $stmt->execute();
                $subject_id = $stmt->insert_id;
                $stmt->close();
            }

            // Link to level
            $stmt = $conn->prepare("INSERT IGNORE INTO subject_levels(subject_id,class_level) VALUES(?,?)");
            $stmt->bind_param("is", $subject_id, $class_level);
            $stmt->execute();
            $stmt->close();

            $_SESSION['setup_step'] = 5;
            $conn->commit();
            $success = true;
        }

        /* ---------- STEP 5: ADD ADMIN & FINALIZE ---------- */
        if (isset($_POST['add_admin'])) {
            $admin_username = trim($_POST['admin_username']);
            $admin_password = $_POST['admin_password'];
            if ($admin_username === '' || $admin_password === '') throw new Exception("Admin username and password required.");

            // Admin insert
            $stmt = $conn->prepare("SELECT id FROM admins WHERE username=?");
            $stmt->bind_param("s", $admin_username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $stmt->close();
                $hashedPassword = password_hash($admin_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO admins (username,password,role) VALUES(?,?,'admin')");
                $stmt->bind_param("ss", $admin_username, $hashedPassword);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt->close();
            }

            // System settings
            $stmt = $conn->prepare("INSERT INTO system_settings(setup_completed,setup_completed_at,setup_by) VALUES(1,NOW(),?)");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            unset($_SESSION['setup_step']);
            header("Location: /EXAMCENTER/super_admin/dashboard.php");
            exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
        error_log($error);
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System setup | Super Admin</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
</head>
<body>

<div class="container mt-5">
    <h3 class="mb-4">System Setup Wizard</h3>

    <!-- Progress -->
    <div class="progress mb-4">
        <div id="setupProgress" class="progress-bar" style="width: 20%"></div>
    </div>

    <!-- STEP 1: SCHOOL -->
    <div class="setup-step" data-step="1">
        <h5>Step 1: Create School</h5>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="system_setup.php">
            <input type="text" name="school_name" class="form-control mb-3" placeholder="School Name" required>
            <button type="submit" name="add_school" class="btn btn-primary">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 2: ACADEMIC YEAR -->
    <div class="setup-step d-none" data-step="2">
        <h5>Step 2: Add Academic Year</h5>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="system_setup.php">
            <input type="text" name="new_year" class="form-control mb-3" placeholder="e.g. 2025/2026" required>
            <button type="submit" name="add_year" class="btn btn-primary">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 3: CLASS -->
    <div class="setup-step d-none" data-step="3">
        <h5>Step 3: Add Class</h5>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="system_setup.php">
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
            <button type="submit" name="add_class" class="btn btn-primary">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 4: SUBJECT -->
    <div class="setup-step d-none" data-step="4">
        <h5>Step 4: Add Subject</h5>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="system_setup.php">
            <input type="text" name="subject_name" class="form-control mb-3" placeholder="Subject Name" required>

            <select name="class_level" class="form-control mb-3" required>
                <option value="">Select Class Level</option>
                <option value="PRIMARY">PRIMARY</option>
                <option value="JSS">JSS</option>
                <option value="SS">SS</option>
            </select>

            <button type="submit" name="add_subject" class="btn btn-success">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 5: CREATE ADMIN -->
    <div class="setup-step d-none" data-step="5">
        <h5>Step 5: Create Admin Account</h5>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="system_setup.php">
            <input type="text" name="admin_username" class="form-control mb-3" placeholder="Admin Username" required>
            <input type="password" name="admin_password" class="form-control mb-3" placeholder="Admin Password" required>
            <button type="submit" name="add_admin" class="btn btn-success">Finish Setup</button>
        </form>
    </div>

</div>


<script src="../js/bootstrap.bundle.min.js"></script>
<script>
    let currentStep = <?= (int)($_SESSION['setup_step'] ?? 1) ?>;
    const totalSteps = 5;

    function showStep(step) {
        document.querySelectorAll('.setup-step').forEach(el => {
            el.classList.add('d-none');
        });

        document.querySelector(`.setup-step[data-step="${step}"]`)
            .classList.remove('d-none');

        document.getElementById('setupProgress').style.width =
            (step / totalSteps) * 100 + '%';
    }

    showStep(currentStep);
</script>

</body>
</html>