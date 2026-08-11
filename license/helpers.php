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

function canonicalizeLicenseJson(mixed $value): mixed
{
    // JSON objects decoded as stdClass
    if ($value instanceof stdClass) {
        $properties = get_object_vars($value);

        ksort($properties, SORT_STRING);

        foreach ($properties as $key => $property) {
            $properties[$key] = canonicalizeLicenseJson($property);
        }

        return (object) $properties;
    }

    // JSON arrays remain JSON arrays
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = canonicalizeLicenseJson($item);
        }

        return $value;
    }

    return $value;
}

function verifyLicenseSignature(array $license): bool
{
    /*
    |--------------------------------------------------------------------------
    | Load stored signed license
    |--------------------------------------------------------------------------
    */

    $licenseText = LicenseStorage::get();

    if (empty($licenseText)) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Load public key
    |--------------------------------------------------------------------------
    */

    $config = config("license");

    $keyFile = $config["crypto"]["public_key"];

    if (!file_exists($keyFile)) {
        return false;
    }

    $publicKey = openssl_pkey_get_public(
        file_get_contents($keyFile)
    );

    if (!$publicKey) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Decode JSON WITHOUT converting objects to associative arrays
    |
    | This is critical.
    |
    | Python:
    |     "features": {}
    |
    | must remain:
    |     "features": {}
    |
    | PHP's json_decode(..., true) would turn {} into an empty array,
    | and json_encode() would then produce [].
    |--------------------------------------------------------------------------
    */

    $decoded = json_decode($licenseText);

    if (!($decoded instanceof stdClass)) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Required cryptographic fields
    |--------------------------------------------------------------------------
    */

    if (
        empty($decoded->signature) ||
        empty($decoded->checksum)
    ) {
        return false;
    }

    $signature = $decoded->signature;
    $checksum = $decoded->checksum;

    /*
    |--------------------------------------------------------------------------
    | Remove cryptographic metadata
    |--------------------------------------------------------------------------
    */

    unset(
        $decoded->signature,
        $decoded->checksum,
        $decoded->checksum_algorithm,
        $decoded->signature_algorithm
    );

    /*
    |--------------------------------------------------------------------------
    | Recreate Python canonical JSON
    |--------------------------------------------------------------------------
    */

    $canonicalData = canonicalizeLicenseJson($decoded);

    $canonicalJson = json_encode(
        $canonicalData,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_PRESERVE_ZERO_FRACTION
    );

    if ($canonicalJson === false) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Verify checksum
    |--------------------------------------------------------------------------
    */

    $expectedChecksum = hash(
        "sha256",
        $canonicalJson
    );

    if (
        !hash_equals(
            $expectedChecksum,
            $checksum
        )
    ) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Decode RSA signature
    |--------------------------------------------------------------------------
    */

    $signatureBytes = base64_decode(
        $signature,
        true
    );

    if ($signatureBytes === false) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Verify RSA PKCS#1 v1.5 + SHA-256
    |--------------------------------------------------------------------------
    */

    $result = openssl_verify(
        $canonicalJson,
        $signatureBytes,
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