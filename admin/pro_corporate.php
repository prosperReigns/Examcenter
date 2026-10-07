<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
require_pro('corporate_assessment');
require_once __DIR__ . '/../db.php';
$db = Database::connection();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = $db->prepare("INSERT INTO pro_corporate_assessments(institution_id,test_id,assessment_code,name,assessment_type,department,role_title,pass_score) VALUES(?,?,?,?,?,?,?,?)");
    $test = ($_POST['test_id'] ?? '') !== '' ? (int)$_POST['test_id'] : null;
    $pass = ($_POST['pass_score'] ?? '') !== '' ? (float)$_POST['pass_score'] : null;
    $s->bind_param('iisssssd', $_POST['institution_id'], $test, $_POST['assessment_code'], $_POST['name'], $_POST['assessment_type'], $_POST['department'], $_POST['role_title'], $pass);
    $s->execute();
    $s->close();
    $msg = 'Corporate assessment created.';
}
$rows = $db->query("SELECT * FROM pro_corporate_assessments ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Corporate Assessments</title>
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
</head>

<body><?php $admin = $_SESSION['admin'] ?? [];
        $user = $_SESSION['user'] ?? [];
        require __DIR__ . '/sidebar.php'; ?><main class="main-content">
        <h2>Corporate / Recruitment Assessments</h2><?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?><form method="post" class="card p-3 mb-3">
            <div class="row g-2"><input name="institution_id" type="number" class="form-control" placeholder="Institution ID" required><input name="test_id" type="number" class="form-control" placeholder="Existing test ID (optional)"><input name="assessment_code" class="form-control" placeholder="Assessment code" required><input name="name" class="form-control" placeholder="Assessment name" required><input name="assessment_type" class="form-control" value="recruitment"><input name="department" class="form-control" placeholder="Department"><input name="role_title" class="form-control" placeholder="Role"><input name="pass_score" type="number" step="0.01" class="form-control" placeholder="Pass score"><button class="btn btn-primary mt-2">Create assessment</button></div>
        </form>
        <table class="table">
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Role</th>
                <th>Status</th>
            </tr><?php foreach ($rows as $r): ?><tr>
                    <td><?= $r['assessment_code'] ?></td>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= $r['assessment_type'] ?></td>
                    <td><?= htmlspecialchars((string)$r['role_title']) ?></td>
                    <td><?= $r['status'] ?></td>
                </tr><?php endforeach; ?>
        </table>
    </main>
</body>

</html>