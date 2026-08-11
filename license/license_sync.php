<?php

require_once __DIR__ . "/Heartbeat.php";


/*
|--------------------------------------------------------------------------
| License Runtime Synchronizer
|--------------------------------------------------------------------------
|
| Handles automatic communication between ExamCenter and the
| license server. This class can be called by the application
| without requiring any manual CLI operation.
|
*/


class LicenseSynchronizer
{

    public static function run(): array
    {

        try {

            /*
            |--------------------------------------------------------------------------
            | Skip if heartbeat is not due
            |--------------------------------------------------------------------------
            */

            if (!Heartbeat::due()) {

                return [

                    "success" => true,

                    "status" =>
                        Heartbeat::serverStatus(),

                    "message" =>
                        "Synchronization not required."

                ];

            }


            /*
            |--------------------------------------------------------------------------
            | Send heartbeat
            |--------------------------------------------------------------------------
            */

            $response =
                Heartbeat::send();


            return [

                "success" => true,

                "status" =>
                    $response["status"]
                    ??
                    "unknown",

                "expiry_date" =>
                    $response["expiry_date"]
                    ??
                    null,

                "grace_until" =>
                    $response["grace_until"]
                    ??
                    null,

                "message" =>
                    $response["message"]
                    ??
                    null

            ];

        }
        catch (Throwable $e) {

            return [

                "success" => false,

                "status" => "offline",

                "error" =>
                    $e->getMessage()

            ];

        }

    }

}
?>
