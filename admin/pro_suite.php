<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
require_once __DIR__ . '/../includes/product_policy.php';
require_once __DIR__ . '/../includes/pro_features.php';
require_pro('pro', 'Examcenter Pro is required for the Pro Assessment Suite.');
$features = [['Question Bank', ProFeatures::QUESTION_BANK, '../teacher/pro_question_bank.php'], ['Blueprints', ProFeatures::BLUEPRINTS, '../teacher/pro_blueprints.php'], ['Analytics', ProFeatures::ANALYTICS, 'pro_analytics.php'], ['Secure Exam Mode', ProFeatures::SECURE_MODE, 'pro_security.php'], ['Exam Sessions & Invigilation', ProFeatures::SESSIONS, 'pro_sessions.php'], ['Certificates', ProFeatures::CERTIFICATES, 'pro_certificates.php'], ['Multi-Campus', ProFeatures::MULTI_CAMPUS, 'pro_campuses.php'], ['AI Assessment', ProFeatures::AI_ASSESSMENT, '../teacher/pro_ai.php'], ['Moderation', ProFeatures::MODERATION, '../teacher/pro_moderation.php'], ['Corporate', ProFeatures::CORPORATE, 'pro_corporate.php'], ['Coding', ProFeatures::CODING, '../teacher/pro_coding.php']];
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Examcenter Pro Suite</title>
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
</head>

<body><?php $admin = $_SESSION['admin'] ?? [];
        $user = $_SESSION['user'] ?? [];
        require __DIR__ . '/sidebar.php'; ?><main class="main-content">
        <h1>Examcenter Pro Suite</h1>
        <div class="alert alert-success"><strong>Core Free policy:</strong> everything necessary to conduct a normal examination remains free. Pro provides supporting and advanced capabilities.</div>
        <p class="text-muted">Advanced assessment features. Core CBT remains available independently.</p>
        <div class="row"><?php foreach ($features as $f): ?><div class="col-md-4 mb-3">
                    <div class="card p-3 h-100">
                        <h5><?= htmlspecialchars($f[0]) ?></h5><code><?= htmlspecialchars($f[1]) ?></code><a class="btn btn-primary mt-3" href="<?= htmlspecialchars($f[2]) ?>">Open module</a>
                    </div>
                </div><?php endforeach; ?></div>
    </main>
</body>

</html>