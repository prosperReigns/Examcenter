<?php

session_start();

require_once "verify.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        if (!isset($_FILES["license_file"])) {
            throw new Exception("No license file uploaded.");
        }

        if ($_FILES["license_file"]["error"] !== UPLOAD_ERR_OK) {
            throw new Exception("Failed to upload license file.");
        }

        if ($_FILES["license_file"]["size"] > 1024 * 100) {
            throw new Exception(
                "License file is too large."
            );
        }
        
        $extension = strtolower(
            pathinfo(
                $_FILES["license_file"]["name"],
                PATHINFO_EXTENSION
            )
        );

        if ($extension !== "lic") {
            throw new Exception("Invalid license file.");
        }

        $licenseText = file_get_contents(
            $_FILES["license_file"]["tmp_name"]
        );

        if (!$licenseText) {
            throw new Exception("Unable to read license file.");
        }

        $verify = new LicenseVerifier();

        $verify->activate(trim($licenseText));

        $_SESSION["license_success"] =
            "Software activated successfully.";

        header("Location: ../super_admin/system_setup.php");

        exit();

    } catch (Exception $e) {

        header(
            "Location: required.php?error=" .
            urlencode($e->getMessage())
        );

        exit();

    }

}

header("Location: required.php");

exit();