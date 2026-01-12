<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['student_id'], $input['academic_year_id'], $input['scores'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit();
}

$student_id = (int)$input['student_id'];
$academic_year_id = (int)$input['academic_year_id'];
$scores = $input['scores'];
$remark = $input['remark'] ?? '';

try {
    $db = Database::getInstance()->getConnection();

    // Save each score
    $stmt = $db->prepare("
        INSERT INTO student_subject_scores
        (student_id, subject_id, academic_year_id, ca1, ca2, ca3, ca4, exam, class_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ca1 = VALUES(ca1), ca2 = VALUES(ca2), ca3 = VALUES(ca3), ca4 = VALUES(ca4), exam = VALUES(exam)
    ");

    // Fetch the student’s class first
    $stmt_class = $db->prepare("SELECT class FROM students WHERE id = ?");
    $stmt_class->bind_param('i', $student_id);
    $stmt_class->execute();
    $student_row = $stmt_class->get_result()->fetch_assoc();
    $stmt_class->close();

    $class_id = $student_row['class']; // this is the numeric ID

    foreach ($scores as $row) {
        $stmt->bind_param(
            'iiiiiiiii',
            $student_id,
            $row['subject_id'],
            $academic_year_id,
            $row['ca1'],
            $row['ca2'],
            $row['ca3'],
            $row['ca4'],
            $row['exam'],
            $class_id
        );
        $stmt->execute();
    }

    $teacher_id = (int) $_SESSION['user_id'];
    // Save teacher remark
    $stmt2 = $db->prepare("
        INSERT INTO student_term_remarks (student_id, academic_year_id, teacher_id, remark)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE remark = VALUES(remark)
    ");
    $stmt2->bind_param('iiis', $student_id, $academic_year_id, $teacher_id, $remark);
    $stmt2->execute();

    echo json_encode(['status' => 'success', 'message' => 'Results saved successfully.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save results: ' . $e->getMessage()]);
}
?>