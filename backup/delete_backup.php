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

if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    $_SESSION['error'] = "Invalid backup selected.";
    header("Location: backup_list.php");
    exit;
}

$id = (int) $_POST['id'];

$backup = getBackup($conn, $id);

if (!$backup) {
    $_SESSION['error'] = "Backup record not found.";
    header("Location: backup_list.php");
    exit;
}

$file = BACKUP_DIRECTORY. DIRECTORY_SEPARATOR . $backup['filename'];

// Verify the file path is inside the backup directory
$realBackupDir = realpath(BACKUP_DIRECTORY);

if (!is_dir(BACKUP_DIRECTORY)) {
    $_SESSION['error'] = "Backup directory does not exist.";
    header("Location: backup_list.php");
    exit;
}

$realFile = file_exists($file) ? realpath($file) : null;

if (
    $realFile !== null &&
    strncmp($realFile, $realBackupDir, strlen($realBackupDir)) !== 0
) {
    $_SESSION['error'] = "Invalid backup path.";
    header("Location: backup_list.php");
    exit;
}

try {

    // Delete physical file if it exists
    if ($realFile !== null && file_exists($realFile)) {

        if (!unlink($realFile)) {
            throw new Exception("Unable to delete backup file.");
        }
    }

    // Delete database record
    if (!deleteBackupRecord($conn, $id)) {
        throw new Exception("Unable to delete backup record.");
    }

    /*
    |--------------------------------------------------------------------------
    | Audit Log
    |--------------------------------------------------------------------------
    */

    if (function_exists("logAudit")) {

        logAudit(
            $conn,
            $_SESSION['user_id'],
            "Backups",
            "DELETE",
            "Deleted backup '{$backup['filename']}'"
            
        );

    }

    $_SESSION['success'] = "Backup deleted successfully.";
    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();

    $_SESSION['error'] = $e->getMessage();

}

header("Location: backup_list.php");
exit;