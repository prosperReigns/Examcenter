<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db.php';
//require_once __DIR__.'/../includes/pro.php';
header('Content-Type: application/json');
if (!pro_enabled('secure_exam_mode')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Pro Secure Exam is not enabled.']);
    exit;
}
if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}
$testId = (int)($_POST['test_id'] ?? 0);
$studentId = (int)($_POST['student_id'] ?? 0);
$type = trim((string)($_POST['event_type'] ?? 'unknown'));
$severity = trim((string)($_POST['severity'] ?? 'info'));
$evidence = json_decode((string)($_POST['evidence'] ?? '{}'), true);
if (!is_array($evidence)) $evidence = [];
if ($studentId !== (int)$_SESSION['student_id'] || $testId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid event context.']);
    exit;
}
require_once __DIR__ . '/../pro/ProAssessmentService.php';
$id = (new ProAssessmentService())->recordSecurityEvent($testId, $studentId, null, $type, $severity, $evidence);
echo json_encode(['success' => true, 'event_id' => $id]);
