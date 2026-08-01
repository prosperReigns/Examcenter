<?php

require_once "license_api.php";


function getLicensePlans()
{

    try {


        $response =
            LicenseAPI::plans();



        if (
            isset(
                $response["plans"]
            )
        ){

            return $response["plans"];

        }


    } catch(Exception $e){


        return [];

    }


    return [];

}