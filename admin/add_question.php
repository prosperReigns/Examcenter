<?php
session_start();
require_once '../db.php';

// Check if admin is logged in
if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Define available classes and subjects
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];
$jss_subjects = ['Mathematics', 'English', 'ICT', 'Agriculture', 'History', 'Civic Education', 'Basic Science', 'Basic Technology'];
$ss_subjects = ['Mathematics', 'English', 'Data Processing', 'Economics', 'Government', 'Accounting', 'Physics', 'Chemistry', 'Biology'];

// Handle test creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['test_title'])) {
    $test_title = mysqli_real_escape_string($conn, $_POST['test_title']);
    $class = mysqli_real_escape_string($conn, $_POST['selected_class']);
    $subject = mysqli_real_escape_string($conn, $_POST['selected_subject']);
    
    // Check if test already exists
    $check_sql = "SELECT id FROM tests WHERE title = '$test_title' AND class = '$class' AND subject = '$subject'";
    $check_result = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_result) > 0) {
        // Test exists, use existing test
        $test = mysqli_fetch_assoc($check_result);
        $_SESSION['current_test_id'] = $test['id'];
    } else {
        // Create new test
        $test_sql = "INSERT INTO tests (title, class, subject) VALUES ('$test_title', '$class', '$subject')";
        if (mysqli_query($conn, $test_sql)) {
            $_SESSION['current_test_id'] = mysqli_insert_id($conn);
        } else {
            $error = "Error creating test: " . mysqli_error($conn);
        }
    }
}

// Fetch all questions
$sql = "SELECT * FROM questions";
$result = mysqli_query($conn, $sql);
$questions = mysqli_fetch_all($result, MYSQLI_ASSOC);
$total_questions = count($questions);

// Get current question index from session or set to 0
if (!isset($_SESSION['current_question_index'])) {
    $_SESSION['current_question_index'] = 0;
}

// Handle navigation
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'prev' && $_SESSION['current_question_index'] > 0) {
        $_SESSION['current_question_index']--;
    } elseif ($_POST['action'] === 'next' && $_SESSION['current_question_index'] < $total_questions - 1) {
        $_SESSION['current_question_index']++;
    }
}

$current_index = $_SESSION['current_question_index'];
$current_question = $total_questions > 0 ? $questions[$current_index] : null;

// Handle question submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['question'])) {
    if (!isset($_SESSION['current_test_id'])) {
        $error = "Please create or select a test first";
    } else {
        $test_id = $_SESSION['current_test_id'];
        $question = mysqli_real_escape_string($conn, $_POST['question']);
        $option1 = mysqli_real_escape_string($conn, $_POST['option1']);
        $option2 = mysqli_real_escape_string($conn, $_POST['option2']);
        $option3 = mysqli_real_escape_string($conn, $_POST['option3']);
        $option4 = mysqli_real_escape_string($conn, $_POST['option4']);
        $correct_answer = mysqli_real_escape_string($conn, $_POST['correct_answer']);
        
        $test_query = "SELECT class, subject FROM tests WHERE id = $test_id";
        $test_result = mysqli_query($conn, $test_query);
        $test_data = mysqli_fetch_assoc($test_result);
        $class = $test_data['class'];
        $subject = $test_data['subject'];

        
        $sql = "INSERT INTO questions (question_text, option1, option2, option3, option4, correct_answer, test_id, class, subject) 
                VALUES ('$question', '$option1', '$option2', '$option3', '$option4', '$correct_answer', $test_id, '$class', '$subject')";
        
        if (mysqli_query($conn, $sql)) {
            $success = "Question added successfully!";
        } else {
            $error = "Error adding question: " . mysqli_error($conn);
        }
    }
}

// Get current test info if exists
$current_test = null;
$questions = [];
if (isset($_SESSION['current_test_id'])) {
    $test_id = $_SESSION['current_test_id'];
    $test_query = "SELECT * FROM tests WHERE id = $test_id";
    $test_result = mysqli_query($conn, $test_query);
    $current_test = mysqli_fetch_assoc($test_result);
    
    // Fetch all questions for this test
    $questions_query = "SELECT * FROM questions WHERE test_id = $test_id ORDER BY id ASC";
    $questions_result = mysqli_query($conn, $questions_query);
    $questions = mysqli_fetch_all($questions_result, MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/add_question.css" rel="stylesheet">
</head>
<body>
    <!-- navigation bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
    <button class="btn btn-primary btn-sm toggle-sidebar" id="sidebarToggle">
                    <i class="bi bi-list"></i>☰
                </button>
        <a class="navbar-brand" href="dashboard.php">CBT Admin</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="dashboard.php">Dashboard</a>
            <a class="nav-link" href="logout.php">Logout</a>
        </div>
    </div>
</nav>

<!-- sidebar -->
<div class="container-fluid">
    <div class="row">
        <div class="sidebar col-md-3 col-lg-2" id="sidebar">
                <h5 class="mb-0">Create Exam</h5>
            <div class="p-3">
                <form method="POST" id="testForm">
                    <div class="mb-3">
                        <label class="form-label">Test Title</label>
                        <select class="form-select" name="test_title" required>
                            <option value="">Select Test Title</option>
                            <option value="First Term Exam" <?php echo ($current_test && $current_test['title'] == 'First Term Exam') ? 'selected' : ''; ?>>First Term Exam</option>
                            <option value="First Term Mid Exam" <?php echo ($current_test && $current_test['title'] == 'First Term Mid Exam') ? 'selected' : ''; ?>>First Term Mid Exam</option>
                            <option value="Second Term Exam" <?php echo ($current_test && $current_test['title'] == 'Second Term Exam') ? 'selected' : ''; ?>>Second Term Exam</option>
                            <option value="Second Term Mid Exam" <?php echo ($current_test && $current_test['title'] == 'Second Term Mid Exam') ? 'selected' : ''; ?>>Second Term Mid Exam</option>
                            <option value="Third Term Exam" <?php echo ($current_test && $current_test['title'] == 'Third Term Exam') ? 'selected' : ''; ?>>Third Term Exam</option>
                            <option value="Third Term Mid Exam" <?php echo ($current_test && $current_test['title'] == 'Third Term Mid Exam') ? 'selected' : ''; ?>>Third Term Mid Exam</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Class</label>
                        <select class="form-select" name="selected_class" required>
                            <option value="">Select Class</option>
                            <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class; ?>" 
                                        <?php echo ($current_test && $current_test['class'] == $class) ? 'selected' : ''; ?>>
                                    <?php echo $class; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <select class="form-select" name="selected_subject" required>
                            <option value="">Select Subject</option>
                            <?php 
                            // Determine which subject list to use based on class
                            $current_class = $current_test ? $current_test['class'] : '';
                            $available_subjects = [];
                            
                            if (strpos($current_class, 'JSS') === 0) {
                                $available_subjects = $jss_subjects;
                            } elseif (strpos($current_class, 'SS') === 0) {
                                $available_subjects = $ss_subjects;
                            }
                            
                            foreach($available_subjects as $subject): ?>
                                <option value="<?php echo $subject; ?>" 
                                        <?php echo ($current_test && $current_test['subject'] == $subject) ? 'selected' : ''; ?>>
                                    <?php echo $subject; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Test</button>
                </form>
            </div>
            </div>
        </div>

        <!-- Question Form Section -->
        <div class="main-content col-md-9 col-lg-10 p-3">
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>

            
                <!-- question header -->
            <div id="questionForm" class="container">
                <?php if($current_test): ?>
                    <div class="alert alert-info">
                        <strong>Current Test:</strong> <?php echo htmlspecialchars($current_test['title']); ?> |
                        <strong>Class:</strong> <?php echo htmlspecialchars($current_test['class']); ?> |
                        <strong>Subject:</strong> <?php echo htmlspecialchars($current_test['subject']); ?>
                    </div>
                    
                    <!-- preview questions -->
                    <?php if($current_test && !empty($questions)): ?>
                        <div class="question-preview">
                <h6 class="mb-3">Question Preview</h6>
                <div id="questionPreview">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="question-count">
                            Question <span id="currentQuestionNum">1</span> of <span id="totalQuestions"><?php echo count($questions); ?></span>
                        </div>
                        <div class="question-actions">
                            <button class="btn btn-warning btn-sm me-2" onclick="editQuestion()">Edit</button>
                            <button class="btn btn-danger btn-sm" onclick="deleteQuestion()">Delete</button>
                        </div>
                    </div>
                    <p class="preview-text"></p>
                    <div class="options"></div>
                    <div class="preview-nav">
                        <button class="btn btn-secondary" onclick="previousQuestion()">Previous</button>
                        <button class="btn btn-secondary" onclick="nextQuestion()">Next</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

                <div class="container-lg">
                    <h3 class="mb-3">Add New Question</h3>
                    <div class="mb-3">
                        <label class="form-label">Question Type</label>
                        <select class="form-select" name="question_type" id="questionType" required>
                            <option value="multiple_choice_single">Multiple Choice (Single Answer)</option>
                            <option value="multiple_choice_multiple">Multiple Choice (Multiple Answers)</option>
                            <option value="true_false">True or False</option>
                            <option value="short_answer">Short Answer</option>
                            <option value="long_answer">Long Answer / Essay</option>
                            <option value="fill_blanks">Fill in the Blanks</option>
                        </select>
                    </div>
                    <form method="POST" id="questionForm">
                        <div class="mb-3">
                            <label class="form-label">Question Text</label>
                            <textarea class="form-control" name="question" rows="3" required></textarea>
                        </div>
                        
                        <!-- Multiple Choice Options (Single/Multiple) -->
                        <div id="multipleChoiceOptions">
                            <div class="mb-3">
                                <label class="form-label">Option 1</label>
                                <input type="text" class="form-control" name="option1">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Option 2</label>
                                <input type="text" class="form-control" name="option2">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Option 3</label>
                                <input type="text" class="form-control" name="option3">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Option 4</label>
                                <input type="text" class="form-control" name="option4">
                            </div>
                            <div class="mb-3" id="singleAnswerSelect">
                                <label class="form-label">Correct Answer (1-4)</label>
                                <select class="form-select" name="correct_answer">
                                    <option value="">Select correct answer</option>
                                    <option value="1">Option 1</option>
                                    <option value="2">Option 2</option>
                                    <option value="3">Option 3</option>
                                    <option value="4">Option 4</option>
                                </select>
                            </div>
                            <div class="mb-3" id="multipleAnswerSelect" style="display: none;">
                                <label class="form-label">Correct Answers</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="correct_answers[]" value="1">
                                    <label class="form-check-label">Option 1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="correct_answers[]" value="2">
                                    <label class="form-check-label">Option 2</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="correct_answers[]" value="3">
                                    <label class="form-check-label">Option 3</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="correct_answers[]" value="4">
                                    <label class="form-check-label">Option 4</label>
                                </div>
                            </div>
                        </div>

                        <!-- True/False Options -->
                        <div id="trueFalseOptions" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Correct Answer</label>
                                <select class="form-select" name="true_false_answer">
                                    <option value="">Select correct answer</option>
                                    <option value="true">True</option>
                                    <option value="false">False</option>
                                </select>
                            </div>
                        </div>

                        <!-- Short Answer Options -->
                        <div id="shortAnswerOptions" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Correct Answer</label>
                                <input type="text" class="form-control" name="short_answer">
                            </div>
                        </div>

                        <!-- Long Answer Options -->
                        <div id="longAnswerOptions" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Answer Guidelines (Optional)</label>
                                <textarea class="form-control" name="answer_guidelines" rows="3"></textarea>
                            </div>
                        </div>

                        <!-- Fill in the Blanks Options -->
                        <div id="fillBlanksOptions" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Correct Answer</label>
                                <input type="text" class="form-control" name="fill_blank_answer">
                                <small class="form-text text-muted">Use underscore (_) to indicate blank in the question text</small>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success">Add Question</button>
                    </form>
                </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        Please create or select a test first to add questions.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    window.jssSubjects = <?php echo json_encode($jss_subjects); ?>;
    window.ssSubjects = <?php echo json_encode($ss_subjects); ?>;
    window.questions = <?php echo json_encode($questions); ?>;
</script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="../js/questionType.js"></script>
<script src="../js/deleteQuestion.js"></script>
<script src="../js/editQuestion.js"></script>
<script src="../js/sidebar.js"></script>
<script src="../js/updateSubjects.js"></script>
<script src="../js/previewQuestions.js"></script>

</body>
</html>