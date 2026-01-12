<?php
session_start();
require_once '../db.php';
if (!isset($_POST['delete']) || !isset($_GET['id'])) die(json_encode(['success'=>false,'message'=>'Invalid request']));
$student_id = (int)$_GET['id'];
$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
if ($stmt->execute()) {
    echo json_encode(['success'=>true,'message'=>'Student deleted successfully']);
} else {
    echo json_encode(['success'=>false,'message'=>'Failed to delete student']);
}
$stmt->close();
?>