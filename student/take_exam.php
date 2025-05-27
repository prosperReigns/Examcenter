<?php
session_start();
require_once '../db.php';

// Show errors (for development)
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['student_id'])) {
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

$class = mysqli_real_escape_string($conn, $_SESSION['student_class']);
$subject = mysqli_real_escape_string($conn, $_SESSION['student_subject']);
$test_title = mysqli_real_escape_string($conn, $_SESSION['test_title']);

$test_query = "SELECT id FROM tests WHERE title = '$test_title' AND class = '$class' AND subject = '$subject'";
$test_result = mysqli_query($conn, $test_query);
$test = mysqli_fetch_assoc($test_result);

if (!$test) {
    die("No test available for this combination of test title, class, and subject.");
}

$test_id = $test['id'];
$_SESSION['current_test_id'] = $test_id;

$sql = "SELECT * FROM new_questions WHERE test_id = $test_id ORDER BY id ASC";
$questions_result = mysqli_query($conn, $sql);

$questions = [];
while ($row = mysqli_fetch_assoc($questions_result)) {
    $question_id = $row['id'];
    $type = $row['question_type'];

    switch ($type) {
        case 'single_choice':
            $detail_query = "SELECT option1, option2, option3, option4 FROM single_choice_questions WHERE question_id = $question_id";
            break;
        case 'multiple_choice':
            $detail_query = "SELECT option1, option2, option3, option4 FROM multiple_choice_questions WHERE question_id = $question_id";
            break;
        case 'true_false':
            $detail_query = "SELECT correct_answer FROM true_false_questions WHERE question_id = $question_id";
            break;
        case 'fill_blank':
            $detail_query = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = $question_id";
            break;
        default:
            $detail_query = null;
    }

    if ($detail_query) {
        $detail_result = mysqli_query($conn, $detail_query);
        $detail = mysqli_fetch_assoc($detail_result);
        $questions[] = array_merge($row, $detail ?? []);
    } else {
        $questions[] = $row;
    }
}

$_SESSION['exam_questions'] = $questions;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Take Exam</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/calculator.css" rel="stylesheet">
    <style>
        .question-container { display: none; }
        .question-container.active { display: block; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?></h2>
    <div class="alert alert-info">
        <strong>Test:</strong> <?php echo htmlspecialchars($_SESSION['test_title']); ?> |
        <strong>Class:</strong> <?php echo htmlspecialchars($_SESSION['student_class']); ?> |
        <strong>Subject:</strong> <?php echo htmlspecialchars($_SESSION['student_subject']); ?>
    </div>

    <!-- Calculator Button -->
    <div class="calculator-btn mb-3" onclick="openCalculator()">
        <i class="bi bi-calculator"></i> 🔢 Calculator
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
                    <input type="text" id="display" readonly class="form-control mb-2">
                    <div class="d-grid gap-2">
                        <button onclick="appendNumber('1')">1</button>
                        <button onclick="appendNumber('2')">2</button>
                        <button onclick="appendNumber('+')">+</button>
                        <button onclick="calculate()">=</button>
                        <button onclick="clearDisplay()">C</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($questions)): ?>
        <div class="alert alert-warning">No questions available for this test.</div>
    <?php else: ?>
        <form method="POST" action="submit_exam.php" id="examForm">
            <?php foreach ($questions as $index => $question): ?>
                <div class="question-container <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5>Question <?php echo $index + 1; ?></h5>
                            <p><?php echo htmlspecialchars($question['question_text']); ?></p>

                            <?php if ($question['question_type'] === 'single_choice'): ?>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <?php if (!empty($question["option$i"])): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio"
                                                name="answers[<?php echo $question['id']; ?>]"
                                                value="<?php echo $i; ?>" required>
                                            <label class="form-check-label"><?php echo htmlspecialchars($question["option$i"]); ?></label>
                                        </div>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" onclick="previousQuestion()">Previous</button>
                <button type="button" class="btn btn-primary" onclick="nextQuestion()">Next</button>
                <button type="submit" class="btn btn-success" id="submitBtn" style="display: none;">Submit Exam</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
<script>
    let current = 0;
    const total = <?php echo count($questions); ?>;
    const containers = document.querySelectorAll('.question-container');

    function showQuestion(index) {
        containers.forEach(c => c.classList.remove('active'));
        containers[index].classList.add('active');
        document.getElementById('submitBtn').style.display = index === total - 1 ? 'inline-block' : 'none';
    }

    function nextQuestion() {
        if (current < total - 1) {
            current++;
            showQuestion(current);
        }
    }

    function previousQuestion() {
        if (current > 0) {
            current--;
            showQuestion(current);
        }
    }

    // Calculator
    function openCalculator() {
        const modal = new bootstrap.Modal(document.getElementById('calculatorModal'));
        modal.show();
    }

    function appendNumber(val) {
        document.getElementById('display').value += val;
    }

    function calculate() {
        const display = document.getElementById('display');
        try {
            display.value = eval(display.value);
        } catch {
            display.value = "Error";
        }
    }

    function clearDisplay() {
        document.getElementById('display').value = '';
    }
</script>
</body>
</html>
