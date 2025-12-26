<?php
require '../vendor/autoload.php';
require_once '../db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$input = json_decode(file_get_contents('php://input'), true);
$student_id = (int)$input['student_id'];
$academic_year_id = (int)$input['academic_year_id'];

$db = Database::getInstance()->getConnection();

// Fetch student info
$stmt = $db->prepare("SELECT name, email, class FROM students WHERE id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Generate PDF (same as download)
ob_start();
require 'download_student_report.php';
$pdfContent = ob_get_clean();

// Send email
$mail = new PHPMailer(true);
try {
    $mail->setFrom('no-reply@yourdomain.com', 'CBT System');
    $mail->addAddress($student['email'], $student['name']);
    $mail->Subject = 'Your Student Report';
    $mail->Body = 'Please find attached your report.';
    $mail->addStringAttachment($pdfContent, 'Student_Report.pdf');
    $mail->send();
    echo json_encode(['status'=>'success','message'=>'Report emailed successfully.']);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Email could not be sent: '.$mail->ErrorInfo]);
}
?>