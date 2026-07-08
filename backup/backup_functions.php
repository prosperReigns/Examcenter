<?php

require_once "config.php";
define(
    'BACKUP_DIRECTORY',
    __DIR__ . DIRECTORY_SEPARATOR . 'backups'
);

if (!is_dir(BACKUP_DIRECTORY)) {

    if (!mkdir(BACKUP_DIRECTORY, 0755, true)) {

        throw new RuntimeException(
            "Unable to create backup directory."
        );

    }

}

if (!is_writable(BACKUP_DIRECTORY)) {
    throw new RuntimeException(
        "Backup directory is not writable."
    );
}

function getBackupConfig(): array
{
    return [
        'dbHost'      => $GLOBALS['dbHost'],
        'dbUser'      => $GLOBALS['dbUser'],
        'dbPass'      => $GLOBALS['dbPass'],
        'dbName'      => $GLOBALS['dbName'],
        'mysqldump'   => $GLOBALS['mysqldump'],
        'mysql'       => $GLOBALS['mysql'],
        'backupDir'   => BACKUP_DIRECTORY
    ];
}


/**
 * Generate backup filename
 */
function generateBackupFilename(): string
{
   return sprintf(
        "%s_backup_%s.sql",
        $GLOBALS['dbName'],
        date("Y-m-d_H-i-s")
    );
}


/**
 * Convert bytes to readable format
 */
function formatFileSize(int $bytes): string
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
function generateChecksum(string $file): string
{
    if (!file_exists($file)) {
        return "";
    }

    return hash_file("sha256", $file);
}


/**
 * Verify backup integrity
 */
function verifyBackup(string $file, string $checksum): bool
{
    if (!file_exists($file)) {
        return false;
    }

    return hash_equals(
        $checksum,
        hash_file("sha256",$file)
    );
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
): bool
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

    $result = $stmt->execute();
    $stmt->close();

    return $result;
}


/**
 * Get backup by ID
 */
function getBackup(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM backups
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $backup = $result->fetch_assoc();

    $stmt->close();

    return $backup ?: null;
}


/**
 * Delete backup record
 */
function deleteBackupRecord(mysqli $conn, $id): bool
{
    $stmt = $conn->prepare("
        DELETE
        FROM backups
        WHERE id=?
    ");

    $stmt->bind_param("i", $id);

    $result = $stmt->execute();

    $stmt->close();

    return $result;
}


/**
 * Get all backups
 */
function getAllBackups(mysqli $conn): mysqli_result|false
{
    return $conn
        ->query("
            SELECT
                backups.*,
                admins.username
            FROM backups

            LEFT JOIN admins
            ON admins.id=backups.created_by

            ORDER BY backups.created_at DESC
        ");
}


/**
 * Execute mysqldump
 */
function createDatabaseBackup():string|false{
        try{
            $config = getBackupConfig();

            extract($config);

            if (!file_exists($mysqldump)) {
                return false;
            }

            $filename = generateBackupFilename();

            $filepath = BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $filename;

            $command  = escapeshellarg($mysqldump) . " ";
            $command .= "--host=" . escapeshellarg($dbHost) . " ";
            $command .= "--user=" . escapeshellarg($dbUser) . " ";

            if (!empty($dbPass)) {
                $command .= "--password=" . escapeshellarg($dbPass) . " ";
            }

            $command .= "--routines --triggers --events ";
            $command .= "--single-transaction ";
            $command .= "--databases " . escapeshellarg($dbName) . " ";
            $command .= "> " . escapeshellarg($filepath);

            $lock = fopen(
            BACKUP_DIRECTORY . "/backup.lock",
                "c"
            );

            if ($lock === false) {
                return false;
            }

            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                return false;
            }

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                error_log(
                    implode(PHP_EOL, $output)
                );
            }

            flock($lock, LOCK_UN);

            fclose($lock);

            if ($returnCode !== 0) {
                return false;
            }

            if (!file_exists($filepath) || filesize($filepath) === 0) {
                return false;
            }

            return $filepath;
        }catch(Throwable $e) {
            error_log($e->getMessage());
            return false;
        }
        
    }



/**
 * Restore a backup using the mysql client.
 */
function restoreDatabaseBackup(string $backupFile):bool
    {
        try{
            $config = getBackupConfig();

        extract($config);

        if (!file_exists($mysql)) {
            return false;
        }

        if (!file_exists($backupFile)) {
            return false;
        }

        if (filesize($backupFile) === 0) {
            return false;
        }

        if (
            strtolower(pathinfo(
                $backupFile,
                PATHINFO_EXTENSION
            )) !== "sql"
        ) {
            return false;
        }
        $command = escapeshellarg($mysql) . " ";
        $command .= "--host=" . escapeshellarg($dbHost) . " ";
        $command .= "--user=" . escapeshellarg($dbUser) . " ";

        if (!empty($dbPass)) {
            $command .= "--password=" . escapeshellarg($dbPass) . " ";
        }

        $command .= escapeshellarg($dbName) . " < " . escapeshellarg($backupFile);

        $lock = fopen(
        BACKUP_DIRECTORY . "/backup.lock",
            "c"
        );

        if ($lock === false) {
            return false;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }

        exec($command, $output, $returnCode);

        flock($lock, LOCK_UN);

        fclose($lock);

        return ($returnCode === 0);
        }catch (Throwable $e){
            error_log($e->getMessage());
            return false;
        }
        
    }
