<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
require_once __DIR__ . '/../includes/product_policy.php';
require_once __DIR__ . '/../includes/pro_features.php';
require_pro('pro', 'Examcenter Pro is required for the Pro Assessment Suite.');

$features = [
    ['Question Bank', ProFeatures::QUESTION_BANK, '../teacher/pro_question_bank.php', 'Organize reusable questions and build richer assessments.', 'fa-layer-group', 'Assessment'],
    ['Blueprints', ProFeatures::BLUEPRINTS, '../teacher/pro_blueprints.php', 'Plan balanced exams with structured assessment blueprints.', 'fa-sitemap', 'Assessment'],
    ['Analytics', ProFeatures::ANALYTICS, 'pro_analytics.php', 'Review performance trends and assessment insights.', 'fa-chart-line', 'Insights'],
    ['Secure Exam Mode', ProFeatures::SECURE_MODE, 'pro_security.php', 'Configure additional safeguards for online examinations.', 'fa-shield-alt', 'Assessment'],
    ['Exam Sessions & Invigilation', ProFeatures::SESSIONS, 'pro_sessions.php', 'Coordinate scheduled exam sessions and invigilation.', 'fa-calendar-check', 'Operations'],
    ['Certificates', ProFeatures::CERTIFICATES, 'pro_certificates.php', 'Create and manage certificates for completed assessments.', 'fa-certificate', 'Operations'],
    ['Multi-Campus', ProFeatures::MULTI_CAMPUS, 'pro_campuses.php', 'Manage assessment activity across multiple campuses.', 'fa-building', 'Institution'],
    ['AI Assessment', ProFeatures::AI_ASSESSMENT, '../teacher/pro_ai.php', 'Use AI-assisted tools in assessment workflows.', 'fa-magic', 'Assessment'],
    ['Moderation', ProFeatures::MODERATION, '../teacher/pro_moderation.php', 'Review and moderate assessment content.', 'fa-clipboard-check', 'Assessment'],
    ['Corporate', ProFeatures::CORPORATE, 'pro_corporate.php', 'Support corporate training and assessment programs.', 'fa-briefcase', 'Institution'],
    ['Coding', ProFeatures::CODING, '../teacher/pro_coding.php', 'Create coding-focused assessments and exercises.', 'fa-code', 'Assessment'],
];

$featureGroups = [
    'Assessment tools' => array_values(array_filter($features, static fn(array $feature): bool => in_array($feature[5], ['Assessment', 'Insights'], true))),
    'Institution tools' => array_values(array_filter($features, static fn(array $feature): bool => $feature[5] === 'Institution')),
    'Operations' => array_values(array_filter($features, static fn(array $feature): bool => $feature[5] === 'Operations')),
];

$admin = $_SESSION['admin'] ?? [];
$user = $_SESSION['user'] ?? [];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Examcenter Pro Suite</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <style>
        .pro-suite-page {
            --pro-ink: #17213b;
            --pro-muted: #667085;
            --pro-accent: #4f46e5;
            --pro-border: #e7eaf2;
            color: var(--pro-ink);
            padding: 4px 4px 28px;
        }

        .pro-suite-page .page-topline {
            color: #77819a;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .pro-suite-page .page-heading {
            color: var(--pro-ink);
            font-size: clamp(1.7rem, 3vw, 2.2rem);
            font-weight: 750;
            letter-spacing: -.04em;
        }

        .pro-suite-page .page-intro {
            color: var(--pro-muted);
            max-width: 700px;
            line-height: 1.65;
        }

        .pro-hero {
            position: relative;
            overflow: hidden;
            padding: clamp(24px, 4vw, 40px);
            margin-bottom: 24px;
            border-radius: 22px;
            color: #fff;
            background: linear-gradient(115deg, #202c59 0%, #373b88 52%, #6254c7 100%);
            box-shadow: 0 18px 42px rgba(34, 43, 99, .2);
        }

        .pro-hero::after {
            position: absolute;
            right: -70px;
            bottom: -180px;
            width: 390px;
            height: 390px;
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 50%;
            box-shadow: 0 0 0 36px rgba(255, 255, 255, .035), 0 0 0 76px rgba(255, 255, 255, .025);
            content: "";
        }

        .pro-hero-content { position: relative; z-index: 1; }
        .pro-hero .eyebrow { color: #c8d2ff; font-size: .78rem; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; }
        .pro-hero h1 { margin: 10px 0 12px; color: #fff; font-size: clamp(1.8rem, 3.5vw, 2.65rem); font-weight: 750; letter-spacing: -.045em; }
        .pro-hero p { max-width: 680px; margin: 0; color: rgba(255, 255, 255, .78); line-height: 1.7; }
        .pro-hero-mark { display: grid; width: 76px; height: 76px; place-items: center; border: 1px solid rgba(255,255,255,.24); border-radius: 22px; background: rgba(255,255,255,.12); color: #fff; font-size: 2rem; }

        .pro-policy {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 17px 20px;
            margin-bottom: 30px;
            border: 1px solid #cdebdc;
            border-radius: 14px;
            background: #f0fbf5;
            color: #265c40;
        }

        .pro-policy-icon { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border-radius: 12px; background: #d9f3e4; color: #218452; }
        .pro-policy strong { display: block; margin-bottom: 2px; color: #1e5137; }
        .pro-policy p { margin: 0; color: #4d705d; line-height: 1.55; }

        .pro-section { margin-top: 30px; }
        .pro-section-heading { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
        .pro-section-heading h2 { margin: 0; color: var(--pro-ink); font-size: 1.12rem; font-weight: 750; }
        .pro-section-heading p { margin: 4px 0 0; color: var(--pro-muted); font-size: .9rem; }
        .pro-section-count { padding: 6px 10px; border: 1px solid var(--pro-border); border-radius: 999px; background: #fff; color: #69738c; font-size: .78rem; font-weight: 650; white-space: nowrap; }

        .pro-feature-card {
            display: flex;
            height: 100%;
            flex-direction: column;
            padding: 20px;
            border: 1px solid var(--pro-border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 5px 18px rgba(24, 34, 67, .045);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }

        .pro-feature-card:hover { transform: translateY(-3px); border-color: #cdd2f7; box-shadow: 0 14px 28px rgba(24, 34, 67, .09); }
        .pro-feature-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .pro-feature-icon { display: grid; width: 46px; height: 46px; flex: 0 0 46px; place-items: center; border-radius: 14px; background: #eef0ff; color: var(--pro-accent); font-size: 1.1rem; }
        .pro-feature-tag { padding: 5px 8px; border-radius: 999px; background: #f4f5f9; color: #737c91; font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .pro-feature-card h3 { margin: 18px 0 7px; color: var(--pro-ink); font-size: 1.02rem; font-weight: 730; }
        .pro-feature-card p { min-height: 48px; margin: 0 0 18px; color: var(--pro-muted); font-size: .88rem; line-height: 1.55; }
        .pro-feature-card .btn { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: auto; padding: 9px 12px; border-radius: 10px; font-size: .86rem; font-weight: 650; }
        .pro-empty-group { padding: 20px; border: 1px dashed var(--pro-border); border-radius: 14px; color: var(--pro-muted); }

        @media (max-width: 767.98px) {
            .main-content { padding: 14px; }
            .pro-suite-page { padding: 0 0 24px; }
            .pro-hero { border-radius: 17px; }
            .pro-hero-mark { width: 58px; height: 58px; border-radius: 17px; font-size: 1.5rem; }
            .pro-feature-card p { min-height: 0; }
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <div>
                <div class="page-topline">Admin workspace / Pro</div>
                <h2 class="mb-0">Assessment Suite</h2>
            </div>
            <button class="btn btn-primary d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <div class="pro-suite-page">
            <section class="pro-hero" aria-labelledby="proSuiteTitle">
                <div class="pro-hero-content row align-items-center g-4">
                    <div class="col">
                        <div class="eyebrow">Examcenter Pro</div>
                        <h1 id="proSuiteTitle">Advanced assessment tools, in one place.</h1>
                        <p>Choose a module to manage specialized assessment workflows. Your core examination tools remain available independently.</p>
                    </div>
                    <div class="col-auto d-none d-sm-block" aria-hidden="true">
                        <div class="pro-hero-mark"><i class="fas fa-layer-group"></i></div>
                    </div>
                </div>
            </section>

            <aside class="pro-policy" aria-label="Product policy">
                <div class="pro-policy-icon" aria-hidden="true"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <strong>Core Free policy</strong>
                    <p>Everything needed to conduct a normal examination remains free. Pro adds supporting and advanced capabilities for institutions that need them.</p>
                </div>
            </aside>

            <?php foreach ($featureGroups as $groupName => $groupFeatures): ?>
                <section class="pro-section" aria-labelledby="group-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $groupName)), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="pro-section-heading">
                        <div>
                            <h2 id="group-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $groupName)), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= $groupName === 'Assessment tools' ? 'Build, deliver, and review more flexible assessments.' : ($groupName === 'Institution tools' ? 'Extend support across your institution and programs.' : 'Coordinate exam delivery and learner recognition.') ?></p>
                        </div>
                        <span class="pro-section-count"><?= count($groupFeatures) ?> <?= count($groupFeatures) === 1 ? 'module' : 'modules' ?></span>
                    </div>

                    <?php if ($groupFeatures): ?>
                        <div class="row g-3">
                            <?php foreach ($groupFeatures as $feature): ?>
                                <div class="col-sm-6 col-xl-4">
                                    <article class="pro-feature-card">
                                        <div class="pro-feature-top">
                                            <div class="pro-feature-icon" aria-hidden="true"><i class="fas <?= htmlspecialchars($feature[4], ENT_QUOTES, 'UTF-8') ?>"></i></div>
                                            <span class="pro-feature-tag"><?= htmlspecialchars($feature[5], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <h3><?= htmlspecialchars($feature[0], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p><?= htmlspecialchars($feature[3], ENT_QUOTES, 'UTF-8') ?></p>
                                        <a class="btn btn-outline-primary" href="<?= htmlspecialchars($feature[2], ENT_QUOTES, 'UTF-8') ?>">
                                            <span>Open module</span><i class="fas fa-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    </article>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="pro-empty-group">No modules are currently available in this section.</div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </main>

    <script src="../js/sidebar.js"></script>
</body>

</html>
