<?php

session_start();


require_once "verify.php";
require_once "LicenseDownloader.php";

if(
!isset($_POST["confirm"])
){

header(
"Location: required.php"
);

exit();

}

/*
|--------------------------------------------------------------------------
| Prevent duplicate activation
|--------------------------------------------------------------------------
*/

if (licenseActive()) {

    $_SESSION["license_error"] =
        "This system is already activated.";

    header(
        "Location: index.php"
    );

    exit();

}

$message = "";

/*
|--------------------------------------------------------------------------
| Automatic activation
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["download_token"])
) {


    try {
        $licenseText =
            LicenseDownloader::downloadWithRetry(

                $_GET["download_token"]

            );

        $verifier =
            new LicenseVerifier();

        $verifier->activate(
            $licenseText
        );

        $licenseDirectory =
            __DIR__
            .
            "/storage";



        if (
            !is_dir(
                $licenseDirectory
            )
        ) {

            mkdir(
                $licenseDirectory,
                0755,
                true
            );

        }

        file_put_contents(

            $licenseDirectory."/license.lic",

            $licenseText

        );

        $_SESSION["license_success"] =
            "Software activated successfully.";

        header(
            "Location: ../super_admin/system_setup.php"
        );

        exit();

    } catch(Exception $e) {

        header(
            "Location: required.php?error="
            .
            urlencode(
                $e->getMessage()
            )
        );

        exit();

    }

}

/*
|--------------------------------------------------------------------------
| Manual license upload fallback
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    ===
    "POST"
) {

    try {
        if (
            !isset(
                $_FILES["license_file"]
            )
        ) {

            throw new Exception(
                "No license file uploaded."
            );

        }

        if (
            $_FILES["license_file"]["error"]
            !==
            UPLOAD_ERR_OK
        ) {

            throw new Exception(
                "Failed to upload license file."
            );

        }

        if (
            $_FILES["license_file"]["size"]
            >
            1024 * 100
        ) {

            throw new Exception(
                "License file is too large."
            );

        }

        $extension =
            strtolower(

                pathinfo(

                    $_FILES["license_file"]["name"],

                    PATHINFO_EXTENSION

                )

            );

        if (
            $extension !== "lic"
        ) {

            throw new Exception(
                "Invalid license file."
            );

        }

        $licenseText =
            trim(

                file_get_contents(

                    $_FILES["license_file"]["tmp_name"]

                )

            );

        if (
            empty($licenseText)
        ) {

            throw new Exception(
                "Unable to read license file."
            );

        }

        /*
        |--------------------------------------------------------------------------
        | Verify first
        |--------------------------------------------------------------------------
        */

        $verify =
            new LicenseVerifier();

            $decoded =
            $verify->preview(
            trim($licenseText)
            );

            $_SESSION["pending_license"] = $decoded;
            header(
            "Location: preview.php"
            );

            exit();
    } catch(Exception $e) {
        header(

            "Location: required.php?error="
            .
            urlencode(
                $e->getMessage()
            )

        );
        exit();

    }

}

header(
    "Location: required.php"
);

exit();
?>