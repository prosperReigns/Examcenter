<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const MIGRATION_KEY = '20261005_0001_universal_architecture';

$db = Database::connection();

$db->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(191) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$check = $db->prepare("SELECT id FROM schema_migrations WHERE migration_key = ? LIMIT 1");
$check->bind_param('s', $key = MIGRATION_KEY);
$check->execute();
$already = $check->get_result()->fetch_assoc();
$check->close();

if ($already) {
    echo "Migration already applied: " . MIGRATION_KEY . PHP_EOL;
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/migrations/' . MIGRATION_KEY . '.sql');
if ($sql === false) {
    throw new RuntimeException('Migration SQL file could not be read.');
}

if (!$db->multi_query($sql)) {
    throw new RuntimeException('Migration failed: ' . $db->error);
}

do {
    if ($result = $db->store_result()) {
        $result->free();
    }
} while ($db->more_results() && $db->next_result());

if ($db->errno) {
    throw new RuntimeException('Migration failed: ' . $db->error);
}

$stmt = $db->prepare("INSERT INTO schema_migrations (migration_key) VALUES (?)");
$stmt->bind_param('s', $key);
$stmt->execute();
$stmt->close();

echo "Migration applied: " . MIGRATION_KEY . PHP_EOL;
