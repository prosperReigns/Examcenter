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

$connection = Database::connection();

if (!$connection->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(191) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
    throw new RuntimeException('Unable to create schema_migrations: ' . $connection->error);
}

foreach (MIGRATION_KEYS as $migrationKey) {
    $check = $connection->prepare(
        "SELECT id FROM schema_migrations WHERE migration_key = ? LIMIT 1"
    );
    $check->bind_param('s', $migrationKey);
    $check->execute();
    $already = $check->get_result()->fetch_assoc();
    $check->close();

    if ($already) {
        echo "Migration already applied: {$migrationKey}" . PHP_EOL;
        continue;
    }

    $sqlPath = __DIR__ . '/migrations/' . $migrationKey . '.sql';
    $phpPath = __DIR__ . '/migrations/' . $migrationKey . '.php';

    if (is_file($phpPath)) {
        $GLOBALS['db'] = $connection;
        try {
            require $phpPath;
        } finally {
            unset($GLOBALS['db']);
        }

        $stmt = $connection->prepare(
            "INSERT INTO schema_migrations (migration_key) VALUES (?)"
        );
        $stmt->bind_param('s', $migrationKey);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                "Could not record migration {$migrationKey}: " . $connection->error
            );
        }
        $stmt->close();

        echo "Migration applied: {$migrationKey}" . PHP_EOL;
        continue;
    }

    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        throw new RuntimeException(
            "Migration file could not be read: {$migrationKey}"
        );
    }

    if (!$connection->multi_query($sql)) {
        throw new RuntimeException(
            "Migration failed ({$migrationKey}): {$connection->error}"
        );
    }

    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());

    if ($connection->errno) {
        throw new RuntimeException(
            "Migration failed ({$migrationKey}): {$connection->error}"
        );
    }

    $stmt = $connection->prepare(
        "INSERT INTO schema_migrations (migration_key) VALUES (?)"
    );
    $stmt->bind_param('s', $migrationKey);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException(
            "Could not record migration {$migrationKey}: " . $connection->error
        );
    }
    $stmt->close();

    echo "Migration applied: {$migrationKey}" . PHP_EOL;
}

exit(0);
