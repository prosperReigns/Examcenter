<?php

require_once "fingerprint.php";

/*
|--------------------------------------------------------------------------
| Generate fingerprint information
|--------------------------------------------------------------------------
*/

$fingerprint = MachineFingerprint::generate();

$data = [

    "product" => config("app")["name"],

    "product_version" => config("app")["version"],

    "fingerprint_version" => MachineFingerprint::VERSION,

    "fingerprint" => MachineFingerprint::generate(),

    "hardware" => MachineFingerprint::details(),

    "generated_at" => date("c")

];

$data["checksum"] = hash(

    "sha256",

    json_encode($data)

);

/*
|--------------------------------------------------------------------------
| Force download
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json");
header("Content-Disposition: attachment; filename=fingerprint.json");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

echo json_encode($data, JSON_PRETTY_PRINT);

exit;