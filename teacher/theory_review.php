<?php

declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/pro.php';
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'teacher') {
    header('Location: ../login.php?error=Not logged in');
    exit;
}
require_pro('ai_theory', 'Offline AI theory review is an Examcenter Pro feature.');
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
    $stmt = $db->prepare("SELECT awarded_marks,maximum_marks FROM theory_marks WHERE id=? LIMIT 1");
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
    }
}
$stmt = $db->prepare("SELECT s.id,s.student_id,st.full_name,s.status,s.grading_status,s.total_marks,s.maximum_marks FROM theory_submissions s JOIN students st ON st.id=s.student_id WHERE s.theory_assessment_id=? ORDER BY s.created_at DESC");
$stmt->bind_param('i', $assessment['id']);
$stmt->execute();
$subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Theory Review</title>
</head>

<body>
    <h2><?= htmlspecialchars($assessment['title']) ?> — Theory Review</h2>
    <p><a href="grade_theory.php?test_id=<?= $testId ?>">Run local AI grading for submitted answers</a></p>
    <?php foreach ($subs as $s): ?><h3><?= htmlspecialchars($s['full_name']) ?> — <?= htmlspecialchars((string)$s['status']) ?> / <?= htmlspecialchars((string)$s['grading_status']) ?></h3>
        <?php $st = $db->prepare("SELECT a.id,a.answer_text,q.question_number,q.question_text,q.maximum_marks,m.id mark_id,m.awarded_marks,m.status,m.explanation FROM theory_answers a JOIN theory_questions q ON q.id=a.theory_question_id LEFT JOIN theory_marks m ON m.answer_id=a.id WHERE a.submission_id=? ORDER BY q.question_number");
        $st->bind_param('i', $s['id']);
        $st->execute();
        $marks = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        foreach ($marks as $m): ?><article><b>Q<?= $m['question_number'] ?> / <?= $m['maximum_marks'] ?> marks</b>
                <p><?= nl2br(htmlspecialchars($m['question_text'])) ?></p>
                <p><strong>Student:</strong> <?= nl2br(htmlspecialchars($m['answer_text'])) ?></p>
                <p><strong>AI provisional:</strong> <?= htmlspecialchars((string)$m['awarded_marks']) ?> — <?= nl2br(htmlspecialchars((string)$m['explanation'])) ?></p><?php if ($m['mark_id']): ?><form method="post"><input type="hidden" name="mark_id" value="<?= $m['mark_id'] ?>"><input type="number" name="final_mark" min="0" max="<?= $m['maximum_marks'] ?>" step="0.01" value="<?= $m['awarded_marks'] ?>"><input name="reason" placeholder="Reason for teacher review" required><button name="save_mark">Save final mark</button></form><?php endif; ?>
            </article><?php endforeach; ?><?php endforeach; ?>
</body>

</html>