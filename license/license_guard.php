<?php
declare(strict_types=1);

/**
 * Examcenter Pro Architecture v2 compatibility guard.
 *
 * Core exam operations are intentionally NOT license-blocked.
 * This policy is permanent: Core Free features never require a Pro entitlement.
 * Pro access is checked at individual Pro feature boundaries.
 *
 * The old license implementation remains available as a migration adapter,
 * but an expired/unavailable Pro entitlement must never prevent Core CBT use.
 */

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/../includes/ProEntitlementStorage.php";
require_once __DIR__ . "/ProEntitlementVerifier.php";

$GLOBALS['examcenter_pro'] = [
    'active' => ProEntitlementVerifier::hasFeature('pro'),
    'features' => [],
];

$package = ProEntitlementVerifier::current();
if ($package !== null) {
    $GLOBALS['examcenter_pro']['features'] = $package['entitlement']['features'] ?? [];
}

/*
 * Best-effort online heartbeat. Never block Core when the server is unavailable.
 * This is deliberately not executed on license-management pages.
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$skipHeartbeat = in_array($currentPage, [
    'activate.php','activate_online.php','required.php','expired.php','renew.php',
    'purchase.php','waiting.php','download.php','verify.php'
], true);

if (!$skipHeartbeat && $package !== null) {
    try {
        require_once __DIR__ . '/ProEntitlementAPI.php';
        ProEntitlementAPI::heartbeat();
    } catch (Throwable $e) {
        error_log('Examcenter Pro heartbeat unavailable: ' . $e->getMessage());
    }
}
