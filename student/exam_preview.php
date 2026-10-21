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
        body { background: #f3f6fb; color: #1f2937; }
        .preview-wrap { max-width: 980px; }
        .preview-hero { background: linear-gradient(120deg, #123d70, #1769aa); color: #fff; border-radius: 1rem; }
        .detail-card, .instruction-card { border: 0; border-radius: .9rem; box-shadow: 0 8px 24px rgba(15, 38, 66, .07); }
        .detail-label { color: #64748b; font-size: .82rem; text-transform: uppercase; letter-spacing: .04em; }
        .start-button { min-width: 210px; }
    </style>
</head>
<body>
<main class="container preview-wrap py-4 py-md-5">
    <header class="preview-hero p-4 p-md-5 mb-4">
        <div class="small text-uppercase fw-semibold opacity-75 mb-2">Before you begin</div>
        <h1 class="h2 mb-2">Review your exam details</h1>
        <p class="mb-0">Check your information and read the exam instructions. Your timed attempt starts after you select a section below.</p>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>

    <?php if ($test && $student): ?>
        <section class="card detail-card mb-4" aria-labelledby="student-details-heading">
            <div class="card-body p-4">
                <h2 class="h5 mb-4" id="student-details-heading">Student details</h2>
                <div class="row g-3">
                    <div class="col-sm-6"><div class="detail-label">Full name</div><div class="fw-semibold"><?= $h($student['full_name']) ?></div></div>
                    <?php if ($groupName !== ''): ?><div class="col-sm-6"><div class="detail-label">Class / group</div><div class="fw-semibold"><?= $h($groupName) ?></div></div><?php endif; ?>
                    <div class="col-sm-6"><div class="detail-label">Candidate number</div><div class="fw-semibold"><?= $h($studentId) ?></div></div>
                </div>
            </div>
        </section>

        <section class="card detail-card mb-4" aria-labelledby="exam-details-heading">
            <div class="card-body p-4">
                <h2 class="h5 mb-4" id="exam-details-heading">Assessment details</h2>
                <div class="row g-3 mb-4">
                    <div class="col-sm-6"><div class="detail-label">Assessment</div><div class="fw-semibold"><?= $h($test['title']) ?></div></div>
                    <div class="col-sm-6"><div class="detail-label">Subject / course</div><div class="fw-semibold"><?= $h($test['subject'] ?: 'Not specified') ?></div></div>
                    <div class="col-sm-6"><div class="detail-label">Year</div><div class="fw-semibold"><?= $h($test['year'] ?: 'Not specified') ?></div></div>
                    <?php if ((int)$test['duration'] > 0): ?><div class="col-sm-6"><div class="detail-label">Objective time limit</div><div class="fw-semibold"><?= $h((int)$test['duration']) ?> minutes</div></div><?php endif; ?>
                </div>
                <div class="row g-3">
                    <?php if ($objectiveAvailable): ?>
                        <div class="col-md-6"><div class="border rounded-3 p-3 h-100"><div class="fw-semibold mb-1">Objective section</div><div><?= $h($objectiveCount) ?> questions</div><div class="text-secondary small">Multiple choice and other selected response questions</div></div></div>
                    <?php endif; ?>
                    <?php if ($theoryAvailable): ?>
                        <div class="col-md-6"><div class="border rounded-3 p-3 h-100"><div class="fw-semibold mb-1">Theory section</div><div><?= $h($theoryCount) ?> questions · <?= $h(rtrim(rtrim(number_format($theoryMarks, 2, '.', ''), '0'), '.')) ?> marks</div><?php if (trim((string)$theory['instructions']) !== ''): ?><div class="text-secondary small mt-2"><?= nl2br($h($theory['instructions'])) ?></div><?php endif; ?></div></div>
                    <?php endif; ?>
                    <?php if (!$objectiveAvailable && !$theoryAvailable): ?><div class="col-12"><div class="alert alert-warning mb-0">No questions are currently available for this assessment. Please contact the exam administrator.</div></div><?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($objectiveAvailable): ?>
            <section class="card instruction-card mb-4" aria-labelledby="instructions-heading">
                <div class="card-body p-4">
                    <h2 class="h5" id="instructions-heading">Objective exam instructions</h2>
                    <ul class="mb-0">
                        <li>Keep this exam open in the current browser tab. The first tab switch shows a warning; a second tab switch terminates and submits the exam.</li>
                        <li>Stay in full-screen mode. If you exit full screen, return using the prompt shown on the exam page.</li>
                        <li>Copying, pasting, and the browser context menu are disabled during the objective exam.</li>
                        <li>Use the exam page to move between questions and submit your own answers. Avoid browser Back navigation.</li>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($theoryAvailable): ?>
            <section class="card instruction-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h5">Theory exam instructions</h2>
                    <ul class="mb-0">
                        <li>Read the assessment instructions above before starting, then write your answers in the response boxes.</li>
                        <li>Use your own words and submit only when you have finished reviewing your answers.</li>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <form method="post" class="d-flex flex-column flex-sm-row gap-2 justify-content-center pb-4">
            <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
            <?php if ($objectiveAvailable): ?>
                <button type="submit" name="mode" value="objective" class="btn btn-primary btn-lg start-button">Start Objective Exam</button>
            <?php endif; ?>
            <?php if ($theoryAvailable): ?>
                <button type="submit" name="mode" value="theory" class="btn btn-success btn-lg start-button">Start Theory Exam</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
