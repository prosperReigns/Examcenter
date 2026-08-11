<?php

require_once "license_api.php";
require_once "fingerprint.php";


$app =
config("app");


if(
    !isset($_GET["plan"])
){

    die(
        "No plan selected."
    );

}


$plan =
$_GET["plan"];



try {


$response =
LicenseAPI::startPurchase(

[

"product" =>
$app["name"],


"version" =>
$app["version"],


"plan" =>
$plan,


"fingerprint" =>
MachineFingerprint::generate()

]

);

if (isset($response["checkout_url"])) {

    $_SESSION["purchase"] = [

        "poll_token"      => $response["poll_token"],
        "checkout_token"  => $response["checkout_token"],
        "started_at"      => time()

    ];

    header("Location: " . $response["checkout_url"]);
    exit();
}



throw new Exception(
"Unable to create purchase."
);



}
catch(Exception $e){


echo $e->getMessage();


}