<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_POST['id'], $_POST['duration'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$id = (int)$_POST['id'];
$duration = (int)$_POST['duration'];

if ($duration < 1) {
    echo json_encode(['success' => false, 'error' => 'Duration must be at least 1 minute']);
    exit();
}

try {
    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare("UPDATE tests SET duration = ? WHERE id = ?");
    $stmt->bind_param("ii", $duration, $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update duration']);
    }
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
