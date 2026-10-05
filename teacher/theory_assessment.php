<?php
declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/pro.php';

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'teacher') {
    header('Location: ../login.php?error=Not logged in'); exit;
}
require_pro('ai_theory', 'Offline AI theory assessment is an Examcenter Pro feature.');

$db = Database::connection();
$teacherId = (int)$_SESSION['user_id'];
$testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
if ($testId <= 0) die('A valid test is required.');

$stmt = $db->prepare("SELECT t.id,t.title,t.subject,t.year FROM tests t WHERE t.id=? LIMIT 1");
$stmt->bind_param('i',$testId); $stmt->execute(); $test=$stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$test) die('Test not found.');

$stmt=$db->prepare("SELECT id FROM theory_assessments WHERE test_id=? LIMIT 1");
$stmt->bind_param('i',$testId);$stmt->execute();$assessment=$stmt->get_result()->fetch_assoc();$stmt->close();

if (!$assessment) {
    $instructions = trim((string)($_POST['instructions'] ?? ''));
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_assessment'])) {
        $stmt=$db->prepare("INSERT INTO theory_assessments(test_id,instructions,ai_model,status) VALUES(?,?,?,'draft')");
        $model=(string)(getenv('EXAMCENTER_THEORY_MODEL') ?: 'llama3.2:3b');
        $stmt->bind_param('iss',$testId,$instructions,$model);
        $stmt->execute(); $assessment=['id'=>$stmt->insert_id]; $stmt->close();
    }
}
if (!$assessment) {
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Create Theory Assessment</title></head><body>
    <h2>Create offline AI theory assessment</h2>
    <p><?=htmlspecialchars($test['title'])?> — <?=htmlspecialchars($test['subject'])?></p>
    <p>Teachers provide only the question and maximum marks. No answer key, keywords or marking scheme is required.</p>
    <form method="post"><input type="hidden" name="test_id" value="<?=$testId?>">
    <textarea name="instructions" rows="5" cols="70" placeholder="Optional student instructions"></textarea><br>
    <button name="create_assessment">Create assessment</button></form>
    </body></html>
    <?php exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_question'])) {
    $text=trim((string)($_POST['question_text']??'')); $marks=(float)($_POST['maximum_marks']??0);
    if ($text!=='' && $marks>0) {
        $stmt=$db->prepare("SELECT COALESCE(MAX(question_number),0)+1 n FROM theory_questions WHERE theory_assessment_id=?");
        $stmt->bind_param('i',$assessment['id']);$stmt->execute();$n=(int)$stmt->get_result()->fetch_assoc()['n'];$stmt->close();
        $stmt=$db->prepare("INSERT INTO theory_questions(theory_assessment_id,question_number,question_text,maximum_marks) VALUES(?,?,?,?)");
        $stmt->bind_param('iisd',$assessment['id'],$n,$text,$marks);$stmt->execute();$stmt->close();
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_question'])) {
    $qid=(int)$_POST['delete_question'];$stmt=$db->prepare("DELETE FROM theory_questions WHERE id=? AND theory_assessment_id=?");$stmt->bind_param('ii',$qid,$assessment['id']);$stmt->execute();$stmt->close();
}
$stmt=$db->prepare("SELECT id,question_number,question_text,maximum_marks FROM theory_questions WHERE theory_assessment_id=? ORDER BY question_number");
$stmt->bind_param('i',$assessment['id']);$stmt->execute();$questions=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Theory Assessment</title></head><body>
<h2>Offline AI Theory Assessment</h2>
<p><strong><?=htmlspecialchars($test['title'])?></strong> — <?=htmlspecialchars($test['subject'])?></p>
<p>Teacher rule: enter the question and maximum marks only. The local AI uses its own knowledge to grade student answers and gives provisional marks for teacher review.</p>
<form method="post"><input type="hidden" name="test_id" value="<?=$testId?>">
<textarea name="question_text" rows="4" cols="70" placeholder="Theory question" required></textarea><br>
<input type="number" name="maximum_marks" min="0.01" step="0.01" placeholder="Maximum marks" required>
<button name="add_question">Add question</button></form>
<h3>Questions</h3>
<?php foreach($questions as $q): ?><article><b>Q<?=$q['question_number']?> (<?=$q['maximum_marks']?> marks)</b><p><?=nl2br(htmlspecialchars($q['question_text']))?></p><form method="post"><input type="hidden" name="test_id" value="<?=$testId?>"><button name="delete_question" value="<?=$q['id']?>" onclick="return confirm('Delete this question?')">Delete</button></form></article><?php endforeach; ?>
<?php if ($questions): ?><p><a href="../student/theory_register.php?test_id=<?=$testId?>">Open student theory registration</a> | <a href="theory_review.php?test_id=<?=$testId?>">Review submissions</a></p><?php endif; ?>
</body></html>