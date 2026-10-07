<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token']);
    exit;
}

if (!isset($_SESSION['student_id'], $_SESSION['current_test_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Exam session expired']);
    exit;
}

$conn = Database::connection();
$userId = (int)$_SESSION['student_id'];
$testId = (int)$_SESSION['current_test_id'];
$questionId = (int)($_POST['question_id'] ?? 0);
$answer = (string)($_POST['answer'] ?? '');

if ($questionId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid question']);
    exit;
}

if (isset($_POST['user_id']) && (int)$_POST['user_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid candidate']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT q.id
     FROM new_questions q
     WHERE q.id = ? AND q.test_id = ?
     LIMIT 1"
);
$stmt->bind_param('ii', $questionId, $testId);
$stmt->execute();
$questionExists = (bool)$stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$questionExists) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Question does not belong to this exam']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, expires_at, status
     FROM exam_attempt_sessions
     WHERE user_id = ? AND test_id = ? AND status = 'in_progress'
     ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('ii', $userId, $testId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($session && (strtotime((string)$session['expires_at']) <= time())) {
    $update = $conn->prepare(
        "UPDATE exam_attempt_sessions
         SET status = 'expired', updated_at = NOW()
         WHERE id = ?"
    );
    $sid = (int)$session['id'];
    $update->bind_param('i', $sid);
    $update->execute();
    $update->close();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Exam time has expired']);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO exam_attempts (user_id, test_id, question_id, answer)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = CURRENT_TIMESTAMP"
);
$stmt->bind_param('iiis', $userId, $testId, $questionId, $answer);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    if ($session) {
        $event = $conn->prepare(
            "INSERT INTO exam_attempt_events(attempt_session_id,event_type,question_id,metadata)
             VALUES(?,?,?,?)"
        );
        $eventType = 'answer_saved';
        $metadata = json_encode(['answer_length' => mb_strlen($answer)], JSON_UNESCAPED_UNICODE) ?: '{}';
        $sid = (int)$session['id'];
        $event->bind_param('isis', $sid, $eventType, $questionId, $metadata);
        $event->execute();
        $event->close();

        $touch = $conn->prepare(
            "UPDATE exam_attempt_sessions SET last_activity_at = NOW() WHERE id = ?"
        );
        $touch->bind_param('i', $sid);
        $touch->execute();
        $touch->close();
    }
}

echo json_encode([
    'success' => $success,
    'message' => $success ? 'Answer saved' : 'Failed to save answer'
]);
