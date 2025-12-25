<?php
require '../db.php';
$conn = Database::getInstance()->getConnection();

$class = $_GET['class'];
$subject = $_GET['subject'];
$title = $_GET['title'];

// Fetch test
$stmt = $conn->prepare("SELECT * FROM tests WHERE class=? AND subject=? AND title=? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("sss", $class, $subject, $title);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test) die("Test not found");

$test_id = $test['id'];

// Fetch questions helper function
function fetchQuestions($conn, $table, $columns, $test_id) {
    $sql = "SELECT " . implode(", ", $columns) . " FROM $table t
            JOIN new_questions n ON t.question_id = n.id
            WHERE n.test_id = ?
            ORDER BY n.id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

// Single choice
$single = fetchQuestions($conn, 'single_choice_questions', ['n.question_text', 't.option1', 't.option2', 't.option3', 't.option4', 't.correct_answer'], $test_id);

// Multiple choice (multiple answers)
$multiple = fetchQuestions($conn, 'multiple_choice_questions', ['n.question_text', 't.option1', 't.option2', 't.option3', 't.option4', 't.correct_answers', 't.image_path'], $test_id);

// True/False
$tf = fetchQuestions($conn, 'true_false_questions', ['n.question_text', 't.correct_answer'], $test_id);

// Fill in the blank
$fill = fetchQuestions($conn, 'fill_blank_questions', ['n.question_text', 't.correct_answer'], $test_id);

// Merge all questions in order: single -> multiple -> true/false -> fill
$questions = array_merge($single, $multiple, $tf, $fill);

// Headers for Word download
header("Content-Type: application/vnd.ms-word");
header("Content-Disposition: attachment; filename={$title}_{$subject}.doc");

// Output test info
echo "{$test['title']}\n";
echo "Class: {$test['class']}\n";
echo "Subject: {$test['subject']}\n";
echo "Duration: {$test['duration']} mins\n\n";

// Output questions
foreach ($questions as $index => $q) {
    $num = $index + 1;
    echo "{$num}. {$q['question_text']}";

    // Options
    if (isset($q['option1'])) {
        echo " A) {$q['option1']} B) {$q['option2']} C) {$q['option3']} D) {$q['option4']}";
    }

    echo "\n";

    // Correct answer
    if (isset($q['correct_answer'])) {
        echo "correct answer: {$q['correct_answer']}\n\n";
    } elseif (isset($q['correct_answers'])) {
        echo "correct answer: " . implode(", ", array_map('trim', explode(',', $q['correct_answers']))) . "\n\n";
    }
}
?>
