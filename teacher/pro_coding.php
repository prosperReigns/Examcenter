<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
require_pro('coding_assessment');
require_once __DIR__ . '/../coding/CodingRunner.php';
$out = null;
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $out = (new CodingRunner())->run($_POST['language'], $_POST['source'], $_POST['input'] ?? '');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
} ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Coding Assessment</title>
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
</head>

<body><?php $teacher = $_SESSION['teacher'] ?? [];
        $admin = $_SESSION['admin'] ?? [];
        $user = $_SESSION['user'] ?? [];
        require __DIR__ . '/sidebar.php'; ?><main class="main-content">
        <h2>Coding Assessment Engine</h2>
        <p class="text-muted">Execution is local and requires an explicitly configured runner for each language.</p><?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?><?php if ($out): ?>
            <pre class="card p-3"><?= htmlspecialchars(json_encode($out, JSON_PRETTY_PRINT)) ?></pre><?php endif; ?><form method="post" class="card p-3"><select name="language" class="form-control mb-2">
                <option>python</option>
                <option>javascript</option>
                <option>php</option>
                <option>java</option>
                <option>c</option>
                <option>cpp</option>
            </select><textarea name="source" rows="12" class="form-control mb-2" placeholder="Source code" required></textarea><textarea name="input" rows="3" class="form-control mb-2" placeholder="Input"></textarea><button class="btn btn-primary">Run locally</button></form>
    </main>
</body>

</html>