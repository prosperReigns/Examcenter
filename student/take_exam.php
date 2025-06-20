<?php
session_start();
require_once '../db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check if student is logged in
if (!isset($_SESSION['student_id'], $_SESSION['student_class'], $_SESSION['student_subject'], $_SESSION['test_title'], $_SESSION['student_name'])) {
    error_log("Session missing: Redirecting to register.php");
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed: " . $conn->connect_error);
}

// Sanitize session inputs
$user_id = (int)$_SESSION['student_id'];
$class = $_SESSION['student_class'];
$subject = $_SESSION['student_subject'];
$test_title = $_SESSION['test_title'];

// Debug session variables
error_log("Session: user_id=$user_id, class=$class, subject=$subject, title=$test_title");

// Get test details including duration
$stmt = $conn->prepare("SELECT id, duration FROM tests WHERE title = ? AND class = ? AND subject = ?");
if ($stmt === false) {
    error_log("Prepare failed: SELECT id, duration FROM tests - " . $conn->error);
    die("Error preparing test query: " . $conn->error);
}
$stmt->bind_param("sss", $test_title, $class, $subject);
$stmt->execute();
$test_result = $stmt->get_result();
$test = $test_result->fetch_assoc();
$stmt->close();

if (!$test) {
    error_log("No test found for title='$test_title', class='$class', subject='$subject'");
    die("No test available for this combination of test title, class, and subject.");
}

$test_id = $test['id'];
$_SESSION['current_test_id'] = $test_id;
$exam_duration = isset($test['duration']) ? (int)$test['duration'] * 60 : 3600; // Convert minutes to seconds, default 60 minutes

// Check for duplicate exam attempt
$stmt = $conn->prepare("SELECT id, reattempt_approved FROM results WHERE user_id = ? AND test_id = ?");
if ($stmt === false) {
    error_log("Prepare failed: SELECT id, reattempt_approved FROM results - " . $conn->error);
    die("Error preparing attempt check: " . $conn->error);
}
$stmt->bind_param("ii", $user_id, $test_id);
$stmt->execute();
$attempt_result = $stmt->get_result();
$attempt = $attempt_result->fetch_assoc();
$stmt->close();

if ($attempt && !$attempt['reattempt_approved']) {
    die("You have already taken this exam. Contact your administrator to retake it.");
}

// Get questions for the test
$stmt = $conn->prepare("SELECT * FROM new_questions WHERE test_id = ? ORDER BY RAND()");
if ($stmt === false) {
    error_log("Prepare failed: SELECT * FROM new_questions - " . $conn->error);
    die("Error preparing questions query: " . $conn->error);
}
$stmt->bind_param("i", $test_id);
$stmt->execute();
$questions_result = $stmt->get_result();

$questions = [];
$base_url = 'http://localhost/Examcenter'; // Adjust to your server URL
while ($row = $questions_result->fetch_assoc()) {
    $question_id = $row['id'];
    $type = $row['question_type'];

    // Initialize image handling
    $image_html = '';

    $detail_query = null;
    switch ($type) {
        case 'multiple_choice_sing':
            $detail_query = "SELECT option1, option2, option3, option4, image_path FROM single_choice_questions WHERE question_id = ?";
            break;
        case 'multiple_choice_mult':
            $detail_query = "SELECT option1, option2, option3, option4 FROM multiple_choice_questions WHERE question_id = ?";
            break;
        case 'true_false':
            $detail_query = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
            break;
        case 'fill_blank':
            $detail_query = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
            break;
    }

    $detail = [];
    if ($detail_query) {
        $detail_stmt = $conn->prepare($detail_query);
        if ($detail_stmt === false) {
            error_log("Detail query prepare failed: " . $detail_query . " - " . $conn->error);
            continue;
        }
        $detail_stmt->bind_param("i", $question_id);
        $detail_stmt->execute();
        $detail_result = $detail_stmt->get_result();
        $detail = $detail_result->fetch_assoc();
        $detail_stmt->close();

        // Handle image for single_choice
        if ($type === 'multiple_choice_sing' && !empty($detail['image_path'])) {
            $image_path = $base_url . '/' . ltrim($detail['image_path'], '/');
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($detail['image_path'], '/'))) {
                $image_html = "<div class='question-image mb-3'><img src='$image_path' class='img-fluid' alt='Question Image' onerror='this.src=\"/images/fallback.jpg\"; this.alt=\"Image not found\"'></div>";
            } else {
                error_log("Image not found: " . $detail['image_path']);
                $image_html = "<div class='question-image mb-3'><img src='/images/fallback.jpg' class='img-fluid' alt='Image not found'></div>";
            }
        }
    }

    $questions[] = array_merge($row, $detail ?? [], ['image_html' => $image_html]);
}
$stmt->close();

if (empty($questions)) {
    error_log("No questions found for test_id=$test_id");
    die("No questions available for this test.");
}

$_SESSION['exam_questions'] = $questions;

// Fetch show_results_immediately setting
$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_name = ?");
if ($stmt === false) {
    error_log("Prepare failed: SELECT setting_value FROM settings - " . $conn->error);
    die("Error preparing settings query: " . $conn->error);
}
$setting_name = 'show_results_immediately';
$stmt->bind_param("s", $setting_name);
$stmt->execute();
$result = $stmt->get_result();
$show_results = $result->fetch_assoc()['setting_value'] ?? 0;
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Exam - D-Portal</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --warning: #ffc107;
            --danger: #dc3545;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
            color: #212529;
        }

        .exam-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .exam-container {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .question-card {
            border-left: 4px solid var(--primary);
            border-radius: 8px;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }

        .question-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .question-image img {
            max-height: 300px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }

        .question-nav {
            position: sticky;
            top: 20px;
        }

        .question-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(40px, 1fr));
            gap: 10px;
            margin-top: 1rem;
        }

        .question-box {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            background-color: #e9ecef;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }

        .question-box:hover {
            background-color: #dee2e6;
        }

        .question-box.current {
            background-color: var(--primary);
            color: white;
        }

        .question-box.answered {
            background-color: #28a745;
            color: white;
        }

        .question-box.flagged {
            background-color: var(--warning);
            color: #212529;
        }

        .timer-container {
            background-color: white;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }

        .timer {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .timer.warning {
            color: var(--warning);
        }

        .timer.danger {
            color: var(--danger);
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .calculator-btn {
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 5px;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .calculator-btn:hover {
            background-color: var(--secondary);
        }

        .calculator-modal .btn {
            min-width: 50px;
            height: 50px;
            font-size: 1.2rem;
            margin: 0.2rem;
        }

        .option-label {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            border-radius: 5px;
            transition: all 0.2s;
            cursor: pointer;
            border: 1px solid #dee2e6;
            margin-bottom: 0.5rem;
        }

        .option-label:hover {
            background-color: #f8f9fa;
        }

        .option-input {
            margin-right: 10px;
        }

        .navigation-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }

        .btn-submit {
            background-color: #28a745;
            border-color: #28a745;
        }

        .btn-submit:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }

        .full-screen-warning {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }

        .question-container {
            display: none;
        }

        .question-container.active {
            display: block;
        }

        @media (max-width: 768px) {
            .exam-header {
                border-radius: 0;
            }
            .question-nav {
                position: static;
                margin-bottom: 1.5rem;
            }
            .question-boxes {
                grid-template-columns: repeat(auto-fill, minmax(35px, 1fr));
            }
        }
    </style>
</head>
<body>
    <!-- Full screen warning -->
    <div class="full-screen-warning" id="fullscreenWarning">
        <h2><i class="bi bi-exclamation-triangle-fill"></i> Warning!</h2>
        <p>You have exited full screen mode. Please return to full screen to continue your exam.</p>
        <button class="btn btn-primary mt-3" onclick="requestFullscreen()">Return to Full Screen</button>
    </div>

    <!-- Exam Header -->
    <div class="exam-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>D-Portal Examination</h4>
                    <div class="d-flex gap-3">
                        <span><i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($_SESSION['student_name']); ?></span>
                        <span><i class="bi bi-journal-text"></i> <?php echo htmlspecialchars($_SESSION['test_title']); ?></span>
                        <span><i class="bi bi-book"></i> <?php echo htmlspecialchars($_SESSION['student_subject']); ?></span>
                        <span><i class="bi bi-people-fill"></i> <?php echo htmlspecialchars($_SESSION['student_class']); ?></span>
                    </div>
                </div>
                <div class="timer-container">
                    <div class="timer" id="examTimer">00:00:00</div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row">
            <!-- Question Navigation -->
            <div class="col-md-3">
                <div class="question-nav">
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Question Navigation</h5>
                        </div>
                        <div class="card-body">
                            <div class="question-boxes" id="questionBoxes">
                                <?php foreach ($questions as $index => $question): ?>
                                    <div class="question-box <?php echo $index === 0 ? 'current' : ''; ?>" 
                                         data-index="<?php echo $index; ?>" 
                                         onclick="goToQuestion(<?php echo $index; ?>)">
                                        <?php echo $index + 1; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Calculator Button -->
                    <button class="calculator-btn w-100 mb-3" data-bs-toggle="modal" data-bs-target="#calculatorModal">
                        <i class="bi bi-calculator"></i> Calculator
                    </button>

                    <!-- Instructions -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">Instructions</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <li><i class="bi bi-square-fill text-primary"></i> Current question</li>
                                <li><i class="bi bi-square-fill text-success"></i> Answered question</li>
                                <li><i class="bi bi-square-fill text-secondary"></i> Unanswered question</li>
                                <li><i class="bi bi-square-fill text-warning"></i> Flagged for review</li>
                            </ul>
                            <p class="mb-0">Time remaining will turn yellow when 5 minutes remain and red when 1 minute remains.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Exam Questions -->
            <div class="col-md-9">
                <?php if (empty($questions)): ?>
                    <div class="alert alert-warning">No questions available for this test.</div>
                <?php else: ?>
                    <form method="POST" action="submit_exam.php" id="examForm">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="question-container <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-index="<?php echo $index; ?>" id="question-<?php echo $index; ?>">
                                <div class="question-card card mb-4">
                                    <div class="card-body">
                                        <h5 class="card-title">Question <?php echo $index + 1; ?> (<?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?>)</h5>
                                        <?php echo $question['image_html']; ?>
                                        <p class="card-text"><?php echo htmlspecialchars($question['question_text']); ?></p>

                                        <?php if ($question['question_type'] === 'multiple_choice_sing'): ?>
                                            <div class="options-container">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                    <?php if (!empty($question["option$i"])): ?>
                                                        <label class="option-label">
                                                            <input class="option-input" type="radio"
                                                                   name="answers[<?php echo $question['id']; ?>]"
                                                                   id="q<?php echo $question['id']; ?>_opt<?php echo $i; ?>"
                                                                   value="<?php echo $i; ?>"
                                                                   onchange="markAnswered(<?php echo $index; ?>)">
                                                            <?php echo htmlspecialchars($question["option$i"]); ?>
                                                        </label>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </div>
                                        <?php elseif ($question['question_type'] === 'multiple_choice'): ?>
                                            <div class="options-container">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                    <?php if (!empty($question["option$i"])): ?>
                                                        <label class="option-label">
                                                            <input class="option-input" type="checkbox"
                                                                   name="answers[<?php echo $question['id']; ?>][]"
                                                                   id="q<?php echo $question['id']; ?>_opt<?php echo $i; ?>"
                                                                   value="<?php echo $i; ?>"
                                                                   onchange="markAnswered(<?php echo $index; ?>)">
                                                            <?php echo htmlspecialchars($question["option$i"]); ?>
                                                        </label>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </div>
                                        <?php elseif ($question['question_type'] === 'true_false'): ?>
                                            <div class="options-container">
                                                <label class="option-label">
                                                    <input class="option-input" type="radio"
                                                           name="answers[<?php echo $question['id']; ?>]"
                                                           id="q<?php echo $question['id']; ?>_true"
                                                           value="True"
                                                           onchange="markAnswered(<?php echo $index; ?>)">
                                                    True
                                                </label>
                                                <label class="option-label">
                                                    <input class="option-input" type="radio"
                                                           name="answers[<?php echo $question['id']; ?>]"
                                                           id="q<?php echo $question['id']; ?>_false"
                                                           value="False"
                                                           onchange="markAnswered(<?php echo $index; ?>)">
                                                    False
                                                </label>
                                            </div>
                                        <?php elseif ($question['question_type'] === 'fill_blank'): ?>
                                            <div class="form-group">
                                                <input type="text" class="form-control"
                                                       name="answers[<?php echo $question['id']; ?>]"
                                                       id="q<?php echo $question['id']; ?>_answer"
                                                       placeholder="Type your answer here"
                                                       oninput="markAnswered(<?php echo $index; ?>)">
                                            </div>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-warning btn-sm mt-2"
                                                onclick="flagQuestion(<?php echo $index; ?>)">
                                            <i class="bi bi-flag-fill"></i> Flag for Review
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="navigation-buttons">
                            <button type="button" class="btn btn-secondary" id="prevBtn" onclick="previousQuestion()">
                                <i class="bi bi-chevron-left"></i> Previous
                            </button>
                            <button type="button" class="btn btn-primary" id="nextBtn" onclick="nextQuestion()">
                                Next <i class="bi bi-chevron-right"></i>
                            </button>
                            <button type="submit" class="btn btn-submit" id="submitBtn" style="display: none;">
                                <i class="bi bi-send-fill"></i> Submit Exam
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Calculator Modal -->
    <div class="modal fade" id="calculatorModal" tabindex="-1" aria-labelledby="calculatorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="calculatorModalLabel">Scientific Calculator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="text" id="calcDisplay" readonly class="form-control mb-3" value="0">
                    <div class="d-flex flex-wrap justify-content-center">
                        <button class="btn btn-secondary" onclick="calcClear()">C</button>
                        <button class="btn btn-secondary" onclick="calcAppend('(')">(</button>
                        <button class="btn btn-secondary" onclick="calcAppend(')')">)</button>
                        <button class="btn btn-secondary" onclick="calcFunction('sqrt')">√</button>
                        <button class="btn btn-secondary" onclick="calcAppend('/')">÷</button>
                        <button class="btn btn-secondary" onclick="calcAppend('7')">7</button>
                        <button class="btn btn-secondary" onclick="calcAppend('8')">8</button>
                        <button class="btn btn-secondary" onclick="calcAppend('9')">9</button>
                        <button class="btn btn-secondary" onclick="calcAppend('*')">×</button>
                        <button class="btn btn-secondary" onclick="calcFunction('sin')">sin</button>
                        <button class="btn btn-secondary" onclick="calcAppend('4')">4</button>
                        <button class="btn btn-secondary" onclick="calcAppend('5')">5</button>
                        <button class="btn btn-secondary" onclick="calcAppend('6')">6</button>
                        <button class="btn btn-secondary" onclick="calcAppend('-')">-</button>
                        <button class="btn btn-secondary" onclick="calcFunction('cos')">cos</button>
                        <button class="btn btn-secondary" onclick="calcAppend('1')">1</button>
                        <button class="btn btn-secondary" onclick="calcAppend('2')">2</button>
                        <button class="btn btn-secondary" onclick="calcAppend('3')">3</button>
                        <button class="btn btn-secondary" onclick="calcAppend('+')">+</button>
                        <button class="btn btn-secondary" onclick="calcFunction('tan')">tan</button>
                        <button class="btn btn-secondary" onclick="calcAppend('0')">0</button>
                        <button class="btn btn-secondary" onclick="calcAppend('.')">.</button>
                        <button class="btn btn-secondary" onclick="calcBackspace()">←</button>
                        <button class="btn btn-primary" onclick="calcEvaluate()">=</button>
                        <button class="btn btn-secondary" onclick="calcFunction('log')">log</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        let currentIndex = 0;
        const totalQuestions = <?php echo count($questions); ?>;
        const containers = document.querySelectorAll('.question-container');
        const questionBoxes = document.querySelectorAll('.question-box');
        const timerEl = document.getElementById('examTimer');
        const formEl = document.getElementById('examForm');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        let timeLeft = <?php echo $exam_duration; ?>;
        let timerWarning = false;
        let timerDanger = false;

        // Timer
        function startTimer() {
            const interval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(interval);
                    formEl.submit();
                    return;
                }
                timeLeft--;
                const hours = Math.floor(timeLeft / 3600);
                const minutes = Math.floor((timeLeft % 3600) / 60);
                const seconds = timeLeft % 60;
                timerEl.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= 300 && !timerWarning) {
                    timerEl.classList.add('warning');
                    timerWarning = true;
                }
                if (timeLeft <= 60 && !timerDanger) {
                    timerEl.classList.remove('warning');
                    timerEl.classList.add('danger');
                    timerDanger = true;
                }
            }, 1000);
        }

        // Question Navigation
        function showQuestion(index) {
            containers.forEach(c => c.style.display = 'none');
            containers[index].style.display = 'block';
            questionBoxes.forEach(b => b.classList.remove('current'));
            questionBoxes[index].classList.add('current');
            
            prevBtn.style.display = index === 0 ? 'none' : 'inline-block';
            nextBtn.style.display = index === totalQuestions - 1 ? 'none' : 'inline-block';
            submitBtn.style.display = index === totalQuestions - 1 ? 'inline-block' : 'none';
            
            currentIndex = index;
            window.scrollTo({ top: document.getElementById(`question-${index}`).offsetTop - 100, behavior: 'smooth' });
        }

        function nextQuestion() {
            if (currentIndex < totalQuestions - 1) {
                showQuestion(currentIndex + 1);
            }
        }

        function previousQuestion() {
            if (currentIndex > 0) {
                showQuestion(currentIndex - 1);
            }
        }

        function goToQuestion(index) {
            if (index >= 0 && index < totalQuestions) {
                showQuestion(index);
            }
        }

        function markAnswered(index) {
            if (!questionBoxes[index].classList.contains('flagged')) {
                questionBoxes[index].classList.remove('unanswered');
                questionBoxes[index].classList.add('answered');
            }
        }

        function flagQuestion(index) {
            questionBoxes[index].classList.remove('unanswered', 'answered');
            questionBoxes[index].classList.add('flagged');
        }

        // Calculator
        let calcExpression = '';
        function calcAppend(char) {
            try {
                calcExpression += char;
                document.getElementById('calcDisplay').value = calcExpression;
                console.log('Appended:', char);
            } catch (e) {
                console.error('Calc append error:', e);
            }
        }

        function calcClear() {
            try {
                calcExpression = '';
                document.getElementById('calcDisplay').value = '0';
                console.log('Calculator cleared');
            } catch (e) {
                console.error('Calc clear error:', e);
            }
        }

        function calcBackspace() {
            try {
                calcExpression = calcExpression.slice(0, -1);
                document.getElementById('calcDisplay').value = calcExpression || '0';
                console.log('Backspace');
            } catch (e) {
                console.error('Calc backspace error:', e);
            }
        }

        function calcFunction(func) {
            try {
                let value = parseFloat(calcExpression) || 0;
                switch (func) {
                    case 'sin': value = Math.sin(value * Math.PI / 180); break;
                    case 'cos': value = Math.cos(value * Math.PI / 180); break;
                    case 'tan': value = Math.tan(value * Math.PI / 180); break;
                    case 'sqrt': value = Math.sqrt(value); break;
                    case 'log': value = Math.log10(value); break;
                }
                calcExpression = value.toString();
                document.getElementById('calcDisplay').value = calcExpression;
                console.log('Function applied:', func, value);
            } catch (e) {
                calcExpression = '';
                document.getElementById('calcDisplay').value = 'Error';
                console.error('Calc function error:', e);
            }
        }

        function calcEvaluate() {
            try {
                const result = eval(calcExpression.replace(/[^-()\d/*+.\s]/g, ''));
                calcExpression = result.toString();
                document.getElementById('calcDisplay').value = calcExpression;
                console.log('Evaluated:', result);
            } catch (e) {
                calcExpression = '';
                document.getElementById('calcDisplay').value = 'Error';
                console.error('Calc evaluate error:', e);
            }
        }

        // Full Screen Control
        function requestFullscreen() {
            const elem = document.documentElement;
            if (elem.requestFullscreen) {
                elem.requestFullscreen().catch(e => console.error('Fullscreen error:', e));
            } else if (elem.webkitRequestFullscreen) {
                elem.webkitRequestFullscreen();
            } else if (elem.msRequestFullscreen) {
                elem.msRequestFullscreen();
            }
            document.getElementById('fullscreenWarning').style.display = 'none';
        }

        // Security
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                document.getElementById('fullscreenWarning').style.display = 'flex';
            }
        });

        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('copy', e => e.preventDefault());
        document.addEventListener('paste', e => e.preventDefault());

        let tabSwitchCount = 0;
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                tabSwitchCount++;
                if (tabSwitchCount > 2) {
                    alert('Warning: Multiple tab switches detected. Exam may be terminated.');
                }
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            try {
                requestFullscreen();
                startTimer();
                showQuestion(0);
                console.log('Exam initialized');
            } catch (e) {
                console.error('Initialization error:', e);
            }
        });
    </script>
</body>
</html>