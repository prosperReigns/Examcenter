<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

// Initialize database connection
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    // Fetch teacher profile and assigned subjects
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
    if (!$stmt) {
        error_log("Prepare failed for assigned subjects: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    if (empty($assigned_subjects)) {
        $error = "No subjects assigned to you. Contact your admin.";
    }

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

    // Initialize variables
    $error = $success = '';
    $current_test = null;
    $questions = [];
    $total_questions = 0;
    $tests = [];

    // Fetch tests for assigned subjects
    if (!empty($assigned_subjects)) {
        $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
        $stmt = $conn->prepare("SELECT id, title, class, subject FROM tests WHERE subject IN ($placeholders) ORDER BY created_at DESC");
        if (!$stmt) {
            error_log("Prepare failed for tests: " . $conn->error);
            $error = "Error fetching tests.";
        } else {
            $stmt->bind_param(str_repeat('s', count($assigned_subjects)), ...$assigned_subjects);
            $stmt->execute();
            $tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

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
            $error = "Please fill in all test details, including a valid duration.";
        } elseif (!is_valid_subject($class, $subject, $assigned_subjects)) {
            $error = "Invalid or unauthorized subject for selected class!";
            error_log("Invalid subject attempt: {$subject} for {$class} by teacher_id=$teacher_id");
        } else {
            $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND class = ? AND subject = ?");
            if (!$stmt) {
                error_log("Prepare failed for test check: " . $conn->error);
                $error = "Database error.";
            } else {
                $stmt->bind_param("sss", $title, $class, $subject);
                $stmt->execute();
                $existing_test = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing_test) {
                    $error = "A test with the same title, class, and subject already exists!";
                } else {
                    $stmt = $conn->prepare("INSERT INTO tests (title, class, subject, duration, created_at) VALUES (?, ?, ?, ?, NOW())");
                    if (!$stmt) {
                        error_log("Prepare failed for test creation: " . $conn->error);
                        $error = "Database error.";
                    } else {
                        $stmt->bind_param("sssi", $title, $class, $subject, $duration);
                        if ($stmt->execute()) {
                            $_SESSION['current_test_id'] = $stmt->insert_id;
                            $success = "Test created successfully!";
                            // Log activity
                            $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                            $activity = "Teacher {$teacher['username']} created test: $title ($class, $subject)";
                            $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                            if ($stmt_log) {
                                $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                                $stmt_log->execute();
                                $stmt_log->close();
                            }
                            $current_test = [
                                'id' => $_SESSION['current_test_id'],
                                'title' => $title,
                                'class' => $class,
                                'subject' => $subject,
                                'duration' => $duration
                            ];
                        } else {
                            error_log("Execute failed for test creation: " . $stmt->error);
                            $error = "Error creating test.";
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }

    // Handle test selection
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['select_test'])) {
        $test_id = (int)($_POST['test_id'] ?? 0);
        if ($test_id <= 0) {
            $error = "Please select a valid test.";
        } else {
            $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
            $stmt = $conn->prepare("SELECT id, title, class, subject, duration FROM tests WHERE id = ? AND subject IN ($placeholders)");
            if (!$stmt) {
                error_log("Prepare failed for test selection: " . $conn->error);
                $error = "Database error.";
            } else {
                $params = array_merge([$test_id], $assigned_subjects);
                $types = 'i' . str_repeat('s', count($assigned_subjects));
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $test = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($test) {
                    $_SESSION['current_test_id'] = $test_id;
                    $success = "Test selected successfully!";
                    $current_test = $test;
                } else {
                    $error = "Unauthorized or invalid test selected.";
                }
            }
        }
    }

    // Load current test
    if (isset($_SESSION['current_test_id']) && !$current_test) {
        $test_id = (int)$_SESSION['current_test_id'];
        $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
        $stmt = $conn->prepare("SELECT id, title, class, subject, duration FROM tests WHERE id = ? AND subject IN ($placeholders)");
        if (!$stmt) {
            error_log("Prepare failed for current test: " . $conn->error);
            $error = "Database error.";
        } else {
            $params = array_merge([$test_id], $assigned_subjects);
            $types = 'i' . str_repeat('s', count($assigned_subjects));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $current_test = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($current_test) {
                $stmt = $conn->prepare("SELECT id, question_text, question_type FROM new_questions WHERE test_id = ? ORDER BY id ASC");
                if (!$stmt) {
                    error_log("Prepare failed for questions: " . $conn->error);
                    $error = "Database error.";
                } else {
                    $stmt->bind_param("i", $test_id);
                    $stmt->execute();
                    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $total_questions = count($questions);
                    $stmt->close();
                }
            } else {
                unset($_SESSION['current_test_id']);
                $error = "Selected test is invalid or unauthorized.";
            }
        }
    }

    // Handle clear test selection
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['clear_test'])) {
        unset($_SESSION['current_test_id']);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Handle question deletion
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_question'])) {
        $question_id = (int)($_POST['question_id'] ?? 0);
        $question_type = $_POST['question_type'] ?? '';
        $valid_types = ['multiple_choice_single', 'multiple_choice_multiple', 'true_false', 'fill_blanks'];

        if ($question_id <= 0 || !in_array($question_type, $valid_types)) {
            $error = "Invalid question ID or type.";
        } else {
            $table_map = [
                'multiple_choice_single' => 'single_choice_questions',
                'multiple_choice_multiple' => 'multiple_choice_questions',
                'true_false' => 'true_false_questions',
                'fill_blanks' => 'fill_blank_questions',
            ];
            $table = $table_map[$question_type];

            // Delete associated image
            $stmt = $conn->prepare("SELECT image_path FROM $table WHERE question_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $question_id);
                $stmt->execute();
                $image = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($image['image_path'] && file_exists("../{$image['image_path']}")) {
                    if (!unlink("../{$image['image_path']}")) {
                        error_log("Failed to delete image: ../{$image['image_path']}");
                    }
                }
            }

            // Delete from specific table
            $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $question_id);
                $stmt->execute();
                $stmt->close();
            }

            // Delete from new_questions
            $stmt = $conn->prepare("SELECT test_id, question_text FROM new_questions WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $question_id);
                $stmt->execute();
                $question = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $question_id);
                    if ($stmt->execute()) {
                        $success = "Question deleted successfully!";
                        // Log activity
                        $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                        $activity = "Teacher {$teacher['username']} deleted question ID $question_id: " . substr($question['question_text'] ?? '', 0, 50);
                        $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                        if ($stmt_log) {
                            $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                            $stmt_log->execute();
                            $stmt_log->close();
                        }
                    } else {
                        error_log("Execute failed for question deletion: " . $stmt->error);
                        $error = "Error deleting question.";
                    }
                    $stmt->close();
                }
            }
        }
    }

    // Handle question editing (load question into form)
    $edit_question = null;
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_question'])) {
        $question_id = (int)($_POST['question_id'] ?? 0);
        if ($question_id <= 0) {
            $error = "Invalid question ID.";
        } else {
            $stmt = $conn->prepare("SELECT id, question_text, question_type FROM new_questions WHERE id = ?");
            if (!$stmt) {
                error_log("Prepare failed for edit question: " . $conn->error);
                $error = "Database error.";
            } else {
                $stmt->bind_param("i", $question_id);
                $stmt->execute();
                $edit_question = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($edit_question) {
                    $sql = '';
                    switch ($edit_question['question_type']) {
                        case 'multiple_choice_single':
                            $sql = "SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?";
                            break;
                        case 'multiple_choice_multiple':
                            $sql = "SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?";
                            break;
                        case 'true_false':
                            $sql = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
                            break;
                        case 'fill_blanks':
                            $sql = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
                            break;
                    }

                    if ($sql) {
                        $stmt = $conn->prepare($sql);
                        if ($stmt) {
                            $stmt->bind_param("i", $question_id);
                            $stmt->execute();
                            $edit_question['options'] = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                        }
                    }
                } else {
                    $error = "Question not found.";
                }
            }
        }
    }

    // Handle image upload
    function handleImageUpload($question_id) {
        global $conn;
        if (!isset($_FILES['question_image']) || $_FILES['question_image']['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $max_size = 2 * 1024 * 1024; // 2MB
        if ($_FILES['question_image']['size'] > $max_size) {
            return false;
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($_FILES['question_image']['type'], $allowed_types)) {
            return false;
        }

        $upload_dir = '../Uploads/questions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
        $filename = 'question_' . $question_id . '_' . time() . '.' . $ext;
        $full_path = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['question_image']['tmp_name'], $full_path)) {
            return 'Uploads/questions/' . $filename;
        }

        return false;
    }

    // Handle question submission
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['question'])) {
        if (!isset($_SESSION['current_test_id'])) {
            $error = "Please create or select a test first.";
        } else {
            $test_id = (int)$_SESSION['current_test_id'];
            $question_id = (int)($_POST['question_id'] ?? 0);
            $question_text = trim($_POST['question'] ?? '');
            $question_type = trim($_POST['question_type'] ?? '');

            if (empty($question_text) || empty($question_type) || !in_array($question_type, ['multiple_choice_single', 'multiple_choice_multiple', 'true_false', 'fill_blanks'])) {
                $error = "Question text and valid type are required.";
            } else {
                $stmt = $conn->prepare("SELECT class, subject FROM tests WHERE id = ?");
                if (!$stmt) {
                    error_log("Prepare failed for test data: " . $conn->error);
                    $error = "Database error.";
                } else {
                    $stmt->bind_param("i", $test_id);
                    $stmt->execute();
                    $test_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$test_data) {
                        unset($_SESSION['current_test_id']);
                        $error = "Invalid test.";
                    } else {
                        $class = $test_data['class'];
                        $subject = $test_data['subject'];

                        // Handle image
                        $image_path = null;
                        if (isset($_POST['remove_image']) && $_POST['remove_image'] === 'on' && $question_id) {
                            $table = $question_type === 'multiple_choice_single' ? 'single_choice_questions' : 'multiple_choice_questions';
                            $stmt = $conn->prepare("SELECT image_path FROM $table WHERE question_id = ?");
                            if ($stmt) {
                                $stmt->bind_param("i", $question_id);
                                $stmt->execute();
                                $image = $stmt->get_result()->fetch_assoc();
                                $stmt->close();
                                if ($image['image_path'] && file_exists("../{$image['image_path']}")) {
                                    unlink("../{$image['image_path']}");
                                }
                            }
                        } else {
                            $image_path = handleImageUpload($question_id ?: time());
                            if ($image_path === false) {
                                $error = "Image upload failed: Invalid file or size.";
                            }
                        }

                        if (!$error) {
                            $conn->begin_transaction();
                            try {
                                if ($question_id) {
                                    $stmt = $conn->prepare("UPDATE new_questions SET question_text = ?, question_type = ? WHERE id = ?");
                                    $stmt->bind_param("ssi", $question_text, $question_type, $question_id);
                                } else {
                                    $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, class, subject, question_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                                    $stmt->bind_param("sisss", $question_text, $test_id, $class, $subject, $question_type);
                                }
                                if (!$stmt->execute()) {
                                    throw new Exception("Error saving question: " . $stmt->error);
                                }
                                $question_id = $question_id ?: $stmt->insert_id;
                                $stmt->close();

                                // Delete existing options
                                $table_map = [
                                    'multiple_choice_single' => 'single_choice_questions',
                                    'multiple_choice_multiple' => 'multiple_choice_questions',
                                    'true_false' => 'true_false_questions',
                                    'fill_blanks' => 'fill_blank_questions',
                                ];
                                if (isset($table_map[$question_type])) {
                                    $table = $table_map[$question_type];
                                    $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
                                    if ($stmt) {
                                        $stmt->bind_param("i", $question_id);
                                        $stmt->execute();
                                        $stmt->close();
                                    }
                                }

                                switch ($question_type) {
                                    case 'multiple_choice_single':
                                        $option1 = trim($_POST['option1'] ?? '');
                                        $option2 = trim($_POST['option2'] ?? '');
                                        $option3 = trim($_POST['option3'] ?? '');
                                        $option4 = trim($_POST['option4'] ?? '');
                                        $correct_answer = trim($_POST['correct_answer'] ?? '');
                                        $options = [$option1, $option2, $option3, $option4];
                                        if ($option1 && $option2 && $option3 && $option4 && $correct_answer && in_array($correct_answer, ['1', '2', '3', '4'])) {
                                            $correct_text = $options[(int)$correct_answer - 1];
                                            $stmt = $conn->prepare("INSERT INTO single_choice_questions (question_id, option1, option2, option3, option4, correct_answer, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                            $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, $correct_text, $image_path);
                                            $stmt->execute();
                                            $stmt->close();
                                        } else {
                                            throw new Exception("All options and a valid correct answer are required.");
                                        }
                                        break;

                                    case 'multiple_choice_multiple':
                                        $option1 = trim($_POST['option1'] ?? '');
                                        $option2 = trim($_POST['option2'] ?? '');
                                        $option3 = trim($_POST['option3'] ?? '');
                                        $option4 = trim($_POST['option4'] ?? '');
                                        $correct_answers = isset($_POST['correct_answers']) ? array_map('intval', $_POST['correct_answers']) : [];
                                        $correct_text = implode(',', array_map(fn($i) => [$option1, $option2, $option3, $option4][$i - 1], $correct_answers));
                                        if ($option1 && $option2 && $option3 && $option4 && $correct_answers) {
                                            $stmt = $conn->prepare("INSERT INTO multiple_choice_questions (question_id, option1, option2, option3, option4, correct_answers, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                            $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, $correct_text, $image_path);
                                            $stmt->execute();
                                            $stmt->close();
                                        } else {
                                            throw new Exception("All options and at least one correct answer are required.");
                                        }
                                        break;

                                    case 'true_false':
                                        $correct_answer = trim($_POST['correct_answer'] ?? '');
                                        if (in_array($correct_answer, ['True', 'False'])) {
                                            $stmt = $conn->prepare("INSERT INTO true_false_questions (question_id, correct_answer) VALUES (?, ?)");
                                            $stmt->bind_param("is", $question_id, $correct_answer);
                                            $stmt->execute();
                                            $stmt->close();
                                        } else {
                                            throw new Exception("A valid correct answer is required.");
                                        }
                                        break;

                                    case 'fill_blanks':
                                        $correct_answer = trim($_POST['correct_answer'] ?? '');
                                        if ($correct_answer) {
                                            $stmt = $conn->prepare("INSERT INTO fill_blank_questions (question_id, correct_answer) VALUES (?, ?)");
                                            $stmt->bind_param("is", $question_id, $correct_answer);
                                            $stmt->execute();
                                            $stmt->close();
                                        } else {
                                            throw new Exception("A correct answer is required.");
                                        }
                                        break;
                                }

                                $conn->commit();
                                $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                                // Log activity
                                $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                                $activity = "Teacher {$teacher['username']} " . ($edit_question ? 'updated' : 'added') . " question ID $question_id: " . substr($question_text, 0, 50);
                                $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                                if ($stmt_log) {
                                    $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                                    $stmt_log->execute();
                                    $stmt_log->close();
                                }
                            } catch (Exception $e) {
                                $conn->rollback();
                                error_log("Question save error: " . $e->getMessage());
                                $error = "Error saving question: " . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }

    $edit_data = [
        'options' => $edit_question['options'] ?? [],
        'question_type' => $edit_question['question_type'] ?? ''
    ];
    if (isset($edit_question['options']['correct_answers'])) {
        $edit_data['correct_answers'] = array_map('intval', explode(',', $edit_question['options']['correct_answers']));
    } else {
        $edit_data['correct_answers'] = [];
    }

} catch (Exception $e) {
    error_log("Add question error: " . $e->getMessage());
    die("System error");
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/add_question.css">
    <style>
        #imageUploadContainer { display: none; }
        .question-card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group-spacing { margin-bottom: 1.5rem; }
        .preview-disabled { cursor: not-allowed; opacity: 0.6; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($teacher['last_name']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" class="active"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="manage_students.php" style="text-decoration: line-through"><i class="fas fa-users"></i>Manage Students</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="my-profile.php"><i class="fas fa-user"></i>My Profile</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Add Questions</h2>
            <div class="header-actions">
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <button class="btn btn-primary <?php echo !$current_test ? 'preview-disabled' : ''; ?>" 
                        <?php echo !$current_test ? 'disabled' : ''; ?>
                        data-bs-toggle="modal" data-bs-target="#previewModal" id="previewButton">
                    <i class="fas fa-eye me-2"></i>Preview
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Question Form -->
            <div class="col-lg-8">
                <div class="question-card">
                    <?php if (!$current_test): ?>
                        <h5 class="mb-3">Test Setup</h5>
                        <form method="POST" id="testForm">
                            <div class="row g-3">
                                <div class="col-md-3 form-group-spacing">
                                    <label class="form-label fw-bold">Test Title</label>
                                    <select class="form-select" name="test_title" required>
                                        <option value="">Select Test Title</option>
                                        <option value="First term exam">First term exam</option>
                                        <option value="First term test">First term test</option>
                                        <option value="Second term exam">Second term exam</option>
                                        <option value="Second term test">Second term test</option>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group-spacing">
                                    <label class="form-label fw-bold">Class</label>
                                    <select class="form-select" name="class" required id="classSelect">
                                        <option value="">Select Class</option>
                                        <option value="JSS1">JSS1</option>
                                        <option value="JSS2">JSS2</option>
                                        <option value="JSS3">JSS3</option>
                                        <option value="SS1">SS1</option>
                                        <option value="SS2">SS2</option>
                                        <option value="SS3">SS3</option>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group-spacing">
                                    <label class="form-label fw-bold">Subject</label>
                                    <select class="form-select" name="subject" required id="subjectSelect">
                                        <option value="">Select Subject</option>
                                        <?php foreach ($assigned_subjects as $subject): ?>
                                            <option value="<?php echo htmlspecialchars($subject); ?>"><?php echo htmlspecialchars($subject); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group-spacing">
                                    <label class="form-label fw-bold">Duration (min)</label>
                                    <input type="number" class="form-control" name="duration" required placeholder="e.g. 30" min="1">
                                </div>
                            </div>
                            <button type="submit" name="create_test" class="btn btn-primary mt-3"><i class="fas fa-plus me-2"></i>Create Test</button>
                        </form>

                        <?php if (!empty($tests)): ?>
                            <hr>
                            <h6>Select Existing Test</h6>
                            <form method="POST">
                                <div class="form-group-spacing">
                                    <label class="form-label fw-bold">Available Tests</label>
                                    <select class="form-select" name="test_id" required>
                                        <option value="">Select a Test</option>
                                        <?php foreach ($tests as $test): ?>
                                            <option value="<?php echo (int)$test['id']; ?>">
                                                <?php echo htmlspecialchars($test['title'] . ' (' . $test['class'] . ' - ' . $test['subject'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="select_test" class="btn btn-primary"><i class="fas fa-check me-2"></i>Select Test</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <h5 class="mb-3"><?php echo $edit_question ? 'Edit Question' : 'Add Question'; ?></h5>
                        <form method="POST" id="questionForm" enctype="multipart/form-data">
                            <input type="hidden" name="question_id" value="<?php echo (int)($edit_question['id'] ?? ''); ?>">
                            <div class="form-group-spacing">
                                <label class="form-label fw-bold">Question Type</label>
                                <select class="form-select form-select-lg" name="question_type" id="questionType" required>
                                    <option value="multiple_choice_single" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_single') ? 'selected' : ''; ?>>Single Choice Question</option>
                                    <option value="multiple_choice_multiple" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_multiple') ? 'selected' : ''; ?>>Multiple Choice Question</option>
                                    <option value="true_false" <?php echo ($edit_question && $edit_question['question_type'] == 'true_false') ? 'selected' : ''; ?>>True/False</option>
                                    <option value="fill_blanks" <?php echo ($edit_question && $edit_question['question_type'] == 'fill_blanks') ? 'selected' : ''; ?>>Fill in Blanks</option>
                                </select>
                            </div>
                            <div class="form-group-spacing">
                                <label class="form-label fw-bold">Question Text</label>
                                <textarea class="form-control" name="question" rows="4" placeholder="Enter your question here..." required><?php echo htmlspecialchars($edit_question['question_text'] ?? ''); ?></textarea>
                            </div>
                            <div id="optionsContainer" class="form-group-spacing"></div>
                            <div class="d-flex justify-content-end gap-3 mt-4">
                                <button type="reset" class="btn btn-secondary">Clear</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-<?php echo $edit_question ? 'save' : 'plus'; ?> me-2"></i><?php echo $edit_question ? 'Update Question' : 'Add Question'; ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Test Overview -->
            <div class="col-lg-4">
                <div class="question-card">
                    <h5 class="mb-3">Test Overview</h5>
                    <?php if ($current_test): ?>
                        <div class="alert alert-primary">
                            <strong><?php echo htmlspecialchars($current_test['title']); ?></strong><br>
                            <span class="text-muted"><?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?></span><br>
                            <small>Duration: <?php echo (int)$current_test['duration']; ?> minutes</small>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span>Total Questions:</span>
                            <strong><?php echo $total_questions; ?></strong>
                        </div>
                        <form method="POST">
                            <button type="submit" name="clear_test" class="btn btn-outline-danger w-100"><i class="fas fa-times me-2"></i>Clear Test Selection</button>
                        </form>
                    <?php else: ?>
                        <div class="text-center">
                            <p class="text-muted">No test selected. Create or select a test to start.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewModalLabel">Test Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body modal-preview">
                    <?php if ($current_test && !empty($questions)): ?>
                        <h6><?php echo htmlspecialchars($current_test['title']); ?> (<?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?>)</h6>
                        <p><small>Duration: <?php echo (int)$current_test['duration']; ?> minutes</small></p>
                        <hr>
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong>Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question_text']); ?></strong>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                            <input type="hidden" name="edit_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                            <input type="hidden" name="question_type" value="<?php echo htmlspecialchars($question['question_type']); ?>">
                                            <input type="hidden" name="delete_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <span class="badge bg-primary ms-2"><?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?></span>
                                <div class="mt-2">
                                    <?php
                                    switch ($question['question_type']) {
                                        case 'multiple_choice_single':
                                            $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if ($options['image_path'] && file_exists("../{$options['image_path']}")) {
                                                echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                            } elseif ($options['image_path']) {
                                                echo '<div class="mb-3"><small class="text-muted">Image not found.</small></div>';
                                            }
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                echo "<div>" . ($options['correct_answer'] === $options[$opt] ? '<i class="fas fa-check text-success me-2"></i>' : '') .
                                                     htmlspecialchars($options[$opt] ?? '') . "</div>";
                                            }
                                            break;
                                        case 'multiple_choice_multiple':
                                            $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if ($options['image_path'] && file_exists("../{$options['image_path']}")) {
                                                echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                            } elseif ($options['image_path']) {
                                                echo '<div class="mb-3"><small class="text-muted">Image not found.</small></div>';
                                            }
                                            $correct = explode(',', $options['correct_answers']);
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                echo "<div>" . (in_array($options[$opt], $correct) ? '<i class="fas fa-check text-success me-2"></i>' : '') .
                                                     htmlspecialchars($options[$opt] ?? '') . "</div>";
                                            }
                                            break;
                                        case 'true_false':
                                            $stmt = $conn->prepare("SELECT correct_answer FROM true_false_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer'] ?? '') . "</div>";
                                            break;
                                        case 'fill_blanks':
                                            $stmt = $conn->prepare("SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer'] ?? '') . "</div>";
                                            break;
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No questions available to preview.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        const editData = <?php echo json_encode($edit_data); ?>;
        const questionTemplates = {
            multiple_choice_single: `
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleImageBtn">
                        <i class="fas fa-image"></i> Add Image to Question
                    </button>
                    <div id="imageUploadContainer">
                        <label class="form-label mt-3">Question Image</label>
                        <input type="file" class="form-control" name="question_image" accept="image/*">
                        ${editData.options?.image_path ? `
                            <div class="mt-2">
                                <p>Current Image:</p>
                                <img src="../${editData.options.image_path}" style="max-height: 100px;" class="img-thumbnail">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_image" id="removeImage">
                                    <label class="form-check-label" for="removeImage">Remove current image</label>
                                </div>
                            </div>
                        ` : ''}
                        <small class="text-muted">Optional. Max size: 2MB. Formats: JPG, PNG, GIF</small>
                    </div>
                </div>
                <div class="option-group">
                    ${[1,2,3,4].map(i => `
                        <div class="mb-3 option-highlight">
                            <label class="form-label">Option ${i}</label>
                            <input type="text" class="form-control" name="option${i}" required placeholder="Enter option ${i}" value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '"') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <select class="form-select" name="correct_answer" required>
                            <option value="">Select Correct Answer</option>
                            ${[1,2,3,4].map(i => `
                                <option value="${i}" ${editData.options.correct_answer && editData.options['option' + editData.options.correct_answer] === editData.options['option' + i] ? 'selected' : ''}>Option ${i}</option>
                            `).join('')}
                        </select>
                    </div>
                </div>
            `,
            multiple_choice_multiple: `
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleImageBtn">
                        <i class="fas fa-image"></i> Add Image to Question
                    </button>
                    <div id="imageUploadContainer">
                        <label class="form-label mt-3">Question Image</label>
                        <input type="file" class="form-control" name="question_image" accept="image/*">
                        ${editData.options?.image_path ? `
                            <div class="mt-2">
                                <p>Current Image:</p>
                                <img src="../${editData.options.image_path}" style="max-height: 100px;" class="img-thumbnail">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_image" id="removeImage">
                                    <label class="form-check-label" for="removeImage">Remove current image</label>
                                </div>
                            </div>
                        ` : ''}
                        <small class="text-muted">Optional. Max size: 2MB. Formats: JPG, PNG, GIF</small>
                    </div>
                </div>
                <div class="option-group">
                    ${[1,2,3,4].map(i => `
                        <div class="mb-3 option-highlight">
                            <label class="form-label">Option ${i}</label>
                            <input type="text" class="form-control" name="option${i}" required placeholder="Enter option ${i}" value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '"') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answers</label>
                        <div class="form-check">
                            ${[1,2,3,4].map(i => `
                                <div>
                                    <input class="form-check-input" type="checkbox" name="correct_answers[]" value="${i}" id="correct${i}" ${editData.correct_answers.includes(i) ? 'checked' : ''}>
                                    <label class="form-check-label" for="correct${i}">Option ${i}</label>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `,
            true_false: `
                <div class="mb-3">
                    <label class="form-label">Correct Answer</label>
                    <select class="form-select" name="correct_answer" required>
                        <option value="">Select Correct Answer</option>
                        <option value="True" ${editData.options?.correct_answer === 'True' ? 'selected' : ''}>True</option>
                        <option value="False" ${editData.options?.correct_answer === 'False' ? 'selected' : ''}>False</option>
                    </select>
                </div>
            `,
            fill_blanks: `
                <div class="mb-3">
                    <label class="form-label">Correct Answer</label>
                    <input type="text" class="form-control" name="correct_answer" required placeholder="Enter the correct answer" value="${editData.options?.correct_answer ? editData.options.correct_answer.replace(/"/g, '"') : ''}">
                </div>
            `
        };

        function updateOptionsContainer() {
            const questionType = document.getElementById('questionType')?.value;
            const optionsContainer = document.getElementById('optionsContainer');
            if (questionType && optionsContainer) {
                optionsContainer.innerHTML = questionTemplates[questionType] || '';
            }
        }

        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Initialize question form
            updateOptionsContainer();
            $('#questionType').on('change', updateOptionsContainer);

            // Image toggle
            $(document).on('click', '#toggleImageBtn', function() {
                const container = document.getElementById('imageUploadContainer');
                if (container) {
                    container.style.display = container.style.display === 'none' ? 'block' : 'none';
                }
            });

            // Class-subject mapping
            const classSubjectMapping = {
                'JSS1': <?php echo json_encode($jss_subjects); ?>,
                'JSS2': <?php echo json_encode($jss_subjects); ?>,
                'JSS3': <?php echo json_encode($jss_subjects); ?>,
                'SS1': <?php echo json_encode($ss_subjects); ?>,
                'SS2': <?php echo json_encode($ss_subjects); ?>,
                'SS3': <?php echo json_encode($ss_subjects); ?>
            };
            const assignedSubjects = <?php echo json_encode($assigned_subjects); ?>;

            // Update subjects when class changes
            $('#classSelect').on('change', function() {
                const selectedClass = this.value;
                const subjectSelect = document.getElementById('subjectSelect');
                if (subjectSelect) {
                    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
                    if (selectedClass && classSubjectMapping[selectedClass]) {
                        classSubjectMapping[selectedClass].filter(subject => assignedSubjects.includes(subject)).forEach(subject => {
                            const option = document.createElement('option');
                            option.value = subject;
                            option.textContent = subject;
                            subjectSelect.appendChild(option);
                        });
                    }
                }
            });

            // Form validation
            $('#testForm').validate({
                rules: {
                    test_title: { required: true },
                    class: { required: true },
                    subject: { required: true },
                    duration: { required: true, number: true, min: 1 }
                },
                messages: {
                    duration: { min: "Duration must be at least 1 minute." }
                }
            });

            $('#questionForm').validate({
                rules: {
                    question_type: { required: true },
                    question: { required: true },
                    option1: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    option2: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    option3: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    option4: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    correct_answer: { required: true },
                    'correct_answers[]': { required: { depends: () => $('#questionType').val() === 'multiple_choice_multiple' } }
                },
                messages: {
                    'correct_answers[]': "At least one correct answer is required."
                }
            });

            // Preview modal debug
            $('#previewButton').on('click', function() {
                if ($(this).hasClass('preview-disabled')) {
                    console.log('Preview disabled: No test selected.');
                    return false;
                }
                try {
                    $('#previewModal').modal('show');
                } catch (e) {
                    console.error('Error opening preview modal:', e);
                }
            });
        });
    </script>
</body>
</html>