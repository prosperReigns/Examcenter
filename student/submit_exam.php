<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['student_id']) || !isset($_SESSION['exam_questions']) || !isset($_SESSION['current_test_id'])) {
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
$student_id = $_SESSION['student_id'];
$test_id = $_SESSION['current_test_id'];
$questions = $_SESSION['exam_questions'];
$submitted_answers = $_POST['answers'];

$score = 0;
$total_questions = count($questions);

// Grade the exam
foreach ($questions as $question) {
    $question_id = $question['id'];
            if (isset($submitted_answers[$question_id]) && 
                $submitted_answers[$question_id] == $question['correct_answer']) {
                $score++;
            }
}

// Store result in database with test_id
$sql = "INSERT INTO results (user_id, test_id, score, total_questions) 
        VALUES ($student_id, $test_id, $score, $total_questions)";
mysqli_query($conn, $sql);

// Store score in session for result page
$_SESSION['exam_score'] = $score;
$_SESSION['total_questions'] = $total_questions;

// Clear exam questions and test_id from session
unset($_SESSION['exam_questions']);
unset($_SESSION['current_test_id']);

header("Location: result.php");
exit();