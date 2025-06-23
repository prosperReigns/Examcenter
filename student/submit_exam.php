<?php
session_start();
require_once '../db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check if student is logged in
if (!isset($_SESSION['student_id'], $_SESSION['current_test_id'], $_SESSION['exam_questions'])) {
    error_log("Session missing in submit_exam.php: Redirecting to register.php");
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

$student_id = (int)$_SESSION['student_id'];
$test_id = (int)$_SESSION['current_test_id'];
$questions = $_SESSION['exam_questions'];
$submitted_answers = $_POST['answers'] ?? [];
$score = 0;
$total_questions = count($questions);

// Log submitted answers for debugging
error_log("Submitted answers for test_id=$test_id, user_id=$student_id: " . print_r($submitted_answers, true));

// Process each question
$answer_details = [];
foreach ($questions as $question) {
    $question_id = $question['id'];
    $type = $question['question_type'];
    $user_answer = isset($submitted_answers[$question_id]) ? $submitted_answers[$question_id] : null;

    // Initialize correct answer variable
    $correct_answer = null;
    $is_correct = false;

    // Fetch correct answer based on question type
    switch ($type) {
        case 'multiple_choice_sing':
            $stmt = $conn->prepare("SELECT correct_option FROM single_choice_questions WHERE question_id = ?");
            if (!$stmt) {
                error_log("Prepare failed for single_choice_questions, question_id=$question_id: " . $conn->error);
                continue;
            }
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $correct_answer = $row['correct_option'] ?? null;
            $stmt->close();
            if ($user_answer && $user_answer == $correct_answer) {
                $score++;
                $is_correct = true;
            }
            break;

        case 'multiple_choice_mult':
            $stmt = $conn->prepare("SELECT correct_options FROM multiple_choice_questions WHERE question_id = ?");
            if (!$stmt) {
                error_log("Prepare failed for multiple_choice_questions, question_id=$question_id: " . $conn->error);
                continue;
            }
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $correct_options = $row['correct_options'] ?? '';
            $stmt->close();
            $correct_array = array_filter(explode(',', $correct_options));
            sort($correct_array);
            $user_array = is_array($user_answer) ? array_filter($user_answer) : [];
            sort($user_array);
            if ($user_array === $correct_array) {
                $score++;
                $is_correct = true;
            }
            $correct_answer = $correct_options;
            break;

        case 'true_false':
            $stmt = $conn->prepare("SELECT correct_answer FROM true_false_questions WHERE question_id = ?");
            if (!$stmt) {
                error_log("Prepare failed for true_false_questions, question_id=$question_id: " . $conn->error);
                continue;
            }
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $correct_answer = $row['correct_answer'] ?? null;
            $stmt->close();
            if ($user_answer && $user_answer === $correct_answer) {
                $score++;
                $is_correct = true;
            }
            break;

        case 'fill_blank':
            $stmt = $conn->prepare("SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?");
            if (!$stmt) {
                error_log("Prepare failed for fill_blank_questions, question_id=$question_id: " . $conn->error);
                continue;
            }
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $correct_answer = $row['correct_answer'] ?? null;
            $stmt->close();
            if ($user_answer && strtolower(trim($user_answer)) === strtolower(trim($correct_answer))) {
                $score++;
                $is_correct = true;
            }
            break;

        default:
            error_log("Unknown question type '$type' for question_id=$question_id");
            continue;
    }

    // Store answer details for debugging
    $answer_details[$question_id] = [
        'user_answer' => $user_answer,
        'correct_answer' => $correct_answer,
        'is_correct' => $is_correct,
        'type' => $type
    ];
}

// Log answer details and score
error_log("Answer details: " . print_r($answer_details, true));
error_log("Final score: $score out of $total_questions for user_id=$student_id, test_id=$test_id");

// Store result in database
$stmt = $conn->prepare("INSERT INTO results (user_id, test_id, score, total_questions, status, created_at) VALUES (?, ?, ?, ?, 'completed', NOW())");
if (!$stmt) {
    error_log("Prepare failed for results insertion: " . $conn->error);
    die("Error preparing result insertion.");
}
$stmt->bind_param("iiii", $student_id, $test_id, $score, $total_questions);
if (!$stmt->execute()) {
    error_log("Result insertion failed: " . $stmt->error);
    die("Error saving results. Please try again.");
}
$stmt->close();

// Store score in session for result page
$_SESSION['exam_score'] = $score;
$_SESSION['total_questions'] = $total_questions;

// Clear session data
unset($_SESSION['exam_questions'], $_SESSION['current_test_id']);

// Redirect to result page
header("Location: result.php");
exit();
?>