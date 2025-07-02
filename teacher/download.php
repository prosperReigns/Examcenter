<?php
require '../db.php';

$conn = Database::getInstance()->getConnection(); // ✅ Use MySQLi-style variable

$class = $_GET['class'];
$subject = $_GET['subject'];
$title = $_GET['title'];

// Use MySQLi prepared statements
$stmt = $conn->prepare("SELECT * FROM tests WHERE class=? AND subject=? AND title=?");
$stmt->bind_param("sss", $class, $subject, $title);
$stmt->execute();
$result = $stmt->get_result();
$test = $result->fetch_assoc();
$stmt->close();

if (!$test) {
    die("Test not found");
}

$test_id = $test['id'];

function fetchQuestions($conn, $table, $type, $test_id) {
    $sql = "
    SELECT q.*, n.question_text as question
    FROM $table q
    JOIN new_questions n ON q.question_id = n.id
    WHERE n.test_id = ?
";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        unset($row['question_id']);
        unset($row['id']);  // remove id from the exported JSON
        $row['type'] = $type;
        $data[] = $row;
    }
    $stmt->close();
    return $data;
}

$questions = array_merge(
    fetchQuestions($conn, 'single_choice_questions', 'multiple_choice_single', $test_id),
    fetchQuestions($conn, 'multiple_choice_questions', 'multiple_choice_multiple', $test_id),
    fetchQuestions($conn, 'fill_blank_questions', 'fill_blank', $test_id),
    fetchQuestions($conn, 'true_false_questions', 'true_false', $test_id)
);

$output = [
    "test" => [
        "title" => $test['title'],
        "class" => $test['class'],
        "subject" => $test['subject'],
        "duration" => $test['duration']
    ],
    "questions" => $questions
];

header('Content-Type: application/json');
header("Content-Disposition: attachment; filename={$title}_{$subject}.json");
echo json_encode($output, JSON_PRETTY_PRINT);
?>