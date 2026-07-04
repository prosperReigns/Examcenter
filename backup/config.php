<?php
/**
 * Backup Configuration
 * --------------------------------------
 * Update these values to match your server.
 */

require_once "../db.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Database Credentials
|--------------------------------------------------------------------------
|
| You may hardcode them or include them from your db.php
|
*/

$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "cbt_exam";     // Change to your database name


/*
|--------------------------------------------------------------------------
| Backup Directory
|--------------------------------------------------------------------------
*/

$backupDirectory = __DIR__ . DIRECTORY_SEPARATOR . "backups";

if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0755, true);
}


/*
|--------------------------------------------------------------------------
| MySQL Executables
|--------------------------------------------------------------------------
|
| XAMPP Default Installation
|
*/

$mysqldump = "C:\\xampp\\mysql\\bin\\mysqldump.exe";

$mysql = "C:\\xampp\\mysql\\bin\\mysql.exe";


/*
|--------------------------------------------------------------------------
| Maximum Upload Size
|--------------------------------------------------------------------------
*/

$maxBackupUpload = 100 * 1024 * 1024; //100MB