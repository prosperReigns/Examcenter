<?php

session_start();

require_once "verify.php";


if(
    empty($_SESSION["pending_license"])
){

    header(
        "Location: required.php"
    );

    exit();

}


$data =
$_SESSION["pending_license"];

$license =
$data["license"];


?>


<!DOCTYPE html>

<html>

<head>

<title>
Confirm Activation
</title>

<link href="../css/bootstrap.min.css"
rel="stylesheet">

</head>


<body class="bg-light">


<div class="container py-5">


<div class="card shadow">


<div class="card-header bg-primary text-white">

<h4>
License Information
</h4>

</div>


<div class="card-body">


<table class="table table-bordered">


<tr>

<th>
School
</th>

<td>
<?=htmlspecialchars(
$license["school"]
)?>
</td>

</tr>



<tr>

<th>
Product
</th>

<td>
<?=htmlspecialchars(
$license["product"]
)?>
</td>

</tr>



<tr>

<th>
Expiry
</th>

<td>
<?=htmlspecialchars(
$license["expiry"]
)?>
</td>

</tr>


</table>



<form method="POST"
action="activate.php">


<button
class="btn btn-success"
name="confirm"
value="1">

Activate Software

</button>


</form>


</div>


</div>


</div>


</body>

</html>