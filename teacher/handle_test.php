<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Initialize database connection
$database = Database::getInstance();
$conn = $database->getConnection();
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    $_SESSION['error'] = "Database connection failed.";
    header("Location: add_question.php");
    exit();
}

// Fetch assigned subjects
$teacher_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$assigned_subjects = [];
while ($row = $result->fetch_assoc()) {
    $assigned_subjects[] = $row['subject'];
}
$stmt->close();

// The hardcoded subject arrays have been removed.

// Update is_valid_subject function
function is_valid_subject($class, $subject, $assigned_subjects, $conn) {
    $subject_lower = strtolower(trim($subject));
    $class_lower = strtolower(trim($class));
    $class_type = '';

    if (strpos($class_lower, 'jss') === 0) {
        $class_type = 'JSS';
    } elseif (strpos($class_lower, 'ss') === 0) {
        $class_type = 'SS';
    } else {
        return false; // Not a JSS or SS class
    }

    // Check if subject is valid for the class type in the subjects table
    $stmt = $conn->prepare("SELECT COUNT(*) FROM subjects WHERE LOWER(subject_name) = ? AND class_level = ?");
    $stmt->bind_param("ss", $subject_lower, $class_type);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    if ($count == 0) {
        return false; // Subject not valid for this class
    }

    // Check if the subject is assigned to the teacher
    return in_array($subject, $assigned_subjects);
}

// Handle test creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_test'])) {
    $year = trim($_POST['year'] ?? '');
    $title = trim($_POST['test_title'] ?? '');
    $academic_level_id = (int)($_POST['academic_level_id'] ?? 0);
    $stream_id = (int)($_POST['stream_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $duration = (int)($_POST['duration'] ?? 0);

    if (empty($year) || empty($title) || empty($class_id) || empty($class) || empty($subject) || $duration <= 0) {
        $_SESSION['error'] = "Please fill in all test details, including a valid duration.";
    } elseif (!is_valid_subject($class, $subject, $assigned_subjects, $conn)) {
        $_SESSION['error'] = "Invalid or unauthorized subject for selected class!";
        error_log("Invalid subject attempt: {$subject} for {$class} by teacher_id=$teacher_id");
    } else {
        $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND academic_level_id= ? AND subject = ?");
        if (!$stmt) {
            error_log("Prepare failed for test check: " . $conn->error);
            $_SESSION['error'] = "Database error.";
        } else {
            $stmt->bind_param("sis", $title, $academic_level_id, $subject);
            $stmt->execute();
            $existing_test = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing_test) {
                $_SESSION['error'] = "A test with the same title, class, and subject already exists!";
            } else {
                $stmt = $conn->prepare("INSERT INTO tests (title, academic_level_id, class, subject, duration, year, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                if (!$stmt) {
                    error_log("Prepare failed for test creation: " . $conn->error);
                    $_SESSION['error'] = "Database error.";
                } else {
                    $stmt->bind_param("sissis", $title, $academic_level_id, $class, $subject, $duration, $year);
                    if ($stmt->execute()) {
                        $_SESSION['current_test_id'] = $stmt->insert_id;
                        $_SESSION['success'] = "Test created successfully!";
                        // Log activity
                        $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                        $activity = "Teacher created test: $title (academic_level_id$academic_level_id, $subject)";
                        $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                        if ($stmt_log) {
                            $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                            $stmt_log->execute();
                            $stmt_log->close();
                        }
                    } else {
                        error_log("Execute failed for test creation: " . $stmt->error);
                        $_SESSION['error'] = "Error creating test.";
                    }
                    $stmt->close();
                }
            }
        }
    }
    header("Location: add_question.php");
    exit();
}

// Handle test selection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['select_test'])) {
    $test_id = (int)($_POST['test_id'] ?? 0);
    if ($test_id <= 0) {
        $_SESSION['error'] = "Please select a valid test.";
    } else {
        $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
        $stmt = $conn->prepare("SELECT id, title, class_id, class, subject, duration, year FROM tests WHERE id = ? AND subject IN ($placeholders)");
        if (!$stmt) {
            error_log("Prepare failed for test selection: " . $conn->error);
            $_SESSION['error'] = "Database error.";
        } else {
            $params = array_merge([$test_id], $assigned_subjects);
            $types = 'i' . str_repeat('s', count($assigned_subjects));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $test = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($test) {
                $_SESSION['current_test_id'] = $test_id;
                $_SESSION['success'] = "Test selected successfully!";
            } else {
                $_SESSION['error'] = "Unauthorized or invalid test selected.";
            }
        }
    }
    header("Location: add_question.php");
    exit();
}

// Handle clear test selection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['clear_test'])) {
    unset($_SESSION['current_test_id']);
    $_SESSION['success'] = "Test selection cleared.";
    header("Location: add_question.php");
    exit();
}

$conn->close();
?>