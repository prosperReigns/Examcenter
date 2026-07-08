<?php

require_once "fingerprint.php";

/*
|--------------------------------------------------------------------------
| Generate fingerprint information
|--------------------------------------------------------------------------
*/

$fingerprint = MachineFingerprint::generate();

$data = [
    "fingerprint" => $fingerprint,
    "computer_name" => getenv("COMPUTERNAME") ?: php_uname("n"),
    "operating_system" => php_uname("s") . " " . php_uname("r"),
    "php_version" => PHP_VERSION,
    "generated_at" => date("c")
];

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