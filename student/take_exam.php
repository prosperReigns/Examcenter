<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Fetch questions for specific test title, class and subject
$class = mysqli_real_escape_string($conn, $_SESSION['student_class']);
$subject = mysqli_real_escape_string($conn, $_SESSION['student_subject']);
$test_title = mysqli_real_escape_string($conn, $_SESSION['test_title']);

// Get the specific test based on title, class and subject
$test_query = "SELECT id FROM tests WHERE title = '$test_title' AND class = '$class' AND subject = '$subject'";
$test_result = mysqli_query($conn, $test_query);
$test = mysqli_fetch_assoc($test_result);

if (!$test) {
    die("No test available for this combination of test title, class and subject.");
}

$test_id = $test['id'];
$_SESSION['current_test_id'] = $test_id; // Store test_id in session

// Fetch questions for this specific test
$sql = "SELECT * FROM questions WHERE test_id = $test_id ORDER BY RAND()";
$result = mysqli_query($conn, $sql);
$questions = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Store questions in session for validation later
$_SESSION['exam_questions'] = $questions;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Exam - CBT Application</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/calculator.css" rel="stylesheet">
    <link href="../css/takeExam.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?></h2>
        <div class="alert alert-info">
            <strong>Test:</strong> <?php echo htmlspecialchars($_SESSION['test_title']); ?> |
            <strong>Class:</strong> <?php echo htmlspecialchars($_SESSION['student_class']); ?> |
            <strong>Subject:</strong> <?php echo htmlspecialchars($_SESSION['student_subject']); ?>
        </div>
        
        <!-- calculator toggle button -->
        <div class="calculator-btn" onclick="openCalculator()">
        <i class="bi bi-calculator"></i>
        🔢
        </div>
        <?php if(empty($questions)): ?>
            <div class="alert alert-warning">
                No questions available for this test. Please contact your administrator.
            </div>
        <?php else: ?>
            <div class="question-progress">
                Question <span id="currentQuestionNum">1</span> of <?php echo count($questions); ?>
            </div>

<!-- Calculator Modal -->
<div class="modal fade" id="calculatorModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Calculator</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="calculator">
                    <input type="text" class="calculator-display" id="display" readonly>
                    <div class="calculator-grid">
                        <button class="calculator-btn-grid" onclick="clearDisplay()">C</button>
                        <button class="calculator-btn-grid" onclick="backspace()">⌫</button>
                        <button class="calculator-btn-grid operator" onclick="appendOperator('%')">%</button>
                        <button class="calculator-btn-grid operator" onclick="appendOperator('/')">/</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('7')">7</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('8')">8</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('9')">9</button>
                        <button class="calculator-btn-grid operator" onclick="appendOperator('*')">×</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('4')">4</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('5')">5</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('6')">6</button>
                        <button class="calculator-btn-grid operator" onclick="appendOperator('-')">-</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('1')">1</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('2')">2</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('3')">3</button>
                        <button class="calculator-btn-grid operator" onclick="appendOperator('+')">+</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('0')">0</button>
                        <button class="calculator-btn-grid" onclick="appendNumber('.')">.</button>
                        <button class="calculator-btn-grid equals" onclick="calculate()" style="grid-column: span 2">=</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

            <!-- question and answer section -->
            <form method="POST" action="submit_exam.php" id="examForm">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-container <?php echo $index === 0 ? 'active' : ''; ?>" data-question="<?php echo $index; ?>">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Question <?php echo $index + 1; ?></h5>
                                <p class="card-text"><?php echo htmlspecialchars($question['question_text']); ?></p>
                                
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $question['id']; ?>]" value="1" required>
                                    <label class="form-check-label"><?php echo htmlspecialchars($question['option1']); ?></label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $question['id']; ?>]" value="2">
                                    <label class="form-check-label"><?php echo htmlspecialchars($question['option2']); ?></label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $question['id']; ?>]" value="3">
                                    <label class="form-check-label"><?php echo htmlspecialchars($question['option3']); ?></label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $question['id']; ?>]" value="4">
                                    <label class="form-check-label"><?php echo htmlspecialchars($question['option4']); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="navigation-buttons">
                    <button type="button" class="btn btn-secondary" onclick="previousQuestion()">Previous</button>
                    <button type="button" class="btn btn-primary" onclick="nextQuestion()">Next</button>
                    <button type="submit" class="btn btn-success" id="submitBtn" style="display: none;">Submit Exam</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Add progress boxes -->
    <div class="progress-boxes" id="progressBoxes">
                <?php for($i = 0; $i < count($questions); $i++): ?>
                    <div class="question-box" data-index="<?php echo $i; ?>" onclick="jumpToQuestion(<?php echo $i; ?>)">
                        <?php echo $i + 1; ?>
                    </div>
                <?php endfor; ?>
            </div>
    
        <script>
       window.totalQuestions = <?php echo count($questions); ?>;
    </script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/takeExam.js"></script>
    <script src="../js/calculator.js"></script>
</body>
</html>