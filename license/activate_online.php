<?php


require_once "license_api.php";
require_once "verify.php";


$token =
$_GET["token"] ?? null;


if(!$token){

die(
"Activation token missing"
);

}



try{


$response =
LicenseAPI::fetchLicense(
    $token
);



if(
empty($response["license"])
){

throw new Exception(
"License not received"
);

}



$verifier =
new LicenseVerifier();



$verifier->activate(
    $response["license"]
);



echo "Activation successful";



}catch(Exception $e){


echo $e->getMessage();


}