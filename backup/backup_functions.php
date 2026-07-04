<?php

require_once "config.php";

/**
 * Generate backup filename
 */
function generateBackupFilename()
{
    return "cbt_backup_" . date("Y-m-d_H-i-s") . ".sql";
}


/**
 * Convert bytes to readable format
 */
function formatFileSize($bytes)
{
    if ($bytes >= 1073741824)
        return number_format($bytes / 1073741824, 2) . " GB";

    if ($bytes >= 1048576)
        return number_format($bytes / 1048576, 2) . " MB";

    if ($bytes >= 1024)
        return number_format($bytes / 1024, 2) . " KB";

    return $bytes . " Bytes";
}


/**
 * Generate SHA256 checksum
 */
function generateChecksum($file)
{
    return hash_file("sha256", $file);
}


/**
 * Verify backup integrity
 */
function verifyBackup($file, $checksum)
{
    return hash_file("sha256", $file) === $checksum;
}


/**
 * Save backup record
 */
function saveBackupRecord(
    mysqli $conn,
    $filename,
    $backupType,
    $filesize,
    $checksum,
    $createdBy
)
{
    $stmt = $conn->prepare("
        INSERT INTO backups
        (
            filename,
            backup_type,
            file_size,
            checksum,
            created_by
        )
        VALUES
        (?,?,?,?,?)
    ");

    $stmt->bind_param(
        "ssisi",
        $filename,
        $backupType,
        $filesize,
        $checksum,
        $createdBy
    );

    return $stmt->execute();
}


/**
 * Get backup by ID
 */
function getBackup(mysqli $conn, $id)
{
    $stmt = $conn->prepare("
        SELECT *
        FROM backups
        WHERE id=?
    ");

    $stmt->bind_param("i", $id);

    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}


/**
 * Delete backup record
 */
function deleteBackupRecord(mysqli $conn, $id)
{
    $stmt = $conn->prepare("
        DELETE
        FROM backups
        WHERE id=?
    ");

    $stmt->bind_param("i", $id);

    return $stmt->execute();
}


/**
 * Get all backups
 */
function getAllBackups(mysqli $conn)
{
    return $conn
        ->query("
            SELECT
                backups.*,
                admins.full_name
            FROM backups

            LEFT JOIN admins
            ON admins.id=backups.created_by

            ORDER BY backups.created_at DESC
        ");
}


/**
 * Execute mysqldump
 */
function createDatabaseBackup()
{
    global
        $dbHost,
        $dbUser,
        $dbPass,
        $dbName,
        $backupDirectory,
        $mysqldump;

    if (!file_exists($mysqldump)) {
        return false;
    }

    $filename = generateBackupFilename();

    $filepath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;

    $command = "\"{$mysqldump}\" "
        . "--host=\"{$dbHost}\" "
        . "--user=\"{$dbUser}\" ";

    if (!empty($dbPass)) {
        $command .= "--password=\"{$dbPass}\" ";
    }

    $command .= "--routines --triggers --events ";
    $command .= "--single-transaction ";
    $command .= "--databases \"{$dbName}\" ";
    $command .= "> \"{$filepath}\"";

    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        return false;
    }

    if (!file_exists($filepath) || filesize($filepath) == 0) {
        return false;
    }

    return $filepath;
}


/**
 * Restore a backup using the mysql client.
 */
function restoreDatabaseBackup($backupFile)
{
    global $dbHost,
           $dbUser,
           $dbPass,
           $dbName,
           $mysql;

    if (!file_exists($mysql)) {
        return false;
    }

    if (!file_exists($backupFile)) {
        return false;
    }

    $command = "\"{$mysql}\" ";
    $command .= "--host=\"{$dbHost}\" ";
    $command .= "--user=\"{$dbUser}\" ";

    if (!empty($dbPass)) {
        $command .= "--password=\"{$dbPass}\" ";
    }

    $command .= "\"{$dbName}\" < \"{$backupFile}\"";

    exec($command, $output, $returnCode);

    return ($returnCode === 0);
}