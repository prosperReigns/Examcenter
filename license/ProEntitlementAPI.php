<?php
declare(strict_types=1);

require_once __DIR__ . '/installation.php';
require_once __DIR__ . '/fingerprint.php';
require_once __DIR__ . '/ProEntitlementVerifier.php';

final class ProEntitlementAPI
{
    private static function config(): array
    {
        $config = require __DIR__ . '/../config/license.php';
        return $config['pro_v2'] ?? [];
    }

    private static function request(string $method, string $path, array $payload = []): array
    {
        $base = rtrim((string)(self::config()['server'] ?? ''), '/');
        if ($base === '') throw new RuntimeException('Pro entitlement server is not configured.');

        $url = $base . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) throw new RuntimeException('Unable to encode Pro request.');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT => (int)(self::config()['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => (int)(self::config()['connect_timeout'] ?? 5),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Examcenter/' . (string)((require __DIR__ . '/../config/app.php')['version'] ?? 'unknown')
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error ?: 'Pro server request failed.');
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);
        if (!is_array($json)) throw new RuntimeException('Invalid Pro server response.');
        if ($status >= 400) throw new RuntimeException((string)($json['detail'] ?? 'Pro server request failed.'));
        return $json;
    }

    public static function activate(string $activationToken): array
    {
        $response = self::request('POST','/api/v2/public/entitlements/activate',[
            'activation_token'=>$activationToken,
            'installation_id'=>InstallationIdentity::id(),
            'machine_id'=>MachineFingerprint::generate(),
            'computer_name'=>gethostname(),
            'app_version'=>(string)((require __DIR__ . '/../config/app.php')['version'] ?? '')
        ]);

        if (!empty($response['signed_package'])) {
            (new ProEntitlementVerifier())->verify($response['signed_package']);
            ProEntitlementStorage::store($response['signed_package']);
        }

        return $response;
    }

    public static function heartbeat(): array
    {
        $current = ProEntitlementVerifier::current();
        if ($current === null) throw new RuntimeException('No valid local Pro entitlement.');

        return self::request('POST','/api/v2/public/entitlements/heartbeat',[
            'entitlement_id'=>$current['entitlement']['id'],
            'installation_id'=>InstallationIdentity::id(),
            'machine_id'=>MachineFingerprint::generate(),
            'app_version'=>(string)((require __DIR__ . '/../config/app.php')['version'] ?? '')
        ]);
    }

    public static function plans(): array
    {
        return self::request('GET','/api/v2/public/plans');
    }
}
