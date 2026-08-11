<?php

session_start();

require_once "../db.php";
require_once "backup_functions.php";
require_once "../includes/audit.php";


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$conn = Database::connection();

if (!$conn instanceof mysqli) {

    $_SESSION['error'] =
        "Unable to connect to the database.";

    header("Location: backup_list.php");
    exit;
}


$adminId = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| Create Backup
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | Step 1: Create SQL backup
    |--------------------------------------------------------------------------
    */

    $backupPath = createDatabaseBackup();


    if (
        $backupPath === false ||
        !is_string($backupPath) ||
        !file_exists($backupPath)
    ) {

        throw new RuntimeException(
            "Backup creation failed. " .
            "The SQL backup file could not be generated."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Step 2: Get file information
    |--------------------------------------------------------------------------
    */

    $filename = basename($backupPath);

    $fileSize = filesize($backupPath);


    if ($fileSize === false || $fileSize <= 0) {

        @unlink($backupPath);

        throw new RuntimeException(
            "Backup creation failed. " .
            "The generated backup file is empty."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Step 3: Generate checksum
    |--------------------------------------------------------------------------
    */

    $checksum = generateChecksum($backupPath);


    if (
        !$checksum ||
        strlen($checksum) !== 64
    ) {

        @unlink($backupPath);

        throw new RuntimeException(
            "Backup was created, but its integrity checksum could not be generated."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Step 4: Save metadata
    |--------------------------------------------------------------------------
    */

    if (
        !saveBackupRecord(
            $conn,
            $filename,
            "manual",
            (int) $fileSize,
            $checksum,
            $adminId
        )
    ) {

        /*
        |----------------------------------------------------------------------
        | Important:
        | Do NOT delete the SQL backup here while debugging.
        |----------------------------------------------------------------------
        */

        throw new RuntimeException(
            "Backup file was created successfully, " .
            "but its database record could not be saved. " .
            "Check the PHP error log for the database error."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Step 5: Audit
    |--------------------------------------------------------------------------
    */

    if (function_exists('logAudit')) {

        logAudit(
            $conn,
            $adminId,
            "Backup",
            "CREATE",
            "Created database backup: {$filename}"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Step 6: Success
    |--------------------------------------------------------------------------
    */

    $_SESSION['success'] =
        "Database backup created successfully. " .
        "Backup: {$filename} (" .
        formatFileSize((int) $fileSize) .
        ").";


} catch (Throwable $e) {

    error_log(
        "Backup creation error: " .
        $e->getMessage()
    );


    $_SESSION['error'] =
        $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

header("Location: backup_list.php");
exit;