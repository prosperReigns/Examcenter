<?php

require_once "license_api.php";
require_once "LicenseDownloader.php";
require_once "verify.php";


class AutomaticActivation
{


    /*
    |--------------------------------------------------------------------------
    | Activate from purchase polling
    |--------------------------------------------------------------------------
    */

    public static function activate(
        string $pollToken
    ): array {


        if (
            empty($pollToken)
        ) {

            throw new Exception(
                "Missing purchase token."
            );

        }



        /*
        |--------------------------------------------------------------------------
        | Check purchase status
        |--------------------------------------------------------------------------
        */

        $status =
            LicenseAPI::purchaseStatus(
                $pollToken
            );



        if (
            empty(
                $status["status"]
            )
        ) {

            throw new Exception(
                "Invalid purchase response."
            );

        }



        switch (
            $status["status"]
        ) {


            case "pending":

            case "processing":

                return [

                    "success" => false,

                    "status" =>
                        $status["status"],

                    "message" =>
                        "Payment is still processing."

                ];



            case "failed":

                throw new Exception(
                    "Payment failed."
                );



            case "completed":

                break;



            default:

                throw new Exception(
                    "Unknown purchase status."
                );

        }




        /*
        |--------------------------------------------------------------------------
        | Get download token
        |--------------------------------------------------------------------------
        */

        if (
            empty(
                $status["download_token"]
            )
        ) {

            throw new Exception(
                "License download token missing."
            );

        }



        $downloadToken =
            $status["download_token"];




        /*
        |--------------------------------------------------------------------------
        | Download signed license
        |--------------------------------------------------------------------------
        */

        $licenseText =
            LicenseDownloader::downloadWithRetry(

                $downloadToken

            );




        /*
        |--------------------------------------------------------------------------
        | Verify and install
        |--------------------------------------------------------------------------
        */

        $verifier =
            new LicenseVerifier();



        $verifier->activate(
            $licenseText
        );




        return [

            "success" => true,

            "status" =>
                "completed",

            "message" =>
                "License activated successfully."

        ];

    }


}
?>