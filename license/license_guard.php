<?php

require_once "../db.php";

$db = Database::getInstance()->getConnection();

$result = $db->query("SELECT * FROM licenses LIMIT 1");

if($result->num_rows == 0){

    header("Location: /EXAMCENTER/license/activate.php");

    exit();

}

$license = $result->fetch_assoc();

if($license['status'] != 'active'){

    header("Location: /EXAMCENTER/license/activate.php");

    exit();

}

if(strtotime($license['expiry_date']) < time()){

    header("Location: /EXAMCENTER/license/expired.php");

    exit();

}
?>