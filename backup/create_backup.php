<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";

require_once "../includes/audit.php";

$conn = Database::connection();
$conn->begin_transaction();

// Ensure admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$adminId = $_SESSION['user_id'];

try {

    // Create backup
    $backupPath = createDatabaseBackup();

    if (!$backupPath || !file_exists($backupPath)) {
        throw new Exception("Backup creation failed.");
    }

    // File information
    $filename = basename($backupPath);
    $fileSize = filesize($backupPath);
    $checksum = generateChecksum($backupPath);

    // Save to database
    $saved = saveBackupRecord(
        $conn,
        $filename,
        "manual",
        $fileSize,
        $checksum,
        $adminId
    );

    if (!$saved) {
        // Remove the created file if DB insert failed
        @unlink($backupPath);
        throw new Exception("Backup created but could not be saved in the database.");
    }

    /*
    |--------------------------------------------------------------------------
    | Audit Log
    |--------------------------------------------------------------------------
    */

    if (function_exists('logAudit')) {
        logAudit(
            $conn,
            $adminId,
            "Backups",
            "Created backup: {$filename}",
            "CREATE"
        );
    }
    $conn->commit();

    $_SESSION['success'] = "Database backup created successfully.";

} catch (Throwable $e) {

    if ($conn->errno === 0) {
        $conn->rollback();
    }

    if (isset($backupPath) && file_exists($backupPath)) {
        @unlink($backupPath);
    }

    $_SESSION['error'] = $e->getMessage();
}

header("Location: backup_list.php");
exit;