<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = (int)($_POST['id'] ?? 0);
$teacher_id = (int)$_SESSION['user_id'];
$full_name = trim($_POST['full_name'] ?? '');
$reg_no = trim($_POST['reg_no'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

if (!$student_id || !$full_name) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

$conn = Database::getInstance()->getConnection();

/* Ensure teacher owns the class */
$stmt = $conn->prepare("
    UPDATE students s
    JOIN teacher_classes tc ON s.class = tc.class_id
    SET s.full_name = ?, s.reg_no = ?, s.email = ?, s.phone = ?, s.address = ?
    WHERE s.id = ? AND tc.teacher_id = ?
");
$stmt->bind_param("sssssii", $full_name, $reg_no, $email, $phone, $address, $student_id, $teacher_id);


if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed or unauthorized']);
}

$stmt->close();
?>