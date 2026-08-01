<?php
session_start();

require_once "../db.php";
require_once "../includes/system_guard.php";
require_once "../license/license_guard.php";

require_once "helpers.php";

$license = getLicense();

$daysRemaining = daysRemaining();

function currentFingerprint(): string
{
    return MachineFingerprint::generate();
}
?>
<!DOCTYPE html>
<html>

<head>

    <title>License Information</title>

    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../css/all.css">

</head>

<body class="bg-light">

<div class="container py-5">

    <div class="row">

        <div class="col-lg-10 mx-auto">

            <div class="card shadow">

                <div class="card-header bg-primary text-white">

                    <h3>

                        <i class="fas fa-key"></i>

                        License Information

                    </h3>

                </div>

                <div class="card-body">

                    <?php

                    $badge = "secondary";

                    switch ($license['status']) {

                        case "active":
                            $badge = "success";
                            break;

                        case "expired":
                            $badge = "danger";
                            break;

                        case "inactive":
                            $badge = "warning";
                            break;

                        case "revoked":
                            $badge = "dark";
                            break;
                    }

                    ?>

                    <table class="table table-bordered">

                        <tr>

                            <th width="30%">Status</th>

                            <td>

                                <span class="badge bg-<?= $badge ?>">

                                    <?= ucfirst($license['status']) ?>

                                </span>

                            </td>

                        </tr>

                        <tr>

                            <th>School</th>

                            <td>

                                <?= htmlspecialchars($license['school_name']) ?>

                            </td>

                        </tr>

                        <tr>

                            <th>License Key</th>

                            <td>

                                <?= htmlspecialchars($license['license_key']) ?>

                            </td>

                        </tr>

                        <tr>

                            <th>Machine Fingerprint</th>

                            <td style="word-break:break-all">

                                <?= htmlspecialchars($license['machine_fingerprint']) ?>

                            </td>

                        </tr>

                        <tr>

                            <th>Activated On</th>

                            <td>

                                <?= htmlspecialchars($license['activation_date']) ?>

                            </td>

                        </tr>

                        <tr>

                            <th>Expires On</th>

                            <td>

                                <?= htmlspecialchars($license['expiry_date']) ?>

                            </td>

                        </tr>

                        <tr>

                            <th>Days Remaining</th>

                            <td>

                                <?php

                                if ($daysRemaining < 0) {

                                    echo "<span class='text-danger'>Expired</span>";

                                } else {

                                    echo $daysRemaining . " day(s)";

                                }

                                ?>

                            </td>

                        </tr>

                        <tr>

                            <th>Last Verified</th>

                            <td>

                                <?= htmlspecialchars($license['last_verified']) ?>

                            </td>

                        </tr>

                    </table>

                    <div class="mt-4">

                        <a href="download.php"
                           class="btn btn-success">

                            <i class="fas fa-download"></i>

                            Download Fingerprint

                        </a>

                        <a href="renew.php"
                           class="btn btn-warning">

                            <i class="fas fa-sync"></i>

                            Renew License

                        </a>

                        <a href="replace.php"
                           class="btn btn-danger">

                            <i class="fas fa-exchange-alt"></i>

                            Replace License

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script src="../js/bootstrap.bundle.min.js"></script>

</body>

</html>