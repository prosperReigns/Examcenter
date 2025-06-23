<?php
session_start();
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    exit();
}

$conn = Database::getInstance()->getConnection();
$user_id = (int)$_POST['user_id'];
$test_id = (int)$_POST['test_id'];
$time_left = (int)$_POST['time_left'];
$current_index = (int)$_POST['current_index'];

$stmt = $conn->prepare("UPDATE exam_attempts SET time_left = ?, current_index = ? WHERE user_id = ? AND test_id = ?");
$stmt->bind_param("iiii", $time_left, $current_index, $user_id, $test_id);
$stmt->execute();
$stmt->close();