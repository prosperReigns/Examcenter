<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../license/license_guard.php';

if(!isset($_SESSION['student_id'])){header('Location: theory_register.php');exit;}
$db=Database::connection();$testId=(int)($_GET['test_id']??$_SESSION['theory_test_id']??0);
$stmt=$db->prepare("SELECT ta.id assessment_id,t.title,t.subject,ta.instructions FROM theory_assessments ta JOIN tests t ON t.id=ta.test_id WHERE ta.test_id=? LIMIT 1");
$stmt->bind_param('i',$testId);$stmt->execute();$test=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$test)die('Assessment not found.');
$stmt=$db->prepare("SELECT id,question_number,question_text,maximum_marks FROM theory_questions WHERE theory_assessment_id=? ORDER BY question_number");
$stmt->bind_param('i',$test['assessment_id']);$stmt->execute();$questions=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
if(!$questions)die('No theory questions have been added.');
$stmt=$db->prepare("SELECT id,status FROM theory_submissions WHERE theory_assessment_id=? AND student_id=? LIMIT 1");$sid=(int)$_SESSION['student_id'];$stmt->bind_param('ii',$test['assessment_id'],$sid);$stmt->execute();$submission=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$submission){$stmt=$db->prepare("INSERT INTO theory_submissions(theory_assessment_id,student_id,started_at,status,maximum_marks) SELECT ?,?,NOW(),'in_progress',COALESCE(SUM(maximum_marks),0) FROM theory_questions WHERE theory_assessment_id=?");$stmt->bind_param('iii',$test['assessment_id'],$sid,$test['assessment_id']);$stmt->execute();$submission=['id'=>$stmt->insert_id,'status'=>'in_progress'];$stmt->close();}
if($submission['status']==='submitted'||$submission['status']==='graded'){\n ?><!doctype html><html><head><meta charset="utf-8"><title>Theory Submitted</title></head><body><h2>Theory examination submitted</h2><p>Your answers are stored locally on this computer. AI grading is a separate local process and remains provisional until a teacher reviews it.</p><a href="theory_result.php?test_id=<?=$testId?>">View local result</a> | <a href="../login.php">Return</a></body></html><?php exit;}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['submit_theory'])){
 foreach($questions as $q){$answer=trim((string)($_POST['answer'][$q['id']]??''));$wc=$answer===''?0:count(preg_split('/\s+/u',$answer));$stmt=$db->prepare("INSERT INTO theory_answers(submission_id,theory_question_id,answer_text,word_count,submitted_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text),word_count=VALUES(word_count),submitted_at=NOW()");$stmt->bind_param('iisi',$submission['id'],$q['id'],$answer,$wc);$stmt->execute();$stmt->close();}
 $stmt=$db->prepare("UPDATE theory_submissions SET status='submitted',submitted_at=NOW(),grading_status='queued' WHERE id=?");$stmt->bind_param('i',$submission['id']);$stmt->execute();$stmt->close();
 header('Location: theory_exam.php?test_id='.$testId);exit;
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Theory Exam</title></head><body>
<h2><?=htmlspecialchars($test['title'])?></h2><p>Student: <?=htmlspecialchars($_SESSION['student_name']??'Student')?></p>
<p><strong>Offline theory exam:</strong> write your own answers. There is no answer key supplied by the teacher.</p>
<form method="post"><?php foreach($questions as $q): ?><section><h3>Q<?=$q['question_number']?> — <?=$q['maximum_marks']?> marks</h3><p><?=nl2br(htmlspecialchars($q['question_text']))?></p><textarea name="answer[<?=$q['id']?>]" rows="8" cols="80" required></textarea></section><?php endforeach; ?><button name="submit_theory" onclick="return confirm('Submit your theory answers?')">Submit Theory Exam</button></form>
</body></html>