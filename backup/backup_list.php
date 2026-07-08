<?php
session_start();

require_once "../db.php";
require_once "backup_functions.php";

require_once "../includes/audit.php";

$conn = Database::connection();

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
<style>
body{
    background:#f5f7fb;
}

.page-title{
    font-weight:700;
}

.stat-card{
    border:none;
    border-radius:12px;
    transition:.25s;
}

.stat-card:hover{
    transform:translateY(-3px);
    box-shadow:0 .5rem 1rem rgba(0,0,0,.12);
}

.table thead th{
    vertical-align:middle;
}

.filename{
    font-weight:600;
    color:#0d6efd;
}

.checksum{
    font-family:monospace;
    font-size:.8rem;
}

.action-btn{
    width:36px;
    height:36px;
    padding:0;
    display:inline-flex;
    justify-content:center;
    align-items:center;
}

.card{
    border:none;
    border-radius:12px;
}

.table-responsive{
    border-radius:12px;
}

.badge{
    font-size:.8rem;
}
</style>
</head>

<body>

    <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
   <div>
    <h3 class="page-title mb-1">
    <i class="fas fa-database text-primary"></i>
    Database Backups
    </h3>

    <small class="text-muted">
    Manage, restore and download your database backups.
    </small>
    </div>
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
    <div class="card stat-card shadow-sm">
    <div class="card-body">
    <div class="d-flex justify-content-between">

    <div>

    <small class="text-muted">
    Total Backups
    </small>

    <h2 class="fw-bold">
    <?= $totalBackups ?>
    </h2>

    </div>

    <i class="fas fa-copy fa-2x text-primary"></i>

    </div>
    </div>
    </div>
    </div>

    <div class="col-md-4">
    <div class="card stat-card shadow-sm">
    <div class="card-body">
    <div class="d-flex justify-content-between">

    <div>

    <small class="text-muted">
    Storage Used
    </small>

    <h2 class="fw-bold">
    <?= formatFileSize($totalStorage) ?>
    </h2>

    </div>

    <i class="fas fa-hdd fa-2x text-success"></i>

    </div>
    </div>
    </div>
    </div>

    <div class="col-md-4">
    <div class="card stat-card shadow-sm">
    <div class="card-body">
    <div class="d-flex justify-content-between">

    <div>
    <small class="text-muted">
    Latest Backup
    </small>
    <h6 class="fw-bold mt-2">
    <?=$totalBackups ?
    date(
    "d M Y H:i",
    strtotime($backups[0]['created_at'])
    ):"None"?>
    </h6>
    </div>
    <i class="fas fa-clock fa-2x text-warning"></i>
    </div>
    </div>
    </div>
    </div>

    <div class="card shadow">
    <div class="card-header">
    <strong>Available Backups</strong>
    </div>

    <div class="card-body p-0">
    <div class="table-responsive">
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
    <td>
        <div class="filename">
        <i class="fas fa-file-archive text-secondary me-2"></i>
        <?= htmlspecialchars($backup['filename']) ?>
        </div>
    </td>
    <td>
        <span class="badge bg-primary">
        <?= ucfirst($backup['backup_type']) ?>
        </span>
    </td>
    <td>
        <?= formatFileSize($backup['file_size']) ?>
    </td>
    <td>
        <span class="checksum" title="<?= htmlspecialchars($backup['checksum']) ?>">
        <?= substr($backup['checksum'],0,20) ?>...
        </span>
    </td>
    <td>
        <?= htmlspecialchars($backup['username'] ?? 'Unknown') ?>
    </td>
    <td>
        <?= date(
        "d M Y H:i",
        strtotime($backup['created_at'])
        ) ?>
    </td>
    <td>
        <a
        href="#"
        class="btn btn-info btn-sm action-btn viewBackup"
        data-id="<?= $backup['id'] ?>"
        title="View Details">
        <i class="fas fa-eye"></i>
        </a>
        <a
        href="download_backup.php?id=<?= $backup['id'] ?>"
        class="btn btn-sm btn-primary btn-sm action-btn" title="Download">
        <i class="fas fa-download"></i>
        </a>

        <form action="restore_backup.php" method="post" class="d-inline">
            <input type="hidden" name="id" value="<?= $backup['id'] ?>">

            <button
                class="btn btn-sm btn-warning action-btn" title="Restore"
                onclick="return confirm('Restore this backup? Current database will be overwritten.')">
                <i class="fas fa-upload"></i>
            </button>
        </form>

        <form action="delete_backup.php" method="POST" class="d-inline">
            <input type="hidden" name="id" value="<?= $backup['id'] ?>">

            <button
                class="btn btn-sm btn-danger action-btn" title="Delete"
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
    </div>

    <div
    class="modal fade"
    id="backupModal"
    tabindex="-1">

    <div class="modal-dialog modal-lg">

    <div class="modal-content">

    <div id="backupContent">

    <div class="text-center p-5">

    <div class="spinner-border text-primary"></div>

    <p class="mt-3">

    Loading backup details...

    </p>

    </div>

    </div>

    </div>

    </div>

    </div>
    <script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
    document.querySelectorAll('[title]').forEach(function(el){

    new bootstrap.Tooltip(el);

    });
    </script>
    <script>
    document.querySelectorAll(".viewBackup").forEach(btn=>{
    btn.addEventListener("click",function(e){

    e.preventDefault();

    const id=this.dataset.id;
    fetch("backup_details.php?id="+id,{

    headers:{
    "X-Requested-With":"XMLHttpRequest"
    }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error("Unable to load backup.");
        }
        return response.text();
    })
    .then(html=>{

    document.getElementById("backupContent").innerHTML=html;

    new bootstrap.Modal(

    document.getElementById("backupModal")

    ).show();

    })
    .catch(error => {

    document.getElementById("backupContent").innerHTML=

    '<div class="alert alert-danger">'+
    error.message+
    '</div>';

    });

    });

    });

</script>
</body>
</html>