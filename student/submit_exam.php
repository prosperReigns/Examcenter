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
    $question_type = $question['question_type'];

    $correct_answer = '';
    $submitted_value = null;

    switch ($question_type) {
        case 'multiple_choice_single':
            $query = "SELECT option1, option2, option3, option4, correct_answer FROM single_choice_questions WHERE question_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $correct_answer = $row['correct_answer'];

                if (isset($submitted_answers[$question_id])) {
                    $selected_option = (int)$submitted_answers[$question_id];

                    // Map index to actual option value
                    switch ($selected_option) {
                        case 1: $submitted_value = $row['option1']; break;
                        case 2: $submitted_value = $row['option2']; break;
                        case 3: $submitted_value = $row['option3']; break;
                        case 4: $submitted_value = $row['option4']; break;
                        default: $submitted_value = null;
                    }

                    if (trim(strtolower($submitted_value)) == trim(strtolower($correct_answer))) {
                        $score++;
                    }
                }
            }
            break;

        case 'multiple_choice_multiple':
            $query = "SELECT correct_answers FROM multiple_choice_questions WHERE question_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc() && isset($submitted_answers[$question_id])) {
                $correct_array = explode(',', $row['correct_answers']);
                $submitted_array = is_array($submitted_answers[$question_id]) ? $submitted_answers[$question_id] : [$submitted_answers[$question_id]];
                sort($correct_array);
                sort($submitted_array);
                if (implode(',', $correct_array) === implode(',', $submitted_array)) {
                    $score++;
                }
            }
            break;

        case 'true_false':
            $query = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc() && isset($submitted_answers[$question_id])) {
                if (trim(strtolower($submitted_answers[$question_id])) == trim(strtolower($row['correct_answer']))) {
                    $score++;
                }
            }
            break;

        case 'fill_blanks':
        case 'short_answer':
            $query = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc() && isset($submitted_answers[$question_id])) {
                if (trim(strtolower($submitted_answers[$question_id])) == trim(strtolower($row['correct_answer']))) {
                    $score++;
                }
            }
            break;
    }
}

// Store result in database with test_id
$sql = "INSERT INTO results (user_id, test_id, score, total_questions) 
        VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $student_id, $test_id, $score, $total_questions);
$stmt->execute();

// Store score in session for result page
$_SESSION['exam_score'] = $score;
$_SESSION['total_questions'] = $total_questions;

// Clear exam questions and test_id from session
unset($_SESSION['exam_questions']);
unset($_SESSION['current_test_id']);

header("Location: result.php");
exit();
