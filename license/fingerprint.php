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
    private static function runCommand(string $command): string
    {
        if (!function_exists("shell_exec")) {
            return "";
        }

        $output = @shell_exec($command . " 2>NUL");

        return trim((string)$output);
    }

    private static function runPowerShell(string $command): string
    {
        return self::runCommand(
            'powershell -NoProfile -ExecutionPolicy Bypass -Command "' .
            $command .
            '"'
        );
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
    private static function getBiosSerial(): string
    {
        $output = self::runCommand(
            "wmic bios get serialnumber"
        );

        $lines = array_values(
            array_filter(
                array_map("trim", explode("\n", $output))
            )
        );

        if (!empty($lines[1])) {
            return $lines[1];
        }

        return trim(
            self::runPowerShell(
                "(Get-CimInstance Win32_BIOS).SerialNumber"
            )
        );
    }

    /**
     * Motherboard Serial
     */
    private static function getBoardSerial(): string
    {
        $output = self::runCommand(
            "wmic baseboard get serialnumber"
        );

        $lines = array_values(
            array_filter(
                array_map("trim", explode("\n", $output))
            )
        );

        if (!empty($lines[1])) {
            return $lines[1];
        }

        return trim(
            self::runPowerShell(
                "(Get-CimInstance Win32_BaseBoard).SerialNumber"
            )
        );
    }

    /**
     * UUID
     */
    private static function getUUID(): string
    {
        $output = self::runCommand(
            "wmic csproduct get uuid"
        );

        $lines = array_values(
            array_filter(
                array_map("trim", explode("\n", $output))
            )
        );

        if (!empty($lines[1])) {
            return $lines[1];
        }

        return trim(
            self::runPowerShell(
                "(Get-CimInstance Win32_ComputerSystemProduct).UUID"
            )
        );
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
        $parts = array_filter([
            self::getUUID(),
            self::getBoardSerial(),
            self::getBiosSerial(),
            self::getVolumeSerial(),

        ]);
        $parts = array_filter($parts);

        if (count($parts) < 2) {
            throw new RuntimeException(
                "Unable to generate a reliable machine fingerprint."
            );

        }
        $raw = implode("|", $parts);

        if ($raw === "") {
            $raw = php_uname();
        }
        
        return strtoupper(hash('sha256', $raw));
    }
    public static function details(): array
    {
        return [

            "computer_name" => self::getComputerName(),

            "uuid" => self::getUUID(),

            "bios_serial" => self::getBiosSerial(),

            "board_serial" => self::getBoardSerial(),

            "volume_serial" => self::getVolumeSerial(),

            "fingerprint" => self::generate()

        ];
    }
}