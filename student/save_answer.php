<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$conn = Database::getInstance()->getConnection();
$user_id = (int)$_POST['user_id'];
$test_id = (int)$_POST['test_id'];
$question_id = (int)$_POST['question_id'];
$answer = $_POST['answer'];

$stmt = $conn->prepare("INSERT INTO exam_attempts (user_id, test_id, question_id, answer) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE answer = ?");
$stmt->bind_param("iiiss", $user_id, $test_id, $question_id, $answer, $answer);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $success, 'message' => $success ? 'Answer saved' : 'Failed to save answer']);