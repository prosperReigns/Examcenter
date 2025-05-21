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
// Add this near the top of your PHP code
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

// Handle test creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_test'])) {
    $title = $conn->real_escape_string($_POST['test_title'] ?? '');
    $class = $conn->real_escape_string($_POST['class'] ?? '');
    $subject = $conn->real_escape_string($_POST['subject'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);

    // Validate subject-class relationship
    if (!is_valid_subject($class, $subject)) {
        $error = "Invalid subject for selected class!";
        error_log("Invalid subject attempt: {$subject} for {$class}");
    } elseif ($title && $class && $subject && $duration > 0) {
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
        // Delete from type-specific table
        $table_map = [
            'multiple_choice_single' => 'single_choice_questions',
            'multiple_choice_multiple' => 'multiple_choice_questions',
            'true_false' => 'true_false_questions',
            'fill_blanks' => 'fill_blank_questions'
        ];
        
        $table = $table_map[$question_type] ?? '';
        if ($table) {
            $sql = "DELETE FROM $table WHERE question_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Delete from main questions table
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
        
        // Fetch type-specific data
        switch ($edit_question['question_type']) {
            case 'multiple_choice_single':
                $sql = "SELECT * FROM single_choice_questions WHERE question_id = ?";
                break;
            case 'multiple_choice_multiple':
                $sql = "SELECT * FROM multiple_choice_questions WHERE question_id = ?";
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

// Handle question submission (new or edited)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['question'])) {
    if (!isset($_SESSION['current_test_id'])) {
        $error = "Please create or select a test first";
    } else {
        $test_id = $_SESSION['current_test_id'];
        $question_id = intval($_POST['question_id'] ?? 0); // For editing
        $question = $conn->real_escape_string($_POST['question']);
        $question_type = $conn->real_escape_string($_POST['question_type'] ?? '');

        // Fetch test details
        $test_query = "SELECT class, subject FROM tests WHERE id = ?";
        $stmt = $conn->prepare($test_query);
        $stmt->bind_param("i", $test_id);
        $stmt->execute();
        $test_result = $stmt->get_result();
        $test_data = $test_result->fetch_assoc();
        $stmt->close();
        $class = $test_data['class'];
        $subject = $test_data['subject'];

        // Insert or update main questions table
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
            $success = false;
            
            // Delete existing type-specific data if editing
            if ($question_id && $_POST['question_id']) {
                $table_map = [
                    'multiple_choice_single' => 'single_choice_questions',
                    'multiple_choice_multiple' => 'multiple_choice_questions',
                    'true_false' => 'true_false_questions',
                    'fill_blanks' => 'fill_blank_questions'
                ];
                $table = $table_map[$question_type] ?? '';
                if ($table) {
                    $sql = "DELETE FROM $table WHERE question_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $question_id);
                    $stmt->execute();
                }
            }
            
            // Handle question type-specific data
            switch ($question_type) {
                case 'multiple_choice_single':
                    $option1 = $conn->real_escape_string($_POST['option1'] ?? '');
                    $option2 = $conn->real_escape_string($_POST['option2'] ?? '');
                    $option3 = $conn->real_escape_string($_POST['option3'] ?? '');
                    $option4 = $conn->real_escape_string($_POST['option4'] ?? '');
                    $correct_answer = $conn->real_escape_string($_POST['correct_answer'] ?? '');
                    
                    if ($option1 && $option2 && $option3 && $option4 && $correct_answer) {
                        $sql = "INSERT INTO single_choice_questions (question_id, option1, option2, option3, option4, correct_answer) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("isssss", $question_id, $option1, $option2, $option3, $option4, $correct_answer);
                        $success = $stmt->execute();
                    } else {
                        $error = "All options and correct answer are required for single choice questions";
                    }
                    break;
                    
                case 'multiple_choice_multiple':
                    $option1 = $conn->real_escape_string($_POST['option1'] ?? '');
                    $option2 = $conn->real_escape_string($_POST['option2'] ?? '');
                    $option3 = $conn->real_escape_string($_POST['option3'] ?? '');
                    $option4 = $conn->real_escape_string($_POST['option4'] ?? '');
                    $correct_answers = isset($_POST['correct_answers']) ? implode(',', array_map('intval', $_POST['correct_answers'])) : '';
                    $correct_answers = $conn->real_escape_string($correct_answers);
                    
                    if ($option1 && $option2 && $option3 && $option4 && $correct_answers) {
                        $sql = "INSERT INTO multiple_choice_questions (question_id, option1, option2, option3, option4, correct_answers) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("isssss", $question_id, $option1, $option2, $option3, $option4, $correct_answers);
                        $success = $stmt->execute();
                    } else {
                        $error = "All options and at least one correct answer are required for multiple choice questions";
                    }
                    break;
                    
                case 'true_false':
                    $correct_answer = $conn->real_escape_string($_POST['correct_answer'] ?? '');
                    
                    if ($correct_answer) {
                        $sql = "INSERT INTO true_false_questions (question_id, correct_answer) 
                                VALUES (?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("is", $question_id, $correct_answer);
                        $success = $stmt->execute();
                    } else {
                        $error = "Correct answer is required for true/false questions";
                    }
                    break;
                    
                case 'fill_blanks':
                    $correct_answer = $conn->real_escape_string($_POST['correct_answer'] ?? '');
                    
                    if ($correct_answer) {
                        $sql = "INSERT INTO fill_blank_questions (question_id, correct_answer) 
                                VALUES (?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("is", $question_id, $correct_answer);
                        $success = $stmt->execute();
                    } else {
                        $error = "Correct answer is required for fill-in-the-blank questions";
                    }
                    break;
            }
            
            if ($success) {
                $success = $question_id ? "Question updated successfully!" : "Question added successfully!";
            } else {
                $error = $error ?: "Error adding question details: " . $conn->error;
                if (!$question_id) {
                    $conn->query("DELETE FROM new_questions WHERE id = $question_id");
                }
            }
            $stmt->close();
        } else {
            $error = "Error saving question: " . $conn->error;
        }
    }
}

// Fetch current test and questions
if (isset($_SESSION['current_test_id'])) {
    $test_id = $_SESSION['current_test_id'];
    
    // Fetch test details
    $test_query = "SELECT * FROM tests WHERE id = ?";
    $stmt = $conn->prepare($test_query);
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $test_result = $stmt->get_result();
    $current_test = $test_result->fetch_assoc();
    $stmt->close();
    
    // Fetch questions
    $questions_query = "SELECT * FROM new_questions WHERE test_id = ? ORDER BY id ASC";
    $stmt = $conn->prepare($questions_query);
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $questions_result = $stmt->get_result();
    
    if ($questions_result) {
        $questions = $questions_result->fetch_all(MYSQLI_ASSOC);
        $total_questions = count($questions);
    } else {
        $error = "Failed to fetch questions: " . $conn->error;
    }
    $stmt->close();
}

// Prepare edit data for JavaScript
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --accent: #4cc9f0;
            --light-bg: #f8f9fa;
        }

        .gradient-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem;
            border-radius: 0 0 30px 30px;
            margin-bottom: 2rem;
        }

        .question-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            padding: 2rem;
        }

        .question-card:hover {
            transform: translateY(-5px);
        }

        .option-highlight {
            border-left: 4px solid var(--accent);
            background: var(--light-bg);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
        }

        .preview-card {
            position: sticky;
            top: 20px;
            max-height: 80vh;
            overflow-y: auto;
            padding: 1rem;
        }

        .type-indicator {
            position: absolute;
            top: -10px;
            right: -10px;
            background: var(--primary);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8em;
            box-shadow: 0 5px 15px rgba(67,97,238,0.3);
        }

        .floating-action {
            position: fixed;
            bottom: 30px;
            right: 30px;
            box-shadow: 0 10px 30px rgba(67,97,238,0.3);
            border-radius: 50%;
        }

        .form-group-spacing {
            margin-bottom: 1.5rem;
        }

        .error-message {
            color: #dc3545;
            font-size: 0.9em;
            margin-top: 0.5rem;
        }

        .success-message {
            color: #28a745;
            font-size: 0.9em;
            margin-top: 0.5rem;
        }

        .modal-preview .modal-body {
            max-height: 60vh;
            overflow-y: auto;
        }

        .action-buttons .btn {
            margin-left: 0.5rem;
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

                    <!-- Test Creation/Selection Form (shown only if no test is selected) -->
                    <?php if (!$current_test): ?>
                        <h5 class="mb-3">Test Setup</h5>
                        <div class="card mb-4">
                            <div class="card-body">
                                <h6>Create New Test</h6>
                                <form method="POST">
                                    <div class="row g-3">
                                        <div class="col-md-4 form-group-spacing">
                                            <label class="form-label fw-bold">Test Title</label>
                                            <input type="text" class="form-control" name="test_title" required placeholder="e.g., Midterm Exam">
                                        </div>
                                        <div class="col-md-3 form-group-spacing">
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
                                    <!-- Replace the existing subject dropdown with this -->
<div class="col-md-3 form-group-spacing">
    <label class="form-label fw-bold">Subject</label>
    <select class="form-select" name="subject" required id="subjectSelect">
        <option value="">Select Subject</option>
        <!-- Options populated by JavaScript -->
    </select>
</div>
                                        <div class="col-md-2 form-group-spacing">
                                            <label class="form-label fw-bold">Duration (min)</label>
                                            <input type="number" class="form-control" name="duration" required placeholder="e.g., 30" min="1">
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
                        <form method="POST" id="questionForm">
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
                                            $option_query = "SELECT option1, option2, option3, option4, correct_answer FROM single_choice_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . ($options['correct_answer'] == $option_number ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'multiple_choice_multiple':
                                            $option_query = "SELECT option1, option2, option3, option4, correct_answers FROM multiple_choice_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
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

    // Add this script before closing </body>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.querySelector('select[name="class"]');
    const subjectSelect = document.querySelector('#subjectSelect');
    
    // Define subjects from PHP
    const subjects = {
        jss: <?php echo json_encode(array_map('strtolower', $jss_subjects)); ?>,
        ss: <?php echo json_encode(array_map('strtolower', $ss_subjects)); ?>
    };

    function updateSubjects() {
        const selectedClass = classSelect.value.toLowerCase();
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        
        // Determine subject category
        let category = '';
        if (selectedClass.startsWith('jss')) category = 'jss';
        else if (selectedClass.startsWith('ss')) category = 'ss';
        
        if (category && subjects[category]) {
            subjects[category].forEach(subject => {
                const option = document.createElement('option');
                option.value = subject;
                option.textContent = subject.charAt(0).toUpperCase() + subject.slice(1);
                subjectSelect.appendChild(option);
            });
        }
    }

    // Initial population and event listener
    classSelect.addEventListener('change', updateSubjects);
    updateSubjects();
});
</script>
    <script>
        // Pass PHP edit data to JavaScript
        const editData = <?php echo json_encode($edit_data); ?>;

        // Initialize question type templates
        const questionTemplates = {
            multiple_choice_single: `
                <div class="option-group">
                    ${[1,2,3,4].map(i => `
                        <div class="mb-3 option-highlight">
                            <label class="form-label">Option ${i}</label>
                            <input type="text" class="form-control" name="option${i}" required placeholder="Enter option ${i}" 
                                value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '&quot;') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <select class="form-select" name="correct_answer" required>
                            <option value="">Select Correct Answer</option>
                            ${[1,2,3,4].map(i => `
                                <option value="${i}" ${editData.options.correct_answer && parseInt(editData.options.correct_answer) === i ? 'selected' : ''}>Option ${i}</option>
                            `).join('')}
                        </select>
                    </div>
                </div>
            `,
            multiple_choice_multiple: `
                <div class="option-group">
                    ${[1,2,3,4].map(i => `
                        <div class="mb-3 option-highlight">
                            <label class="form-label">Option ${i}</label>
                            <input type="text" class="form-control" name="option${i}" required placeholder="Enter option ${i}" 
                                value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '&quot;') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answers (select all that apply)</label>
                        ${[1,2,3,4].map(i => `
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="correct_answers[]" value="${i}" id="correct${i}" 
                                    ${editData.correct_answers.includes(i) ? 'checked' : ''}>
                                <label class="form-check-label" for="correct${i}">Option ${i}</label>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `,
            true_false: `
                <div class="option-group">
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <select class="form-select" name="correct_answer" required>
                            <option value="">Select Correct Answer</option>
                            <option value="True" ${editData.options.correct_answer === 'True' ? 'selected' : ''}>True</option>
                            <option value="False" ${editData.options.correct_answer === 'False' ? 'selected' : ''}>False</option>
                        </select>
                    </div>
                </div>
            `,
            fill_blanks: `
                <div class="option-group">
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <input type="text" class="form-control" name="correct_answer" required placeholder="Enter the correct answer" 
                            value="${editData.options.correct_answer ? editData.options.correct_answer.replace(/"/g, '&quot;') : ''}">
                    </div>
                </div>
            `
        };

        // Initialize options on page load
        const questionTypeSelect = document.getElementById('questionType');
        const optionsContainer = document.getElementById('optionsContainer');
        if (questionTypeSelect && optionsContainer) {
            optionsContainer.innerHTML = questionTemplates[questionTypeSelect.value];

            // Update options when question type changes
            questionTypeSelect.addEventListener('change', function() {
                optionsContainer.innerHTML = questionTemplates[this.value];
            });
        }

        // Client-side form validation
        document.getElementById('questionForm')?.addEventListener('submit', function(e) {
            const questionType = questionTypeSelect.value;
            let isValid = true;
            const errorContainer = document.createElement('div');
            errorContainer.className = 'error-message';

            if (questionType === 'multiple_choice_multiple') {
                const checkboxes = document.querySelectorAll('input[name="correct_answers[]"]:checked');
                if (checkboxes.length === 0) {
                    isValid = false;
                    errorContainer.textContent = 'At least one correct answer must be selected for multiple choice questions.';
                }
            }

            if (!isValid) {
                e.preventDefault();
                optionsContainer.appendChild(errorContainer);
            }
        });
    </script>
</body>
</html>