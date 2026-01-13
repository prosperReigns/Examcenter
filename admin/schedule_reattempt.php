<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

header('Content-Type: application/json');

// =======================
// AUTH CHECK
// =======================
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

$admin_id = (int) $_SESSION['user_id'];

$database = Database::getInstance();
$conn = $database->getConnection();

// Verify admin
$stmt = $conn->prepare("SELECT role FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || strtolower($admin['role']) !== 'admin') {
    echo json_encode([
        'success' => false,
        'message' => 'Only admins can schedule reattempts'
    ]);
    exit;
}

// =======================
// INPUT VALIDATION
// =======================
if (!isset($_POST['result_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Result ID is required'
    ]);
    exit;
}

$result_id = (int) $_POST['result_id'];

// =======================
// CHECK RESULT EXISTS
// =======================
$stmt = $conn->prepare("
    SELECT id, reattempt_approved 
    FROM results 
    WHERE id = ?
");
$stmt->bind_param("i", $result_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Result record not found'
    ]);
    exit;
}

// Prevent duplicate approvals
if ((int)$result['reattempt_approved'] === 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Reattempt already approved'
    ]);
    exit;
}

// =======================
// APPROVE REATTEMPT
// =======================
$stmt = $conn->prepare("
    UPDATE results
    SET 
        reattempt_approved = 1,
        status = 'reattempt_scheduled'
    WHERE id = ?
");

$stmt->bind_param("i", $result_id);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Reattempt successfully scheduled'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to schedule reattempt'
    ]);
}

$stmt->close();
$conn->close();
?>