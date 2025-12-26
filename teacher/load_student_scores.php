<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$academic_year_id = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;

try {
    $db = Database::getInstance()->getConnection();

    // Load scores
    $stmt = $db->prepare("
        SELECT *
        FROM student_subject_scores
        WHERE student_id = ? AND academic_year_id = ?
    ");
    $stmt->bind_param('ii', $student_id, $academic_year_id);
    $stmt->execute();
    $scores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Load remark
    $stmt2 = $db->prepare("
        SELECT remark
        FROM student_remarks
        WHERE student_id = ? AND academic_year_id = ?
        LIMIT 1
    ");
    $stmt2->bind_param('ii', $student_id, $academic_year_id);
    $stmt2->execute();
    $remark = $stmt2->get_result()->fetch_assoc()['remark'] ?? '';
    $stmt2->close();

    echo json_encode(['status' => 'success', 'scores' => $scores, 'remark' => $remark]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to load scores: ' . $e->getMessage()]);
}
?>