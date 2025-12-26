<?php
require_once '../db.php';
require_once '../includes/system_guard.php';
header('Content-Type: application/json');

if (!isset($_GET['class'])) {
    echo json_encode([]);
    exit;
}

$class = $_GET['class'];
$level = str_starts_with($class, 'JSS') ? 'JSS' : 'SS';

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    $stmt = $conn->prepare("
    SELECT s.id, s.subject_name
    FROM subject_levels sl
    JOIN subjects s ON sl.subject_id = s.id
    WHERE sl.class_level = ?
");

    $stmt->bind_param("s", $level);
    $stmt->execute();
    $subjects = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_name');

    echo json_encode($subjects);
} catch (Exception $e) {
    echo json_encode([]);
}
