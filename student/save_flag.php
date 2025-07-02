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
$is_flagged = (int)$_POST['is_flagged'];

$stmt = $conn->prepare("INSERT INTO exam_attempts (user_id, test_id, question_id, is_flagged) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_flagged = ?");
$stmt->bind_param("iiiii", $user_id, $test_id, $question_id, $is_flagged, $is_flagged);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $success, 'message' => $success ? 'Flag updated' : 'Failed to update flag']);