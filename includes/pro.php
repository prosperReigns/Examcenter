<?php
declare(strict_types=1);

require_once __DIR__ . '/../license/ProEntitlementVerifier.php';

function pro_enabled(string $feature = 'pro'): bool
{
    return ProEntitlementVerifier::hasFeature($feature);
}

function require_pro(string $feature = 'pro', string $message = 'This feature requires Examcenter Pro.'): void
{
    if (pro_enabled($feature)) return;

    http_response_code(402);
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Examcenter Pro</title></head><body>';
    echo '<h2>Examcenter Pro feature</h2><p>' . $safe . '</p>';
    echo '<p>Core Examcenter functionality remains available offline.</p>';
    echo '</body></html>';
    exit;
}
