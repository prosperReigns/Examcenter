<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/ProEntitlementStorage.php';
require_once __DIR__ . '/installation.php';
require_once __DIR__ . '/fingerprint.php';

final class ProEntitlementVerifier
{
    private const PACKAGE_TYPE = 'examcenter_pro_entitlement';
    private const PACKAGE_VERSION = 2;

    private string $publicKey;

    public function __construct(?string $publicKeyPath = null)
    {
        $path = $publicKeyPath ?: __DIR__ . '/../keys/public.pem';
        if (!is_file($path)) throw new RuntimeException('Pro entitlement public key not found.');
        $this->publicKey = (string)file_get_contents($path);
    }

    public function verify(string $document): array
    {
        $package = json_decode(trim($document), true);
        if (!is_array($package)) throw new RuntimeException('Invalid Pro entitlement JSON.');
        if (($package['package_type'] ?? null) !== self::PACKAGE_TYPE) throw new RuntimeException('Unsupported entitlement package type.');
        if ((int)($package['package_version'] ?? 0) !== self::PACKAGE_VERSION) throw new RuntimeException('Unsupported entitlement package version.');

        $payload = $package['entitlement'] ?? null;
        if (!is_array($payload)) throw new RuntimeException('Entitlement payload missing.');

        foreach (['id','product_code','edition','installation_id','machine_id','issued_at','starts_at','expires_at','features'] as $field) {
            if (!array_key_exists($field, $payload)) throw new RuntimeException("Entitlement field missing: {$field}");
        }

        if ($payload['product_code'] !== 'examcenter' || $payload['edition'] !== 'pro') throw new RuntimeException('Entitlement is not for Examcenter Pro.');
        if (!hash_equals(InstallationIdentity::id(), (string)$payload['installation_id'])) throw new RuntimeException('Entitlement installation mismatch.');
        if (!hash_equals(MachineFingerprint::generate(), (string)$payload['machine_id'])) throw new RuntimeException('Entitlement machine mismatch.');

        $canonical = $this->canonicalJson($payload);
        if (!hash_equals((string)($package['checksum'] ?? ''), hash('sha256', $canonical))) throw new RuntimeException('Entitlement checksum mismatch.');

        $signature = base64_decode((string)($package['signature'] ?? ''), true);
        if ($signature === false) throw new RuntimeException('Invalid entitlement signature encoding.');

        $key = openssl_pkey_get_public($this->publicKey);
        if ($key === false || openssl_verify($canonical, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Entitlement signature verification failed.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (new DateTimeImmutable((string)$payload['starts_at']) > $now) throw new RuntimeException('Entitlement is not active yet.');
        if ($payload['expires_at'] !== null && new DateTimeImmutable((string)$payload['expires_at']) <= $now) throw new RuntimeException('Entitlement has expired.');

        return $package;
    }

    private function canonicalJson(array $data): string
    {
        $this->sortKeys($data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('Unable to canonicalize entitlement.');
        return $json;
    }

    private function sortKeys(array &$data): void
    {
        if ($this->isAssociative($data)) uksort($data, static fn($a,$b)=>strcmp((string)$a,(string)$b));
        foreach ($data as &$value) if (is_array($value)) $this->sortKeys($value);
        unset($value);
    }

    private function isAssociative(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    public static function current(): ?array
    {
        try {
            $raw = ProEntitlementStorage::get();
            return $raw === null ? null : (new self())->verify($raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function hasFeature(string $feature = 'pro'): bool
    {
        $package = self::current();
        if ($package === null) return false;
        if ($feature === 'pro') return true;
        return !empty($package['entitlement']['features'][$feature]);
    }
}
