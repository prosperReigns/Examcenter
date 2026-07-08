<?php
session_start();

require_once "fingerprint.php";

$fingerprint = MachineFingerprint::generate();

$message = "";

if (isset($_GET['error'])) {
    $message = $_GET['error'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Software Activation</title>

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
          href="../css/all.css">

</head>

<body class="bg-light">

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-8">

            <div class="card shadow-lg border-0">

                <div class="card-header bg-primary text-white">

                    <h3 class="mb-0">

                        <i class="fas fa-key"></i>

                        CBT Examination System Activation

                    </h3>

                </div>

                <div class="card-body">

                    <?php if(!empty($message)): ?>

                        <div class="alert alert-danger">

                            <?= htmlspecialchars($message) ?>

                        </div>

                    <?php endif; ?>

                    <p class="lead">

                        This installation has not yet been activated.

                    </p>

                    <hr>

                    <h5>

                        Machine Fingerprint

                    </h5>

                    <div class="input-group mb-3">

                        <input

                            id="fingerprint"

                            type="text"

                            class="form-control"

                            readonly

                            value="<?= htmlspecialchars($fingerprint) ?>">

                        <button

                            class="btn btn-outline-secondary"

                            onclick="copyFingerprint()">

                            <i class="fas fa-copy"></i>

                            Copy

                        </button>

                    </div>

                    <a href="download.php"
                       class="btn btn-success mb-4">

                        <i class="fas fa-download"></i>

                        Download Fingerprint

                    </a>

                    <hr>

                    <h5>

                        Activation Steps

                    </h5>

                    <ol>

                        <li>

                            Copy or download the machine fingerprint.

                        </li>

                        <li>

                            Visit your License Portal.

                        </li>

                        <li>

                            Purchase or obtain a license.

                        </li>

                        <li>

                            Download the generated
                            <strong>.lic</strong> file.

                        </li>

                        <li>

                            Upload the file below.

                        </li>

                    </ol>

                    <hr>

                    <form

                        method="POST"

                        action="activate.php"

                        enctype="multipart/form-data">

                        <label class="form-label">

                            Select License File

                        </label>

                        <input

                            class="form-control mb-3"

                            type="file"

                            name="license_file"

                            accept=".lic"

                            required>

                        <button

                            class="btn btn-primary">

                            <i class="fas fa-lock-open"></i>

                            Activate Software

                        </button>

                    </form>

                </div>

                <div class="card-footer text-center text-muted">

                    Contact your software vendor if you need assistance.

                </div>

            </div>

        </div>

    </div>

</div>

<script>

function copyFingerprint(){

    const input = document.getElementById("fingerprint");

    input.select();

    input.setSelectionRange(0,99999);

    navigator.clipboard.writeText(input.value);

    alert("Fingerprint copied.");

}

</script>

<script src="../js/bootstrap.bundle.min.js"></script>

</body>

</html>