<?php

require_once __DIR__ . "/Heartbeat.php";


/*
|--------------------------------------------------------------------------
| License Runtime Synchronizer
|--------------------------------------------------------------------------
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

            if (
                !Heartbeat::due()
            ) {

                return [

                    "success" => true,

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
                    "unknown"

            ];



        }
        catch(Exception $e) {


            return [

                "success" => false,
                "status" => "offline",
                "error" =>
                    $e->getMessage()    
            ];

        }


    }


}


/*
|--------------------------------------------------------------------------
| CLI execution
|--------------------------------------------------------------------------
*/

if (
    php_sapi_name()
    ===
    "cli"
) {


    $result =
        LicenseSynchronizer::run();



    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
    );


}