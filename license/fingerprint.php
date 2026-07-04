<!-- generates computer fingerprint -->
 <?php

/**
 * fingerprint.php
 * Generates a unique machine fingerprint.
 */

class MachineFingerprint
{
    /**
     * Execute Windows command safely
     */
    private static function runCommand($command)
    {
        $output = @shell_exec($command);

        if (!$output) {
            return '';
        }

        return trim($output);
    }

    /**
     * Get Windows Volume Serial Number
     */
    private static function getVolumeSerial()
    {
        $output = self::runCommand('vol C:');

        if (preg_match('/Serial Number is ([A-Z0-9\-]+)/i', $output, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * BIOS Serial
     */
    private static function getBiosSerial()
    {
        $output = self::runCommand('wmic bios get serialnumber');

        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return $lines[1] ?? '';
    }

    /**
     * Motherboard Serial
     */
    private static function getBoardSerial()
    {
        $output = self::runCommand('wmic baseboard get serialnumber');

        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return $lines[1] ?? '';
    }

    /**
     * UUID
     */
    private static function getUUID()
    {
        $output = self::runCommand('wmic csproduct get uuid');

        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return $lines[1] ?? '';
    }

    /**
     * Computer Name
     */
    private static function getComputerName()
    {
        return getenv('COMPUTERNAME') ?: php_uname('n');
    }

    /**
     * Generate Fingerprint
     */
    public static function generate()
    {
        $parts = [

            self::getUUID(),

            self::getBoardSerial(),

            self::getBiosSerial(),

            self::getVolumeSerial(),

            self::getComputerName()

        ];

        $raw = implode('|', $parts);

        return strtoupper(hash('sha256', $raw));
    }
}