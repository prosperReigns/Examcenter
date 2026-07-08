<?php
session_start();

require_once "../db.php";

$db = Database::getInstance()->getConnection();

$result = $db->query("
    SELECT *
    FROM licenses
    LIMIT 1
");

$license = null;

if ($result && $result->num_rows > 0) {
    $license = $result->fetch_assoc();
}

$daysExpired = 0;

if ($license && !empty($license['expiry_date'])) {

    $expiry = new DateTime($license['expiry_date']);
    $today  = new DateTime();

    if ($today > $expiry) {
        $daysExpired = $expiry->diff($today)->days;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>License Expired</title>

<link href="../css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="../css/all.css">

</head>

<body class="bg-light">

<div class="container py-5">

<div class="row justify-content-center">

<div class="col-lg-8">

<div class="card shadow border-danger">

<div class="card-header bg-danger text-white">

<h3 class="mb-0">

<i class="fas fa-exclamation-triangle"></i>

License Expired

</h3>

</div>

<div class="card-body">

<div class="text-center mb-4">

<i class="fas fa-ban text-danger"
   style="font-size:80px;"></i>

</div>

<h4 class="text-center mb-4">

Your software license has expired.

</h4>

<p class="text-center">

This copy of the CBT Examination System can no longer be used until the license is renewed.

</p>

<hr>

<?php if($license): ?>

<table class="table table-bordered">

<tr>

<th width="35%">School</th>

<td><?= htmlspecialchars($license['school_name']) ?></td>

</tr>

<tr>

<th>License Status</th>

<td>

<span class="badge bg-danger">

<?= ucfirst($license['status']) ?>

</span>

</td>

</tr>

<tr>

<th>License Key</th>

<td>

<?= htmlspecialchars($license['license_key']) ?>

</td>

</tr>

<tr>

<th>Expiry Date</th>

<td>

<?= htmlspecialchars($license['expiry_date']) ?>

</td>

</tr>

<tr>

<th>Days Expired</th>

<td>

<?= $daysExpired ?>

day(s)

</td>

</tr>

</table>

<?php endif; ?>

<hr>

<h5>

What should I do?

</h5>

<ol>

<li>
Contact your software vendor.
</li>

<li>
Purchase or renew your license.
</li>

<li>
Download the new license file.
</li>

<li>
Upload it using the Renew License button below.
</li>

</ol>

<div class="text-center mt-4">

<form
    action="renew.php"
    method="POST"
    enctype="multipart/form-data"
    class="mt-3">

    <input
        type="file"
        name="license_file"
        accept=".lic"
        class="form-control mb-3"
        required>

    <button class="btn btn-warning">

        <i class="fas fa-sync"></i>

        Renew License

    </button>

</form>

<a href="download.php"
   class="btn btn-success">

<i class="fas fa-download"></i>

Download Fingerprint

</a>

</div>

</div>

<div class="card-footer text-center text-muted">

CBT Examination System

</div>

</div>

</div>

</div>

</div>

<script src="../js/bootstrap.bundle.min.js"></script>

</body>

</html>