<?php

require_once 'verify.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    try{

        $verify = new LicenseVerifier();

        $verify->activate($_POST['license']);

        header("Location: ../super_admin/system_setup.php");

        exit();

    }

    catch(Exception $e){

        $message = $e->getMessage();

    }

}
?>