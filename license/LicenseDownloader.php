<?php

require_once __DIR__ . "/license_api.php";


class LicenseDownloader
{


    /*
    |--------------------------------------------------------------------------
    | Download signed license
    |--------------------------------------------------------------------------
    */

    public static function download(
        string $downloadToken
    ): string {


        if (
            empty($downloadToken)
        ) {

            throw new Exception(
                "Missing download token."
            );

        }



        $response =
            LicenseAPI::downloadLicense(
                $downloadToken
            );



        /*
        |--------------------------------------------------------------------------
        | Validate response
        |--------------------------------------------------------------------------
        */


        if (
            !isset(
                $response["license"]
            )
        ) {

            throw new Exception(
                "License data missing from server response."
            );

        }



        $license =
            trim(
                $response["license"]
            );



        if (
            empty($license)
        ) {

            throw new Exception(
                "Received empty license."
            );

        }



        return $license;

    }



    /*
    |--------------------------------------------------------------------------
    | Download with retry support
    |--------------------------------------------------------------------------
    */

    public static function downloadWithRetry(
        string $downloadToken,
        int $attempts = 3
    ): string {


        $lastError = null;



        for (
            $i = 1;
            $i <= $attempts;
            $i++
        ) {


            try {


                return self::download(
                    $downloadToken
                );


            } catch(Exception $e) {


                $lastError = $e;



                /*
                |--------------------------------------------------------------------------
                | Wait before retry
                |--------------------------------------------------------------------------
                */

                sleep($i);

            }

        }



        throw new Exception(
            "Unable to download license after multiple attempts: "
            .
            $lastError->getMessage()
        );

    }


}