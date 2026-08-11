<?php

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/fingerprint.php";
require_once __DIR__ . "/license_api.php";
require_once __DIR__ . "/installation.php";
require_once __DIR__ . "/CloneDetector.php";
// require_once __DIR__ . "/SecurityLogger.php";

class Heartbeat
{


    /*
    |--------------------------------------------------------------------------
    | Send heartbeat
    |--------------------------------------------------------------------------
    */

    public static function send(): array
    {


        $license =
            getLicense();



        if (!$license) {

            throw new Exception(
                "No installed license found."
            );

        }

        $app =
            config("app");

        /*
        |--------------------------------------------------------------------------
        | Prepare payload
        |--------------------------------------------------------------------------
        */

        $payload = [

            "machine_id" =>
                MachineFingerprint::generate(),

            "ip_address" =>
                null,

            "last_user" =>
                null

        ];

        /*
        |--------------------------------------------------------------------------
        | Contact license server
        |--------------------------------------------------------------------------
        */

        $response =
            LicenseAPI::heartbeat(
                $payload
            );

        if (
            empty($response["status"])
        ) {

            throw new Exception(
                "Invalid heartbeat response."
            );

        }

        /*
        |--------------------------------------------------------------------------
        | Store runtime cache
        |--------------------------------------------------------------------------
        */

        self::updateCache(
            $response
        );

        return $response;

    }

    public static function withinGracePeriod():     bool
    {

        $cache =
            self::cache();

        if (
            empty(
                $cache["last_sync"]
            )
        ) {

            return false;

        }

        $config =
            config("license");

        $graceDays =
            $config["grace_period"];

        $lastSync =
            strtotime(
                $cache["last_sync"]
            );

        $graceLimit =
            $lastSync
            +
            (
                $graceDays
                *
                86400
            );

        return time() <= $graceLimit;

    }

        public static function serverStatus(): string
    {

        $cache =
            self::cache();

        return
            $cache["server_status"]
            ??
            "unknown";

    }

    private static function cacheChecksum(
        array $data
    ): string
    {

        unset(
            $data["checksum"]
        );


        return hash(
            "sha256",
            json_encode(
                $data
            )
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Update local cache
    |--------------------------------------------------------------------------
    */

    private static function updateCache(
        array $response
    ): void
    {

        $config =
            config("license");


        $cacheFile =
            $config["storage"]["cache_file"];



        $directory =
            dirname(
                $cacheFile
            );



        if (
            !is_dir($directory)
        ) {

            mkdir(
                $directory,
                0755,
                true
            );

        }



        $cache = [

            "version" => 1,


            "last_sync" =>
                date(
                    "Y-m-d H:i:s"
                ),


            "server_status" =>
                $response["status"],


            "expiry_date" =>
                $response["expiry_date"]
                ??
                null,


            "grace_until" =>
                $response["grace_until"]
                ??
                null,


            "server_message" =>
                $response["message"]
                ??
                null

        ];



        $cache["checksum"] =
            self::cacheChecksum(
                $cache
            );



        /*
        |--------------------------------------------------------------------------
        | Atomic write
        |--------------------------------------------------------------------------
        */

        $temporary =
            $cacheFile
            .
            ".tmp";



        file_put_contents(

            $temporary,

            json_encode(
                $cache,
                JSON_PRETTY_PRINT
            ),

            LOCK_EX

        );



        rename(
            $temporary,
            $cacheFile
        );

    }




    /*
    |--------------------------------------------------------------------------
    | Read cache
    |--------------------------------------------------------------------------
    */

    public static function cache(): array
    {

        $config =
            config("license");


        $cacheFile =
            $config["storage"]["cache_file"];



        if (
            !file_exists($cacheFile)
        ) {

            return [];

        }



        $content =
            file_get_contents(
                $cacheFile
            );



        $data =
            json_decode(
                $content,
                true
            );



        if (
            !is_array($data)
        ) {

            return [];

        }



        /*
        |--------------------------------------------------------------------------
        | Validate checksum
        |--------------------------------------------------------------------------
        */

        if (
            empty(
                $data["checksum"]
            )
        ) {

            return [];

        }



        $checksum =
            self::cacheChecksum(
                $data
            );



        if (
            !hash_equals(

                $data["checksum"],

                $checksum

            )
        ) {

            return [];

        }



        return $data;

    }




    /*
    |--------------------------------------------------------------------------
    | Determine if heartbeat is required
    |--------------------------------------------------------------------------
    */

    public static function due(): bool
    {


        $cache =
            self::cache();



        if (
            empty($cache["last_sync"])
        ) {

            return true;

        }



        $config =
            config("license");



        $days =
            $config["verification_interval"];



        $last =
            strtotime(
                $cache["last_sync"]
            );



        return

            (
                time()
                -
                $last
            )

            >

            (
                $days
                *
                86400
            );

    }


}
?>