<?php
session_start();
require_once '../db.php';

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

// Define subjects by category
$jss_subjects = [
    'Mathematics', 'English', 'ICT', 'Agriculture', 'History',
    'Civic Education', 'Basic Science', 'Basic Technology',
    'Business studies', 'Agricultural sci', 'Physical Health Edu',
    'Cultural and Creative Art', 'Social Studies', 'Security Edu',
    'Yoruba', 'french', 'Coding and Robotics', 'C.R.S', 'I.R.S', 'Chess'
];
$ss_subjects = [
    'Mathematics', 'English', 'Civic Edu', 'Data Processing', 'Economics',
    'Government', 'Commerce', 'Accounting', 'Financial Accounting',
    'Dyeing and Bleaching', 'Physics', 'Chemistry', 'Biology',
    'Agricultural Sci', 'Geography', 'technical Drawing', 'yoruba Lang',
    'French Lang', 'Further Maths', 'Literature in English', 'C.R.S', 'I.R.S'
];

// Update is_valid_subject function
function is_valid_subject($class, $subject, $assigned_subjects) {
    global $jss_subjects, $ss_subjects;
    $subject = strtolower(trim($subject));
    $class = strtolower(trim($class));
    $valid_subjects = [];

    if (strpos($class, 'jss') === 0) {
        $valid_subjects = array_map('strtolower', $jss_subjects);
    } elseif (strpos($class, 'ss') === 0) {
        $valid_subjects = array_map('strtolower', $ss_subjects);
    }

    return in_array($subject, $valid_subjects) && in_array($subject, array_map('strtolower', $assigned_subjects));
}

// Handle test creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_test'])) {
    $title = trim($_POST['test_title'] ?? '');
    $class = trim($_POST['class'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $duration = (int)($_POST['duration'] ?? 0);

    if (empty($title) || empty($class) || empty($subject) || $duration <= 0) {
        $_SESSION['error'] = "Please fill in all test details, including a valid duration.";
    } elseif (!is_valid_subject($class, $subject, $assigned_subjects)) {
        $_SESSION['error'] = "Invalid or unauthorized subject for selected class!";
        error_log("Invalid subject attempt: {$subject} for {$class} by teacher_id=$teacher_id");
    } else {
        $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND class = ? AND subject = ?");
        if (!$stmt) {
            error_log("Prepare failed for test check: " . $conn->error);
            $_SESSION['error'] = "Database error.";
        } else {
            $stmt->bind_param("sss", $title, $class, $subject);
            $stmt->execute();
            $existing_test = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing_test) {
                $_SESSION['error'] = "A test with the same title, class, and subject already exists!";
            } else {
                $stmt = $conn->prepare("INSERT INTO tests (title, class, subject, duration, created_at) VALUES (?, ?, ?, ?, NOW())");
                if (!$stmt) {
                    error_log("Prepare failed for test creation: " . $conn->error);
                    $_SESSION['error'] = "Database error.";
                } else {
                    $stmt->bind_param("sssi", $title, $class, $subject, $duration);
                    if ($stmt->execute()) {
                        $_SESSION['current_test_id'] = $stmt->insert_id;
                        $_SESSION['success'] = "Test created successfully!";
                        // Log activity
                        $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                        $activity = "Teacher created test: $title ($class, $subject)";
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
        $stmt = $conn->prepare("SELECT id, title, class, subject, duration FROM tests WHERE id = ? AND subject IN ($placeholders)");
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