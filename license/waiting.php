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

try {

    $result = AutomaticActivation::activate($pollToken);

    if (!empty($result["success"])) {

        unset($_SESSION["purchase"]);

        header("Location: " . getPostActivationRedirect());
        exit();
    }

} catch (Exception $e) {

    $error = $e->getMessage();

}

?>
<!DOCTYPE html>
<html>
<head>

    <meta charset="utf-8">

    <?php if (!$result || empty($result["success"])): ?>

        <meta http-equiv="refresh" content="3">

    <?php endif; ?>

    <title>Automatic Activation</title>

</head>

<body>

<h2>Automatic Activation</h2>

<?php if ($error): ?>

    <p style="color:red;">
        <?= htmlspecialchars($error) ?>
    </p>

<?php elseif ($result): ?>

    <p>

        <?= htmlspecialchars($result["message"]) ?>

    </p>

<?php else: ?>

    <p>

        Waiting for payment confirmation...

    </p>

<?php endif; ?>

</body>

</html>