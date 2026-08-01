<?php

require_once "helpers.php";
require_once "fingerprint.php";


$license =
licenseInfo();


$fingerprint =
MachineFingerprint::generate();


$app =
config("app");


?>


<!DOCTYPE html>

<html>

<head>

<title>
License Status
</title>

<link href="../css/bootstrap.min.css"
rel="stylesheet">

<link rel="stylesheet"
href="../css/all.css">

</head>


<body class="bg-light">


<div class="container py-5">


<div class="card shadow">


<div class="card-header bg-primary text-white">

<h4>
<i class="fas fa-shield-alt"></i>

License Information

</h4>

</div>


<div class="card-body">


<?php if(empty($license)): ?>


<div class="alert alert-danger">

No license installed.

</div>


<a href="required.php"
class="btn btn-primary">

Activate License

</a>


<?php else: ?>

<?php if(
$license["days_remaining"] <= 30
): ?>


<div class="alert alert-warning">

Your license expires in

<strong>

<?=$license["days_remaining"]?>

days.

</strong>

Please renew.

</div>


<?php endif; ?>

<table class="table table-bordered">


<tr>

<th>
Software
</th>

<td>

<?=htmlspecialchars(
$app["display_name"]
)?>

</td>

</tr>


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
Status
</th>

<td>

<span class="badge bg-success">

<?=htmlspecialchars(
$license["status"]
)?>

</span>

</td>

</tr>



<tr>

<th>
Expiry Date
</th>

<td>

<?=htmlspecialchars(
$license["expiry"]
)?>

</td>

</tr>



<tr>

<th>
Days Remaining
</th>

<td>

<?=$license["days_remaining"]?>

days

</td>

</tr>



<tr>

<th>
Machine Fingerprint
</th>

<td>

<small>

<?=$fingerprint?>

</small>

</td>

</tr>



</table>



<?php endif; ?>

<a

href="<?=config("license")["portal_url"]?>"

target="_blank"

class="btn btn-warning"

>

<i class="fas fa-sync"></i>

Renew License

</a>
</div>

<?php

$status =
licenseStatus();


$class =
$status === "active"
?
"success"
:
"danger";


?>

<div class="alert alert-<?=$class?>">

License Health:

<strong>

<?=ucfirst($status)?>

</strong>

</div>
</div>


</div>


</body>

</html>