<?php
session_start();
require_once '../db.php';

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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_year'])) {
        $newYear = trim($_POST['new_year']);
        if ($newYear === '') {
            $errorMsg = "Academic year cannot be empty.";
        } else {
            // check distinct year already exists
            $stmt = $conn->prepare("SELECT 1 FROM academic_years WHERE year = ? LIMIT 1");
            $stmt->bind_param("s", $newYear);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($r->num_rows > 0) {
                $errorMsg = "Academic year already exists.";
                $stmt->close();
            } else {
                $stmt->close();
                // insert a placeholder row with session NULL so year appears
                $status = 'inactive';
                $stmt = $conn->prepare("INSERT INTO academic_years (year, session, exam_title, status) VALUES (?, NULL, NULL, ?)");
                $stmt->bind_param("ss", $newYear, $status);
                if ($stmt->execute()) {
                    $success = "Academic year added successfully.";
                } else {
                    $errorMsg = "Database error while adding year.";
                }
                $stmt->close();
            }
        }
    }

    // handle class creation
    if (isset($_POST['add_class'])) {
        $class_name = trim($_POST['class_name']);

        if (!empty($class_name)) {
            $stmt = $conn->prepare("INSERT INTO classes (class_name) VALUES (?)");
            $stmt->bind_param("s", $class_name);
            if ($stmt->execute()) {
                $success = "Class added successfully.";
            } else {
                $error = "Error adding Class. It might already exist for that class name.";
            }
            $stmt->close();
        }else{
            $error = "Class name are required.";
        }
    }

    // handle subject creation
    if (isset($_POST['add_subject'])) {
        $subject_name = trim($_POST['subject_name']);
        $class_level = $_POST['class_level'];

        if (!empty($subject_name) && !empty($class_level)) {
            $stmt = $conn->prepare("INSERT INTO subjects (subject_name, class_level) VALUES (?, ?)");
            $stmt->bind_param("ss", $subject_name, $class_level);
            if ($stmt->execute()) {
                $success = "Subject added successfully.";
            } else {
                $error = "Error adding subject. It might already exist for that class level.";
            }
            $stmt->close();
        } else {
            $error = "Subject name and class level are required.";
        }
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

<!-- create academic year -->
<div>
    <h5>Add Academic Year</h5>
    <form action="system_setup.php" method="POST">
        <div class="mb-3">
            <label for="">Year</label>
            <input type="text" name="new_year" class="form-control" placeholder="e.g. 2025/2026" required>
        </div>

        <button type="submit" name="add_year" class="btn btn-primary">Add year</button>
    </form>
</div>

<!-- create class -->
<div>
    <h5>Add Class</h5>
    <form action="system_setup.php" method="POST">
        <div class="mb-3">
            <label for="">Class</label>
            <input type="text" name="class" class="form-control">
        </div>

        <button type="submit" name="add_class" class="btn btn-primary">Add Class</button>
    </form>
</div>

<!-- create subject -->
<div>
    <h5>Add Subject</h5>
    <form action="system_setup.php" method="POST">
        <div class="mb-3">
            <label for="">Subject</label>
            <input type="text" name="subject_name">
        </div>

        <div class="mb-3">
            <label for="">Class level</label>
            <input type="text" name="class_level">
        </div>

        <button type="submit" name="add_subject" class="btn btn-primary">Add Class</button>
    </form>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>

</body>
</html>