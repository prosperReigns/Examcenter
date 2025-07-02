<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// DISABLE CAHING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

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

// Initialize exam attempt
$stmt = $conn->prepare("SELECT time_left, current_index FROM exam_attempts WHERE user_id = ? AND test_id = ? LIMIT 1");
$stmt->bind_param("ii", $user_id, $test_id);
$stmt->execute();
$attempt_result = $stmt->get_result();
$exam_state = $attempt_result->fetch_assoc();
$stmt->close();

$time_left = $exam_state ? $exam_state['time_left'] : $exam_duration;
$current_index = $exam_state ? (int)$exam_state['current_index'] : 0;

if (!$exam_state) {
    $stmt = $conn->prepare("INSERT INTO exam_attempts (user_id, test_id, time_left, current_index) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiii", $user_id, $test_id, $exam_duration, $current_index);
    $stmt->execute();
    $stmt->close();
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
$base_url = 'http://localhost/EXAMCENTER';
while ($row = $questions_result->fetch_assoc()) {
    $question_id = $row['id'];
    $type = $row['question_type'];

    // Initialize image handling
    $image_html = '';

    $detail_query = null;
    switch ($type) {
        case 'multiple_choice_single':
            $detail_query = "SELECT option1, option2, option3, option4, image_path, correct_answer FROM single_choice_questions WHERE question_id = ?";
            break;
        case 'multiple_choice_multiple':
            $detail_query = "SELECT option1, option2, option3, option4, correct_answer FROM multiple_choice_questions WHERE question_id = ?";
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
        if ($type === 'multiple_choice_single' && !empty($detail['image_path'])) {
            $image_path = $base_url . '/' . $detail['image_path'];
            $file_path = $_SERVER['DOCUMENT_ROOT'] . '/EXAMCENTER/' . $detail['image_path'];
            if (file_exists($file_path)) {
                error_log("Image found at: $file_path");
                $image_html = "<div class='question-image mb-3'><img src='$image_path' class='img-fluid zoomable' alt='Question Image' onclick='openImageModal(this.src)' onerror='this.src=\"/images/fallback.jpg\"; this.alt=\"Image not found\"'></div>";
            } else {
                error_log("Image not found at: $file_path for path: " . $detail['image_path']);
                $image_html = "<div class='question-image mb-3'><img src='/images/fallback.jpg' class='img-fluid' alt='Image not found'></div>";
            }
        }
    }

    // Load saved answer and flagged status
$answer_stmt = $conn->prepare("SELECT answer, is_flagged FROM exam_attempts WHERE user_id = ? AND test_id = ? AND question_id = ?");
$answer_stmt->bind_param("iii", $user_id, $test_id, $question_id);
$answer_stmt->execute();
$answer_result = $answer_stmt->get_result();
$saved_answer = $answer_result->fetch_assoc();
$answer_stmt->close();


    $questions[] = array_merge($row, $detail ?? [], [
        'image_html' => $image_html,
        'saved_answer' => $saved_answer['answer'] ?? null,
        'is_flagged' => $saved_answer['is_flagged'] ?? 0
    ]);
}


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

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    <script src="https://cdn.jsdelivr.net/npm/mathjs@10.6.4/lib/browser/math.js"></script>
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
            max-width: 100%;
            width: auto;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            cursor: pointer;
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

        #imageModal img {
            max-width: 90%;
            max-height: 90vh;
            margin: auto;
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

    <!-- Image Zoom Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalLabel">Image Zoom</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="zoomedImage" src="" alt="Zoomed Image">
                </div>
            </div>
        </div>
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
                                    <div class="question-box <?php echo $index === $current_index ? 'current' : ''; ?> <?php echo $question['saved_answer'] ? 'answered' : ''; ?> <?php echo $question['is_flagged'] ? 'flagged' : ''; ?>" 
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
                        <input type="hidden" name="test_id" value="<?php echo $test_id; ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="question-container <?php echo $index === $current_index ? 'active' : ''; ?>" 
                                 data-index="<?php echo $index; ?>" id="question-<?php echo $index; ?>">
                                <div class="question-card card mb-4">
                                    <div class="card-body">
                                        <h5 class="card-title">Question <?php echo $index + 1; ?> (<?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?>)</h5>
                                        <?php echo $question['image_html']; ?>
                                        <p class="card-text"><?php echo htmlspecialchars($question['question_text']); ?></p>

                                        <?php if ($question['question_type'] === 'multiple_choice_single'): ?>
                                            <div class="options-container">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                    <?php if (!empty($question["option$i"])): ?>
                                                        <label class="option-label">
                                                            <input class="option-input" type="radio"
                                                                   name="answers[<?php echo $question['id']; ?>]"
                                                                   id="q<?php echo $question['id']; ?>_opt<?php echo $i; ?>"
                                                                   value="<?php echo $i; ?>"
                                                                   <?php echo $question['saved_answer'] == $i ? 'checked' : ''; ?>
                                                                   onchange="saveAnswer(<?php echo $question['id']; ?>, this.value, '<?php echo $question['question_type']; ?>', <?php echo $index; ?>)">
                                                            <?php echo htmlspecialchars($question["option$i"]); ?>
                                                        </label>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </div>
                                        <?php elseif ($question['question_type'] === 'multiple_choice_multiple'): ?>
                                            <div class="options-container">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                    <?php if (!empty($question["option$i"])): ?>
                                                        <label class="option-label">
                                                            <input class="option-input" type="checkbox"
                                                                   name="answers[<?php echo $question['id']; ?>][]"
                                                                   id="q<?php echo $question['id']; ?>_opt<?php echo $i; ?>"
                                                                   value="<?php echo $i; ?>"
                                                                   <?php echo in_array($i, json_decode($question['saved_answer'] ?? '[]', true)) ? 'checked' : ''; ?>
                                                                   onchange="saveAnswer(<?php echo $question['id']; ?>, getCheckboxValues(<?php echo $question['id']; ?>), '<?php echo $question['question_type']; ?>', <?php echo $index; ?>)">
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
                                                           <?php echo $question['saved_answer'] === 'True' ? 'checked' : ''; ?>
                                                           onchange="saveAnswer(<?php echo $question['id']; ?>, this.value, '<?php echo $question['question_type']; ?>', <?php echo $index; ?>)">
                                                    True
                                                </label>
                                                <label class="option-label">
                                                    <input class="option-input" type="radio"
                                                           name="answers[<?php echo $question['id']; ?>]"
                                                           id="q<?php echo $question['id']; ?>_false"
                                                           value="False"
                                                           <?php echo $question['saved_answer'] === 'False' ? 'checked' : ''; ?>
                                                           onchange="saveAnswer(<?php echo $question['id']; ?>, this.value, '<?php echo $question['question_type']; ?>', <?php echo $index; ?>)">
                                                    False
                                                </label>
                                            </div>
                                        <?php elseif ($question['question_type'] === 'fill_blank'): ?>
                                            <div class="form-group">
                                                <input type="text" class="form-control"
                                                       name="answers[<?php echo $question['id']; ?>]"
                                                       id="q<?php echo $question['id']; ?>_answer"
                                                       placeholder="Type your answer here"
                                                       value="<?php echo htmlspecialchars($question['saved_answer'] ?? ''); ?>"
                                                       oninput="saveAnswer(<?php echo $question['id']; ?>, this.value, '<?php echo $question['question_type']; ?>', <?php echo $index; ?>)">
                                            </div>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-warning btn-sm mt-2"
                                                onclick="flagQuestion(<?php echo $question['id']; ?>, <?php echo $index; ?>, <?php echo $question['is_flagged'] ? 0 : 1; ?>)">
                                            <i class="bi bi-flag-fill"></i> <?php echo $question['is_flagged'] ? 'Unflag' : 'Flag for Review'; ?>
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
                    <div class="container" style="max-width: 320px;">
                        <div class="row g-2">
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcClear()">C</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcBackspace()">←</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('(')">(</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend(')')">)</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('sqrt')">√</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('pow')">x²</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('fact')">x!</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('/')">÷</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('sin')">sin</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('cos')">cos</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('tan')">tan</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('*')">×</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcFunction('log')">log</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('pi')">π</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('-')">-</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('+')">+</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('7')">7</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('8')">8</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('9')">9</button></div>
                            <div class="col-3"></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('4')">4</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('5')">5</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('6')">6</button></div>
                            <div class="col-3"></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('1')">1</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('2')">2</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('3')">3</button></div>
                            <div class="col-3"></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('0')">0</button></div>
                            <div class="col-3"><button class="btn btn-secondary w-100" onclick="calcAppend('.')">.</button></div>
                            <div class="col-6"></div>
                            <div class="col-12"><button class="btn btn-primary w-100" onclick="calcEvaluate()">=</button></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/lock_exam_window.js"></script>
    <script>
        let currentIndex = <?php echo $current_index; ?>;
        const totalQuestions = <?php echo count($questions); ?>;
        const containers = document.querySelectorAll('.question-container');
        const questionBoxes = document.querySelectorAll('.question-box');
        const timerEl = document.getElementById('examTimer');
        const formEl = document.getElementById('examForm');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        let timeLeft = <?php echo $time_left; ?>;
        let timerWarning = false;
        let timerDanger = false;
        let tabSwitchCount = 0;

        // Timer
        function startTimer() {
            const interval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(interval);
                    submitExam('timeout');
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

                // Save time periodically (every 10 seconds)
                if (timeLeft % 10 === 0) {
                    saveState();
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
            saveState();
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

        function flagQuestion(questionId, index, flag) {
            questionBoxes[index].classList.remove('unanswered', 'answered');
            if (flag) {
                questionBoxes[index].classList.add('flagged');
            } else {
                questionBoxes[index].classList.remove('flagged');
                if (document.querySelector(`input[name="answers[${questionId}]"]:checked`) || 
                    document.querySelector(`input[name="answers[${questionId}][]"]:checked`) || 
                    document.querySelector(`#q${questionId}_answer`)?.value) {
                    questionBoxes[index].classList.add('answered');
                }
            }
            document.querySelector(`button[onclick="flagQuestion(${questionId}, ${index}, ${flag ? 0 : 1})"]`).innerHTML = `<i class="bi bi-flag-fill"></i> ${flag ? 'Unflag' : 'Flag for Review'}`;
            saveFlag(questionId, flag);
        }

        function getCheckboxValues(questionId) {
            const checkboxes = document.querySelectorAll(`input[name="answers[${questionId}][]"]:checked`);
            return Array.from(checkboxes).map(cb => cb.value).join(',');
        }

        function saveAnswer(questionId, answer, questionType, index) {
            markAnswered(index);
            const data = new FormData();
            data.append('question_id', questionId);
            data.append('answer', questionType === 'multiple_choice_multiple' ? `[${answer}]` : answer);
            data.append('user_id', <?php echo $user_id; ?>);
            data.append('test_id', <?php echo $test_id; ?>);
            data.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('save_answer.php', {
                method: 'POST',
                body: data
            }).then(response => response.json())
              .then(result => {
                  if (!result.success) {
                      console.error('Save answer failed:', result.message);
                  }
              }).catch(error => console.error('Save answer error:', error));
        }

        function saveFlag(questionId, flag) {
            const data = new FormData();
            data.append('question_id', questionId);
            data.append('is_flagged', flag);
            data.append('user_id', <?php echo $user_id; ?>);
            data.append('test_id', <?php echo $test_id; ?>);
            data.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('save_flag.php', {
                method: 'POST',
                body: data
            }).then(response => response.json())
              .then(result => {
                  if (!result.success) {
                      console.error('Save flag failed:', result.message);
                  }
              }).catch(error => console.error('Save flag error:', error));
        }

        function saveState() {
            const data = new FormData();
            data.append('user_id', <?php echo $user_id; ?>);
            data.append('test_id', <?php echo $test_id; ?>);
            data.append('time_left', timeLeft);
            data.append('current_index', currentIndex);
            data.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('save_state.php', {
                method: 'POST',
                body: data
            }).catch(error => console.error('Save state error:', error));
        }

        function submitExam(reason = 'manual') {
            formEl.querySelector('input[name="submit_reason"]').value = reason;
            formEl.submit();
        }

        // Calculator Functions
        let calcExpression = '';
        function calcAppend(char) {
            try {
                if (char === 'pi') char = 'π';
                if (char === '.' && calcExpression.slice(-1) === '.') return false;
                calcExpression += char;
                updateDisplay();
            } catch (e) {
                console.error('calcAppend error:', e);
                updateDisplay('Error');
            }
        }

        function calcClear() {
            try {
                calcExpression = '';
                updateDisplay('0');
            } catch (e) {
                console.error('calcClear error:', e);
            }
        }

        function calcBackspace() {
            try {
                calcExpression = calcExpression.slice(0, -1);
                updateDisplay(calcExpression || '0');
            } catch (e) {
                console.error('calcBackspace error:', e);
            }
        }

        function calcFunction(func) {
            try {
                const lastChar = calcExpression.slice(-1);
                const isNumberOrClose = /[0-9)]/.test(lastChar);
                if (['sin', 'cos', 'tan'].includes(func)) {
                    calcExpression += `${func}(`;
                } else if (func === 'sqrt') {
                    calcExpression += 'sqrt(';
                } else if (func === 'log') {
                    calcExpression += 'log10(';
                } else if (func === 'pow' && isNumberOrClose) {
                    calcExpression += '^2';
                } else if (func === 'fact' && isNumberOrClose) {
                    calcExpression += '!';
                } else {
                    return;
                }
                updateDisplay();
            } catch (e) {
                console.error('calcFunction error:', e);
                updateDisplay('Error');
            }
        }

        function calcEvaluate() {
            try {
                let expr = calcExpression
                    .replace(/π/g, `${Math.PI}`)
                    .replace(/([0-9.]+)!/g, 'factorial($1)')
                    .replace(/(sin|cos|tan)\(([^)]+)\)/g, (match, func, arg) => `${func}(${arg} * pi / 180)`);
                const result = math.evaluate(expr);
                if (isNaN(result) || result === Infinity || result === -Infinity) {
                    throw new Error('Invalid result');
                }
                calcExpression = result.toString();
                updateDisplay();
            } catch (e) {
                console.error('calcEvaluate error:', e);
                calcExpression = '';
                updateDisplay('Error');
            }
        }

        function updateDisplay(val = null) {
            try {
                document.getElementById('calcDisplay').value = val === null ? calcExpression : val;
            } catch (e) {
                console.error('updateDisplay error:', e);
            }
        }

        // Image Zoom
        function openImageModal(src) {
            document.getElementById('zoomedImage').src = src;
            const modal = new bootstrap.Modal(document.getElementById('imageModal'));
            modal.show();
        }

        // Full Screen Control
        function requestFullscreen() {
            const elem = document.documentElement;
            if (elem.requestFullscreen) {
                elem.requestFullscreen().catch(e => {
                    console.error('Fullscreen error:', e);
                    alert('Please enable full screen to continue the exam.');
                });
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

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                tabSwitchCount++;
                if (tabSwitchCount >= 2) {
                    alert('Exam terminated due to multiple tab switches.');
                    submitExam('tab_switch');
                } else {
                    alert(`Warning: Tab switch detected (${tabSwitchCount}/2). Exam will terminate after one more switch.`);
                }
            }
        });

        // Prevent Back Navigation
        window.history.pushState(null, null, window.location.href);
        window.addEventListener('popstate', () => {
            window.history.pushState(null, null, window.location.href);
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            try {
                requestFullscreen();
                startTimer();
                showQuestion(currentIndex);
                formEl.insertAdjacentHTML('beforeend', '<input type="hidden" name="submit_reason" value="manual">');
            } catch (e) {
                console.error('Initialization error:', e);
            }
        });
    </script>
</body>
</html>
