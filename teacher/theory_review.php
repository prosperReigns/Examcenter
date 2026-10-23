<?php

declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/pro.php';
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'teacher') {
    header('Location: ../login.php?error=Not logged in');
    exit;
}// Pro guard intentionally disabled during development; restore before production.
// require_pro('ai_assessment', 'Offline AI theory grading is an Examcenter Pro feature.');
$db = Database::connection();
$testId = (int)($_GET['test_id'] ?? 0);
$stmt = $db->prepare("SELECT ta.id,t.title,t.subject FROM theory_assessments ta JOIN tests t ON t.id=ta.test_id WHERE ta.test_id=? LIMIT 1");
$stmt->bind_param('i', $testId);
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$assessment) die('Assessment not found.');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mark'])) {
    $markId = (int)$_POST['mark_id'];
    $final = max(0, (float)$_POST['final_mark']);
    $reason = trim((string)$_POST['reason']);
    $reviewer = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("SELECT awarded_marks,maximum_marks,answer_id FROM theory_marks WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $markId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($old && $reason !== '') {
        $final = min($final, (float)$old['maximum_marks']);
        $stmt = $db->prepare("UPDATE theory_marks SET awarded_marks=?,percentage=?,status='teacher_final',reviewed_by=?,reviewed_at=NOW(),review_reason=? WHERE id=?");
        $pct = ((float)$old['maximum_marks'] > 0) ? $final / (float)$old['maximum_marks'] * 100 : 0;
        $stmt->bind_param('ddisi', $final, $pct, $reviewer, $reason, $markId);
        $stmt->execute();
        $stmt->close();
        $stmt = $db->prepare("INSERT INTO theory_manual_reviews(mark_id,reviewer_id,original_awarded_marks,final_awarded_marks,reason) VALUES(?,?,?,?,?)");
        $stmt->bind_param('iidds', $markId, $reviewer, $old['awarded_marks'], $final, $reason);
        $stmt->execute();
        $stmt->close();
        // Recalculate from persisted marks; never increment the result score blindly.
        $answerId = (int)$old['answer_id'];
        $stmt = $db->prepare("SELECT s.id submission_id,COALESCE(SUM(m.awarded_marks),0) total_marks,COALESCE(SUM(q.maximum_marks),0) maximum_marks
            FROM theory_answers a
            JOIN theory_submissions s ON s.id=a.submission_id
            JOIN theory_questions q ON q.id=a.theory_question_id
            LEFT JOIN theory_marks m ON m.answer_id=a.id
            WHERE s.id=(SELECT submission_id FROM theory_answers WHERE id=?) GROUP BY s.id");
        $stmt->bind_param('i', $answerId);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($totals) {
            $submissionId = (int)$totals['submission_id'];
            $totalMarks = (float)$totals['total_marks'];
            $maximumMarks = (float)$totals['maximum_marks'];
            $stmt = $db->prepare("UPDATE theory_submissions SET total_marks=?,maximum_marks=? WHERE id=?");
            $stmt->bind_param('ddi', $totalMarks, $maximumMarks, $submissionId);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare("UPDATE results r
                JOIN theory_grading_jobs j ON j.result_id=r.id
                SET r.theory_score=?,
                    r.score=COALESCE(r.objective_score,r.score-COALESCE(r.theory_score,0))+?
                WHERE j.submission_id=? AND r.theory_grading_status='completed'");
            $stmt->bind_param('ddi', $totalMarks, $totalMarks, $submissionId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
$stmt = $db->prepare("SELECT s.id,s.student_id,st.full_name,s.status,s.grading_status,s.total_marks,s.maximum_marks,j.status AS queue_status,j.attempt_count AS queue_attempts,j.last_error AS queue_error FROM theory_submissions s JOIN students st ON st.id=s.student_id LEFT JOIN theory_grading_jobs j ON j.submission_id=s.id WHERE s.theory_assessment_id=? ORDER BY s.created_at DESC");
$stmt->bind_param('i', $assessment['id']);
$stmt->execute();
$subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Theory Review | Examcenter</title>
<link rel="stylesheet" href="../css/bootstrap.min.css">
<style>
:root{--review-ink:#172b4d;--review-muted:#64748b;--review-line:#e2e8f0;--review-blue:#2459a6}
body{min-height:100vh;background:radial-gradient(ellipse at 90% 0%,rgba(50,105,190,.09),transparent 34rem),#f3f6fb;color:var(--review-ink)}
.review-shell{max-width:1240px}
.review-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:24px}
.review-eyebrow{text-transform:uppercase;font-size:.72rem;letter-spacing:.12em;font-weight:800;color:var(--review-blue);margin-bottom:7px}
.review-head h1{font-size:clamp(1.55rem,3vw,2rem);font-weight:800;letter-spacing:-.04em;margin:0 0 7px}
.review-head p{margin:0;color:var(--review-muted)}
.submission-card{background:#fff;border:1px solid var(--review-line);border-radius:17px;box-shadow:0 9px 26px rgba(20,43,77,.045);overflow:hidden;margin-bottom:22px}
.submission-head{padding:18px 21px;border-bottom:1px solid var(--review-line);display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.submission-head h2{font-size:1.05rem;font-weight:800;margin:0 0 5px}
.submission-meta{font-size:.82rem;color:var(--review-muted)}
.submission-body{padding:20px}
.review-error{padding:12px 14px;background:#fff4f3;border:1px solid #f3cbc7;color:#912018;border-radius:11px;font-size:.88rem}
.question-review{border:1px solid var(--review-line);border-radius:14px;margin-bottom:16px;overflow:hidden}
.question-review:last-child{margin-bottom:0}
.question-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 17px;background:#f8fafd;border-bottom:1px solid var(--review-line)}
.question-head h3{font-size:.95rem;font-weight:800;margin:0}
.question-body{padding:18px}
.question-prompt{font-weight:650;line-height:1.65;margin-bottom:16px;white-space:pre-wrap}
.answer-block{padding:14px 15px;border-radius:11px;background:#f7f9fc;border:1px solid #e6ebf2;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}
.answer-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:var(--review-muted);margin-bottom:7px}
.mark-summary{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:16px 0}
.mark-number{font-size:1.25rem;font-weight:850;letter-spacing:-.03em;color:var(--review-blue)}
.ai-feedback{border-left:3px solid #9dbbe4;background:#f5f8fd;padding:13px 15px;border-radius:0 10px 10px 0;margin-top:12px;font-size:.9rem;line-height:1.6}
.ai-feedback p:last-child{margin-bottom:0}
.review-form{display:grid;grid-template-columns:minmax(110px,.35fr) minmax(220px,1fr) auto;gap:12px;align-items:end;padding:16px;background:#fbfcfe;border-top:1px solid var(--review-line)}
.review-form label{font-size:.78rem;font-weight:750;color:#475569;display:block;margin-bottom:6px}
.review-form .form-control{border-radius:9px;border-color:#d5deea}
.review-form .btn{border-radius:9px;font-weight:750;padding:10px 15px;white-space:nowrap}
.empty-review{background:#fff;border:1px dashed #cbd5e1;border-radius:16px;text-align:center;padding:42px 20px;color:var(--review-muted)}
@media(max-width:720px){.review-head{flex-direction:column}.review-head .btn{width:100%}.review-form{grid-template-columns:1fr}.review-form .btn{width:100%}.submission-body{padding:13px}.question-body{padding:14px}}
</style>
</head>
<body>
<main class="container review-shell py-4 py-lg-5">
<header class="review-head"><div><div class="review-eyebrow">Assessment operations · Teacher review</div><h1><?= htmlspecialchars($assessment['title'], ENT_QUOTES, 'UTF-8') ?> — Theory review</h1><p>Inspect submitted answers, review AI feedback, and confirm or adjust marks.</p></div><a class="btn btn-outline-primary" href="grade_theory.php?test_id=<?= $testId ?>">View grading queue</a></header>
<div class="d-flex flex-wrap gap-2 mb-4"><span class="badge rounded-pill text-bg-light border px-3 py-2"><?= count($subs) ?> submission<?= count($subs)===1?'':'s' ?></span><span class="badge rounded-pill text-bg-info px-3 py-2">Teacher approval recommended</span></div>
<?php if (!$subs): ?><div class="empty-review"><div class="display-6 mb-2" aria-hidden="true">—</div><h2 class="h5 fw-bold">No submissions yet</h2><p class="mb-0">Student theory responses will appear here after they submit their exam.</p></div><?php endif; ?>
<?php foreach ($subs as $s): ?>
<section class="submission-card">
<header class="submission-head"><div><h2><?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?></h2><div class="submission-meta">Submission #<?= (int)$s['id'] ?> · <?= htmlspecialchars((string)$s['status'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div class="d-flex gap-2 flex-wrap"><span class="badge rounded-pill text-bg-<?= ($s['grading_status'] ?? '')==='completed'?'success':((($s['grading_status'] ?? '')==='failed')?'danger':'warning') ?>"><?= htmlspecialchars((string)$s['grading_status'], ENT_QUOTES, 'UTF-8') ?></span><span class="badge rounded-pill text-bg-secondary">Queue: <?= htmlspecialchars((string)($s['queue_status'] ?? 'not queued'), ENT_QUOTES, 'UTF-8') ?></span><?php if ($s['maximum_marks'] !== null): ?><span class="badge rounded-pill text-bg-light border"><?= htmlspecialchars((string)$s['total_marks']) ?> / <?= htmlspecialchars((string)$s['maximum_marks']) ?> marks</span><?php endif; ?></div></header>
<div class="submission-body">
<?php if (!empty($s['queue_error'])): ?><div class="review-error mb-3"><strong>Last grading error</strong><div class="mt-1"><?= htmlspecialchars((string)$s['queue_error'], ENT_QUOTES, 'UTF-8') ?></div><a class="btn btn-sm btn-outline-danger mt-2" href="grade_theory.php?test_id=<?= $testId ?>">Open queue to retry</a></div><?php endif; ?>
<?php $st = $db->prepare("SELECT a.id,a.answer_text,q.question_number,q.question_text,q.maximum_marks,m.id mark_id,m.awarded_marks,m.status,m.explanation,m.confidence,m.weaknesses,m.missing_points FROM theory_answers a JOIN theory_questions q ON q.id=a.theory_question_id LEFT JOIN theory_marks m ON m.answer_id=a.id WHERE a.submission_id=? ORDER BY q.question_number");
$st->bind_param('i', $s['id']); $st->execute(); $marks = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); ?>
<?php if (!$marks): ?><p class="text-muted mb-0">No saved answers were found for this submission.</p><?php endif; ?>
<?php foreach ($marks as $m): ?>
<article class="question-review">
<header class="question-head"><h3>Question <?= htmlspecialchars((string)$m['question_number']) ?></h3><span class="badge rounded-pill text-bg-light border"><?= htmlspecialchars((string)$m['maximum_marks']) ?> marks available</span></header>
<div class="question-body"><div class="question-prompt"><?= nl2br(htmlspecialchars((string)$m['question_text'], ENT_QUOTES, 'UTF-8')) ?></div>
<div class="answer-label">Student response</div><div class="answer-block"><?php if (trim((string)$m['answer_text']) !== ''): ?><?= nl2br(htmlspecialchars((string)$m['answer_text'], ENT_QUOTES, 'UTF-8')) ?><?php else: ?><span class="text-muted">No answer supplied</span><?php endif; ?></div>
<div class="mark-summary"><div><div class="answer-label">AI suggested mark</div><div class="mark-number"><?= htmlspecialchars((string)($m['awarded_marks'] ?? 'Pending')) ?> <span class="fs-6 text-muted fw-normal">/ <?= htmlspecialchars((string)$m['maximum_marks']) ?></span></div></div><?php if ($m['status']): ?><span class="badge rounded-pill text-bg-<?= $m['status']==='teacher_final'?'success':'secondary' ?>"><?= htmlspecialchars((string)$m['status'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php if ($m['confidence'] !== null): ?><span class="badge rounded-pill text-bg-light border">Confidence <?= htmlspecialchars((string)round((float)$m['confidence']*100,1)) ?>%</span><?php endif; ?></div>
<?php if (!empty($m['explanation']) || !empty($m['weaknesses']) || !empty($m['missing_points'])): ?><div class="ai-feedback"><?php if (!empty($m['explanation'])): ?><p><strong>Grading explanation</strong><br><?= nl2br(htmlspecialchars((string)$m['explanation'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?><?php if (!empty($m['weaknesses'])): ?><p><strong>Potential weaknesses</strong><br><?= nl2br(htmlspecialchars((string)$m['weaknesses'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?><?php if (!empty($m['missing_points'])): ?><p><strong>Points to review</strong><br><?= nl2br(htmlspecialchars((string)$m['missing_points'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?></div><?php endif; ?>
</div>
<?php if ($m['mark_id']): ?><form method="post" class="review-form"><div><label for="final-mark-<?= (int)$m['mark_id'] ?>">Final mark</label><input id="final-mark-<?= (int)$m['mark_id'] ?>" class="form-control" type="number" name="final_mark" min="0" max="<?= htmlspecialchars((string)$m['maximum_marks']) ?>" step="0.01" value="<?= htmlspecialchars((string)$m['awarded_marks']) ?>" required></div><div><label for="review-reason-<?= (int)$m['mark_id'] ?>">Reason for adjustment / confirmation</label><input id="review-reason-<?= (int)$m['mark_id'] ?>" class="form-control" name="reason" placeholder="Explain why this mark is final" required></div><input type="hidden" name="mark_id" value="<?= (int)$m['mark_id'] ?>"><button class="btn btn-primary" type="submit" name="save_mark" value="1">Save final mark</button></form><?php endif; ?>
</article>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
</main>
</body>
</html>