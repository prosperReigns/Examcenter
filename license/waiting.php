<?php

session_start();

require_once "auto_activate.php";
require_once "redirect_helper.php";

$pollToken = $_GET["poll_token"] ?? null;

if (!$pollToken && isset($_SESSION["purchase"])) {
    $pollToken = $_SESSION["purchase"]["poll_token"] ?? null;
}

if (!$pollToken) {
    die("No purchase session found.");
}

$result = null;
$error = null;
$isSuccess = false;

try {

    $result = AutomaticActivation::activate($pollToken);

    if (!empty($result["success"])) {

        $isSuccess = true;

        unset($_SESSION["purchase"]);

        header("Location: " . getPostActivationRedirect());
        exit();
    }

} catch (Exception $e) {

    $error = $e->getMessage();

}

?>

<!DOCTYPE html>

<html lang="en">
<head>

<meta charset="UTF-8">

<?php if (!$isSuccess): ?>
    <meta http-equiv="refresh" content="3">
<?php endif; ?>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Activating Examcenter</title>

<link
    href="../css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="../css/all.css"
>

<style>

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        min-height: 100vh;
        font-family:
            Inter,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;

        background:
            radial-gradient(
                circle at top left,
                rgba(67, 97, 238, 0.12),
                transparent 35%
            ),
            radial-gradient(
                circle at bottom right,
                rgba(67, 97, 238, 0.08),
                transparent 35%
            ),
            #f5f7fb;

        display: flex;
        align-items: center;
        justify-content: center;

        padding: 24px;
    }

    .activation-wrapper {
        width: 100%;
        max-width: 560px;
    }

    .activation-card {
        background: #ffffff;
        border-radius: 20px;
        box-shadow:
            0 20px 50px rgba(15, 23, 42, 0.08),
            0 4px 12px rgba(15, 23, 42, 0.04);

        padding: 42px 38px;
        text-align: center;

        border: 1px solid rgba(15, 23, 42, 0.05);
    }

    /* -------------------------------------------------
       BRAND
    ------------------------------------------------- */

    .brand {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;

        margin-bottom: 32px;
    }

    .brand-icon {
        width: 44px;
        height: 44px;

        border-radius: 12px;

        display: flex;
        align-items: center;
        justify-content: center;

        background: #4361ee;
        color: #ffffff;

        font-size: 20px;

        box-shadow:
            0 8px 18px rgba(67, 97, 238, 0.25);
    }

    .brand-name {
        font-size: 22px;
        font-weight: 700;
        color: #172033;
        letter-spacing: -0.4px;
    }

    /* -------------------------------------------------
       STATUS ICON
    ------------------------------------------------- */

    .status-icon {
        width: 86px;
        height: 86px;

        margin: 0 auto 24px;

        border-radius: 50%;

        display: flex;
        align-items: center;
        justify-content: center;

        font-size: 34px;
    }

    .status-icon.waiting {
        background: rgba(67, 97, 238, 0.10);
        color: #4361ee;
    }

    .status-icon.error {
        background: rgba(220, 53, 69, 0.10);
        color: #dc3545;
    }

    .status-icon.success {
        background: rgba(25, 135, 84, 0.10);
        color: #198754;
    }

    /* -------------------------------------------------
       TEXT
    ------------------------------------------------- */

    .activation-title {
        margin-bottom: 10px;

        color: #172033;

        font-size: 26px;
        font-weight: 700;

        letter-spacing: -0.5px;
    }

    .activation-message {
        max-width: 420px;

        margin: 0 auto;

        color: #667085;

        font-size: 15px;
        line-height: 1.7;
    }

    /* -------------------------------------------------
       LOADING
    ------------------------------------------------- */

    .loading-area {
        margin-top: 30px;
    }

    .spinner {
        width: 34px;
        height: 34px;

        margin: 0 auto 16px;

        border: 3px solid #e8ecf7;
        border-top-color: #4361ee;

        border-radius: 50%;

        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .progress-wrapper {
        max-width: 360px;
        margin: 24px auto 0;
    }

    .progress {
        height: 6px;

        background: #edf0f7;

        border-radius: 20px;

        overflow: hidden;
    }

    .progress-bar {
        width: 45%;

        background: #4361ee;

        border-radius: 20px;

        animation: progressMove 1.8s ease-in-out infinite;
    }

    @keyframes progressMove {

        0% {
            margin-left: -45%;
        }

        50% {
            margin-left: 55%;
        }

        100% {
            margin-left: 100%;
        }

    }

    .checking-text {
        margin-top: 12px;

        font-size: 13px;

        color: #98a2b3;
    }

    /* -------------------------------------------------
       ERROR
    ------------------------------------------------- */

    .error-box {
        margin-top: 28px;

        padding: 16px 18px;

        border-radius: 12px;

        background: #fff5f5;

        border: 1px solid #ffd9dc;

        color: #842029;

        font-size: 14px;

        line-height: 1.6;

        text-align: left;
    }

    .error-box-header {
        display: flex;
        align-items: center;
        gap: 8px;

        font-weight: 600;

        margin-bottom: 5px;
    }

    /* -------------------------------------------------
       FOOTER
    ------------------------------------------------- */

    .security-note {
        margin-top: 30px;

        padding-top: 20px;

        border-top: 1px solid #edf0f5;

        color: #98a2b3;

        font-size: 12px;

        display: flex;
        align-items: center;
        justify-content: center;

        gap: 7px;
    }

    .security-note i {
        color: #4361ee;
    }

    /* -------------------------------------------------
       RESPONSIVE
    ------------------------------------------------- */

    @media (max-width: 576px) {

        body {
            padding: 16px;
        }

        .activation-card {
            padding: 34px 22px;
            border-radius: 16px;
        }

        .activation-title {
            font-size: 23px;
        }

        .brand {
            margin-bottom: 26px;
        }

        .status-icon {
            width: 76px;
            height: 76px;
            font-size: 29px;
        }

    }

</style>


</head>

<body>

<div class="activation-wrapper">

<div class="activation-card">

    <!-- BRAND -->
    <div class="brand">

        <div class="brand-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>

        <div class="brand-name">
            Examcenter
        </div>

    </div>


    <?php if ($error): ?>

        <!-- ERROR STATE -->

        <div class="status-icon error">
            <i class="fas fa-exclamation-triangle"></i>
        </div>

        <h1 class="activation-title">
            Activation Delayed
        </h1>

        <p class="activation-message">
            We encountered a problem while checking your payment
            and activating your Examcenter license.
        </p>

        <div class="error-box">

            <div class="error-box-header">
                <i class="fas fa-circle-exclamation"></i>
                Activation message
            </div>

            <div>
                <?= htmlspecialchars($error) ?>
            </div>

        </div>

        <div class="loading-area">

            <div class="checking-text">
                Retrying automatically...
            </div>

        </div>


    <?php elseif ($result): ?>

        <!-- PAYMENT PROCESSING STATE -->

        <div class="status-icon waiting">

            <i class="fas fa-credit-card"></i>

        </div>

        <h1 class="activation-title">
            Confirming Your Payment
        </h1>

        <p class="activation-message">

            <?= htmlspecialchars(
                $result["message"]
                ?? "We are confirming your payment and preparing your license."
            ) ?>

        </p>

        <div class="loading-area">

            <div class="spinner"></div>

            <div class="checking-text">
                Checking payment status...
            </div>

            <div class="progress-wrapper">

                <div class="progress">

                    <div class="progress-bar"></div>

                </div>

            </div>

        </div>


    <?php else: ?>

        <!-- WAITING STATE -->

        <div class="status-icon waiting">

            <i class="fas fa-shield-halved"></i>

        </div>

        <h1 class="activation-title">
            Activating Examcenter
        </h1>

        <p class="activation-message">

            We are waiting for your payment confirmation.
            Once your payment is confirmed, your Examcenter
            license will be activated automatically.

        </p>

        <div class="loading-area">

            <div class="spinner"></div>

            <div class="checking-text">
                Waiting for payment confirmation...
            </div>

            <div class="progress-wrapper">

                <div class="progress">

                    <div class="progress-bar"></div>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- SECURITY NOTE -->

    <div class="security-note">

        <i class="fas fa-lock"></i>

        <span>
            Secure license activation
        </span>

    </div>

</div>

</div>

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

    /*
     * Small visual enhancement:
     * periodically update the waiting text so the page
     * feels active while automatic activation is polling.
     */

    const checkingMessages = [
        "Checking payment status...",
        "Confirming your transaction...",
        "Preparing your license...",
        "Waiting for confirmation..."
    ];

    const checkingText = document.querySelector('.checking-text');

    if (checkingText && !document.querySelector('.error-box')) {

        let messageIndex = 0;

        setInterval(function () {

            messageIndex =
                (messageIndex + 1) % checkingMessages.length;

            checkingText.style.opacity = '0';

            setTimeout(function () {

                checkingText.textContent =
                    checkingMessages[messageIndex];

                checkingText.style.opacity = '1';

            }, 200);

        }, 3000);

    }

</script>

<style>

    .checking-text {
        transition: opacity 0.2s ease;
    }

</style>

</body>
</html>
