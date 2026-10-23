<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/system_guard.php';

if (!isset($_SESSION['student_id'], $_SESSION['current_test_id'])) {
    header('Location: register.php'); exit;
}
$db = Database::connection();
$studentId = (int)$_SESSION['student_id'];
$testId = (int)($_GET['test_id'] ?? $_SESSION['current_test_id']);
$auth = $_SESSION['exam_preview_authorized'] ?? null;
if ($testId < 1 || $testId !== (int)$_SESSION['current_test_id']
    || !is_array($auth)
    || (int)($auth['student_id'] ?? 0) !== $studentId
    || (int)($auth['test_id'] ?? 0) !== $testId
    || !in_array(($auth['mode'] ?? ''), ['combined', 'theory'], true)) {
    header('Location: exam_preview.php'); exit;
}
$stmt = $db->prepare("SELECT ta.id AS assessment_id, t.title, t.duration, ta.instructions
    FROM theory_assessments ta JOIN tests t ON t.id=ta.test_id
    WHERE ta.test_id=? AND ta.status<>'archived' LIMIT 1");
$stmt->bind_param('i', $testId); $stmt->execute();
$test = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$test) { http_response_code(404); exit('No theory assessment is attached to this test.'); }
$assessmentId = (int)$test['assessment_id'];
$stmt = $db->prepare("SELECT id,question_number,question_text,maximum_marks FROM theory_questions WHERE theory_assessment_id=? ORDER BY question_number");
$stmt->bind_param('i', $assessmentId); $stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
if (!$questions) exit('No theory questions have been added.');
$stmt = $db->prepare("SELECT id,status FROM theory_submissions WHERE theory_assessment_id=? AND student_id=? LIMIT 1");
$stmt->bind_param('ii', $assessmentId, $studentId); $stmt->execute();
$submission = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$submission) {
    $stmt = $db->prepare("INSERT INTO theory_submissions(theory_assessment_id,student_id,started_at,status,maximum_marks)
        SELECT ?,?,NOW(),'in_progress',COALESCE(SUM(maximum_marks),0) FROM theory_questions WHERE theory_assessment_id=?");
    $stmt->bind_param('iii', $assessmentId, $studentId, $assessmentId); $stmt->execute();
    $submission = ['id' => $stmt->insert_id, 'status' => 'in_progress']; $stmt->close();
}
if (in_array($submission['status'], ['submitted','graded'], true)) {
    header('Location: result.php'); exit;
}
$submissionId = (int)$submission['id'];
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        http_response_code(403); exit('Invalid request token. Reload the page and try again.');
    }
    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $answer = trim((string)($_POST['answer'][$qid] ?? ''));
        $wordCount = $answer === '' ? 0 : count(preg_split('/\\s+/u', $answer));
        $stmt = $db->prepare("INSERT INTO theory_answers(submission_id,theory_question_id,answer_text,word_count)
            VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text),word_count=VALUES(word_count)");
        $stmt->bind_param('iisi', $submissionId, $qid, $answer, $wordCount);
        $stmt->execute(); $stmt->close();
    }
    header('Location: take_exam.php?theory_saved=1'); exit;
}
$answers = [];
$stmt = $db->prepare("SELECT theory_question_id,answer_text FROM theory_answers WHERE submission_id=?");
$stmt->bind_param('i', $submissionId); $stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $answers[(int)$row['theory_question_id']] = (string)$row['answer_text'];
$stmt->close();
$stmt = $db->prepare("SELECT started_at FROM exam_attempts WHERE user_id=? AND test_id=? LIMIT 1");
$stmt->bind_param('ii', $studentId, $testId); $stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc(); $stmt->close();
$startedAt = $attempt ? strtotime((string)$attempt['started_at']) : time();
$expiresAt = $startedAt + max(1, (int)$test['duration']) * 60;
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Theory section | Examcenter</title><link href="../css/bootstrap.min.css" rel="stylesheet">
<style>
        :root{--theory-ink:#172b4d;--theory-muted:#64748b;--theory-line:#e1e8f0;--theory-blue:#2459a6}
        body{min-height:100vh;background:radial-gradient(ellipse at 85% 0%,rgba(50,105,190,.10),transparent 35rem),#f3f6fb;color:var(--theory-ink)}
        .wrap{max-width:1000px;margin:28px auto;padding:0 clamp(14px,3vw,24px)}
        .panel{background:#fff;border:1px solid var(--theory-line);border-radius:16px;padding:clamp(18px,3vw,26px);margin-bottom:16px;box-shadow:0 8px 25px rgba(20,43,77,.045)}
        .panel h1{font-weight:800;letter-spacing:-.035em}
        .panel h2{font-weight:750}
        .timer{font-size:1rem;font-weight:800;color:#9f2525;position:sticky;top:12px;background:#fff;border:1px solid #f0caca;padding:12px 16px;border-radius:12px;z-index:5;box-shadow:0 5px 16px rgba(90,25,25,.08);white-space:nowrap}
        .alert{border-radius:12px}
        textarea.form-control{min-height:150px;padding:14px;line-height:1.65;border-color:#d5deea;border-radius:10px}
        textarea.form-control:focus{border-color:#6b98d8;box-shadow:0 0 0 .2rem rgba(36,89,166,.12)}
        .btn{border-radius:10px;font-weight:700;padding:11px 16px}
        @media(max-width:640px){.wrap{margin:14px auto}.panel.d-flex{align-items:flex-start!important;flex-direction:column}.timer{position:static;width:100%}}
 </style>
</head><body><main class="wrap">
<div class="panel d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h1 class="h3"><?= $h($test['title']) ?> — Theory</h1><p class="mb-0">Student: <?= $h($_SESSION['student_name'] ?? 'Student') ?></p></div><div class="timer">Time remaining: <span id="timer">--:--</span></div></div>
<div class="alert alert-info">This is one section of your examination. The original exam timer continues while you are here. Save your answers and return to the objective section; submit the entire exam from the objective page.</div>
<?php if (trim((string)$test['instructions']) !== ''): ?><div class="panel"><?= nl2br($h($test['instructions'])) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
<?php foreach ($questions as $q): $qid=(int)$q['id']; ?><section class="panel"><h2 class="h5">Question <?= $h($q['question_number']) ?> <small class="text-muted">(<?= $h($q['maximum_marks']) ?> marks)</small></h2><p><?= nl2br($h($q['question_text'])) ?></p><textarea class="form-control" name="answer[<?= $qid ?>]" rows="6" placeholder="Write your answer here..."><?= $h($answers[$qid] ?? '') ?></textarea></section><?php endforeach; ?>
<div class="d-flex gap-2 flex-wrap mb-5"><button class="btn btn-success btn-lg" type="submit">Save answers & return to Objective Exam</button><a class="btn btn-outline-primary btn-lg" href="take_exam.php">Return without saving</a></div></form>
</main><script>
const expiry = <?= (int)$expiresAt ?> * 1000;
function updateTimer(){const left=Math.max(0,Math.floor((expiry-Date.now())/1000));const el=document.getElementById('timer');el.textContent=String(Math.floor(left/60)).padStart(2,'0')+':'+String(left%60).padStart(2,'0');if(left<=0){document.querySelector('form').requestSubmit();}}
updateTimer();setInterval(updateTimer,1000);
</script></body></html>