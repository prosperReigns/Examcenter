<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/pro.php';
require_once '../theory/TheoryGradingService.php';
require_once '../theory/LlamaCppRuntime.php';
require_once '../theory/LlamaCppTheoryGrader.php';

if(!isset($_SESSION['user_id'])||strtolower((string)($_SESSION['user_role']??''))!=='teacher'){http_response_code(403);exit('Unauthorized');}
// Pro guard intentionally disabled during development; restore before production.

$db=Database::connection();$testId=(int)($_GET['test_id']??0);
$stmt=$db->prepare("SELECT ta.id,t.title FROM theory_assessments ta JOIN tests t ON t.id=ta.test_id WHERE ta.test_id=? LIMIT 1");$stmt->bind_param('i',$testId);$stmt->execute();$assessment=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$assessment)exit('Assessment not found.');

$stmt=$db->prepare("SELECT id,student_id FROM theory_submissions WHERE theory_assessment_id=? AND status='submitted' ORDER BY id");$stmt->bind_param('i',$assessment['id']);$stmt->execute();$subs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$endpoint=(string)(getenv('EXAMCENTER_LLAMA_ENDPOINT')?:'http://127.0.0.1:8080');$model=(string)(getenv('EXAMCENTER_LLAMA_MODEL_ALIAS')?:'qwen3-1.7b');$runtime=new LlamaCppRuntime($endpoint);$grader=new LlamaCppTheoryGrader($runtime,$endpoint,$model,300);$service=new TheoryGradingService($grader);
$done=0;$failed=0;$messages=[];
foreach($subs as $sub){
 $stmt=$db->prepare("SELECT id FROM theory_answers WHERE submission_id=? ORDER BY id");$stmt->bind_param('i',$sub['id']);$stmt->execute();$answers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
 $total=0.0;$max=0.0;$error='';
 foreach($answers as $a){try{$r=$service->gradeAnswer((int)$a['id']);$total+=(float)$r['awarded_marks'];$max+=(float)$r['maximum_marks'];}catch(Throwable $e){$error=$e->getMessage();break;}}
 if($error===''){ $stmt=$db->prepare("UPDATE theory_submissions SET status='graded',grading_status='completed',total_marks=?,maximum_marks=? WHERE id=?");$stmt->bind_param('ddi',$total,$max,$sub['id']);$stmt->execute();$stmt->close(); $resultStmt=$db->prepare("SELECT id FROM results WHERE user_id=? AND test_id=? ORDER BY created_at DESC,id DESC LIMIT 1");$resultStmt->bind_param('ii',$sub['student_id'],$testId);$resultStmt->execute();$resultRow=$resultStmt->get_result()->fetch_assoc();$resultStmt->close();if($resultRow){$resultUpdate=$db->prepare("UPDATE results SET score=score+? WHERE id=?");$resultUpdate->bind_param('di',$total,$resultRow['id']);$resultUpdate->execute();$resultUpdate->close();}$done++; } else {$stmt=$db->prepare("UPDATE theory_submissions SET grading_status='failed' WHERE id=?");$stmt->bind_param('i',$sub['id']);$stmt->execute();$stmt->close();$failed++;$messages[]=$error;}
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>AI Theory Grading</title></head><body><h2>Offline AI grading</h2><p>Completed: <?=$done?> | Failed: <?=$failed?></p><?php foreach($messages as $m): ?><p><?=htmlspecialchars($m)?></p><?php endforeach; ?><p><a href="theory_review.php?test_id=<?=$testId?>">Return to review</a></p></body></html>