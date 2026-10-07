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
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !is_numeric($_POST['id'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid test ID']);
    exit;
}

$conn = Database::connection();
$testId = (int)$_POST['id'];

$stmt = $conn->prepare("DELETE FROM tests WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$stmt->bind_param('i', $testId);
$success = $stmt->execute();
$stmt->close();

echo json_encode($success
    ? ['success' => true]
    : ['success' => false, 'error' => 'Failed to delete test']
);
