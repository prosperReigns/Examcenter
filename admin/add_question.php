<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') {
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

    // Fetch admin profile
    $admin_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for admin profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin) {
        error_log("No admin found for user_id=$admin_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Log page access
    $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $activity = "Admin {$admin['username']} accessed add question page.";
    $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();

} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}

// Define subjects by category
$jss_subjects = [
    'mathematics', 'english', 'ict', 'agriculture', 'history', 
    'civic education', 'basic science', 'basic technology', 
    'business studies', 'agricultural sci', 'physical health edu',
    'cultural and creative art', 'social studies', 'security edu', 
    'yoruba', 'french', 'coding and robotics', 'c.r.s', 'i.r.s', 'chess'
];
$ss_subjects = [
    'mathematics', 'english', 'civic edu', 'data processing', 'economics',
    'government', 'commerce', 'accounting', 'financial accounting', 
    'dyeing and bleaching', 'physics', 'chemistry', 'biology', 
    'agricultural sci', 'geography', 'technical drawing', 'yoruba lang',
    'french lang', 'further maths', 'literature in english', 'c.r.s', 'i.r.s'
];

// Initialize variables
$error = $success = '';
$current_test = null;
$questions = [];
$total_questions = 0;
$tests = [];

// Fetch all available tests
$stmt = $conn->prepare("SELECT id, title, class, subject FROM tests ORDER BY created_at DESC");
$stmt->execute();
$tests_result = $stmt->get_result();
$tests = $tests_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function is_valid_subject($class, $subject) {
    global $jss_subjects, $ss_subjects;
    $subject = strtolower(trim($subject));
    $class = strtolower(trim($class));
    $valid_subjects = strpos($class, 'jss') === 0 ? $jss_subjects : (strpos($class, 'ss') === 0 ? $ss_subjects : []);
    return in_array($subject, $valid_subjects);
}

function log_activity($conn, $activity, $admin_id) {
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("siss", $activity, $admin_id, $ip, $agent);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_test'])) {
    $title = trim($_POST['test_title'] ?? '');
    $class = trim($_POST['class'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);

    if (!is_valid_subject($class, $subject)) {
        $error = "Invalid subject for selected class!";
        error_log("Invalid subject attempt: {$subject} for {$class}");
    } elseif ($title && $class && $subject && $duration > 0) {
        $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND class = ? AND subject = ?");
        $stmt->bind_param("sss", $title, $class, $subject);
        $stmt->execute();
        $existing_test = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing_test) {
            $error = "A test with the same title, class, and subject already exists!";
        } else {
            $stmt = $conn->prepare("INSERT INTO tests (title, class, subject, duration) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $title, $class, $subject, $duration);
            if ($stmt->execute()) {
                $_SESSION['current_test_id'] = $stmt->insert_id;
                $success = "Test created successfully!";
                log_activity($conn, "Admin created test: $title ($class, $subject)", $admin_id);
            } else {
                $error = "Error creating test: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        $error = "Please fill in all test details, including a valid duration.";
    }
}

// Handle test selection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['select_test'])) {
    $test_id = intval($_POST['test_id'] ?? 0);
    if ($test_id) {
        $_SESSION['current_test_id'] = $test_id;
        $success = "Test selected successfully!";
    } else {
        $error = "Please select a valid test.";
    }
}

// Handle clear test selection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['clear_test'])) {
    unset($_SESSION['current_test_id']);
    $success = "Test selection cleared.";
}

// Handle question deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_question'])) {
    $question_id = intval($_POST['question_id'] ?? 0);
    $question_type = trim($_POST['question_type'] ?? '');
    if ($question_id && $question_type) {
        $table_map = [
            'multiple_choice_single' => 'single_choice_questions',
            'multiple_choice_multiple' => 'multiple_choice_questions',
            'true_false' => 'true_false_questions',
            'fill_blanks' => 'fill_blank_questions',
        ];
        $table = $table_map[$question_type] ?? '';
        if ($table) {
            $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
        $stmt->bind_param("i", $question_id);
        if ($stmt->execute()) {
            $success = "Question deleted successfully!";
            log_activity($conn, "Admin deleted question ID: $question_id", $admin_id);
        } else {
            $error = "Error deleting question: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Invalid question ID or type.";
    }
}

// Handle question editing
$edit_question = null;
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_question'])) {
    $question_id = intval($_POST['question_id'] ?? 0);
    if ($question_id) {
        $stmt = $conn->prepare("SELECT * FROM new_questions WHERE id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $edit_question = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        switch ($edit_question['question_type']) {
            case 'multiple_choice_single':
                $sql = "SELECT *, image_path FROM single_choice_questions WHERE question_id = ?";
                break;
            case 'multiple_choice_multiple':
                $sql = "SELECT *, image_path FROM multiple_choice_questions WHERE question_id = ?";
                break;
            case 'true_false':
                $sql = "SELECT * FROM true_false_questions WHERE question_id = ?";
                break;
            case 'fill_blanks':
                $sql = "SELECT * FROM fill_blank_questions WHERE question_id = ?";
                break;
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $edit_question['options'] = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

function handleImageUpload($question_id) {
    if (!isset($_FILES['question_image']) || $_FILES['question_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $max_size = 2 * 1024 * 1024;
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if ($_FILES['question_image']['size'] > $max_size || !in_array($_FILES['question_image']['type'], $allowed_types)) {
        return false;
    }
    $upload_dir = '../Uploads/questions/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
    $filename = 'question_' . $question_id . '_' . time() . '.' . $ext;
    $full_path = $upload_dir . $filename;
    if (move_uploaded_file($_FILES['question_image']['tmp_name'], $full_path)) {
        return 'Uploads/questions/' . $filename;
    }
    return false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['question'])) {
    if (!isset($_SESSION['current_test_id'])) {
        $error = "Please create or select a test first";
    } else {
        $test_id = $_SESSION['current_test_id'];
        $question_id = intval($_POST['question_id'] ?? 0);
        $question = trim($_POST['question']);
        $question_type = trim($_POST['question_type'] ?? '');

        if (isset($_POST['remove_image']) && $_POST['remove_image'] === 'on' && !empty($edit_question['options']['image_path'])) {
            $file_path = '../' . $edit_question['options']['image_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $image_path = null;
        }

        $stmt = $conn->prepare("SELECT class, subject FROM tests WHERE id = ?");
        $stmt->bind_param("i", $test_id);
        $stmt->execute();
        $test_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $class = $test_data['class'];
        $subject = $test_data['subject'];

        if ($question_id) {
            $stmt = $conn->prepare("UPDATE new_questions SET question_text = ?, question_type = ? WHERE id = ?");
            $stmt->bind_param("ssi", $question, $question_type, $question_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, class, subject, question_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sisss", $question, $test_id, $class, $subject, $question_type);
        }
        
        if ($stmt->execute()) {
            $question_id = $question_id ?: $stmt->insert_id;
            $success = '';
            switch ($question_type) {
                case 'multiple_choice_single':
                    $option1 = trim($_POST['option1'] ?? '');
                    $option2 = trim($_POST['option2'] ?? '');
                    $option3 = trim($_POST['option3'] ?? '');
                    $option4 = trim($_POST['option4'] ?? '');
                    $correct_answer = trim($_POST['correct_answer'] ?? '');
                    $image_path = handleImageUpload($question_id);
                    if ($image_path === false) {
                        $error = "Image upload failed";
                        break;
                    }
                    if ($option1 && $option2 && $option3 && $option4 && $correct_answer) {
                        if ($question_id) {
                            $stmt = $conn->prepare("UPDATE single_choice_questions SET option1 = ?, option2 = ?, option3 = ?, option4 = ?, correct_answer = ?, image_path = ? WHERE question_id = ?");
                            $stmt->bind_param("ssssssi", $option1, $option2, $option3, $option4, $correct_answer, $image_path, $question_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO single_choice_questions (question_id, option1, option2, option3, option4, correct_answer, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, $correct_answer, $image_path);
                        }
                        if ($stmt->execute()) {
                            $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                            log_activity($conn, "Admin " . ($edit_question ? 'updated' : 'added') . " single choice question ID: $question_id", $admin_id);
                        } else {
                            $error = "Error saving question options: " . $conn->error;
                        }
                    }
                    break;
                case 'multiple_choice_multiple':
                    $option1 = trim($_POST['option1'] ?? '');
                    $option2 = trim($_POST['option2'] ?? '');
                    $option3 = trim($_POST['option3'] ?? '');
                    $option4 = trim($_POST['option4'] ?? '');
                    $correct_answers = isset($_POST['correct_answers']) ? implode(',', array_map('intval', $_POST['correct_answers'])) : '';
                    $image_path = handleImageUpload($question_id);
                    if ($image_path === false) {
                        $error = "Image upload failed";
                        break;
                    }
                    if ($option1 && $option2 && $option3 && $option4 && $correct_answers) {
                        if ($question_id) {
                            $stmt = $conn->prepare("UPDATE multiple_choice_questions SET option1 = ?, option2 = ?, option3 = ?, option4 = ?, correct_answers = ?, image_path = ? WHERE question_id = ?");
                            $stmt->bind_param("ssssssi", $option1, $option2, $option3, $option4, $correct_answers, $image_path, $question_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO multiple_choice_questions (question_id, option1, option2, option3, option4, correct_answers, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, $correct_answers, $image_path);
                        }
                        if ($stmt->execute()) {
                            $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                            log_activity($conn, "Admin " . ($edit_question ? 'updated' : 'added') . " multiple choice question ID: $question_id", $admin_id);
                        } else {
                            $error = "Error saving question options: " . $conn->error;
                        }
                    }
                    break;
                case 'true_false':
                    $correct_answer = trim($_POST['correct_answer'] ?? '');
                    if ($correct_answer) {
                        if ($question_id) {
                            $stmt = $conn->prepare("UPDATE true_false_questions SET correct_answer = ? WHERE question_id = ?");
                            $stmt->bind_param("si", $correct_answer, $question_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO true_false_questions (question_id, concrete_answer) VALUES (?, ?)");
                            $stmt->bind_param("is", $question_id, $correct_answer);
                        }
                        if ($stmt->execute()) {
                            $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                            log_activity($conn, "Admin " . ($edit_question ? 'updated' : 'added') . " true/false question ID: $question_id", $admin_id);
                        } else {
                            $error = "Error saving question options: " . $conn->error;
                        }
                    }
                    break;
                case 'fill_blanks':
                    $correct_answer = trim($_POST['correct_answer'] ?? '');
                    if ($correct_answer) {
                        if ($question_id) {
                            $stmt = $conn->prepare("UPDATE fill_blank_questions SET correct_answer = ? WHERE question_id = ?");
                            $stmt->bind_param("si", $correct_answer, $question_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO fill_blank_questions (question_id, correct_answer) VALUES (?, ?)");
                            $stmt->bind_param("is", $question_id, $correct_answer);
                        }
                        if ($stmt->execute()) {
                            $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                            log_activity($conn, "Admin " . ($edit_question ? 'updated' : 'added') . " fill-in-blank question ID: $question_id", $admin_id);
                        } else {
                            $error = "Error saving question options: " . $conn->error;
                        }
                    }
                    break;
            }
        }
        $stmt->close();
    }
}

if (isset($_SESSION['current_test_id'])) {
    $test_id = $_SESSION['current_test_id'];
    $stmt = $conn->prepare("SELECT * FROM tests WHERE id = ?");
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $current_test = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT * FROM new_questions WHERE test_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $total_questions = count($questions);
    $stmt->close();
}

$edit_data = [
    'options' => $edit_question['options'] ?? [],
    'question_type' => $edit_question['question_type'] ?? ''
];
$edit_data['correct_answers'] = isset($edit_question['options']['correct_answers']) ? array_map('intval', explode(',', $edit_question['options']['correct_answers'])) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/view_results.css">
    <style>
        .sidebar {
            background-color: #2c3e50;
            color: white;
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 20px;
            transition: transform 0.3s ease;
        }
        .sidebar.active {
            transform: translateX(-250px);
        }
        .sidebar-brand h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .admin-info small {
            font-size: 0.8rem;
            opacity: 0.7;
            color: white;
        }
        .admin-info h6{
            color: white;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            color: white;
            padding: 10px;
            margin-bottom: 5px;
            border-radius: 5px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: #34495e;
        }
        .sidebar-menu a i {
            margin-right: 10px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background: #f8f9fa;
        }
        .header {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filter-card, .preview-card {
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background-color: white;
        }
        .form-group-spacing {
            margin-bottom: 1.5rem;
        }
        .option-highlight {
            padding: 10px;
            border-left: 3px solid #4361ee;
            background: #f8f9fa;
            border-radius: 4px;
        }
        #imageUploadContainer {
            display: none;
        }
        .empty-state {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        .modal-preview {
            max-height: 500px;
            overflow-y: auto;
        }
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-250px);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
               <b>
                 <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($admin['username']); ?></h6>
               </b>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" class="active"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Add Questions</h2>
            <div class="d-flex gap-3">
                <a href="view_questions.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>View Questions</a>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Question Form -->
            <div class="col-lg-8">
                <div class="card bg-white border-0 shadow-sm filter-card">
                    <div class="card-body">
                        <?php if (!$current_test): ?>
                            <h5 class="mb-3"><i class="fas fa-file-alt me-2"></i>Test Setup</h5>
                            <div class="card mb-4">
                                <div class="card-body">
                                    <h6><b>Create New Test</b></h6>
                                    <form method="POST" id="createTestForm">
                                        <div class="row g-3">
                                            <div class="col-md-3 form-group-spacing">
                                                <label class="form-label fw-bold">Test Title</label>
                                                <select class="form-select" name="test_title" required>
                                                    <option value="">Select Test Title</option>
                                                    <option value="First term exam">First Term Exam</option>
                                                    <option value="First term test">First Term Test</option>
                                                    <option value="Second term exam">Second Term Exam</option>
                                                    <option value="Second term test">Second Term Test</option>
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
                                                </select>
                                            </div>
                                            <div class="col-md-3 form-group-spacing">
                                                <label class="form-label fw-bold">Duration (min)</label>
                                                <input type="number" class="form-control" name="duration" required placeholder="e.g. 30" min="1">
                                            </div>
                                        </div>
                                        <button type="submit" name="create_test" class="btn btn-primary mt-3">
                                            <i class="fas fa-plus me-2"></i>Create Test
                                        </button>
                                    </form>

                                    <?php if (!empty($tests)): ?>
                                        <hr>
                                        <h6>Select Existing Test</h6>
                                        <form method="POST" id="selectTestForm">
                                            <div class="form-group-spacing">
                                                <label class="form-label fw-bold">Available Tests</label>
                                                <select class="form-select" name="test_id" required>
                                                    <option value="">Select a Test</option>
                                                    <?php foreach ($tests as $test): ?>
                                                        <option value="<?php echo $test['id']; ?>">
                                                            <?php echo htmlspecialchars($test['title'] . ' (' . $test['class'] . ' - ' . $test['subject'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button type="submit" name="select_test" class="btn btn-primary mt-2">
                                                <i class="fas fa-check me-2"></i>Select Test
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <h5 class="mb-3"><i class="fas fa-question-circle me-2"></i><?php echo $edit_question ? 'Edit Question' : 'Add Question'; ?></h5>
                            <form method="POST" id="questionForm" enctype="multipart/form-data">
                                <input type="hidden" name="question_id" value="<?php echo $edit_question['id'] ?? ''; ?>">
                                <div class="form-group-spacing">
                                    <label class="form-label fw-bold">Question Type</label>
                                    <select class="form-select form-select-lg" name="question_type" id="questionType" required>
                                        <option value="multiple_choice_single" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_single') ? 'selected' : ''; ?>>Multiple Choice (Single)</option>
                                        <option value="multiple_choice_multiple" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_multiple') ? 'selected' : ''; ?>>Multiple Choice (Multiple)</option>
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
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-<?php echo $edit_question ? 'save' : 'plus'; ?> me-2"></i><?php echo $edit_question ? 'Update Question' : 'Add Question'; ?>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Test Overview -->
            <div class="col-lg-4">
                <div class="card bg-white border-0 shadow-sm preview-card">
                    <div class="card-body">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Test Overview</h5>
                        <?php if ($current_test): ?>
                            <div class="alert alert-primary">
                                <strong><?php echo htmlspecialchars($current_test['title']); ?></strong><br>
                                <span class="text-muted"><?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?></span><br>
                                <small>Duration: <?php echo $current_test['duration']; ?> minutes</small>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Total Questions:</span>
                                <strong><?php echo $total_questions; ?></strong>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#previewModal">
                                    <i class="fas fa-eye me-2"></i>Preview Questions
                                </button>
                                <form method="POST" action="">
                                    <button type="submit" name="clear_test" class="btn btn-outline-danger w-100">
                                        <i class="fas fa-times me-2"></i>Clear Test Selection
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="empty-state text-center">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p class="text-muted">No test selected. Create or select a test to start.</p>
                            </div>
                        <?php endif; ?>
                    </div>
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
                        <p><small>Duration: <?php echo $current_test['duration']; ?> minutes</small></p>
                        <hr>
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong>Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question_text']); ?></strong>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <input type="hidden" name="edit_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <input type="hidden" name="question_type" value="<?php echo $question['question_type']; ?>">
                                            <input type="hidden" name="delete_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
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
                                            if (!empty($options['image_path'])) {
                                                echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                            }   
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . ($options['correct_answer'] == $option_number ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'multiple_choice_multiple':
                                            $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if (!empty($options['image_path'])) {
                                                echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                            }
                                            $correct = explode(',', $options['correct_answers']);
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . (in_array($option_number, $correct) ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'true_false':
                                            $stmt = $conn->prepare("SELECT correct_answer FROM true_false_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer']) . "</div>";
                                            break;
                                        case 'fill_blanks':
                                            $stmt = $conn->prepare("SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer']) . "</div>";
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
    <script src="../js/dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Form validation
            $('#createTestForm').validate({
                rules: {
                    test_title: { required: true, maxlength: 255 },
                    class: { required: true },
                    subject: { required: true },
                    duration: { required: true, number: true, min: 1 }
                },
                errorElement: 'div',
                errorClass: 'invalid-feedback',
                highlight: function(element) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element) {
                    $(element).removeClass('is-invalid');
                }
            });

            $('#selectTestForm').validate({
                rules: {
                    test_id: { required: true }
                },
                errorElement: 'div',
                errorClass: 'invalid-feedback',
                highlight: function(element) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element) {
                    $(element).removeClass('is-invalid');
                }
            });

            $('#questionForm').validate({
                rules: {
                    question_type: { required: true },
                    question: { required: true, maxlength: 1000 },
                    option1: { required: function() { return $('#questionType').val().includes('multiple_choice'); } },
                    option2: { required: function() { return $('#questionType').val().includes('multiple_choice'); } },
                    option3: { required: function() { return $('#questionType').val().includes('multiple_choice'); } },
                    option4: { required: function() { return $('#questionType').val().includes('multiple_choice'); } },
                    correct_answer: { required: function() { return $('#questionType').val() !== 'multiple_choice_multiple'; } },
                    'correct_answers[]': { required: function() { return $('#questionType').val() === 'multiple_choice_multiple'; } }
                },
                errorElement: 'div',
                errorClass: 'invalid-feedback',
                highlight: function(element) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element) {
                    $(element).removeClass('is-invalid');
                },
                errorPlacement: function(error, element) {
                    if (element.attr('name') === 'correct_answers[]') {
                        error.insertAfter(element.closest('.form-check'));
                    } else {
                        error.insertAfter(element);
                    }
                }
            });

            // Pass PHP edit data to JavaScript
            const editData = <?php echo json_encode($edit_data); ?>;

            // Question type templates
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
                                <input type="text" class="form-control" name="option${i}" 
                                       placeholder="Enter option ${i}" 
                                       value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '"') : ''}">
                            </div>
                        `).join('')}
                        <div class="mb-3">
                            <label class="form-label">Correct Answer</label>
                            <select class="form-select" name="correct_answer">
                                <option value="">Select Correct Answer</option>
                                ${[1,2,3,4].map(i => `
                                    <option value="${i}" ${editData.options.correct_answer && parseInt(editData.options.correct_answer) === i ? 'selected' : ''}>
                                        Option ${i}
                                    </option>
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
                                <input type="text" class="form-control" name="option${i}" 
                                       placeholder="Enter option ${i}" 
                                       value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '"') : ''}">
                            </div>
                        `).join('')}
                        <div class="mb-3">
                            <label class="form-label">Correct Answers</label>
                            <div class="form-check">
                                ${[1,2,3,4].map(i => `
                                    <div>
                                        <input class="form-check-input" type="checkbox" name="correct_answers[]" 
                                               value="${i}" id="correct${i}" 
                                               ${editData.correct_answers.includes(i) ? 'checked' : ''}>
                                        <label class="form-check-label" for="correct${i}">
                                            Option ${i}
                                        </label>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                `,
                true_false: `
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <select class="form-select" name="correct_answer">
                            <option value="">Select Correct Answer</option>
                            <option value="True" ${editData.options?.correct_answer === 'True' ? 'selected' : ''}>True</option>
                            <option value="False" ${editData.options?.correct_answer === 'False' ? 'selected' : ''}>False</option>
                        </select>
                    </div>
                `,
                fill_blanks: `
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <input type="text" class="form-control" name="correct_answer" 
                               placeholder="Enter the correct answer" 
                               value="${editData.options?.correct_answer ? editData.options.correct_answer.replace(/"/g, '"') : ''}">
                    </div>
                `
            };

            // Update options container
            function updateOptionsContainer() {
                const questionType = $('#questionType').val();
                const optionsContainer = $('#optionsContainer');
                optionsContainer.html(questionTemplates[questionType] || '');
                const toggleImageBtn = $('#toggleImageBtn');
                if (toggleImageBtn.length) {
                    toggleImageBtn.on('click', function() {
                        $('#imageUploadContainer').toggle();
                    });
                }
            }

            // Initialize form
            if ($('#questionType').length) {
                updateOptionsContainer();
                $('#questionType').on('change', updateOptionsContainer);
            }

            // Class-subject mapping
            const classSubjectMapping = {
                'JSS1': <?php echo json_encode($jss_subjects); ?>,
                'JSS2': <?php echo json_encode($jss_subjects); ?>,
                'JSS3': <?php echo json_encode($jss_subjects); ?>,
                'SS1': <?php echo json_encode($ss_subjects); ?>,
                'SS2': <?php echo json_encode($ss_subjects); ?>,
                'SS3': <?php echo json_encode($ss_subjects); ?>
            };

            // Update subjects on class change
            $('#classSelect').on('change', function() {
                const selectedClass = $(this).val();
                const subjectSelect = $('#subjectSelect');
                subjectSelect.empty().append('<option value="">Select Subject</option>');
                if (selectedClass && classSubjectMapping[selectedClass]) {
                    classSubjectMapping[selectedClass].forEach(subject => {
                        subjectSelect.append(`<option value="${subject}">${subject}</option>`);
                    });
                }
            });

            // Form reset
            $('#questionForm').on('reset', function() {
                $('#optionsContainer').empty();
                $('#imageUploadContainer').hide();
                setTimeout(updateOptionsContainer, 0);
            });
        });
    </script>
</body>
</html>