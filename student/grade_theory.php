<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../theory/TheoryGradingService.php';
require_once '../theory/OllamaTheoryGrader.php';
//require_once '../license/license_guard.php';

if(!isset($_SESSION['student_id'])){http_response_code(403);exit('Unauthorized');}
$db=Database::connection();$submissionId=(int)($_POST['submission_id']??$_GET['submission_id']??0);
if($submissionId<=0)exit('Invalid submission.');
$stmt=$db->prepare("SELECT s.id,s.status,s.grading_status,a.test_id FROM theory_submissions s JOIN theory_assessments a ON a.id=s.theory_assessment_id WHERE s.id=? AND s.student_id=? LIMIT 1");$studentId=(int)$_SESSION['student_id'];$stmt->bind_param('ii',$submissionId,$studentId);$stmt->execute();$sub=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$sub||!in_array($sub['status'],['submitted','grading','graded'],true)){exit('Submission is not ready for grading.');}
$stmt=$db->prepare("SELECT id FROM theory_answers WHERE submission_id=? ORDER BY id");$stmt->bind_param('i',$submissionId);$stmt->execute();$answers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$model=(string)(getenv('EXAMCENTER_THEORY_MODEL')?:'qwen3:1.7b');$grader=new OllamaTheoryGrader('http://127.0.0.1:11434',$model,300);$service=new TheoryGradingService($grader);
$stmt=$db->prepare("UPDATE theory_submissions SET status='grading',grading_status='running' WHERE id=?");$stmt->bind_param('i',$submissionId);$stmt->execute();$stmt->close();
$total=0.0;$max=0.0;$error='';
foreach($answers as $a){try{$r=$service->gradeAnswer((int)$a['id']);$total+=(float)$r['awarded_marks'];$max+=(float)$r['maximum_marks'];}catch(Throwable $e){$error=$e->getMessage();break;}}
if($error===''){$stmt=$db->prepare("UPDATE theory_submissions SET status='graded',grading_status='completed',total_marks=?,maximum_marks=? WHERE id=?");$stmt->bind_param('ddi',$total,$max,$submissionId);$stmt->execute();$stmt->close();echo 'Theory grading completed. You may now view the provisional result.';}else{$stmt=$db->prepare("UPDATE theory_submissions SET status='submitted',grading_status='failed' WHERE id=?");$stmt->bind_param('i',$submissionId);$stmt->execute();$stmt->close();http_response_code(503);echo 'Local AI grading failed: '.htmlspecialchars($error);}
