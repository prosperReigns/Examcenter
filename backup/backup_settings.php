<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";
require_once "../includes/audit.php";

$conn = Database::connection();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Settings
|--------------------------------------------------------------------------
*/

$result = $conn->query("
    SELECT *
    FROM backup_settings
    LIMIT 1
");

$settings = $result->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Save Settings
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $enabled = isset($_POST['auto_backup_enabled']) ? 1 : 0;

    $frequency = $_POST['backup_frequency'];

    $time = $_POST['backup_time'];

    $retention = (int)$_POST['retention_days'];

    $maximum = (int)$_POST['max_backups'];

    $stmt = $conn->prepare("
        UPDATE backup_settings
        SET
            auto_backup_enabled=?,
            backup_frequency=?,
            backup_time=?,
            retention_days=?,
            max_backups=?
        WHERE id=1
    ");

    $stmt->bind_param(
        "issii",
        $enabled,
        $frequency,
        $time,
        $retention,
        $maximum
    );

    if ($stmt->execute()) {

        logAudit(
            $conn,
            $_SESSION['user_id'],
            "Backups",
            "Updated automatic backup settings",
            "SETTINGS"
        );

        $_SESSION['success']="Backup settings updated.";

    } else {

        $_SESSION['error']="Unable to save settings.";

    }

    header("Location: backup_settings.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

    <div>

    <h3>

    <i class="fas fa-cogs text-primary"></i>

    Backup Settings

    </h3>

    <p class="text-muted mb-0">

    Configure automatic database backups.

    </p>

    </div>

    <a
    href="backup_list.php"
    class="btn btn-outline-secondary">

    <i class="fas fa-arrow-left"></i>

    Back

    </a>

    </div>
     <!-- error message -->
     <?php if(isset($_SESSION['success'])): ?>

    <div class="alert alert-success">

    <?= $_SESSION['success']; ?>

    </div>

    <?php unset($_SESSION['success']); endif; ?>


    <?php if(isset($_SESSION['error'])): ?>

    <div class="alert alert-danger">

    <?= $_SESSION['error']; ?>

    </div>

    <?php unset($_SESSION['error']); endif; ?>
    
    <div class="card shadow-sm">

    <div class="card-header">

    <strong>

    Automatic Backup Configuration

    </strong>

    </div>

    <div class="card-body">

    <form method="POST">
        <div class="form-check form-switch mb-4">

        <input
        class="form-check-input"
        type="checkbox"
        name="auto_backup_enabled"
        <?= $settings['auto_backup_enabled'] ? 'checked' : '' ?>>

        <label class="form-check-label">

        Enable Automatic Backup

        </label>

        </div>
        <div class="mb-3">

        <label>

        Backup Frequency

        </label>

        <select
        name="backup_frequency"
        class="form-select">

        <?php

        $options=[
        'hourly',
        'daily',
        'weekly',
        'monthly'
        ];

        foreach($options as $option):

        ?>

        <option

        value="<?=$option?>"

        <?= $settings['backup_frequency']==$option ? 'selected':'' ?>>

        <?= ucfirst($option) ?>

        </option>

        <?php endforeach; ?>

        </select>

        </div>
        <div class="mb-3">

        <label>

        Backup Time

        </label>

        <input

        type="time"

        name="backup_time"

        class="form-control"

        value="<?=substr($settings['backup_time'],0,5)?>">

        </div>
        <div class="mb-3">

        <label>

        Retention (Days)

        </label>

        <input

        type="number"

        class="form-control"

        name="retention_days"

        min="1"

        value="<?=$settings['retention_days']?>">

        </div>
        <div class="mb-4">

        <label>

        Maximum Backups

        </label>

        <input

        type="number"

        class="form-control"

        name="max_backups"

        min="1"

        value="<?=$settings['max_backups']?>">

        </div>
        <button
        class="btn btn-success">

        <i class="fas fa-save"></i>

        Save Settings

        </button>

        </form>

        </div>

        </div>
        <div class="card shadow-sm mt-4">

        <div class="card-header">

        Scheduler Status

        </div>

        <div class="card-body">

        <div class="row">

        <div class="col-md-4">

        <h6>Last Backup</h6>

        <p>

        <?= $settings['last_backup'] ?: 'Never' ?>

        </p>

        </div>

        <div class="col-md-4">

        <h6>Next Backup</h6>

        <p>

        <?= $settings['next_backup'] ?: 'Pending' ?>

        </p>

        </div>

        <div class="col-md-4">

        <h6>Status</h6>

        <p>

        <?php if($settings['auto_backup_enabled']): ?>

        <span class="badge bg-success">

        Enabled

        </span>

        <?php else: ?>

        <span class="badge bg-danger">

        Disabled

        </span>

        <?php endif; ?>

        </p>

        </div>

        </div>

        </div>

        </div>
        <script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>