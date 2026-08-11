<?php

session_start();
header("Content-Type: application/json");

require_once "auto_activate.php";

try {

    if (empty($_SESSION["purchase"]["poll_token"])) {

        echo json_encode([
            "success" => false,
            "status"  => "no_purchase"
        ]);

        exit();
    }

    $result = AutomaticActivation::activate(
        $_SESSION["purchase"]["poll_token"]
    );

    echo json_encode($result);

}
catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => $e->getMessage()
    ]);

}

?>