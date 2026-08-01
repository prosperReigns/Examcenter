<?php

return [

    /*
    |--------------------------------------------------------------------------
    | License Server
    |--------------------------------------------------------------------------
    */

    "server" => "https://license.seedofabraham.com",

    "portal_url" =>
        "https://license.seedofabraham.com",

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    */

    "api_version" => "v1",


    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    */

    "timeout" => 20,

    "connect_timeout" => 5,


    /*
    |--------------------------------------------------------------------------
    | License Verification
    |--------------------------------------------------------------------------
    */

    "verification_interval" => 7,

    "grace_period" => 7,


    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */

    "endpoints" => [

        "trial_url" =>
            "/trial",


        "purchase_url" =>
            "/purchase/start",

        /*
        Create purchase session
        */

        "purchase_create" =>
            "/api/v1/purchase-sessions",


        /*
        Check purchase status
        */

        "purchase_status" =>
            "/api/v1/public/purchase",


        /*
        Download signed license
        */

        "license_download" =>
            "/api/v1/public/license/download",


        /*
        Heartbeat
        */

        "heartbeat" =>
            "/api/v1/license/heartbeat",

    ],


    /*
    |--------------------------------------------------------------------------
    | Local Storage
    |--------------------------------------------------------------------------
    */

    "storage" => [

        "license_file" =>
            __DIR__ .
            "/../license/storage/license.lic",

        "cache_file" =>
            __DIR__ .
            "/../license/cache.json",

    ],


    /*
    |--------------------------------------------------------------------------
    | Cryptography
    |--------------------------------------------------------------------------
    */

    "crypto" => [

        "public_key" =>
            __DIR__ .
            "/../keys/public.pem",

    ],

];