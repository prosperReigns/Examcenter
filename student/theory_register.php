<?php

declare(strict_types=1);
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
//require_once '../license/license_guard.php';

$db = Database::connection();
$testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
$stmt = $db->prepare("SELECT t.id,t.title,t.subject,t.year,ta.instructions FROM tests t JOIN theory_assessments ta ON ta.test_id=t.id WHERE t.id=? AND ta.status<>'archived' LIMIT 1");
$stmt->bind_param('i', $testId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$test) die('Theory assessment not found.');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') $error = 'Full name is required.';
    if ($error === '') {
        $stmt = $db->prepare("INSERT INTO students(full_name,class) VALUES(?,?)");
        $class = 'THEORY';
        $stmt->bind_param('ss', $name, $class);
        if ($stmt->execute()) {
            $_SESSION['student_id'] = $db->insert_id;
            $_SESSION['student_name'] = $name;
            $_SESSION['theory_test_id'] = $testId;
            header('Location: theory_exam.php?test_id=' . $testId);
            exit;
        }
        $error = 'Unable to start the theory exam.';
    }
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Theory Registration</title>
</head>

<body>
    <h2><?= htmlspecialchars($test['title']) ?></h2>
    <p><?= htmlspecialchars($test['subject']) ?> — <?= htmlspecialchars($test['year']) ?></p>
    <?php if ($test['instructions']): ?><p><?= nl2br(htmlspecialchars($test['instructions'])) ?></p><?php endif; ?>
    <?php if ($error): ?><p><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="test_id" value="<?= $testId ?>">
        <label>Full name <input name="name" required></label>
        <button>Start Theory Exam</button>
    </form>
</body>

</html>