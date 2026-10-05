<?php
declare(strict_types=1);

/**
 * Lightweight Pro architecture verification.
 *
 * Usage:
 *   php scripts/verify_pro_architecture.php
 *
 * This performs static/runtime checks that do not require a live license server.
 */

$root = dirname(__DIR__);
$required = [
    'docs/PRO_ARCHITECTURE.md',
    'database/migrations/20261005_0001_universal_architecture.sql',
    'database/migrate.php',
    'database/backfill_legacy.php',
    'license/ProEntitlementVerifier.php',
    'license/ProEntitlementAPI.php',
    'includes/ProEntitlementStorage.php',
    'includes/pro.php',
    'database/migrations/20261005_0002_theory_assessment.sql',
    'theory/GradingResult.php',
    'theory/OllamaTheoryGrader.php',
    'theory/TheoryGradingService.php',
    'teacher/theory_assessment.php',
    'teacher/theory_review.php',
    'teacher/grade_theory.php',
    'student/theory_register.php',
    'student/theory_exam.php',
    'student/grade_theory.php',
    'student/theory_result.php',
];

$failed = false;

foreach ($required as $path) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        fwrite(STDERR, "MISSING: {$path}" . PHP_EOL);
        $failed = true;
    } else {
        echo "OK: {$path}" . PHP_EOL;
    }
}

$phpFiles = [
    'database/migrate.php',
    'database/backfill_legacy.php',
    'license/ProEntitlementVerifier.php',
    'license/ProEntitlementAPI.php',
    'license/license_guard.php',
    'includes/ProEntitlementStorage.php',
    'includes/pro.php',
    'theory/GradingResult.php',
    'theory/OllamaTheoryGrader.php',
    'theory/TheoryGradingService.php',
    'teacher/theory_assessment.php',
    'teacher/theory_review.php',
    'teacher/grade_theory.php',
    'student/theory_register.php',
    'student/theory_exam.php',
    'student/grade_theory.php',
    'student/theory_result.php',
];

foreach ($phpFiles as $path) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) continue;

    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($full) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "SYNTAX ERROR: {$path}" . PHP_EOL . implode(PHP_EOL, $output) . PHP_EOL);
        $failed = true;
    } else {
        echo "SYNTAX OK: {$path}" . PHP_EOL;
    }
}

if ($failed) exit(1);

echo "Pro architecture static verification passed." . PHP_EOL;
