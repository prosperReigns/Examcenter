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
<link href="../css/bootstrap.min.css" rel="stylesheet"><style>
        :root{--queue-ink:#172b4d;--queue-muted:#64748b;--queue-line:#e2e8f0}
        body{min-height:100vh;background:radial-gradient(ellipse at 90% 0%,rgba(50,105,190,.09),transparent 34rem),#f3f6fb;color:var(--queue-ink)}
        .queue-shell{max-width:1440px}
        .queue-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}
        .queue-heading h1{font-weight:800;letter-spacing:-.035em}
        .queue-heading p{color:var(--queue-muted);margin:0}
        .queue-info{border:1px solid #d7e5f8;border-radius:14px;background:#eef5ff;color:#25446e;padding:16px 18px}
        .queue-info code{color:#193f78}
        .queue-panel{background:#fff;border:1px solid var(--queue-line);border-radius:16px;box-shadow:0 10px 28px rgba(20,43,77,.05);overflow:hidden}
        .queue-panel table{margin:0}
        .queue-panel thead th{background:#f7f9fc;color:#53657d;font-size:.76rem;text-transform:uppercase;letter-spacing:.045em;border-bottom:1px solid var(--queue-line);white-space:nowrap}
        .queue-panel td,.queue-panel th{padding:14px 16px;vertical-align:middle}
        .queue-panel tbody tr:hover{background:#f8fbff}
        .queue-panel .badge{padding:.45rem .65rem;border-radius:999px;font-weight:700}
        .queue-panel .btn{border-radius:8px;font-weight:700}
        .queue-panel .text-danger{overflow-wrap:anywhere;max-width:360px}
        @media(max-width:700px){.queue-heading{flex-direction:column}.queue-heading .btn{width:100%}}
 </style>
</head>
<body class="bg-light">
<main class="container queue-shell py-4">
    <header class="queue-heading"><div><div class="text-uppercase small fw-bold text-primary mb-2">Assessment operations</div><h1 class="h3">Theory Grading Queue</h1><p><?= $h($assessment['title']) ?> <span aria-hidden="true">·</span> <?= $h($assessment['subject']) ?></p></div><a class="btn btn-outline-primary" href="theory_review.php?test_id=<?= $testId ?>">Review marks</a></header>
    <div class="queue-info mb-4">
        Exam submissions are graded by the separate CLI worker. This page does not run AI inference inside a browser request.
        Start the worker on the main Examcenter computer with <code>php scripts/theory_grading_worker.php</code>, or launch <code>scripts/start_theory_grading_worker.bat</code> in the bundled Windows build.
        It processes one grading job at a time and sends no more than five questions per model request.
    </div>
    <?php if ($message !== ''): ?><div class="alert alert-secondary"><?= $h($message) ?></div><?php endif; ?>
    <section class="queue-panel"><div class="table-responsive"><table class="table align-middle">
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
        </tbody></table></div></section>
    <p class="text-muted small mt-3">Refresh this page to see updated job states. Original submitted answers are retained across retries.</p>
</main>
</body></html>