<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../license/license_guard.php';

if(!isset($_SESSION['student_id'])){header('Location: theory_register.php');exit;}
$db=Database::connection();$testId=(int)($_GET['test_id']??$_SESSION['theory_test_id']??0);$studentId=(int)$_SESSION['student_id'];
$stmt=$db->prepare("SELECT s.id,s.status,s.grading_status,s.total_marks,s.maximum_marks,t.title,t.subject FROM theory_submissions s JOIN theory_assessments ta ON ta.id=s.theory_assessment_id JOIN tests t ON t.id=ta.test_id WHERE ta.test_id=? AND s.student_id=? LIMIT 1");$stmt->bind_param('ii',$testId,$studentId);$stmt->execute();$submission=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$submission)die('No theory submission found.');
$stmt=$db->prepare("SELECT q.question_number,q.maximum_marks,a.answer_text,m.awarded_marks,m.status,m.explanation FROM theory_answers a JOIN theory_questions q ON q.id=a.theory_question_id LEFT JOIN theory_marks m ON m.answer_id=a.id WHERE a.submission_id=? ORDER BY q.question_number");$stmt->bind_param('i',$submission['id']);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Theory Result</title></head><body>
<h2><?=htmlspecialchars($submission['title'])?> — Theory Result</h2>
<p>Student: <?=htmlspecialchars($_SESSION['student_name']??'Student')?></p>
<p>Status: <?=htmlspecialchars($submission['grading_status'])?></p>
<?php if($submission['total_marks']!==null): ?><h3>Score: <?=htmlspecialchars((string)$submission['total_marks'])?> / <?=htmlspecialchars((string)$submission['maximum_marks'])?></h3><?php else: ?><p>AI grading has not completed yet. Marks remain unavailable until the local grading process runs.</p><?php endif; ?>
<?php foreach($rows as $r): ?><article><h4>Q<?=$r['question_number']?> — <?=$r['maximum_marks']?> marks</h4><p><strong>Your answer:</strong><br><?=nl2br(htmlspecialchars($r['answer_text']))?></p><?php if($r['awarded_marks']!==null): ?><p><strong><?=htmlspecialchars((string)$r['status'])?>:</strong> <?=htmlspecialchars((string)$r['awarded_marks'])?> / <?=htmlspecialchars((string)$r['maximum_marks'])?></p><p><?=nl2br(htmlspecialchars((string)$r['explanation']))?></p><?php endif; ?></article><?php endforeach; ?>
</body></html>