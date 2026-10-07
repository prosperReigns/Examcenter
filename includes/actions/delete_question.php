<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../system_guard.php';

$isAdmin = isset($_SESSION['admin_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) === 'admin';
$isTeacher = strtolower((string)($_SESSION['user_role'] ?? '')) === 'teacher' || isset($_SESSION['teacher_id']);

if (!$isAdmin && !$isTeacher) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['question_id']) || !is_numeric($_POST['question_id'])) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$conn = Database::connection();
$questionId = (int)$_POST['question_id'];
$stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
$stmt->bind_param('i', $questionId);
$success = $stmt->execute();
$stmt->close();

if (!$success) {
    $_SESSION['error'] = $conn->error;
}

$redirect = $GLOBALS['examcenterDeleteQuestionRedirect'] ?? '../teacher/view_questions.php';
header('Location: ' . $redirect);
exit;
