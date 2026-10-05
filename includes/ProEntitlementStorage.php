<?php
declare(strict_types=1);

final class ProEntitlementStorage
{
    private static function file(): string
    {
        return __DIR__ . '/../license/storage/pro-entitlement.enc';
    }

    private static function key(): string
    {
        require_once __DIR__ . '/../license/installation.php';
        return hash('sha256', InstallationIdentity::id() . '|examcenter-pro-entitlement-v2', true);
    }

    public static function store(string $document): bool
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($document, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) return false;

        $dir = dirname(self::file());
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        return file_put_contents(
            self::file(),
            json_encode(['version'=>2,'iv'=>base64_encode($iv),'data'=>base64_encode($encrypted)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    public static function get(): ?string
    {
        if (!is_file(self::file())) return null;
        $payload = json_decode((string)file_get_contents(self::file()), true);
        if (!is_array($payload) || empty($payload['iv']) || empty($payload['data'])) return null;

        $plain = openssl_decrypt(
            base64_decode($payload['data'], true),
            'AES-256-CBC',
            self::key(),
            OPENSSL_RAW_DATA,
            base64_decode($payload['iv'], true)
        );

        return $plain === false ? null : $plain;
    }

    public static function delete(): void
    {
        if (is_file(self::file())) unlink(self::file());
    }
}
