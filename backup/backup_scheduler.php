<?php

require_once "../db.php";
require_once "backup_functions.php";
require_once "../includes/audit.php";
require_once "cleanup_backups.php";


$conn = Database::connection();

// Fetch admin profile
$admin_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed for admin profile: " . $conn->error);
    die("Database error");
}
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    error_log("No admin found for user_id=$admin_id");
    session_destroy();
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

runBackupCleanup(
    $conn,
    $admin_id
);

function getBackupSettings(mysqli $conn): ?array
{
    $result = $conn->query("
        SELECT *
        FROM backup_settings
        LIMIT 1
    ");

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function updateBackupSchedule(
    mysqli $conn,
    string $frequency
): bool
{
    $now = new DateTime();

    $next = clone $now;

    switch ($frequency) {

        case "hourly":
            $next->modify("+1 hour");
            break;

        case "daily":
            $next->modify("+1 day");
            break;

        case "weekly":
            $next->modify("+1 week");
            break;

        case "monthly":
            $next->modify("+1 month");
            break;

        default:
            $next->modify("+1 day");
    }

    $stmt = $conn->prepare("
        UPDATE backup_settings
        SET
            last_backup = NOW(),
            next_backup = ?
        WHERE id = 1
    ");

    $nextBackup = $next->format("Y-m-d H:i:s");

    $stmt->bind_param("s", $nextBackup);

    $result = $stmt->execute();

    $stmt->close();

    return $result;
}

function backupIsDue(array $settings): bool
{
    if (!$settings['auto_backup_enabled']) {
        return false;
    }

    if (empty($settings['next_backup'])) {
        return true;
    }

    return strtotime($settings['next_backup']) <= time();
}

function runBackupScheduler(
    mysqli $conn,
    int $adminId = 0
): bool
{
    $settings = getBackupSettings($conn);

    if (!$settings) {
        return false;
    }

    if (!backupIsDue($settings)) {
        return false;
    }

    $conn->begin_transaction();

    try {

        $backupPath = createDatabaseBackup();

        if (!$backupPath) {
            throw new Exception("Automatic backup failed.");
        }

        $filename = basename($backupPath);

        $saved = saveBackupRecord(
            $conn,
            $filename,
            "automatic",
            filesize($backupPath),
            generateChecksum($backupPath),
            $adminId
        );

        if (!$saved) {
            throw new Exception("Unable to save automatic backup.");
        }

        if (!updateBackupSchedule(
            $conn,
            $settings['backup_frequency']
        )) {
            throw new Exception("Unable to update schedule.");
        }

        if (function_exists("logAudit")) {

            logAudit(
                $conn,
                $adminId,
                "Backups",
                "Automatic backup created '{$filename}'",
                "AUTO BACKUP"
            );

        }

        $conn->commit();

        return true;

    } catch (Throwable $e) {

        $conn->rollback();

        if (
            isset($backupPath) &&
            file_exists($backupPath)
        ) {
            @unlink($backupPath);
        }

        error_log($e->getMessage());

        return false;
    }
}
cleanupExpiredBackups($conn);
cleanupMaximumBackups($conn);