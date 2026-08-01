<?php
session_start();

require_once "fingerprint.php";
require_once "helpers.php";
require_once "plan_helper.php";


$licenseConfig = config("license");

$appConfig = config("app");


$fingerprint =
    MachineFingerprint::generate();

$plans =
getLicensePlans();


if(empty($plans)){

$plans=[

[
"name"=>"7-Day Trial",
"price"=>0,
"currency"=>"NGN",
"id"=>"trial"
],


[
"name"=>"6 Months",
"price"=>50000,
"currency"=>"NGN",
"id"=>"6_months"
],


[
"name"=>"12 Months",
"price"=>100000,
"currency"=>"NGN",
"id"=>"12_months"
],


[
"name"=>"24 Months",
"price"=>200000,
"currency"=>"NGN",
"id"=>"24_months"
]

];

}
/*
|--------------------------------------------------------------------------
| Software Information
|--------------------------------------------------------------------------
*/
$app = config("app");

$licenseConfig = config("license");


$product =
    $appConfig["display_name"];


$version =
    $appConfig["version"];


$licenseServer =
    rtrim(
        $licenseConfig["portal_url"],
        "/"
    );

/*
|--------------------------------------------------------------------------
| License Server
|--------------------------------------------------------------------------
|
| Replace with your production URL later.
|
*/

$licenseServer =
    rtrim(
        $licenseConfig["portal_url"],
        "/"
    );

/*
|--------------------------------------------------------------------------
| Machine Information
|--------------------------------------------------------------------------
*/

$computerName = getenv("COMPUTERNAME") ?: php_uname("n");

$operatingSystem = php_uname("s") . " " . php_uname("r");

$phpVersion = PHP_VERSION;

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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-lg border-0">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0"><i class="fas fa-shield-alt"></i> Welcome to CBT Examination System</h3>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($message)): ?>
                            <div class="alert alert-danger">
                                <?= htmlspecialchars($message) ?>
                            </div>
                        <?php endif; ?>
                        <div class="alert alert-primary">
                            <h5 class="mb-2">Thank you for installing CBT Examination System.</h5>
                            <p class="mb-0">
                                Before you can begin using the software, this installation must be activated.
                                Activation links your license to this computer and enables updates,
                                security verification and technical support.
                            </p>
                        </div>
                        <div class="card mb-4">
                            <div class="card-header">
                                <strong>Machine Information</strong>
                            </div>

                            <div class="card-body">
                                <table class="table table-bordered">
                                    <tr>
                                        <th width="35%">Computer Name</th>
                                        <td><?= htmlspecialchars($computerName) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Operating System</th>
                                        <td><?= htmlspecialchars($operatingSystem) ?></td>
                                    </tr>
                                    <tr>
                                        <th>PHP Version</th>
                                        <td><?= htmlspecialchars($phpVersion) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Software Version</th>
                                        <td><?= htmlspecialchars($version) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <hr>
                        <h5>Machine Fingerprint</h5>

                        <div class="input-group mb-3">
                            <input id="fingerprint" type="text" class="form-control" readonly value="<?= htmlspecialchars($fingerprint) ?>">
                            <button class="btn btn-outline-secondary" onclick="copyFingerprint()"><i class="fas fa-copy"></i> Copy</button>
                        </div>

                        <a href="download.php" class="btn btn-success mb-4"><i class="fas fa-download"></i> Download Fingerprint</a>
                        <a href="https://license.examcenter.com" target="_blank" class="btn btn-primary mb-4"><i class="fas fa-globe"></i> Open License Portal
                        </a>

                        <hr>
                        <ol>
                            <li>Download or copy your computer fingerprint.</li>
                            <li>Visit the License Portal.</li>
                            <li>Choose one of the available plans:
                                <div class="row">

<?php foreach($plans as $plan): ?>


<div class="col-md-3 mb-3">


<div class="card h-100">


<div class="card-body text-center">


<h5>
<?= htmlspecialchars(
$plan["name"]
) ?>
</h5>


<p>

<?= htmlspecialchars(
$plan["currency"]
) ?>

<?= number_format(
$plan["price"]
) ?>

</p>



<a

target="_blank"

href="<?= 
$licenseServer
?>/purchase/start?
fingerprint=<?=urlencode($fingerprint)?>
&plan=<?=urlencode($plan["id"])?>
"

class="btn btn-primary w-100"

>

Choose

</a>


</div>


</div>


</div>


<?php endforeach; ?>


</div>
                            </li>
                            <li>Complete registration and payment (if applicable).</li>
                            <li>Download the generated license (.lic).</li>
                            <li>Return here and upload the license file.</li>
                        </ol>
                        <hr>
                        <div class="card border-success mb-4">
                            <div class="card-header bg-success text-white">
                                <strong>Choose a License</strong>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <h5>Free Trial</h5>
                                            <p>7 Days</p>
                                            <a 
                                                target="_blank"
                                                href="<?= 
                                                $licenseServer .
                                                $licenseConfig["trial_url"]
                                                ?>?fingerprint=<?= urlencode($fingerprint) ?>&product=<?= urlencode($app["name"]) ?>&version=<?= urlencode($version) ?>"
                                                >
                                                Start Trial
                                                </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <h5>6 Months</h5>
                                            <a target="_blank" class="btn btn-primary btn-sm w-100 my-3" href="<?= $licenseServer ?>/purchase/start?fingerprint=<?= urlencode($fingerprint) ?>&product=<?= urlencode($app["name"]) ?>&version=<?= urlencode($version) ?>&plan=6">Buy License</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <h5>12 Months</h5>
                                            <a target="_blank" class="btn btn-primary btn-sm w-100 my-3" href="<?= $licenseServer ?>/purchase/start?fingerprint=<?= urlencode($fingerprint) ?>&product=<?= urlencode($app["name"]) ?>&version=<?= urlencode($version) ?>&plan=12">Buy License</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <h5>24 Months</h5>
                                            <a target="_blank" class="btn btn-warning btn-sm w- my-3" href="<?= $licenseServer ?>/purchase/start?fingerprint=<?= urlencode($fingerprint) ?>&product=<?= urlencode($app["name"]) ?>&version=<?= urlencode($version) ?>&plan=24">Buy License</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <form method="POST" action="activate.php" enctype="multipart/form-data">
                            <h5>Already have a License?</h5>
                            <p class="text-muted">Upload the <strong>.lic</strong> file you received from the License Portal.</p>
                            <label class="form-label">License File</label>
                            <input class="form-control mb-3" type="file" name="license_file" accept=".lic" required>
                            <button class="btn btn-primary"><i class="fas fa-lock-open"></i> Activate Software</button>
                        </form>
                    </div>

                    <div class="card-footer text-center text-muted">
                        <?= $appConfig["display_name"] ?><br>
                        Version <?= $appConfig["version"] ?><br>
                        Licensed by <?= $appConfig["vendor"] ?>
                    </div>
                    <div class="alert alert-light">
                        <h6>Need Assistance?</h6>
                        <p class="mb-1">Email:<?= $appConfig["support_email"] ?></p>
                        <p class="mb-1">WhatsApp: <?= $appConfig["support_phone"] ?></p>
                        <p class="mb-0">Website: <?= htmlspecialchars(
                            $licenseServer
                        ) ?></p>
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