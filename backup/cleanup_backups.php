<?php

require_once "../db.php";
require_once "backup_functions.php";
require_once "../includes/audit.php";

$conn = Database::connection();

function cleanupExpiredBackups(mysqli $conn): int
{
    $settings = getBackupSettings($conn);

    if (!$settings) {
        return 0;
    }

    $days = (int)$settings['retention_days'];

    $stmt = $conn->prepare("
        SELECT id, filename
        FROM backups
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");

    $stmt->bind_param("i", $days);
    $stmt->execute();

    $result = $stmt->get_result();

    $deleted = 0;

    while ($backup = $result->fetch_assoc()) {

        $file = BACKUP_DIRECTORY .
            DIRECTORY_SEPARATOR .
            $backup['filename'];

        if (file_exists($file)) {
            @unlink($file);
        }

        deleteBackupRecord($conn, $backup['id']);

        $deleted++;
    }

    $stmt->close();

    return $deleted;
}

function cleanupMaximumBackups(mysqli $conn): int
{
    $settings = getBackupSettings($conn);

    if (!$settings) {
        return 0;
    }

    $maximum = (int)$settings['max_backups'];

    $result = $conn->query("
        SELECT id, filename
        FROM backups
        ORDER BY created_at DESC
    ");

    $deleted = 0;
    $counter = 0;

    while ($backup = $result->fetch_assoc()) {

        $counter++;

        if ($counter <= $maximum) {
            continue;
        }

        $file = BACKUP_DIRECTORY .
            DIRECTORY_SEPARATOR .
            $backup['filename'];

        if (file_exists($file)) {
            @unlink($file);
        }

        deleteBackupRecord($conn, $backup['id']);

        $deleted++;
    }

    return $deleted;
}

function cleanupOrphanFiles(mysqli $conn): int
{
    $deleted = 0;

    foreach (glob(BACKUP_DIRECTORY . "/*.sql") as $file) {

        $filename = basename($file);

        $stmt = $conn->prepare("
            SELECT id
            FROM backups
            WHERE filename=?
        ");

        $stmt->bind_param("s", $filename);
        $stmt->execute();

        $exists = $stmt->get_result()->num_rows > 0;

        $stmt->close();

        if (!$exists) {

            @unlink($file);

            $deleted++;

        }

    }

    return $deleted;
}

function cleanupOrphanRecords(mysqli $conn): int
{
    $result = $conn->query("
        SELECT id, filename
        FROM backups
    ");

    $deleted = 0;

    while ($backup = $result->fetch_assoc()) {

        $file = BACKUP_DIRECTORY .
            DIRECTORY_SEPARATOR .
            $backup['filename'];

        if (!file_exists($file)) {

            deleteBackupRecord(
                $conn,
                $backup['id']
            );

            $deleted++;

        }

    }

    return $deleted;
}

function runBackupCleanup(
    mysqli $conn,
    int $adminId = 0
): array
{
    $conn->begin_transaction();

    try {

        $expired = cleanupExpiredBackups($conn);

        $maximum = cleanupMaximumBackups($conn);

        $files = cleanupOrphanFiles($conn);

        $records = cleanupOrphanRecords($conn);

        if (function_exists("logAudit")) {

            logAudit(
                $conn,
                $adminId,
                "Backups",
                "Automatic cleanup completed. "
                . "Expired={$expired}, "
                . "Extra={$maximum}, "
                . "Orphan Files={$files}, "
                . "Orphan Records={$records}",
                "CLEANUP"
            );

        }

        $conn->commit();

        return [

            "expired" => $expired,

            "maximum" => $maximum,

            "orphan_files" => $files,

            "orphan_records" => $records,

            "success" => true

        ];

    } catch (Throwable $e) {

        $conn->rollback();

        error_log($e->getMessage());

        return [

            "success" => false,

            "error" => $e->getMessage()

        ];

    }
}

