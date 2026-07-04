<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";

// Uncomment after audit module is implemented
// require_once "../includes/audit.php";

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Retrieve backups
$result = getAllBackups($conn);

$totalBackups = 0;
$totalStorage = 0;

$backups = [];

while ($row = $result->fetch_assoc()) {
    $backups[] = $row;
    $totalBackups++;
    $totalStorage += $row['file_size'];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Database Backups</title>

<link href="../assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="../assets/fontawesome/css/all.min.css">

</head>

<body>

    <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h3>
    <i class="fas fa-database text-primary"></i>
    Database Backups
    </h3>
    <a href="create_backup.php"
    class="btn btn-success">
    <i class="fas fa-plus-circle"></i>
    Create Backup
    </a>
    </div>

    <?php if(isset($_SESSION['success'])): ?>

    <div class="alert alert-success alert-dismissible fade show">

    <?= $_SESSION['success']; ?>

    <button
    class="btn-close"
    data-bs-dismiss="alert">
    </button>

    </div>

    <?php unset($_SESSION['success']); endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
    <?= $_SESSION['error']; ?>
    <button
    class="btn-close"
    data-bs-dismiss="alert">
    </button>

    </div>

    <?php unset($_SESSION['error']); endif; ?>

    <div class="row mb-4">
    <div class="col-md-4">
    <div class="card shadow-sm">
    <div class="card-body">
    <h5>Total Backups</h5>
    <h2><?= $totalBackups ?></h2>
    </div>
    </div>
    </div>

    <div class="col-md-4">
    <div class="card shadow-sm">
    <div class="card-body">
    <h5>Storage Used</h5>
    <h2><?= formatFileSize($totalStorage) ?></h2>
    </div>
    </div>
    </div>

    <div class="col-md-4">
    <div class="card shadow-sm">
    <div class="card-body">
    <h5>Latest Backup</h5>
    <h6>
    <?=$totalBackups ?
    date(
    "d M Y H:i",
    strtotime($backups[0]['created_at'])
    ):"None"?>
    </h6>
    </div>
    </div>
    </div>
    </div>

    <div class="card shadow">
    <div class="card-header">
    <strong>Available Backups</strong>
    </div>

    <div class="card-body p-0">

    <table class="table table-hover table-bordered align-middle mb-0">

    <thead class="table-dark">
    <tr>
    <th>#</th>
    <th>Filename</th>
    <th>Type</th>
    <th>Size</th>
    <th>Checksum</th>
    <th>Created By</th>
    <th>Date</th>
    <th width="220">
    Actions
    </th>
    </tr>
    </thead>

    <tbody>
    <?php if(empty($backups)): ?>
    <tr>
    <td colspan="8" class="text-center">
    No backups available.
    </td>
    </tr>
    <?php endif; ?>

    <?php
    $count=1;
    foreach($backups as $backup):
    ?>
    <tr>

    <td><?= $count++ ?></td>

    <td><?= htmlspecialchars($backup['filename']) ?></td>
    <td>
    <span class="badge bg-primary">
    <?= ucfirst($backup['backup_type']) ?>\
    </span>
    </td>
    <td>
    <?= formatFileSize($backup['file_size']) ?>
    </td>
    <td>
    <small>
    <?= substr($backup['checksum'],0,16) ?>...
    </small>
    </td>

    <td>

    <?= htmlspecialchars($backup['full_name'] ?? 'Unknown') ?>

    </td>
    <td>
    <?= date(
    "d M Y H:i",
    strtotime($backup['created_at'])
    ) ?>
    </td>

    <td>
    <a
    href="download_backup.php?id=<?= $backup['id'] ?>"
    class="btn btn-sm btn-primary">
    <i class="fas fa-download"></i>
    </a>

    <a
    href="restore_backup.php?id=<?= $backup['id'] ?>"
    class="btn btn-sm btn-warning"
    onclick="return confirm('Restore this backup? Current database will be overwritten.')">
    <i class="fas fa-upload"></i>
    </a>

    <form action="delete_backup.php" method="POST" class="d-inline">
        <input type="hidden" name="id" value="<?= $backup['id'] ?>">

        <button
            class="btn btn-sm btn-danger"
            onclick="return confirm('Delete this backup permanently?');">
            <i class="fas fa-trash"></i>
        </button>
    </form>
    </td>
    </tr>
    <?php endforeach; ?>

    </tbody>
    </table>
    </div>
    </div>
    </div>

    <script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>