<?php

require_once __DIR__ . "/installation.php";
require_once __DIR__ . "/fingerprint.php";
require_once __DIR__ . "/helpers.php";


class CloneDetector
{


    /*
    |--------------------------------------------------------------------------
    | Generate installation signature
    |--------------------------------------------------------------------------
    */

    private static function signature(): string
    {

        $data = [

            "installation_id" =>
                InstallationIdentity::id(),


            "machine" =>
                MachineFingerprint::generate()

        ];



        return hash(

            "sha256",

            json_encode($data)

        );

    }





    /*
    |--------------------------------------------------------------------------
    | Verify clone status
    |--------------------------------------------------------------------------
    */

    public static function verify(): bool
    {

        $license =
            getLicense();



        if (!$license) {

            return false;

        }



        if (
            empty(
                $license["installation_signature"]
            )
        ) {

            return false;

        }



        return hash_equals(

            $license["installation_signature"],

            self::signature()

        );

    }





    /*
    |--------------------------------------------------------------------------
    | Generate signature for activation
    |--------------------------------------------------------------------------
    */

    public static function generate(): string
    {

        return self::signature();

    }


}