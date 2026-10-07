<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function failTest(string $message): never { fwrite(STDERR, "[FAIL] {$message}\n"); exit(1); }
function assertTrue(bool $condition, string $message): void { if (!$condition) failTest($message); }

$host=getenv('EXAMCENTER_DB_HOST')?:'127.0.0.1'; $user=getenv('EXAMCENTER_DB_USER')?:'root';
$password=getenv('EXAMCENTER_DB_PASSWORD')?:''; $database=getenv('EXAMCENTER_DB_NAME')?:'cbt_app_ci';
$port=(int)(getenv('EXAMCENTER_DB_PORT')?:3306);

$server=new mysqli($host,$user,$password,'',$port);
if($server->connect_error) failTest('MariaDB connection failed: '.$server->connect_error);
$server->set_charset('utf8mb4');
$safeDb=$server->real_escape_string($database);
if(!$server->query("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"))
    failTest('Could not create test database: '.$server->error);
$server->close();

$db=Database::connection();
$fixture=<<<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS pro_coding_submissions, pro_coding_questions, pro_question_bank_items,
theory_manual_reviews, theory_marks, theory_answers, theory_submissions, theory_questions, theory_assessments,
assessment_assignments, exam_attempt_events, exam_attempt_sessions, exam_attempts, results, new_questions, tests,
admins, teachers, students, classes;
SET FOREIGN_KEY_CHECKS=1;
CREATE TABLE classes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE students (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE teachers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE admins (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE tests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(255) NOT NULL,duration INT NOT NULL DEFAULT 30,
 academic_level_id INT NULL,subject VARCHAR(255) NULL,year VARCHAR(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE new_questions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,test_id INT NOT NULL,question_type VARCHAR(80) NOT NULL,question_text LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE exam_attempts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,test_id INT NOT NULL,time_left INT NOT NULL DEFAULT 0,
 current_index INT NOT NULL DEFAULT 0,started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE results (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,test_id INT NOT NULL,score INT NOT NULL DEFAULT 0,
 total_questions INT NOT NULL DEFAULT 0,created_at DATETIME NULL,status VARCHAR(30) NULL,reattempt_approved TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
if(!$db->multi_query($fixture)) failTest('Legacy fixture failed: '.$db->error);
while($db->more_results() && $db->next_result()) { if($db->errno) failTest('Legacy fixture statement failed: '.$db->error); }
if($db->errno) failTest('Legacy fixture failed: '.$db->error);

require __DIR__.'/../database/migrate.php';

$requiredTables=['institution_types','institutions','organizational_unit_types','organizational_units','academic_periods',
'programmes','programme_levels','people','institution_memberships','unit_memberships','courses','course_assignments',
'assessment_groups','assessment_group_assignments','universal_assessment_context','exam_attempt_sessions',
'exam_attempt_events','core_feature_catalog','theory_assessments','pro_feature_catalog'];
foreach($requiredTables as $table){
  $safe=$db->real_escape_string($table); $result=$db->query("SHOW TABLES LIKE '{$safe}'");
  assertTrue($result && $result->num_rows===1,"Missing migrated table: {$table}"); $result?->free();
}
function insertId(mysqli_stmt $stmt): int { $id=(int)$stmt->insert_id; $stmt->close(); assertTrue($id>0,'Expected inserted row id.'); return $id; }

$stmt=$db->prepare("SELECT id FROM institution_types WHERE code='secondary_school' LIMIT 1"); $stmt->execute(); $type=$stmt->get_result()->fetch_assoc(); $stmt->close();
assertTrue((bool)$type,'Seeded institution type missing.');
$code='ci_universal_'.bin2hex(random_bytes(4)); $name='CI Universal Institution';
$stmt=$db->prepare("INSERT INTO institutions (institution_type_id,name,code) VALUES (?,?,?)"); $stmt->bind_param('iss',$type['id'],$name,$code); $institutionId=insertId($stmt);

$stmt=$db->prepare("SELECT id FROM organizational_unit_types WHERE code='department' LIMIT 1"); $stmt->execute(); $unitType=$stmt->get_result()->fetch_assoc(); $stmt->close();
assertTrue((bool)$unitType,'Seeded organizational unit type missing.');
$unitCode='UNIT-CI'; $unitName='Assessment Department';
$stmt=$db->prepare("INSERT INTO organizational_units (institution_id,unit_type_id,code,name) VALUES (?,?,?,?)"); $stmt->bind_param('iiss',$institutionId,$unitType['id'],$unitCode,$unitName); $unitId=insertId($stmt);

$programmeCode='PROG-CI'; $programmeName='Universal Assessment Programme';
$stmt=$db->prepare("INSERT INTO programmes (institution_id,code,name) VALUES (?,?,?)"); $stmt->bind_param('iss',$institutionId,$programmeCode,$programmeName); $programmeId=insertId($stmt);

$levelCode='LEVEL-1'; $levelName='Level One'; $sort=1;
$stmt=$db->prepare("INSERT INTO programme_levels (programme_id,organizational_unit_id,code,name,sort_order) VALUES (?,?,?,?,?)"); $stmt->bind_param('iissi',$programmeId,$unitId,$levelCode,$levelName,$sort); $levelId=insertId($stmt);

$periodCode='2026-CI'; $periodName='2026 Academic Year'; $periodType='academic_year'; $status='active';
$stmt=$db->prepare("INSERT INTO academic_periods (institution_id,code,name,period_type,status) VALUES (?,?,?,?,?)"); $stmt->bind_param('issss',$institutionId,$periodCode,$periodName,$periodType,$status); $periodId=insertId($stmt);

$courseCode='COURSE-CI'; $courseName='General Assessment';
$stmt=$db->prepare("INSERT INTO courses (institution_id,programme_id,code,name) VALUES (?,?,?,?)"); $stmt->bind_param('iiss',$institutionId,$programmeId,$courseCode,$courseName); $courseId=insertId($stmt);

$personName='CI Candidate'; $externalRef='CAND-CI-001';
$stmt=$db->prepare("INSERT INTO people (institution_id,display_name,external_ref) VALUES (?,?,?)"); $stmt->bind_param('iss',$institutionId,$personName,$externalRef); $personId=insertId($stmt);

$role='candidate';
$stmt=$db->prepare("INSERT INTO institution_memberships (institution_id,person_id,role_code) VALUES (?,?,?)"); $stmt->bind_param('iis',$institutionId,$personId,$role); $membershipId=insertId($stmt);

$membershipRole='candidate';
$stmt=$db->prepare("INSERT INTO unit_memberships (unit_id,person_id,membership_role) VALUES (?,?,?)"); $stmt->bind_param('iis',$unitId,$personId,$membershipRole); insertId($stmt);

$assignmentRole='teacher';
$stmt=$db->prepare("INSERT INTO course_assignments (course_id,unit_id,person_id,academic_period_id,role_code) VALUES (?,?,?,?,?)"); $stmt->bind_param('iiiis',$courseId,$unitId,$personId,$periodId,$assignmentRole); insertId($stmt);

$groupCode='GROUP-CI'; $groupName='Continuous Assessment'; $groupType='continuous'; $weighting=40.0;
$stmt=$db->prepare("INSERT INTO assessment_groups (institution_id,code,name,group_type,weighting) VALUES (?,?,?,?,?)"); $stmt->bind_param('isssd',$institutionId,$groupCode,$groupName,$groupType,$weighting); $groupId=insertId($stmt);

$stmt=$db->prepare("INSERT INTO assessment_group_assignments (assessment_group_id,unit_id,programme_id,academic_period_id,course_id) VALUES (?,?,?,?,?)"); $stmt->bind_param('iiiii',$groupId,$unitId,$programmeId,$periodId,$courseId); insertId($stmt);

$testTitle='Universal E2E Test'; $duration=30; $subject='General Assessment'; $year='2026';
$stmt=$db->prepare("INSERT INTO tests (title,duration,subject,year,institution_id,organizational_unit_id,programme_id,programme_level_id,academic_period_id,course_id,assessment_group_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$stmt->bind_param('sisssiiiiii',$testTitle,$duration,$subject,$year,$institutionId,$unitId,$programmeId,$levelId,$periodId,$courseId,$groupId); $testId=insertId($stmt);

$stmt=$db->prepare("INSERT INTO universal_assessment_context (test_id,institution_id,unit_id,programme_id,academic_period_id,course_id,assessment_group_id) VALUES (?,?,?,?,?,?,?)"); $stmt->bind_param('iiiiiii',$testId,$institutionId,$unitId,$programmeId,$periodId,$courseId,$groupId); insertId($stmt);

$qType='true_false'; $qText='MariaDB integration question';
$stmt=$db->prepare("INSERT INTO new_questions (test_id,question_type,question_text) VALUES (?,?,?)"); $stmt->bind_param('iss',$testId,$qType,$qText); $questionId=insertId($stmt);

$userId=1; $timeLeft=1800; $currentIndex=0;
$stmt=$db->prepare("INSERT INTO exam_attempts (user_id,test_id,time_left,current_index) VALUES (?,?,?,?)"); $stmt->bind_param('iiii',$userId,$testId,$timeLeft,$currentIndex); $attemptId=insertId($stmt);

$attemptNo=1;
$stmt=$db->prepare("INSERT INTO exam_attempt_sessions (user_id,test_id,attempt_no,institution_membership_id,started_at,last_activity_at,expires_at,status) VALUES (?,?,?,?,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 30 MINUTE),'in_progress')");
$stmt->bind_param('iiii',$userId,$testId,$attemptNo,$membershipId); $attemptSessionId=insertId($stmt);

$eventType='answer_saved'; $metadata='{"source":"integration"}';
$stmt=$db->prepare("INSERT INTO exam_attempt_events (attempt_session_id,event_type,question_id,metadata) VALUES (?,?,?,?)"); $stmt->bind_param('isis',$attemptSessionId,$eventType,$questionId,$metadata); insertId($stmt);

$score=1; $totalQuestions=1; $resultStatus='completed';
$stmt=$db->prepare("INSERT INTO results (user_id,test_id,score,total_questions,status,institution_membership_id,academic_period_id) VALUES (?,?,?,?,?,?,?)"); $stmt->bind_param('iiiisii',$userId,$testId,$score,$totalQuestions,$resultStatus,$membershipId,$periodId); insertId($stmt);

$join=$db->query("SELECT i.name institution_name,ou.name unit_name,p.name programme_name,pl.name level_name,ap.name period_name,c.name course_name,t.title test_title,ims.person_id FROM universal_assessment_context uac JOIN institutions i ON i.id=uac.institution_id JOIN organizational_units ou ON ou.id=uac.unit_id JOIN programmes p ON p.id=uac.programme_id JOIN programme_levels pl ON pl.id=uac.programme_level_id JOIN academic_periods ap ON ap.id=uac.academic_period_id JOIN courses c ON c.id=uac.course_id JOIN tests t ON t.id=uac.test_id JOIN institution_memberships ims ON ims.institution_id=i.id WHERE uac.test_id={$testId} AND ims.id={$membershipId} LIMIT 1")->fetch_assoc();
assertTrue($join && $join['institution_name']===$name,'Universal context join failed.');
assertTrue($join['unit_name']===$unitName,'Organizational unit relation failed.');
assertTrue($join['programme_name']===$programmeName,'Programme relation failed.');
assertTrue($join['level_name']===$levelName,'Programme level relation failed.');
assertTrue($join['period_name']===$periodName,'Academic period relation failed.');
assertTrue($join['course_name']===$courseName,'Course relation failed.');
assertTrue($join['test_title']===$testTitle,'Assessment relation failed.');
assertTrue((int)$join['person_id']===$personId,'Membership relation failed.');

$db->query("ALTER TABLE people ADD COLUMN person_code VARCHAR(150) NULL");
$legacyRef='LEGACY-CI-001'; $legacyName='Legacy Reconciled Person';
$stmt=$db->prepare("INSERT INTO people (institution_id,display_name,person_code) VALUES (?,?,?)"); $stmt->bind_param('iss',$institutionId,$legacyName,$legacyRef); $legacyPersonId=insertId($stmt);
$GLOBALS['db']=$db; require __DIR__.'/../database/migrations/20261007_0005_legacy_universal_reconciliation.php'; unset($GLOBALS['db']);
$stmt=$db->prepare("SELECT external_ref FROM people WHERE id=?"); $stmt->bind_param('i',$legacyPersonId); $stmt->execute(); $legacyPerson=$stmt->get_result()->fetch_assoc(); $stmt->close();
assertTrue(($legacyPerson['external_ref']??null)===$legacyRef,'Legacy reconciliation did not backfill people.external_ref.');

echo "MariaDB Universal E2E verification PASSED\n";
echo "Institution={$institutionId} Unit={$unitId} Programme={$programmeId} Level={$levelId} Period={$periodId} Course={$courseId} Test={$testId} Candidate={$personId}\n";
