<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";

require_once "../includes/audit.php";

// Ensure admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_POST['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid backup selected.";
    header("Location: backup_list.php");
    exit;
}

$id = (int) $_GET['id'];

$backup = getBackup($conn, $id);

if (!$backup) {
    $_SESSION['error'] = "Backup record not found.";
    header("Location: backup_list.php");
    exit;
}

$file = $backupDirectory . DIRECTORY_SEPARATOR . $backup['filename'];

// Verify the file path is inside the backup directory
$realBackupDir = realpath($backupDirectory);
$realFile = file_exists($file) ? realpath($file) : null;

if ($realFile !== null && strpos($realFile, $realBackupDir) !== 0) {
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
            "Delete Backup",
            "Deleted backup: " . $backup['filename']
        );

    }

    $_SESSION['success'] = "Backup deleted successfully.";

} catch (Exception $e) {

    $_SESSION['error'] = $e->getMessage();

}

header("Location: backup_list.php");
exit;