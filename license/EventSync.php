<?php

require_once __DIR__
."/license_api.php";

require_once __DIR__
."/fingerprint.php";


class EventSync
{


    public static function send(
        string $event,
        string $message,
        array $context=[]
    )
    {


        $license =
            getLicense();



        if (!$license) {

            return false;

        }



        return LicenseAPI::requestPublic(

            "/api/security/event",

            [

                "license_key" =>
                    $license["license_key"],


                "event" =>
                    $event,


                "message" =>
                    $message,


                "installation_id" =>
                    InstallationIdentity::id(),


                "fingerprint" =>
                    MachineFingerprint::generate(),


                "context" =>
                    $context

            ]

        );

    }


}