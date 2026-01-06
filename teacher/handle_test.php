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

function parse_teacher_subject($subject_string) {
    if (preg_match('/^(.*?)\s*\((jss|ss)\)$/i', trim($subject_string), $m)) {
        return [
            'subject' => strtolower(trim($m[1])),
            'class'   => strtoupper($m[2])
        ];
    }
    return null;
}

// Update is_valid_subject function
function is_valid_subject($class, $subject, $assigned_subjects, $conn) {

    // Parse submitted subject (VERY IMPORTANT)
    $parsed_input = parse_teacher_subject($subject);
    if (!$parsed_input) {
        return false;
    }

    $subject = $parsed_input['subject']; // mathematics
    $class   = $parsed_input['class'];   // JSS / SS

    foreach ($assigned_subjects as $ts) {
        $parsed = parse_teacher_subject($ts);
        if (!$parsed) continue;

        if (
            $parsed['subject'] === $subject &&
            $parsed['class'] === $class
        ) {
            return true;
        }
    }

    return false;
}




// Handle test creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_test'])) {
    $year = trim($_POST['year'] ?? '');
    $title = trim($_POST['test_title'] ?? '');
    $academic_level_id = (int)$_POST['academic_level_id'];
    $stream_id = (int)($_POST['stream_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $duration = (int)($_POST['duration'] ?? 0);

    if  (
        empty($year) ||
        empty($title) ||
        empty($academic_level_id) ||
        empty($subject) ||
        $duration <= 0
    ) {
        $_SESSION['error'] = "Please fill in all test details, including a valid duration.";
    } 
    
    $stmt = $conn->prepare("
        SELECT class_group 
        FROM academic_levels 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $academic_level_id);
    $stmt->execute();
    $class_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $class = $class_row['class_group'] ?? '';

    if (!is_valid_subject($class, $subject, $assigned_subjects, $conn)) {
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
                // Extract subject group from subject name (e.g. mathematics (JSS))
                preg_match('/\((JSS|SS)\)$/i', $subject, $matches);

                if (empty($matches)) {
                    $_SESSION['error'] = "Invalid subject format.";
                    header("Location: add_question.php");
                    exit;
                }

                $subject_group = strtoupper($matches[1]);

                if ($class !== $subject_group) {
                    $_SESSION['error'] = "You cannot assign a {$subject_group} subject to a {$class} class.";
                    header("Location: add_question.php");
                    exit;
                }

                // insert into tests
                $stmt = $conn->prepare("INSERT INTO tests (title, academic_level_id, subject, duration, year, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                if (!$stmt) {
                    error_log("Prepare failed for test creation: " . $conn->error);
                    $_SESSION['error'] = "Database error.";
                } else {
                    $stmt->bind_param("sisis", $title, $academic_level_id, $subject, $duration, $year);
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
        $stmt = $conn->prepare("SELECT id, title, academic_level_id, subject, duration, year FROM tests WHERE id = ? AND subject IN ($placeholders)");
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