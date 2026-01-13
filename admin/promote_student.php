<?php
session_start();
require_once '../db.php';

$conn = Database::getInstance()->getConnection();

$student_id = (int)$_GET['student_id'];

// Get student current class + academic level
$stmt = $conn->prepare("
    SELECT s.class, c.academic_level_id
    FROM students s
    JOIN classes c ON s.class = c.id
    WHERE s.id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Student not found");
}

$current_level = (int)$student['academic_level_id'];

// Get NEXT academic level
$stmt = $conn->prepare("
    SELECT id FROM academic_levels
    WHERE id > ?
    ORDER BY id ASC
    LIMIT 1
");
$stmt->bind_param("i", $current_level);
$stmt->execute();
$next = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$next) {
    die("Student is already in the highest level");
}

// Get a class under next academic level
$stmt = $conn->prepare("
    SELECT id FROM classes
    WHERE academic_level_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $next['id']);
$stmt->execute();
$new_class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$new_class) {
    die("No class available for promotion");
}

// Update student class
$stmt = $conn->prepare("
    UPDATE students SET class = ?
    WHERE id = ?
");
$stmt->bind_param("ii", $new_class['id'], $student_id);
$stmt->execute();
$stmt->close();

header("Location: manage_students.php?success=Student promoted successfully");
exit;
?>