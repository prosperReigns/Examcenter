<?php
session_start();

require_once "fingerprint.php";
require_once "helpers.php";
require_once "plan_helper.php";
require_once "redirect_helper.php";

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
    <title>Software Activation | Examcenter</title>
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



<button
    class="btn btn-primary w-100"
    onclick="startPurchase('<?= htmlspecialchars($plan['id']) ?>')">
    Choose
</button>


</div>


</div>


</div>


<?php endforeach; ?>


</div>
                            </li>
                            <li>Complete registration and payment (if applicable).</li>
                            <li>The license will automatically activate this computer after payment.</li>
                            <li>Keep the application open until activation completes.</li>
                        </ol>

                        <div class="alert alert-info">

                        <h5>
                        Automatic Activation
                        </h5>

                        <p>
                        After successful payment, your license will be delivered automatically to this computer.
                        </p>

                        <p>
                        Keep this window open while activation completes.
                        </p>

                        </div>
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
<script>
    async function startPurchase(plan) {

    try {

        const response = await fetch(
            "<?= $licenseServer ?>/api/public/start-purchase",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({

                    fingerprint: document.getElementById("fingerprint").value,

                    product_code: "cbt_exam",

                    version: "<?= $version ?>",

                    plan_code: plan,

                    customer_name: null,

                    customer_email: null,

                    customer_phone: null,

                    school_name: null

                })
            }
        );

        if (!response.ok) {

            const error = await response.text();

            alert(error);

            return;

        }

        const purchase = await response.json();

        window.location.href = purchase.checkout_url;

    }

    catch (e) {

        alert("Unable to contact the License Server.");

        console.error(e);

    }

}
</script>
<script>

let pollTimer = null;
let activationInProgress = false;

async function pollPurchase() {

    // Prevent overlapping activation requests
    if (activationInProgress) {
        return;
    }

    activationInProgress = true;

    try {

        const response = await fetch(
            "activate.php",
            {
                cache: "no-store"
            }
        );

        const result = await response.json();

        console.log("Automatic activation:", result);

        /*
        |--------------------------------------------------------------------------
        | Activation successful
        |--------------------------------------------------------------------------
        */

        if (result.success) {

            if (pollTimer !== null) {
                clearInterval(pollTimer);
                pollTimer = null;
            }

            alert(
                result.message ||
                "License activated successfully!"
            );

            window.location.href = <?= json_encode(getPostActivationRedirect()) ?>;

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Still waiting for payment
        |--------------------------------------------------------------------------
        */

        if (
            result.status === "pending" ||
            result.status === "processing"
        ) {

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Permanent failure
        |--------------------------------------------------------------------------
        */

        if (
            result.status === "failed" ||
            result.status === "error" ||
            result.status === "no_purchase"
        ) {

            if (pollTimer !== null) {
                clearInterval(pollTimer);
                pollTimer = null;
            }

            alert(
                result.message ||
                "Activation failed."
            );

        }

    }
    catch (error) {

        console.error(
            "Automatic activation polling failed:",
            error
        );

    }
    finally {

        activationInProgress = false;

    }

}


/*
|--------------------------------------------------------------------------
| Start polling
|--------------------------------------------------------------------------
|
| Do NOT call pollPurchase() immediately and start the interval
| at the same time.
|
*/

pollPurchase();

pollTimer = setInterval(
    pollPurchase,
    3000
);

</script>
</body>
</html>