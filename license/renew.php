<?php

session_start();

require_once "verify.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit();
}

try {

    if (!isset($_FILES["license_file"])) {
        throw new Exception("No license uploaded.");
    }

    if ($_FILES["license_file"]["error"] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload failed.");
    }

    if (
        strtolower(
            pathinfo(
                $_FILES["license_file"]["name"],
                PATHINFO_EXTENSION
            )
        ) !== "lic"
    ) {
        throw new Exception(
            "Invalid license file."
        );
    }

    $license = file_get_contents(
        $_FILES["license_file"]["tmp_name"]
    );

    if (!$license) {
        throw new Exception(
            "Unable to read uploaded file."
        );
    }

    file_put_contents(

        __DIR__ . "/storage/license.lic",

        trim($license)

    );
    $verify = new LicenseVerifier();

    $verify->renew(trim($license));

    $_SESSION["license_success"] =
        "License renewed successfully.";

    header("Location: index.php");

    exit();

}
catch(Exception $e){

    $_SESSION["license_error"] =
        $e->getMessage();

    header("Location: expired.php");

    exit();

}
?>