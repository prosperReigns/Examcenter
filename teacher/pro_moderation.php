<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
require_pro('examiner_moderation');
require_once __DIR__ . '/../db.php';
$db = Database::connection();
$rows = $db->query("SELECT m.*,t.title FROM pro_moderation_reviews m JOIN tests t ON t.id=m.test_id ORDER BY m.id DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Moderation</title>
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
</head>

<body><?php $teacher = $_SESSION['teacher'] ?? [];
        $admin = $_SESSION['admin'] ?? [];
        $user = $_SESSION['user'] ?? [];
        require __DIR__ . '/sidebar.php'; ?><main class="main-content">
        <h2>Examiner & Moderation</h2>
        <p class="text-muted">AI and examiner marks remain reviewable; moderation records preserve the decision trail.</p>
        <table class="table table-striped">
            <tr>
                <th>Test</th>
                <th>Student</th>
                <th>Original</th>
                <th>Moderated</th>
                <th>Decision</th>
                <th>Reason</th>
            </tr><?php foreach ($rows as $r): ?><tr>
                    <td><?= htmlspecialchars($r['title']) ?></td>
                    <td><?= $r['student_id'] ?></td>
                    <td><?= $r['original_mark'] ?></td>
                    <td><?= $r['moderated_mark'] ?></td>
                    <td><?= $r['decision'] ?></td>
                    <td><?= htmlspecialchars((string)$r['reason']) ?></td>
                </tr><?php endforeach; ?>
        </table>
    </main>
</body>

</html>