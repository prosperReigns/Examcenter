<?php
return [
    "server" => getenv("EXAMCENTER_LICENSE_SERVER") ?: "",
    "portal_url" => getenv("EXAMCENTER_LICENSE_PORTAL_URL") ?: "",
    "api_version" => "v1",
    "timeout" => 20,
    "connect_timeout" => 5,
    "verification_interval" => 7,
    "grace_period" => 7,
    "endpoints" => [
        "trial_url" => "/trial",
        "purchase_start" => "/api/public/start-purchase",
        "purchase_status" => "/api/public/purchase",
        "verify" => "/api/public/validate-license",
        "heartbeat" => "/api/public/devices/heartbeat",
        "checkout" => "/activation",
        "license_delivery" => "/api/public/license",
        "plans" => "/public/plans",
    ],
    "pro_v2" => [
        "server" => getenv("EXAMCENTER_PRO_SERVER") ?: (getenv("EXAMCENTER_LICENSE_SERVER") ?: ""),
        "timeout" => 20,
        "connect_timeout" => 5,
        "plans" => "/api/v2/public/plans",
        "activate" => "/api/v2/public/entitlements/activate",
        "heartbeat" => "/api/v2/public/entitlements/heartbeat",
        "device_change" => "/api/v2/public/entitlements/device-change",
        "feature_check" => "/api/v2/public/entitlements/feature-check",
    ],
    "storage" => [
        "license_file" => __DIR__ . "/../license/storage/license.lic",
        "cache_file" => __DIR__ . "/../license/cache.json",
        "pro_entitlement_file" => __DIR__ . "/../license/storage/pro-entitlement.enc",
    ],
    "crypto" => [
        "public_key" => __DIR__ . "/../keys/public.pem",
    ],
];