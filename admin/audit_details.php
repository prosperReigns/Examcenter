<?php
session_start();

require_once "../db.php";
require_once "../includes/audit.php";

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit("Invalid audit record.");
}

$id = (int)$_GET['id'];

$audit = getAuditById($conn, $id);

if (!$audit) {
    exit("Audit record not found.");
}

// Detect AJAX request
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
);

if (!$isAjax):
?>

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<title>Audit Details</title>

<link
href="../assets/bootstrap/css/bootstrap.min.css"
rel="stylesheet">

<link
href="../assets/fontawesome/css/all.min.css"
rel="stylesheet">

</head>

<body>

<div class="container mt-4">

<?php endif; ?>


<div class="card shadow">

<div class="card-header bg-primary text-white">

<h5 class="mb-0">

<i class="fas fa-history"></i>

Audit Details

</h5>

</div>

<div class="card-body">

<table class="table table-bordered">

<tr>

<th width="220">Record ID</th>

<td><?= $audit['id'] ?></td>

</tr>

<tr>

<th>Administrator</th>

<td><?= htmlspecialchars($audit['username']) ?></td>

</tr>

<tr>

<th>Module</th>

<td>

<span class="badge bg-info">

<?= htmlspecialchars($audit['module']) ?>

</span>

</td>

</tr>

<tr>

<th>Action</th>

<td>

<?php

$badge = "secondary";

switch(strtolower($audit['action'])){

case "create":
$badge="success";
break;

case "update":
$badge="primary";
break;

case "delete":
$badge="danger";
break;

case "restore":
$badge="warning";
break;

case "login":
$badge="dark";
break;

}

?>

<span class="badge bg-<?= $badge ?>">

<?= htmlspecialchars($audit['action']) ?>

</span>

</td>

</tr>

<tr>

<th>Description</th>

<td>

<?= nl2br(htmlspecialchars($audit['description'])) ?>

</td>

</tr>

<tr>

<th>IP Address</th>

<td><?= htmlspecialchars($audit['ip_address']) ?></td>

</tr>

<tr>

<th>Computer Name</th>

<td><?= htmlspecialchars($audit['computer_name']) ?></td>

</tr>

<tr>

<th>Browser</th>

<td><?= htmlspecialchars($audit['user_agent']) ?></td>

</tr>

<tr>

<th>Date & Time</th>

<td>

<?= date(

"d M Y h:i:s A",

strtotime($audit['created_at'])

) ?>

</td>

</tr>

</table>

</div>

<div class="card-footer text-end">

<button
class="btn btn-secondary"
onclick="window.history.back();">

Back

</button>

<button
class="btn btn-primary"
onclick="window.print();">

Print

</button>

</div>

</div>

<?php if(!$isAjax): ?>

</div>

<script
src="../assets/bootstrap/js/bootstrap.bundle.min.js">
</script>

</body>

</html>

<?php endif; ?>