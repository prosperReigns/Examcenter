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

/* ================= FORM HANDLING ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $conn->begin_transaction();

        /* ---------- ADD SCHOOL ---------- */
        if (isset($_POST['add_school'])) {
            $school_name = trim($_POST['school_name']);

            if ($school_name === '') {
                throw new Exception("School name cannot be empty.");
            }

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
        }

        /* ---------- ADD ACADEMIC YEAR ---------- */
        if (isset($_POST['add_year'])) {
            $year = trim($_POST['new_year']);

            if ($year === '') {
                throw new Exception("Academic year cannot be empty.");
            }

            $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year = ?");
            $stmt->bind_param("s", $year);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $status = 'inactive';
                $stmt->close();

                $stmt = $conn->prepare("
                    INSERT INTO academic_years (year, session, exam_title, status)
                    VALUES (?, NULL, NULL, ?)
                ");
                $stmt->bind_param("ss", $year, $status);
                $stmt->execute();
            }
            $stmt->close();
        }

        /* ---------- ADD CLASS ---------- */
        if (isset($_POST['add_class'])) {
            $academic_level_id = (int)$_POST['academic_level_id'];
            $stream_id         = (int)$_POST['stream_id'];

            if (!$academic_level_id || !$stream_id) {
                throw new Exception("Academic level and stream are required.");
            }

            // Fetch level code (e.g JSS1)
            $stmt = $conn->prepare("SELECT level_code FROM academic_levels WHERE id = ?");
            $stmt->bind_param("i", $academic_level_id);
            $stmt->execute();
            $level = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Fetch stream name
            $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE id = ?");
            $stmt->bind_param("i", $stream_id);
            $stmt->execute();
            $stream = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$level || !$stream) {
                throw new Exception("Invalid academic level or stream.");
            }

            $class_name = $level['level_code'] . ' ' . $stream['stream_name'];

            $stmt = $conn->prepare("
                INSERT INTO classes (academic_level_id, stream_id, class_name)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param("iis", $academic_level_id, $stream_id, $class_name);
            $stmt->execute();
            $stmt->close();
        }

        /* ---------- ADD SUBJECT ---------- */
        if (isset($_POST['add_subject'])) {
            $subject_name = trim($_POST['subject_name']);
            $class_level  = $_POST['class_level'];

            if ($subject_name === '' || $class_level === '') {
                throw new Exception("Subject name and class level are required.");
            }

            // Get or create subject
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ?");
            $stmt->bind_param("s", $subject_name);
            $stmt->execute();
            $result = $stmt->get_result();
            $subject = $result->fetch_assoc();
            $stmt->close();

            if ($subject) {
                $subject_id = $subject['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO subjects (subject_name) VALUES (?)");
                $stmt->bind_param("s", $subject_name);
                $stmt->execute();
                $subject_id = $stmt->insert_id;
                $stmt->close();
            }
            
            // Link subject to level
            $stmt = $conn->prepare("
                INSERT IGNORE INTO subject_levels (subject_id, class_level)
                VALUES (?, ?)
            ");
            $stmt->bind_param("is", $subject_id, $class_level);
            $stmt->execute();
            $stmt->close();
        }

        /* ---------- ADD ADMIN ---------- */
        if (isset($_POST['add_admin'])) {
            $admin_username = trim($_POST['admin_username']);
            $admin_password = $_POST['admin_password'];

            if ($admin_username === '' || $admin_password === '') {
                throw new Exception("Admin username and password are required.");
            }

            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ?");
            $stmt->bind_param("s", $admin_username);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $stmt->close();
                $hashedPassword = password_hash($admin_password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, 'admin')");
                $stmt->bind_param("ss", $admin_username, $hashedPassword);
                $stmt->execute();
            }
            $stmt->close();
        }

        /* ---------- CHECK SETUP COMPLETION ---------- */
        $checks = [
            "schools",
            "academic_years",
            "classes",
            "subjects"
        ];

        foreach ($checks as $table) {
            $r = $conn->query("SELECT 1 FROM {$table} LIMIT 1");
            if ($r->num_rows === 0) {
                throw new Exception("System setup incomplete.");
            }
        }

        // Mark setup completed
        $stmt = $conn->prepare("
            UPDATE system_settings
            SET setup_completed = 1,
                setup_completed_at = NOW(),
                setup_by = ?
            WHERE id = 1
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $success = "System setup updated successfully.";

    } catch (Exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $error = $e->getMessage();
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
        <form method="POST">
            <input type="text" name="school_name" class="form-control mb-3" placeholder="School Name" required>
            <button type="submit" name="add_school" class="btn btn-primary">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 2: ACADEMIC YEAR -->
    <div class="setup-step d-none" data-step="2">
        <h5>Step 2: Add Academic Year</h5>
        <form method="POST">
            <input type="text" name="new_year" class="form-control mb-3" placeholder="e.g. 2025/2026" required>
            <button type="submit" name="add_year" class="btn btn-primary">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 3: CLASS -->
    <div class="setup-step d-none" data-step="3">
        <h5>Step 3: Add Class</h5>
        <form method="POST">
            <input type="text" name="class_name" class="form-control mb-3" placeholder="e.g. JSS 1" required>
            <button type="submit" name="add_class" class="btn btn-primary">Save & Continue</button>
        </form>
    </div>

    <!-- STEP 4: SUBJECT -->
    <div class="setup-step d-none" data-step="4">
        <h5>Step 4: Add Subject</h5>
        <form method="POST">
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
        <form method="POST">
            <input type="text" name="admin_username" class="form-control mb-3" placeholder="Admin Username" required>
            <input type="password" name="admin_password" class="form-control mb-3" placeholder="Admin Password" required>
            <button type="submit" name="add_admin" class="btn btn-success">Finish Setup</button>
        </form>
    </div>

</div>


<script src="../js/bootstrap.bundle.min.js"></script>
<script>
    let currentStep = 1;
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

    // Auto-advance after POST success
    <?php if (!empty($success)): ?>
        currentStep++;
    <?php endif; ?>

    showStep(currentStep);
</script>

</body>
</html>