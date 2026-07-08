<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";

require_once "../includes/audit.php";

$conn = Database::connection();
$conn->begin_transaction();

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

if (!file_exists($file)) {
    $_SESSION['error'] = "Backup file not found.";
    header("Location: backup_list.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Verify checksum
|--------------------------------------------------------------------------
*/

if (!verifyBackup($file, $backup['checksum'])) {

    $_SESSION['error'] =
        "Backup verification failed. File may be corrupted.";

    header("Location: backup_list.php");
    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Create emergency backup
    |--------------------------------------------------------------------------
    */

    $emergencyBackup = createDatabaseBackup();

    if (!$emergencyBackup) {
        throw new Exception(
            "Unable to create emergency backup before restore."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    if (!restoreDatabaseBackup($file)) {
        throw new Exception(
            "Database restore failed."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    if (function_exists("logAudit")) {
        logAudit(
            $conn,
            $_SESSION['user_id'],
            "Backups",
            "Restored backup '{$backup['filename']}'",
            "RESTORE"
        );
    }

    $_SESSION['success'] =
        "Database restored successfully.<br>
        Emergency backup created:<br><strong>" .
        basename($emergencyBackup) .
        "</strong>";

    $conn->commit();
}
catch(Throwable $e){
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
}

header("Location: backup_list.php");
exit;