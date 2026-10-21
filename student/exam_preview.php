<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/system_guard.php';
require_once __DIR__ . '/../includes/universal_architecture.php';

$db = Database::connection();
$studentId = (int)($_SESSION['student_id'] ?? 0);
$testId = (int)($_SESSION['current_test_id'] ?? $_SESSION['theory_test_id'] ?? 0);
$error = '';
$test = null;
$student = null;
$theory = null;
$objectiveCount = 0;
$theoryCount = 0;
$theoryMarks = 0.0;
$groupName = (string)($_SESSION['student_class'] ?? '');

if ($studentId < 1 || $testId < 1) {
    header('Location: register.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$hasInstitutionColumn = examcenterUniversalColumnExists($db, 'tests', 'institution_id');
$testSql = 'SELECT id,title,subject,year,duration' . ($hasInstitutionColumn ? ',institution_id' : '') . ' FROM tests WHERE id=? LIMIT 1';
$stmt = $db->prepare($testSql);
$stmt->bind_param('i', $testId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($test && $hasInstitutionColumn && !empty($_SESSION['institution_membership_id'])) {
    $activeInstitutionId = examcenterActiveInstitutionId($db);
    if ($activeInstitutionId === null || (int)$test['institution_id'] !== $activeInstitutionId) {
        $test = null;
    }
}

if ($test) {
    $stmt = $db->prepare('SELECT full_name,class FROM students WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($student && $groupName !== '' && ctype_digit($groupName) && !empty($_SESSION['institution_membership_id']) && examcenterUniversalTableExists($db, 'organizational_units')) {
        $groupId = (int)$groupName;
        $stmt = $db->prepare('SELECT name FROM organizational_units WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($unit) {
            $groupName = (string)$unit['name'];
        }
    } elseif ($student && $groupName !== '' && ctype_digit($groupName) && examcenterUniversalTableExists($db, 'classes')) {
        $groupId = (int)$groupName;
        $stmt = $db->prepare('SELECT class_name FROM classes WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($class) {
            $groupName = (string)$class['class_name'];
        }
    }

    $stmt = $db->prepare('SELECT COUNT(*) AS question_count FROM new_questions WHERE test_id=?');
    $stmt->bind_param('i', $testId);
    $stmt->execute();
    $objectiveCount = (int)($stmt->get_result()->fetch_assoc()['question_count'] ?? 0);
    $stmt->close();

    $stmt = $db->prepare("SELECT id,instructions,status FROM theory_assessments WHERE test_id=? AND status<>'archived' LIMIT 1");
    $stmt->bind_param('i', $testId);
    $stmt->execute();
    $theory = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($theory) {
        $assessmentId = (int)$theory['id'];
        $stmt = $db->prepare('SELECT COUNT(*) AS question_count,COALESCE(SUM(maximum_marks),0) AS maximum_marks FROM theory_questions WHERE theory_assessment_id=?');
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $theorySummary = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $theoryCount = (int)($theorySummary['question_count'] ?? 0);
        $theoryMarks = (float)($theorySummary['maximum_marks'] ?? 0);
    }
}

if (!$test || !$student) {
    $error = 'We could not verify this student or assessment. Please return to registration and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        $error = 'This request has expired. Reload the page and try again.';
    } else {
        $mode = (string)($_POST['mode'] ?? '');
        $allowed = ($mode === 'objective' && $objectiveCount > 0)
            || ($mode === 'theory' && $theory !== null && $theoryCount > 0);
        if (!$allowed) {
            $error = 'That exam section is not available. Please choose an available section.';
        } else {
            if ($mode === 'theory') {
                $_SESSION['theory_test_id'] = $testId;
            }
            $_SESSION['exam_preview_authorized'] = [
                'student_id' => $studentId,
                'test_id' => $testId,
                'mode' => $mode,
                'authorized_at' => time(),
            ];
            if ($mode === 'theory') {
                header('Location: theory_exam.php?test_id=' . $testId);
            } else {
                header('Location: take_exam.php');
            }
            exit;
        }
    }
}

$objectiveAvailable = $objectiveCount > 0;
$theoryAvailable = $theory !== null && $theoryCount > 0;
$groupName = in_array(strtoupper($groupName), ['UNIVERSAL', 'THEORY'], true) ? '' : $groupName;
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exam Preview | Examcenter</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --preview-ink:#172b4d; --preview-muted:#66758b; --preview-blue:#2459a6; --preview-line:#e4eaf2; }
        body { min-height:100vh; background:radial-gradient(ellipse at 78% 0%,rgba(77,132,211,.12),transparent 34rem),#f3f6fb; color:var(--preview-ink); }
        .preview-topbar { height:68px; display:flex; align-items:center; justify-content:space-between; padding:0 clamp(20px,5vw,64px); background:rgba(255,255,255,.92); border-bottom:1px solid var(--preview-line); }
        .preview-brand { display:flex; align-items:center; gap:10px; color:var(--preview-ink); font-size:17px; font-weight:750; text-decoration:none; }
        .brand-mark { width:32px; height:32px; display:grid; place-items:center; border-radius:9px; color:#fff; background:linear-gradient(145deg,#3475ce,#1e4f95); font-size:14px; font-weight:800; }
        .secure-note { color:var(--preview-muted); font-size:12px; }
        .preview-wrap { max-width:1180px; padding-top:38px; padding-bottom:54px; }
        .preview-heading { display:flex; align-items:center; justify-content:space-between; gap:24px; margin-bottom:26px; }
        .eyebrow { color:var(--preview-blue); font-size:11px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; }
        .preview-heading h1 { margin:5px 0 4px; font-size:clamp(25px,3vw,32px); font-weight:750; letter-spacing:-.7px; }
        .preview-heading p { margin:0; color:var(--preview-muted); }
        .start-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; margin:0; }
        .start-button { min-width:180px; min-height:46px; border:0; border-radius:8px; font-size:14px; font-weight:700; box-shadow:0 6px 15px rgba(36,89,166,.15); }
        .start-objective { background:linear-gradient(110deg,#2b68b8,#24559b); }
        .start-objective:hover { background:#1f4f92; }
        .start-theory { background:linear-gradient(110deg,#18856c,#116b57); }
        .start-theory:hover { background:#0e604e; }
        .preview-card { overflow:hidden; border:1px solid var(--preview-line); border-radius:14px; background:#fff; box-shadow:0 12px 32px rgba(31,55,88,.07); }
        .preview-card-header { padding:19px 22px; border-bottom:1px solid var(--preview-line); }
        .preview-card-header h2 { margin:0; font-size:16px; font-weight:750; }
        .preview-card-body { padding:21px 22px; }
        .detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px 20px; }
        .detail-label { margin-bottom:4px; color:var(--preview-muted); font-size:10px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; }
        .detail-value { overflow-wrap:anywhere; color:var(--preview-ink); font-size:15px; font-weight:650; }
        .assessment-summary { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:20px; }
        .summary-item { padding:15px; border:1px solid var(--preview-line); border-radius:10px; background:#f8fafd; }
        .summary-item strong { display:block; margin-bottom:3px; font-size:13px; }
        .summary-item span { color:var(--preview-muted); font-size:13px; }
        .instruction-card { margin-bottom:16px; }
        .instruction-card:last-child { margin-bottom:0; }
        .instruction-card .preview-card-header { display:flex; align-items:center; gap:10px; }
        .instruction-icon { width:30px; height:30px; display:grid; place-items:center; flex:0 0 30px; border-radius:9px; color:var(--preview-blue); background:#eaf2fc; font-weight:800; }
        .instruction-list { margin:0; padding-left:19px; color:#475467; font-size:13px; }
        .instruction-list li { padding-left:3px; margin-bottom:11px; }
        .instruction-list li:last-child { margin-bottom:0; }
        .theory-copy { color:#475467; font-size:13px; white-space:pre-wrap; }
        .empty-warning { border:1px solid #f2d28a; border-radius:10px; background:#fff8e6; color:#725410; }
        .bottom-note { margin-top:18px; color:var(--preview-muted); font-size:12px; text-align:center; }
        @media(max-width:991.98px) { .preview-heading { align-items:flex-start; flex-direction:column; } .start-actions { justify-content:flex-start; } }
        @media(max-width:575.98px) { .preview-topbar { height:60px; } .secure-note { display:none; } .preview-wrap { padding-top:25px; } .start-actions,.start-button { width:100%; } .detail-grid,.assessment-summary { grid-template-columns:1fr; } .preview-card-header,.preview-card-body { padding-left:18px; padding-right:18px; } }
    </style>
</head>
<body>
<header class="preview-topbar">
    <a class="preview-brand" href="register.php" aria-label="Examcenter"><span class="brand-mark" aria-hidden="true">E</span><span>Examcenter</span></a>
    <span class="secure-note">Assessment preview · Review before starting</span>
</header>
<main class="container preview-wrap">
    <header class="preview-heading">
        <div>
            <div class="eyebrow">Before you begin</div>
            <h1>Review your exam details</h1>
            <p>Check your information and read the instructions before starting your assessment.</p>
        </div>
        <?php if ($test && $student && ($objectiveAvailable || $theoryAvailable)): ?>
            <form method="post" class="start-actions">
                <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
                <?php if ($objectiveAvailable): ?><button type="submit" name="mode" value="objective" class="btn btn-primary start-button start-objective">Start Objective Exam</button><?php endif; ?>
                <?php if ($theoryAvailable): ?><button type="submit" name="mode" value="theory" class="btn btn-success start-button start-theory">Start Theory Exam</button><?php endif; ?>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>

    <?php if ($test && $student): ?>
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <section class="preview-card mb-4" aria-labelledby="student-details-heading">
                    <div class="preview-card-header"><h2 id="student-details-heading">Student details</h2></div>
                    <div class="preview-card-body">
                        <div class="detail-grid">
                            <div><div class="detail-label">Student name</div><div class="detail-value"><?= $h($student['full_name']) ?></div></div>
                            <?php if ($groupName !== ''): ?><div><div class="detail-label">Class / group</div><div class="detail-value"><?= $h($groupName) ?></div></div><?php endif; ?>
                            <div><div class="detail-label">Candidate number</div><div class="detail-value"><?= $h($studentId) ?></div></div>
                        </div>
                    </div>
                </section>

                <section class="preview-card" aria-labelledby="exam-details-heading">
                    <div class="preview-card-header"><h2 id="exam-details-heading">Assessment details</h2></div>
                    <div class="preview-card-body">
                        <div class="detail-grid">
                            <div><div class="detail-label">Assessment</div><div class="detail-value"><?= $h($test['title']) ?></div></div>
                            <div><div class="detail-label">Subject / course</div><div class="detail-value"><?= $h($test['subject'] ?: 'Not specified') ?></div></div>
                            <div><div class="detail-label">Academic year / period</div><div class="detail-value"><?= $h($test['year'] ?: 'Not specified') ?></div></div>
                            <?php if ((int)$test['duration'] > 0): ?><div><div class="detail-label">Objective time limit</div><div class="detail-value"><?= $h((int)$test['duration']) ?> minutes</div><?php endif; ?>
                        </div>
                        <div class="assessment-summary">
                            <?php if ($objectiveAvailable): ?><div class="summary-item"><strong>Objective section</strong><span><?= $h($objectiveCount) ?> questions</span></div><?php endif; ?>
                            <?php if ($theoryAvailable): ?><div class="summary-item"><strong>Theory section</strong><span><?= $h($theoryCount) ?> questions · <?= $h(rtrim(rtrim(number_format($theoryMarks, 2, '.', ''), '0'), '.')) ?> marks</span></div><?php endif; ?>
                        </div>
                        <?php if (!$objectiveAvailable && !$theoryAvailable): ?><div class="empty-warning p-3 mt-3">No questions are currently available for this assessment. Please contact the exam administrator.</div><?php endif; ?>
                    </div>
                </section>
            </div>

            <aside class="col-lg-5" aria-label="Exam instructions">
                <?php if ($objectiveAvailable): ?>
                    <section class="preview-card instruction-card" aria-labelledby="objective-instructions-heading">
                        <div class="preview-card-header"><span class="instruction-icon" aria-hidden="true">!</span><h2 id="objective-instructions-heading">Objective exam instructions</h2></div>
                        <div class="preview-card-body">
                            <ul class="instruction-list">
                                <li>Keep this exam open in the current browser tab. The first tab switch shows a warning; a second tab switch terminates and submits the exam.</li>
                                <li>Stay in full-screen mode. If you exit full screen, return using the prompt shown on the exam page.</li>
                                <li>Copying, pasting, and the browser context menu are disabled during the objective exam.</li>
                                <li>Use the exam page to move between questions and submit your own answers. Avoid browser Back navigation.</li>
                            </ul>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($theoryAvailable): ?>
                    <section class="preview-card instruction-card" aria-labelledby="theory-instructions-heading">
                        <div class="preview-card-header"><span class="instruction-icon" aria-hidden="true">i</span><h2 id="theory-instructions-heading">Theory exam instructions</h2></div>
                        <div class="preview-card-body">
                            <?php if (trim((string)$theory['instructions']) !== ''): ?><p class="theory-copy mb-3"><?= nl2br($h($theory['instructions'])) ?></p><?php endif; ?>
                            <ul class="instruction-list">
                                <li>Read the assessment instructions before starting, then write your answers in the response boxes.</li>
                                <li>Use your own words and submit only when you have finished reviewing your answers.</li>
                            </ul>
                        </div>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
        <div class="bottom-note">Your exam attempt starts when you select a Start button above.</div>
    <?php endif; ?>
</main>
</body>
</html>