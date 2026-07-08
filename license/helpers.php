<?php

require_once __DIR__ . "/../db.php";

function config(string $file): array
{
    $path = __DIR__ . "/../config/" . $file . ".php";

    if (!file_exists($path)) {
        throw new Exception(
            "Configuration file '{$file}' not found."
        );
    }

    return require $path;
}

function getLicense()
{
    $db = Database::getInstance()->getConnection();

    $result = $db->query("SELECT * FROM licenses LIMIT 1");

    if (!$result || $result->num_rows == 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function licenseInstalled()
{
    return getLicense() !== null;
}

function licenseActive()
{
    $license = getLicense();

    if (!$license) {
        return false;
    }

    if ($license["status"] !== "active") {
        return false;
    }

    if (strtotime($license["expiry_date"]) < time()) {
        return false;
    }

    return true;
}

function daysRemaining()
{
    $license = getLicense();

    if (!$license) {
        return 0;
    }

    $today = new DateTime();

    $expiry = new DateTime($license["expiry_date"]);

    return (int)$today->diff($expiry)->format("%r%a");
}
function generateLicenseSignature(array $license): string
{
    $config = config("app");

    return hash_hmac(

        "sha256",

        implode("|", [

            $license["school_name"],

            $license["machine_fingerprint"],

            $license["expiry_date"],

            $license["status"]

        ]),

        $config["license_secret"]

    );
}
?>