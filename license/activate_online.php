<?php
declare(strict_types=1);

require_once __DIR__ . '/ProEntitlementAPI.php';
require_once __DIR__ . '/license_api.php';
require_once __DIR__ . '/verify.php';

$token = $_GET['token'] ?? null;

if (!$token) {
    http_response_code(400);
    exit('Activation token missing');
}

/*
 * Pro v2 is the primary activation path.
 * The legacy license path remains as a compatibility fallback.
 */
try {
    $response = ProEntitlementAPI::activate((string)$token);

    echo 'Examcenter Pro activation successful.';
    exit;
} catch (Throwable $proError) {
    error_log('Pro v2 activation failed; attempting legacy activation: ' . $proError->getMessage());
}

try {
    $response = LicenseAPI::fetchLicense((string)$token);

    if (empty($response['license'])) {
        throw new RuntimeException('License not received');
    }

    $verifier = new LicenseVerifier();
    $verifier->activate($response['license']);

    echo 'Legacy Examcenter activation successful.';
} catch (Throwable $e) {
    http_response_code(400);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
