<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";

require_once "../includes/audit.php";

$conn = Database::connection();

// Ensure admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid backup selected.";
    header("Location: backup_list.php");
    exit;
}

$id = (int)$_GET['id'];

$backup = getBackup($conn, $id);

if (!$backup) {
    $_SESSION['error'] = "Backup record not found.";
    header("Location: backup_list.php");
    exit;
}

$file = BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $backup['filename'];

$realBackupDir = realpath(BACKUP_DIRECTORY);
$realFile = file_exists($file) ? realpath($file) : null;

if ($realFile === false || strncmp($realFile, $realBackupDir, strlen($realBackupDir)) !== 0) {
    $_SESSION['error'] = "Invalid backup path.";
    header("Location: backup_list.php");
    exit;
}

if (!file_exists($file)) {
    $_SESSION['error'] = "Backup file no longer exists.";
    header("Location: backup_list.php");
    exit;
}

// Verify checksum before download
if (!verifyBackup($file, $backup['checksum'])) {
    $_SESSION['error'] = "Backup integrity verification failed.";
    header("Location: backup_list.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Audit Log
|--------------------------------------------------------------------------
*/
if (function_exists('logAudit')) {
    logAudit(
        $conn,
        $_SESSION['user_id'],
        "Backups",
        "DOWNLOAD",
        "Downloaded backup '{$backup['filename']}'"
        
    );
}

// Clear output buffer
if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache');
header('Pragma: public');
header('Expires: 0');

$handle = fopen($file, "rb");

while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}

fclose($handle);
exit;