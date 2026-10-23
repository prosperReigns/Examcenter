<?php
declare(strict_types=1);

/** Idempotent queue/result migration for existing and fresh Examcenter databases. */
$db = $GLOBALS['db'] ?? Database::connection();

$columnExists = static function (mysqli $connection, string $table, string $column): bool {
    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS found_count
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['found_count'] ?? 0) > 0;
};

$columns = [
    'objective_score' => "ALTER TABLE results ADD COLUMN objective_score DECIMAL(10,2) NULL AFTER score",
    'objective_total' => "ALTER TABLE results ADD COLUMN objective_total DECIMAL(10,2) NULL AFTER objective_score",
    'theory_score' => "ALTER TABLE results ADD COLUMN theory_score DECIMAL(10,2) NULL AFTER objective_total",
    'theory_grading_status' => "ALTER TABLE results ADD COLUMN theory_grading_status VARCHAR(20) NOT NULL DEFAULT 'not_required' AFTER status",
];

foreach ($columns as $column => $sql) {
    if (!$columnExists($db, 'results', $column) && !$db->query($sql)) {
        throw new RuntimeException("Unable to add results.{$column}: " . $db->error);
    }
}

$sql = "CREATE TABLE IF NOT EXISTS theory_grading_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    result_id INT NULL,
    test_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(120) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_theory_grading_submission (submission_id),
    KEY idx_theory_grading_claim (status, available_at, id),
    KEY idx_theory_grading_stale (status, locked_at),
    KEY idx_theory_grading_test_student (test_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$db->query($sql)) {
    throw new RuntimeException('Unable to create theory_grading_jobs: ' . $db->error);
}
