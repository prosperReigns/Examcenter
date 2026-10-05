<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/pro.php';
require_once '../theory/TheoryGradingService.php';
require_once '../theory/OllamaTheoryGrader.php';

if(!isset($_SESSION['user_id'])||strtolower((string)($_SESSION['user_role']??''))!=='teacher'){http_response_code(403);exit('Unauthorized');}
require_pro('ai_theory','Offline AI theory grading is an Examcenter Pro feature.');

$db=Database::connection();$testId=(int)($_GET['test_id']??0);
$stmt=$db->prepare("SELECT ta.id,t.title FROM theory_assessments ta JOIN tests t ON t.id=ta.test_id WHERE ta.test_id=? LIMIT 1");$stmt->bind_param('i',$testId);$stmt->execute();$assessment=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$assessment)exit('Assessment not found.');

$stmt=$db->prepare("SELECT id FROM theory_submissions WHERE theory_assessment_id=? AND status='submitted' ORDER BY id");$stmt->bind_param('i',$assessment['id']);$stmt->execute();$subs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$model=(string)(getenv('EXAMCENTER_THEORY_MODEL')?:'qwen1.7b');$grader=new OllamaTheoryGrader('http://127.0.0.1:11434',$model,300);$service=new TheoryGradingService($grader);
$done=0;$failed=0;$messages=[];
foreach($subs as $sub){
 $stmt=$db->prepare("SELECT id FROM theory_answers WHERE submission_id=? ORDER BY id");$stmt->bind_param('i',$sub['id']);$stmt->execute();$answers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
 $total=0.0;$max=0.0;$error='';
 foreach($answers as $a){try{$r=$service->gradeAnswer((int)$a['id']);$total+=(float)$r['awarded_marks'];$max+=(float)$r['maximum_marks'];}catch(Throwable $e){$error=$e->getMessage();break;}}
 if($error===''){ $stmt=$db->prepare("UPDATE theory_submissions SET status='graded',grading_status='completed',total_marks=?,maximum_marks=? WHERE id=?");$stmt->bind_param('ddi',$total,$max,$sub['id']);$stmt->execute();$stmt->close();$done++; } else {$stmt=$db->prepare("UPDATE theory_submissions SET grading_status='failed' WHERE id=?");$stmt->bind_param('i',$sub['id']);$stmt->execute();$stmt->close();$failed++;$messages[]=$error;}
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>AI Theory Grading</title></head><body><h2>Offline AI grading</h2><p>Completed: <?=$done?> | Failed: <?=$failed?></p><?php foreach($messages as $m): ?><p><?=htmlspecialchars($m)?></p><?php endforeach; ?><p><a href="theory_review.php?test_id=<?=$testId?>">Return to review</a></p></body></html>