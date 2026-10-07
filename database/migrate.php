<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const MIGRATION_KEYS = [
    '20261005_0001_universal_architecture',
    '20261005_0002_theory_assessment',
    '20261005_0003_pro_assessment_suite',
    '20261007_0004_system_audit_hardening',
    '20261007_0005_legacy_universal_reconciliation',
];

$db = Database::connection();

if (!$db->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(191) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
    throw new RuntimeException('Unable to create schema_migrations: ' . $db->error);
}

foreach (MIGRATION_KEYS as $migrationKey) {
    $check = $db->prepare("SELECT id FROM schema_migrations WHERE migration_key = ? LIMIT 1");
    $check->bind_param('s', $migrationKey);
    $check->execute();
    $already = $check->get_result()->fetch_assoc();
    $check->close();

    if ($already) {
        echo "Migration already applied: {$migrationKey}" . PHP_EOL;
        continue;
    }

    $path = __DIR__ . '/migrations/' . $migrationKey . '.sql';
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Migration SQL file could not be read: {$migrationKey}");
    }

    if (!$db->multi_query($sql)) {
        throw new RuntimeException("Migration failed ({$migrationKey}): {$db->error}");
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());

    if ($db->errno) {
        throw new RuntimeException("Migration failed ({$migrationKey}): {$db->error}");
    }

    $stmt = $db->prepare("INSERT INTO schema_migrations (migration_key) VALUES (?)");
    $stmt->bind_param('s', $migrationKey);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Could not record migration {$migrationKey}: " . $db->error);
    }
    $stmt->close();

    echo "Migration applied: {$migrationKey}" . PHP_EOL;
}

exit(0);
