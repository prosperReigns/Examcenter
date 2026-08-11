<?php

require_once "license_api.php";
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

        if (empty($pollToken)) {

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
            empty($status["status"])
        ) {

            throw new Exception(
                "Invalid purchase response."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Failed purchase
        |--------------------------------------------------------------------------
        */

        if (
            $status["status"] === "failed"
        ) {

            throw new Exception(
                "Payment failed."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Cancelled purchase
        |--------------------------------------------------------------------------
        */

        if (
            $status["status"] === "cancelled"
        ) {

            throw new Exception(
                "Purchase was cancelled."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Expired purchase
        |--------------------------------------------------------------------------
        */

        if (
            $status["status"] === "expired"
        ) {

            throw new Exception(
                "Purchase has expired."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Purchase still processing
        |--------------------------------------------------------------------------
        */

        if (
            $status["status"] !== "completed"
        ) {

            return [

                "success" =>
                    false,

                "status" =>
                    $status["status"],

                "progress" =>
                    $status["progress"] ?? null,

                "message" =>
                    $status["message"]
                    ??
                    "Purchase is still being processed.",

                "poll_after" =>
                    $status["poll_after"] ?? 3

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Completed purchase must contain license
        |--------------------------------------------------------------------------
        */

        if (
            empty($status["license"])
        ) {

            throw new Exception(
                "Purchase completed but license was not returned."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | The License Server returns the COMPLETE SIGNED LICENSE
        |
        | It is already JSON.
        |
        | DO NOT:
        |
        | - base64_decode()
        | - fetch another activation token
        | - call a license download endpoint
        |
        |--------------------------------------------------------------------------
        */

        $licenseText =
            $status["license"];


        /*
        |--------------------------------------------------------------------------
        | Debug
        |--------------------------------------------------------------------------
        */

        error_log(
            "AUTO ACTIVATION: license received. Length="
            . strlen($licenseText)
        );

        error_log(
            "AUTO ACTIVATION: license prefix="
            . substr(
                $licenseText,
                0,
                150
            )
        );


        /*
        |--------------------------------------------------------------------------
        | Verify and install
        |--------------------------------------------------------------------------
        */

        try {

            $verifier =
                new LicenseVerifier();

            $verifier->activate(
                $licenseText
            );

        } catch (Exception $e) {

            error_log(
                "AUTO ACTIVATION VERIFICATION ERROR: "
                . $e->getMessage()
            );

            throw $e;
        }


        /*
        |--------------------------------------------------------------------------
        | Success
        |--------------------------------------------------------------------------
        */

        return [

            "success" =>
                true,

            "status" =>
                "completed",

            "message" =>
                "License activated successfully."

        ];
    }
}
?>