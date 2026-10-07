<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
require_pro('assessment_analytics');
require_once __DIR__ . '/../pro/ProAssessmentService.php';
$svc = new ProAssessmentService();
$testId = (int)($_GET['test_id'] ?? 0);
$rows = $testId ? $svc->itemAnalysis($testId) : []; ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Assessment Analytics</title>
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
</head>

<body><?php $admin = $_SESSION['admin'] ?? [];
        $user = $_SESSION['user'] ?? [];
        require __DIR__ . '/sidebar.php'; ?><main class="main-content">
        <h2>Advanced Analytics & Item Analysis</h2>
        <form class="mb-3"><input name="test_id" type="number" placeholder="Test ID" value="<?= $testId ?>" class="form-control d-inline-block" style="width:180px"><button class="btn btn-primary">Analyze</button></form><?php if ($rows): ?><table class="table">
                <tr>
                    <th>Question</th>
                    <th>Sample</th>
                    <th>Facility</th>
                </tr><?php foreach ($rows as $r): ?><tr>
                        <td><?= htmlspecialchars((string)$r['question_text']) ?></td>
                        <td><?= $r['sample_size'] ?></td>
                        <td><?= number_format((float)$r['facility'], 3) ?></td>
                    </tr><?php endforeach; ?>
            </table><?php else: ?><p class="text-muted">Enter a test ID to calculate available item statistics.</p><?php endif; ?>
    </main>
</body>

</html>