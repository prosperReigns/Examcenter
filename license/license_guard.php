<?php

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/fingerprint.php";

$db = Database::getInstance()->getConnection();

/*
|--------------------------------------------------------------------------
| License pages that should always be accessible
|--------------------------------------------------------------------------
*/

$allowedPages = [

    "required.php",
    "activate.php",
    "expired.php",
    "renew.php",
    "replace.php",
    "download.php",
    "index.php",
    "about.php"

];

$currentPage = basename($_SERVER["PHP_SELF"]);

if (in_array($currentPage, $allowedPages)) {
    return;
}

/*
|--------------------------------------------------------------------------
| Check installed license
|--------------------------------------------------------------------------
*/

$result = $db->query("SELECT * FROM licenses LIMIT 1");

if (!$result || $result->num_rows == 0) {

    header("Location: /EXAMCENTER/license/required.php");

    exit();

}

$license = $result->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Verify machine fingerprint
|--------------------------------------------------------------------------
*/

$currentFingerprint = MachineFingerprint::generate();

if (
    !hash_equals(
        $license["machine_fingerprint"],
        $currentFingerprint
    )
) {

    $db->query("
        UPDATE licenses
        SET status='revoked'
        LIMIT 1
    ");

    header(
        "Location: /EXAMCENTER/license/required.php?error=" .
        urlencode(
            "This license belongs to another computer."
        )
    );

    exit();

}

/*
|--------------------------------------------------------------------------
| Detect system clock rollback
|--------------------------------------------------------------------------
*/

$currentTime = time();

if (!empty($license["last_system_time"])) {

    $lastTime = strtotime($license["last_system_time"]);

    /*
    |--------------------------------------------------------------------------
    | If computer clock moved backwards by more than 5 minutes
    |--------------------------------------------------------------------------
    */

    if ($currentTime + 300 < $lastTime) {

        $db->query("
            UPDATE licenses
            SET status='revoked'
            LIMIT 1
        ");

        header(
            "Location: /EXAMCENTER/license/required.php?error=" .
            urlencode("System clock manipulation detected.")
        );

        exit();

    }

}

/*
|--------------------------------------------------------------------------
| Verify Database Integrity
|--------------------------------------------------------------------------
*/

$expected = generateLicenseSignature($license);

if (!hash_equals($expected, $license["license_signature"])) {

    header("Location: /EXAMCENTER/license/required.php?error=License has been modified.");

    exit();

}

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

if ($license["status"] === "revoked") {

    header("Location: /EXAMCENTER/license/required.php");

    exit();

}

if ($license["status"] === "inactive") {

    header("Location: /EXAMCENTER/license/required.php");

    exit();

}

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

if (
    !empty($license["expiry_date"]) &&
    strtotime($license["expiry_date"]) < time()
) {

    header("Location: /EXAMCENTER/license/expired.php");

    exit();

}

/*
|--------------------------------------------------------------------------
| Update last verified timestamps
|--------------------------------------------------------------------------
*/

$now = date("Y-m-d H:i:s");

$stmt = $db->prepare("
    UPDATE licenses
    SET
        last_verified=?,
        last_system_time=?
    LIMIT 1
");

$stmt->bind_param(
    "ss",
    $now,
    $now
);

$stmt->execute();
?>