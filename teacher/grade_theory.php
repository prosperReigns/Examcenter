<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/pro.php';
require_once '../theory/TheoryGradingQueue.php';

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'teacher') {
    http_response_code(403);
    exit('Unauthorized');
}
// Pro guard intentionally remains disabled during development; restore before production.

$db = Database::connection();
$testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
$stmt = $db->prepare("SELECT ta.id,t.title,t.subject FROM theory_assessments ta JOIN tests t ON t.id=ta.test_id WHERE ta.test_id=? LIMIT 1");
$stmt->bind_param('i', $testId);
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$assessment) {
    http_response_code(404);
    exit('Theory assessment not found.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_job'])) {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $jobId = (int)$_POST['retry_job'];
    $stmt = $db->prepare("SELECT id FROM theory_grading_jobs WHERE id=? AND test_id=? AND status='failed' LIMIT 1");
    $stmt->bind_param('ii', $jobId, $testId);
    $stmt->execute();
    $belongsToTest = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($belongsToTest) {
        $queue = new TheoryGradingQueue($db);
        $message = $queue->retryFailed($jobId)
            ? 'The grading job has been re-queued. The student does not need to submit again.'
            : 'The job could not be retried; refresh the queue status.';
    } else {
        $message = 'That failed job does not belong to this assessment or is no longer failed.';
    }
}

$stmt = $db->prepare(
    "SELECT s.id submission_id,s.student_id,st.full_name,s.status submission_status,s.grading_status,
            s.total_marks,s.maximum_marks,j.id job_id,j.status job_status,j.attempt_count,j.max_attempts,
            j.last_error,j.available_at,j.updated_at job_updated_at
     FROM theory_submissions s
     JOIN students st ON st.id=s.student_id
     LEFT JOIN theory_grading_jobs j ON j.submission_id=s.id
     WHERE s.theory_assessment_id=?
     ORDER BY s.created_at DESC"
);
$stmt->bind_param('i', $assessment['id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Theory Grading Queue | Examcenter</title>
<link href="../css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<main class="container py-4">
    <h1 class="h3">Theory Grading Queue</h1>
    <p class="text-muted"><?= $h($assessment['title']) ?> — <?= $h($assessment['subject']) ?></p>
    <div class="alert alert-info">
        Exam submissions are graded by the separate CLI worker. This page does not run AI inference inside a browser request.
        Start the worker on the main Examcenter computer with <code>php scripts/theory_grading_worker.php</code>.
        It processes one grading job at a time and sends no more than five questions per model request.
    </div>
    <?php if ($message !== ''): ?><div class="alert alert-secondary"><?= $h($message) ?></div><?php endif; ?>
    <p><a class="btn btn-outline-primary" href="theory_review.php?test_id=<?= $testId ?>">Open teacher review</a></p>
    <div class="table-responsive"><table class="table table-bordered table-striped align-middle bg-white">
        <thead><tr><th>Student</th><th>Submission</th><th>Queue status</th><th>Attempts</th><th>Last error</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= $h($row['full_name']) ?></td>
                <td><?= $h($row['submission_status']) ?></td>
                <td><span class="badge text-bg-<?= ($row['job_status'] ?? '') === 'completed' ? 'success' : ((($row['job_status'] ?? '') === 'failed') ? 'danger' : 'warning') ?>"><?= $h($row['job_status'] ?? 'not queued') ?></span>
                    <?php if (!empty($row['available_at']) && ($row['job_status'] ?? '') === 'pending'): ?><div class="small text-muted">Available: <?= $h($row['available_at']) ?></div><?php endif; ?>
                </td>
                <td><?= $h($row['attempt_count'] ?? 0) ?> / <?= $h($row['max_attempts'] ?? 3) ?></td>
                <td class="small text-danger"><?= $h($row['last_error'] ?? '') ?></td>
                <td>
                    <?php if (($row['job_status'] ?? '') === 'failed' && $row['job_id']): ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>"><input type="hidden" name="test_id" value="<?= $testId ?>"><button class="btn btn-sm btn-warning" name="retry_job" value="<?= (int)$row['job_id'] ?>">Retry grading</button></form>
                    <?php elseif (($row['job_status'] ?? '') === 'completed'): ?>
                        <span class="text-success">Marks saved</span>
                    <?php else: ?><span class="text-muted">Waiting for worker</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center">No student submissions yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    <p class="text-muted small">Refresh this page to see updated job states. Original submitted answers are retained across retries.</p>
</main>
</body></html>