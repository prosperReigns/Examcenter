<?php
declare(strict_types=1);

/**
 * Examcenter static system audit.
 *
 * Run:
 *   php scripts/audit_system.php
 *
 * The audit is intentionally dependency-free and can run offline.
 */

$root = dirname(__DIR__);
$failures = [];
$warnings = [];

function auditFail(string $message): void { global $failures; $failures[] = $message; }
function auditWarn(string $message): void { global $warnings; $warnings[] = $message; }

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
    if (str_contains($path, '/.git/')) continue;
    if (str_contains($path, '/uploads/')) continue;
    $files[] = substr($path, strlen($root) + 1);
}

$hashes = [];
foreach ($files as $path) {
    $full = $root . '/' . $path;
    $hash = @sha1_file($full);
    if ($hash === false) continue;
    $hashes[$hash][] = $path;
}

foreach ($hashes as $hash => $paths) {
    if (count($paths) > 1) {
        $meaningful = array_values(array_filter(
            $paths,
            static fn(string $p): bool => !str_ends_with($p, '.lock')
                && !str_ends_with($p, '.log')
                && !str_ends_with($p, '.DS_Store')
        ));
        if (count($meaningful) > 1) {
            auditWarn('Duplicate file content: ' . implode(', ', $meaningful));
        }
    }
}

$required = [
    'database/migrations/20261005_0001_universal_architecture.sql',
    'database/migrations/20261005_0002_theory_assessment.sql',
    'database/migrations/20261005_0003_pro_assessment_suite.sql',
    'database/migrations/20261007_0004_system_audit_hardening.sql',
    'database/migrate.php',
    'includes/pro.php',
    'includes/pro_features.php',
    'includes/product_policy.php',
    'includes/universal_architecture.php',
    'includes/test_context.php',
    'student/take_exam.php',
    'student/save_answer.php',
    'student/submit_exam.php',
];

foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) auditFail('Missing required file: ' . $path);
}

$migrate = file_get_contents($root . '/database/migrate.php') ?: '';
if (!str_contains($migrate, '20261007_0004_system_audit_hardening')) {
    auditFail('Audit hardening migration is not registered in database/migrate.php.');
}

$policy = file_get_contents($root . '/includes/product_policy.php') ?: '';
foreach ([
    'exam_taking','exam_submission','objective_marking',
    'theory_assessment','offline_exam_operation'
] as $feature) {
    if (!str_contains($policy, "'" . $feature . "'")) {
        auditFail('Core policy is missing feature: ' . $feature);
    }
}

foreach (['student/take_exam.php','student/save_answer.php','student/submit_exam.php'] as $path) {
    $content = file_get_contents($root . '/' . $path) ?: '';
    if (str_contains($content, 'cdn.jsdelivr.net')) {
        auditFail('Core exam file has an external CDN dependency: ' . $path);
    }
}

foreach ([
    'student/save_answer.php',
    'student/submit_exam.php'
] as $path) {
    $content = file_get_contents($root . '/' . $path) ?: '';
    if (!str_contains($content, 'hash_equals')) {
        auditFail('Core mutation endpoint lacks CSRF validation: ' . $path);
    }
}

$universal = file_get_contents($root . '/includes/universal_architecture.php') ?: '';
if (str_contains($universal, "WHERE status = 'active'")) {
    auditFail('Universal institution helper still assumes institutions.status.');
}
if (str_contains($universal, 'period_code')) {
    auditFail('Universal period helper still assumes academic_periods.period_code.');
}

$phpFiles = array_values(array_filter(
    $files,
    static fn(string $p): bool => str_ends_with($p, '.php')
        && !str_contains($p, '/vendor/')
));

// Every explicit require_pro('feature') code must be registered in ProFeatures.
$featureFile = file_get_contents($root . '/includes/pro_features.php') ?: '';
preg_match_all("/='([a-z0-9_]+)'/", $featureFile, $featureMatches);
$registeredFeatures = array_fill_keys($featureMatches[1] ?? [], true);
foreach ($phpFiles as $path) {
    $content = file_get_contents($root . '/' . $path) ?: '';
    if (preg_match_all("/require_pro\\(\\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $feature) {
            if ($feature !== 'pro' && empty($registeredFeatures[$feature])) {
                auditFail("Unregistered Pro feature code '{$feature}' in {$path}.");
            }
        }
    }
}

$hardcodedUniversal = [
    'super_admin/system_setup.php',
    'super_admin/manage_classes.php',
    'admin/manage_classes.php',
    'admin/manage_subject.php',
    'super_admin/manage_subject.php',
    'register.php',
    'student/register.php',
];
foreach ($hardcodedUniversal as $path) {
    $content = file_get_contents($root . '/' . $path) ?: '';
    if (preg_match("/['\"]JSS['\"].*['\"]SS['\"]/s", $content)) {
        auditWarn('Legacy JSS/SS assumption remains in Universal-facing file: ' . $path);
    }
}

foreach ($phpFiles as $path) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        auditFail('PHP syntax error: ' . $path . ' :: ' . implode(' ', $output));
    }
}

echo "Examcenter System Audit" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
echo "Files scanned: " . count($files) . PHP_EOL;
echo "Warnings: " . count($warnings) . PHP_EOL;
echo "Failures: " . count($failures) . PHP_EOL;

foreach ($warnings as $warning) echo "[WARN] {$warning}" . PHP_EOL;
foreach ($failures as $failure) echo "[FAIL] {$failure}" . PHP_EOL;

exit($failures ? 1 : 0);
