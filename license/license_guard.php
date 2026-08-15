<?php

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/fingerprint.php";
require_once __DIR__ . "/Heartbeat.php";
require_once __DIR__ . "/CloneDetector.php";
require_once __DIR__ . "/SecurityLogger.php";
require_once __DIR__ . "/license_sync.php";


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
$config = config("app");
$currentPage = basename($_SERVER["PHP_SELF"]);

if (in_array($currentPage, $allowedPages)) {
    return;
}



function redirectLicenseError(
    string $message
): void
{
    header(
        "Location: required.php?error="
        . urlencode($message)
    );

    exit();
}

/*
|--------------------------------------------------------------------------
| Check installed license
|--------------------------------------------------------------------------
*/

$license = getLicense();

if (!$license) {

    redirectLicenseError(
        "License integrity check failed."
    );

}

/*                                                                         |
| -------------------------------------------------------------------------- |
| Automatic License Synchronization                                          |
| -------------------------------------------------------------------------- |
|                                                                            |
| Synchronization is performed automatically when required.                  |
| No manual CLI command or administrator intervention is required.           |
|                                                                            |
| A failed heartbeat does NOT immediately block ExamCenter.                  |
| The existing local cache and grace-period rules below determine            |
| whether the application may continue operating.                            |
|                                                                            |
| */

$syncResult =
LicenseSynchronizer::run();

if (
!$syncResult["success"]
) {

SecurityLogger::write(
    "HEARTBEAT_FAILED",
    $syncResult["error"]
    ?? "License server synchronization failed."
);

}


/*
|--------------------------------------------------------------------------
| Verify installation binding
|--------------------------------------------------------------------------
*/

if (
    !verifyInstallationBinding()
) {
    SecurityLogger::write(
        "INSTALLATION_MISMATCH",
        "Installation identity validation failed."
    );

    redirectLicenseError(
        "Installation identity mismatch."
    );

}

/*
|--------------------------------------------------------------------------
| Clone Detection
|--------------------------------------------------------------------------
*/

if (
    !CloneDetector::verify()
) {
    SecurityLogger::write(
        "CLONE_DETECTED",
        "Possible duplicated ExamCenter installation."
    );

    redirectLicenseError(
        "Installation clone detected."
    );

}

/*
|--------------------------------------------------------------------------
| Runtime recovery handling
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Remote license status
|--------------------------------------------------------------------------
*/

$cache =
    Heartbeat::cache();

$status =
    $cache["server_status"]
    ?? "unknown";

/*
|--------------------------------------------------------------------------
| Server revoked license
|--------------------------------------------------------------------------
*/

if ($status === "revoked") {

    redirectLicenseError(
        "License has been revoked."
    );

}

/*
|--------------------------------------------------------------------------
| Server suspended license
|--------------------------------------------------------------------------
*/

if ($status === "suspended") {

    redirectLicenseError(
        "License has been suspended."
    );

}

/*
|--------------------------------------------------------------------------
| Server expired license
|--------------------------------------------------------------------------
*/

if ($status === "expired") {

    redirectLicenseError(
        "License has expired."
    );

}

/*
|--------------------------------------------------------------------------
| Check cached expiry date
|--------------------------------------------------------------------------
|
| Even if the last heartbeat returned "alive", the license must not
| remain usable after its cached expiry date.
|
*/

if (!empty($cache["expiry_date"])) {

    $expiryTimestamp =
        strtotime(
            $cache["expiry_date"]
        );

    if (
        $expiryTimestamp !== false
        &&
        time() > $expiryTimestamp
    ) {

        redirectLicenseError(
            "License has expired."
        );

    }

}

/*
|--------------------------------------------------------------------------
| Server unavailable / unknown
|--------------------------------------------------------------------------
*/

if (
    $status === "unknown"
    ||
    $status === "offline"
) {

    if (
        !Heartbeat::withinGracePeriod()
    ) {

        redirectLicenseError(
            "License verification unavailable. Please connect to the internet."
        );

    }

}

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

    SecurityLogger::write(
        "FINGERPRINT_MISMATCH",
        "License machine fingerprint mismatch."
    );

    redirectLicenseError(
        "This license belongs to another computer."
    );
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

    $rollbackWindow =
    $config["clock_rollback_seconds"] ?? 300;

    if ($currentTime + $rollbackWindow < $lastTime) {

        $db->query("
            UPDATE licenses
            SET status='revoked'
            LIMIT 1
        ");

        redirectLicenseError(
            "System clock manipulation detected."
        );
    }

}

/*
|--------------------------------------------------------------------------
| Verify Database Integrity
|--------------------------------------------------------------------------
*/

if (!verifyLicenseSignature($license)) {
    redirectLicenseError(
        "License integrity check failed."
    );
}

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

switch (licenseStatus()) {

    case "tampered":
        redirectLicenseError(
            "License has been tampered with."
        );

    case "inactive":
        redirectLicenseError(
            "License is inactive."
        );

    case "revoked":
        redirectLicenseError(
            "License has been revoked."
        );


    case "expired":
        redirectLicenseError(
            "License has expired."
        );
}

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

if (
    !empty($license["expiry_date"]) &&
    licenseExpired()
) {

    redirectLicenseError(
        "License has reached expiry date."
    );

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