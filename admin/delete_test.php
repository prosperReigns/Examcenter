<?php
session_start();
require_once '../db.php';

// Ensure user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Validate test ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid test ID']);
    exit();
}

$test_id = (int)$_POST['id'];
$conn = Database::getInstance()->getConnection();

// Optional: Verify that the logged-in teacher owns this test
$stmt = $conn->prepare("DELETE FROM tests WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

$stmt->bind_param("i", $test_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete test']);
}

$stmt->close();
$conn->close();
