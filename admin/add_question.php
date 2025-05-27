<?php
session_start();
require_once '../db.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Define subjects by category at the top of your file
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

// Fetch all available tests
$tests_query = "SELECT id, title, class, subject FROM tests ORDER BY created_at DESC";
$tests_result = $conn->query($tests_query);
if ($tests_result) {
    $tests = $tests_result->fetch_all(MYSQLI_ASSOC);
}

function is_valid_subject($class, $subject) {
    global $jss_subjects, $ss_subjects;
    
    $subject = strtolower(trim($subject));
    $class = strtolower(trim($class));
    
    $valid_subjects = [];
    
    if (strpos($class, 'jss') === 0) {
        $valid_subjects = array_map('strtolower', $jss_subjects);
    } elseif (strpos($class, 'ss') === 0) {
        $valid_subjects = array_map('strtolower', $ss_subjects);
    }
    
    return in_array($subject, $valid_subjects);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_test'])) {
    $title = $conn->real_escape_string($_POST['test_title'] ?? '');
    $class = $conn->real_escape_string($_POST['class'] ?? '');
    $subject = $conn->real_escape_string($_POST['subject'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);

    if (!is_valid_subject($class, $subject)) {
        $error = "Invalid subject for selected class!";
        error_log("Invalid subject attempt: {$subject} for {$class}");
    } elseif ($title && $class && $subject && $duration > 0) {
        $check_sql = "SELECT id FROM tests WHERE title = ? AND class = ? AND subject = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("sss", $title, $class, $subject);
        $check_stmt->execute();
        $existing_test = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if ($existing_test) {
            $error = "A test with the same title, class, and subject already exists!";
        } else {
            $sql = "INSERT INTO tests (title, class, subject, duration) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);

            if ($stmt === false) {
                die("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("sssi", $title, $class, $subject, $duration);
            if ($stmt->execute()) {
                $_SESSION['current_test_id'] = $stmt->insert_id;
                $success = "Test created successfully!";
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
    $question_type = $conn->real_escape_string($_POST['question_type'] ?? '');
    
    if ($question_id && $question_type) {
        $table_map = [
            'multiple_choice_single' => 'single_choice_questions',
            'multiple_choice_multiple' => 'multiple_choice_questions',
            'true_false' => 'true_false_questions',
            'fill_blanks' => 'fill_blank_questions',
        ];
        
        $table = $table_map[$question_type] ?? '';
        if ($table) {
            $sql = "DELETE FROM $table WHERE question_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();
        }
        
        $sql = "DELETE FROM new_questions WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $question_id);
        if ($stmt->execute()) {
            $success = "Question deleted successfully!";
        } else {
            $error = "Error deleting question: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Invalid question ID or type.";
    }
}

// Handle question editing (load question into form)
$edit_question = null;
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_question'])) {
    $question_id = intval($_POST['question_id'] ?? 0);
    if ($question_id) {
        $sql = "SELECT * FROM new_questions WHERE id = ?";
        $stmt = $conn->prepare($sql);
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
    global $conn;
    
    if (!isset($_FILES['question_image']) || $_FILES['question_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $max_size = 2 * 1024 * 1024;
    if ($_FILES['question_image']['size'] > $max_size) {
        return false;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($_FILES['question_image']['type'], $allowed_types)) {
        return false;
    }

    $upload_dir = '../uploads/questions/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
    $filename = 'question_' . $question_id . '_' . time() . '.' . $ext;
    $full_path = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES['question_image']['tmp_name'], $full_path)) {
        return 'uploads/questions/' . $filename;
    }
    
    return false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['question'])) {
    if (!isset($_SESSION['current_test_id'])) {
        $error = "Please create or select a test first";
    } else {
        $test_id = $_SESSION['current_test_id'];
        $question_id = intval($_POST['question_id'] ?? 0);
        $question = $conn->real_escape_string($_POST['question']);
        $question_type = $conn->real_escape_string($_POST['question_type'] ?? '');

        if (isset($_POST['remove_image']) && $_POST['remove_image'] === 'on') {
            if (!empty($edit_question['options']['image_path'])) {
                $file_path = '../' . $edit_question['options']['image_path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            $image_path = null;
        }

        $test_query = "SELECT class, subject FROM tests WHERE id = ?";
        $stmt = $conn->prepare($test_query);
        $stmt->bind_param("i", $test_id);
        $stmt->execute();
        $test_result = $stmt->get_result();
        $test_data = $test_result->fetch_assoc();
        $stmt->close();
        $class = $test_data['class'];
        $subject = $test_data['subject'];

        if ($question_id) {
            $sql = "UPDATE new_questions SET question_text = ?, question_type = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $question, $question_type, $question_id);
        } else {
            $sql = "INSERT INTO new_questions (question_text, test_id, class, subject, question_type) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sisss", $question, $test_id, $class, $subject, $question_type);
        }
        
        if ($stmt->execute()) {
    $question_id = $question_id ?: $stmt->insert_id;
    $success = ''; 
    
    switch ($question_type) {
        case 'multiple_choice_single':
            $option1 = $conn->real_escape_string($_POST['option1'] ?? '');
            $option2 = $conn->real_escape_string($_POST['option2'] ?? '');
            $option3 = $conn->real_escape_string($_POST['option3'] ?? '');
            $option4 = $conn->real_escape_string($_POST['option4'] ?? '');
            $correct_answer = $conn->real_escape_string($_POST['correct_answer'] ?? '');
            $image_path = handleImageUpload($question_id);
            
            if ($image_path === false) {
                $error = "Image upload failed";
                break;
            }
            
            if ($option1 && $option2 && $option3 && $option4 && $correct_answer) {
                $sql = "INSERT INTO single_choice_questions 
                        (question_id, option1, option2, option3, option4, correct_answer, image_path) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, 
                                 $correct_answer, $image_path);
                if ($stmt->execute()) {
                    $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                } else {
                    $error = "Error saving question options: " . $conn->error;
                }
            }
            break;
            
        case 'multiple_choice_multiple':
            $option1 = $conn->real_escape_string($_POST['option1'] ?? '');
            $option2 = $conn->real_escape_string($_POST['option2'] ?? '');
            $option3 = $conn->real_escape_string($_POST['option3'] ?? '');
            $option4 = $conn->real_escape_string($_POST['option4'] ?? '');
            $correct_answers = isset($_POST['correct_answers']) ? implode(',', array_map('intval', $_POST['correct_answers'])) : '';
            $correct_answers = $conn->real_escape_string($correct_answers);
            $image_path = handleImageUpload($question_id);
            
            if ($image_path === false) {
                $error = "Image upload failed";
                break;
            }
            
            if ($option1 && $option2 && $option3 && $option4 && $correct_answers) {
                $sql = "INSERT INTO multiple_choice_questions 
                        (question_id, option1, option2, option3, option4, correct_answers, image_path) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, 
                                 $correct_answers, $image_path);
                if ($stmt->execute()) {
                    $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                } else {
                    $error = "Error saving question options: " . $conn->error;
                }
            }
            break;
            
        case 'true_false':
            $correct_answer = $conn->real_escape_string($_POST['correct_answer'] ?? '');
            
            if ($correct_answer) {
                $sql = "INSERT INTO true_false_questions (question_id, correct_answer) 
                        VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("is", $question_id, $correct_answer);
                if ($stmt->execute()) {
                    $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                } else {
                    $error = "Error saving question options: " . $conn->error;
                }
            }
            break;
            
        case 'fill_blanks':
            $correct_answer = $conn->real_escape_string($_POST['correct_answer'] ?? '');
            
            if ($correct_answer) {
                $sql = "INSERT INTO fill_blank_questions (question_id, correct_answer) 
                        VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("is", $question_id, $correct_answer);
                if ($stmt->execute()) {
                    $success = "Question " . ($edit_question ? 'updated' : 'added') . " successfully!";
                } else {
                    $error = "Error saving question options: " . $conn->error;
                }
            }
            break;
    }
}
    }
}

if (isset($_SESSION['current_test_id'])) {
    $test_id = $_SESSION['current_test_id'];
    
    $test_query = "SELECT * FROM tests WHERE id = ?";
    $stmt = $conn->prepare($test_query);
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $test_result = $stmt->get_result();
    $current_test = $test_result->fetch_assoc();
    $stmt->close();
    
    $questions_query = "SELECT * FROM new_questions WHERE test_id = ? ORDER BY id ASC";
    $stmt = $conn->prepare($questions_query);
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $questions_result = $stmt->get_result();
    
    if ($questions_result) {
        $questions = $questions_result->fetch_all(MYSQLI_ASSOC);
        $total_questions = count($questions);
    }
    $stmt->close();
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.min.css">
    <link rel="stylesheet" href="../css/animate.min.css">
    <link rel="stylesheet" href="../css/add_question.css">
    <style>
        #imageUploadContainer {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Gradient Header -->
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">Add questions</h1>
                <div class="d-flex gap-3">
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#previewModal">
                        <i class="fas fa-eye me-2"></i>Preview
                    </button>
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <!-- Question Form -->
            <div class="col-lg-8">
                <div class="question-card">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <?php if (!$current_test): ?>
                        <h5 class="mb-3">Test Setup</h5>
                        <div class="card mb-4">
                            <div class="card-body">
                                <h6> <b> Create new Test</b></h6>
                                <form method="POST">
                                    <div class="row g-3">
                                        <div class="col-md-3 form-group-spacing">
                                            <label class="form-label fw-bold">Test Title</label>
                                           <select class="form-select" name="test_title" required>
                                                <option value="">Select Test title</option>
                                                <option value="First term exam">First term exam</option>
                                                <option value="First term test">First term test</option>
                                                <option value="Second term exam">Second term exam</option>
                                                <option value="Second term test">Second term test</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 form-group-spacing">
                                            <label class="form-label fw-bold">Class</label>
                                            <select class="form-select" name="class" required>
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
                                    <form method="POST">
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
                                        <button type="submit" name="select_test" class="btn btn-primary">
                                            <i class="fas fa-check me-2"></i>Select Test
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Question Form -->
                    <?php if ($current_test): ?>
                        <h5 class="mb-3"><?php echo $edit_question ? 'Edit Question' : 'Add Question'; ?></h5>
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
                                <textarea class="form-control" name="question" rows="4" 
                                    placeholder="Enter your question here..." required><?php echo htmlspecialchars($edit_question['question_text'] ?? ''); ?></textarea>
                            </div>

                            <!-- Dynamic Options Container -->
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

            <!-- Preview Sidebar -->
            <div class="col-lg-4">
                <div class="preview-card">
                    <div class="question-card p-3">
                        <h5 class="mb-3">Test Overview</h5>
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
                            
                            <?php if ($total_questions > 0): ?>
                                <div class="question-navigation">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="prev">
                                        <button type="submit" class="btn btn-sm btn-outline-primary w-100 mb-2" 
                                            <?php echo ($_SESSION['current_question_index'] ?? 0) <= 0 ? 'disabled' : ''; ?>>
                                            Previous Question
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="next">
                                        <button type="submit" class="btn btn-sm btn-outline-primary w-100" 
                                            <?php echo ($_SESSION['current_question_index'] ?? 0) >= $total_questions - 1 ? 'disabled' : ''; ?>>
                                            Next Question
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3">
                                <form method="POST" action="">
                                    <button type="submit" name="clear_test" class="btn btn-outline-danger w-100">
                                        <i class="fas fa-times me-2"></i>Clear Test Selection
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="text-center">
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
                                            $option_query = "SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if (!empty($options['image_path'])) {
                                                echo '<div class="mb-3">';
                                                echo '<img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;">';
                                                echo '</div>';
                                            }   
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . ($options['correct_answer'] == $option_number ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'multiple_choice_multiple':
                                            $option_query = "SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if (!empty($options['image_path'])) {
                                                echo '<div class="mb-3">';
                                                echo '<img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;">';
                                                echo '</div>';
                                            }
                                            $correct = explode(',', $options['correct_answers']);
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . (in_array($option_number, $correct) ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'true_false':
                                            $option_query = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer']) . "</div>";
                                            break;
                                        case 'fill_blanks':
                                            $option_query = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
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

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        // Pass PHP edit data to JavaScript
        const editData = <?php echo json_encode($edit_data); ?>;

        // Initialize question type templates
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
                            <input type="text" class="form-control" name="option${i}" required 
                                   placeholder="Enter option ${i}" 
                                   value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '&quot;') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <select class="form-select" name="correct_answer" required>
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
                            <input type="text" class="form-control" name="option${i}" required 
                                   placeholder="Enter option ${i}" 
                                   value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '&quot;') : ''}">
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
                    <input type="text" class="form-control" name="correct_answer" required 
                           placeholder="Enter the correct answer" 
                           value="${editData.options?.correct_answer ? editData.options.correct_answer.replace(/"/g, '&quot;') : ''}">
                </div>
            `
        };

        // Function to update options container based on question type
        function updateOptionsContainer() {
            const questionType = document.getElementById('questionType').value;
            const optionsContainer = document.getElementById('optionsContainer');
            optionsContainer.innerHTML = questionTemplates[questionType] || '';
            
            // Initialize image upload toggle
            const toggleImageBtn = document.getElementById('toggleImageBtn');
            if (toggleImageBtn) {
                toggleImageBtn.addEventListener('click', function() {
                    const container = document.getElementById('imageUploadContainer');
                    container.style.display = container.style.display === 'none' ? 'block' : 'none';
                });
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateOptionsContainer();
            
            // Update when question type changes
            document.getElementById('questionType').addEventListener('change', updateOptionsContainer);
            
            // Class-subject mapping
            const classSubjectMapping = {
                'JSS1': <?php echo json_encode($jss_subjects); ?>,
                'JSS2': <?php echo json_encode($jss_subjects); ?>,
                'JSS3': <?php echo json_encode($jss_subjects); ?>,
                'SS1': <?php echo json_encode($ss_subjects); ?>,
                'SS2': <?php echo json_encode($ss_subjects); ?>,
                'SS3': <?php echo json_encode($ss_subjects); ?>
            };
            
            // Update subjects when class changes
            const classSelect = document.querySelector('select[name="class"]');
            const subjectSelect = document.getElementById('subjectSelect');
            
            if (classSelect && subjectSelect) {
                classSelect.addEventListener('change', function() {
                    const selectedClass = this.value;
                    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
                    
                    if (selectedClass && classSubjectMapping[selectedClass]) {
                        classSubjectMapping[selectedClass].forEach(subject => {
                            const option = document.createElement('option');
                            option.value = subject;
                            option.textContent = subject;
                            subjectSelect.appendChild(option);
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>