
<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('Invalid request');
}

$conn = Database::getInstance()->getConnection();
$user_id = (int)$_POST['user_id'];
$test_id = (int)$_POST['test_id'];
$submit_reason = $_POST['submit_reason'] ?? 'manual';
$student_name = $_SESSION['student_name'] ?? 'Unknown';

$score = 0;
$total_questions = 0;

// Fetch all questions for the test
$stmt = $conn->prepare("SELECT id, question_type FROM new_questions WHERE test_id = ?");
$stmt->bind_param("i", $test_id);
$stmt->execute();
$questions_result = $stmt->get_result();
$questions = $questions_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_questions = count($questions);

foreach ($questions as $question) {
    $question_id = $question['id'];
    $type = $question['question_type'];

    // Get user's answer from exam_attempts
    $stmt = $conn->prepare("SELECT answer FROM exam_attempts WHERE user_id = ? AND test_id = ? AND question_id = ?");
    $stmt->bind_param("iii", $user_id, $test_id, $question_id);
    $stmt->execute();
    $answer_result = $stmt->get_result();
    $user_answer = $answer_result->fetch_assoc()['answer'] ?? null;
    $stmt->close();

    if (!$user_answer) {
        continue;
    }

    // Get correct answer and options
    $correct_answer = null;
    $options = [];
    $detail_table = '';
    $answer_column = 'correct_answer';
    $query = '';

    switch ($type) {
        case 'multiple_choice_sing':
            $detail_table = 'single_choice_questions';
            $query = "SELECT correct_answer, option1, option2, option3, option4 FROM $detail_table WHERE question_id = ?";
            break;
        case 'multiple_choice_mult':
            $detail_table = 'multiple_choice_questions';
            $answer_column = 'correct_answers';
            $query = "SELECT correct_answers, option1, option2, option3, option4 FROM $detail_table WHERE question_id = ?";
            break;
        case 'true_false':
            $detail_table = 'true_false_questions';
            $query = "SELECT correct_answer FROM $detail_table WHERE question_id = ?";
            break;
        case 'fill_blank':
            $detail_table = 'fill_blank_questions';
            $query = "SELECT correct_answer FROM $detail_table WHERE question_id = ?";
            break;
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $correct_result = $stmt->get_result();
    $row = $correct_result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        continue;
    }

    $correct_answer = $row[$answer_column];
    if ($type === 'multiple_choice_sing' || $type === 'multiple_choice_mult') {
        $options = [
            1 => $row['option1'] ?? '',
            2 => $row['option2'] ?? '',
            3 => $row['option3'] ?? '',
            4 => $row['option4'] ?? ''
        ];
    }

    // Compare answers
    if ($type === 'multiple_choice_sing') {
        // Map user_answer index to option text
        $user_answer_text = $options[(int)$user_answer] ?? '';
        $is_correct = $user_answer_text === $correct_answer;
        if ($is_correct) {
            $score++;
        }
    } elseif ($type === 'multiple_choice_mult') {
        // User answer is JSON array of indices (e.g., ["1","2"])
        $user_answers = json_decode($user_answer, true) ?? [];
        // Map indices to option texts
        $user_answer_texts = array_filter(array_map(function($index) use ($options) {
            return $options[(int)$index] ?? '';
        }, $user_answers));
        // Correct answers are comma-separated (e.g., "cat,dog")
        $correct_answers = array_filter(array_map('trim', explode(',', $correct_answer)));

        sort($user_answer_texts);
        sort($correct_answers);

        $is_correct = $user_answer_texts === $correct_answers;
        if ($is_correct) {
            $score++;
        }
    } elseif ($type === 'fill_blank') {
        $is_correct = strtolower(trim($user_answer)) === strtolower(trim($correct_answer));
        if ($is_correct) {
            $score++;
        }
    } else {
        // true_false
        $is_correct = (string)$user_answer === (string)$correct_answer;
        if ($is_correct) {
            $score++;
        }
    }
}

// Save result
$status = $submit_reason === 'tab_switch' ? 'terminated' : 'completed';
$stmt = $conn->prepare("INSERT INTO results (user_id, test_id, score, total_questions, status) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iiiis", $user_id, $test_id, $score, $total_questions, $status);
$stmt->execute();
$stmt->close();

// Clean up exam_attempts
$stmt = $conn->prepare("DELETE FROM exam_attempts WHERE user_id = ? AND test_id = ?");
$stmt->bind_param("ii", $user_id, $test_id);
$stmt->execute();
$stmt->close();

// Preserve student_name for result.php
$_SESSION['student_name'] = $student_name;

header("Location: result.php?test_id=$test_id&user_id=$user_id");
exit();
