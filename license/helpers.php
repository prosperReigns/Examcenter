<?php

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/LicenseStorage.php";
require_once __DIR__ . "/installation.php";

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
    static $license = null;

    if ($license !== null) {
        return $license;
    }

    $db = Database::getInstance()->getConnection();

    $result = $db->query(
        "SELECT * FROM licenses LIMIT 1"
    );

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    $license = $result->fetch_assoc();

    return $license;
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

    if (
        strtotime($license["expiry_date"])
        < time()
    ) {
        return false;
    }

    if (
        !verifyLicenseSignature(
            $license
        )
    ) {
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

    $days = (int)$today->diff($expiry)->format("%r%a");
    return max(0, $days);
}

function verifyLicenseSignature(
    array $license
): bool
{
    /*
    |--------------------------------------------------------------------------
    | Load stored license
    |--------------------------------------------------------------------------
    */

    if (
        empty(LicenseStorage::get())
    ) {

        return false;

    }

    $licenseText =
        LicenseStorage::get();

    /*
    |--------------------------------------------------------------------------
    | Load public key
    |--------------------------------------------------------------------------
    */

    $config =
        config("license");


    $keyFile =
        $config["crypto"]["public_key"];

    if (
        !file_exists($keyFile)
    ) {

        return false;

    }

    $publicKey =
        openssl_pkey_get_public(
            file_get_contents(
                $keyFile
            )
        );

    if (
        !$publicKey
    ) {

        return false;

    }

    /*
    |--------------------------------------------------------------------------
    | Decode license
    |--------------------------------------------------------------------------
    */

    $decoded =
        json_decode(

            base64_decode(
                $licenseText
            ),

            true

        );

    if (
        empty($decoded["payload"])
        ||
        empty($decoded["signature"])
    ) {

        return false;

    }

    /*
    |--------------------------------------------------------------------------
    | Verify RSA signature
    |--------------------------------------------------------------------------
    */

    $result =
        openssl_verify(

            $decoded["payload"],

            base64_decode(
                $decoded["signature"]
            ),

            $publicKey,

            OPENSSL_ALGO_SHA256

        );



    return $result === 1;

}

function licenseExpired(): bool
{
    $license = getLicense();

    if (!$license) {
        return true;
    }

    return strtotime(
        $license["expiry_date"]
    ) < time();
}

function licenseStatus(): string
{
    $license = getLicense();

    if (!$license) {
        return "missing";
    }

    if (!verifyLicenseSignature($license)) {
        return "tampered";
    }

    if (
        $license["status"] !== "active"
    ) {
        return "inactive";
    }

    if (licenseExpired()) {
        return "expired";
    }

    return "active";
}

function licenseInfo(): array
{
    $license = getLicense();

    if (!$license) {
        return [];
    }

    return [
        "school" =>
        $license["school_name"],


        "expiry" =>
        $license["expiry_date"],


        "days_remaining" =>
        daysRemaining(),


        "status" =>
        licenseStatus(),


        "activation_date" =>
        $license["activation_date"],


        "last_verified" =>
        $license["last_verified"],


        "machine" =>
        $license["machine_fingerprint"]

    ];
}

function verifyInstallationBinding(): bool
{

    $license =
        getLicense();


    if (!$license) {

        return false;

    }


    if (
        empty(
            $license["installation_id"]
        )
    ) {

        return false;

    }


    return hash_equals(

        $license["installation_id"],

        InstallationIdentity::id()

    );

}
?>