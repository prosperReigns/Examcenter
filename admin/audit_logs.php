<?php
session_start();

require_once "../db.php";
require_once "../includes/audit.php";

$conn = Database::connection();
//------------------------------------------------------
// Authentication
//------------------------------------------------------

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

//------------------------------------------------------
// Pagination
//------------------------------------------------------

$perPage = isset($_GET['per_page']) && is_numeric($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 25;

$allowedPerPage = [25, 50, 100];

if (!in_array($perPage, $allowedPerPage)) {
    $perPage = 25;
}

$page = isset($_GET['page']) && is_numeric($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$offset = ($page - 1) * $perPage;

//------------------------------------------------------
// Filters
//------------------------------------------------------

$keyword = trim($_GET['keyword'] ?? '');
$module  = trim($_GET['module'] ?? '');
$action  = trim($_GET['action'] ?? '');
$admin   = trim($_GET['admin'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

//------------------------------------------------------
// Build WHERE Clause
//------------------------------------------------------

$where = [];
$params = [];
$types = "";

if ($keyword !== "") {
    $where[] = "(description LIKE ? OR username LIKE ?)";
    $search = "%{$keyword}%";
    $params[] = $search;
    $params[] = $search;
    $types .= "ss";
}

if ($module !== "") {
    $where[] = "module = ?";
    $params[] = $module;
    $types .= "s";
}

if ($action !== "") {
    $where[] = "action = ?";
    $params[] = $action;
    $types .= "s";
}

if ($admin !== "") {
    $where[] = "username = ?";
    $params[] = $admin;
    $types .= "s";
}

if ($dateFrom !== "") {
    $where[] = "DATE(created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo !== "") {
    $where[] = "DATE(created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$whereSQL = "";

if (!empty($where)) {
    $whereSQL = "WHERE " . implode(" AND ", $where);
}

//------------------------------------------------------
// Statistics
//------------------------------------------------------

$totalLogs = $conn->query("
    SELECT COUNT(*) total
    FROM audit_logs
")->fetch_assoc()['total'];

$todayLogs = $conn->query("
    SELECT COUNT(*) total
    FROM audit_logs
    WHERE DATE(created_at)=CURDATE()
")->fetch_assoc()['total'];

$activeAdmins = $conn->query("
    SELECT COUNT(DISTINCT admin_id) total
    FROM audit_logs
")->fetch_assoc()['total'];

$modulesLogged = $conn->query("
    SELECT COUNT(DISTINCT module) total
    FROM audit_logs
")->fetch_assoc()['total'];

//------------------------------------------------------
// Dropdown Lists
//------------------------------------------------------

$modules = $conn->query("
    SELECT DISTINCT module
    FROM audit_logs
    ORDER BY module
");

$actions = $conn->query("
    SELECT DISTINCT action
    FROM audit_logs
    ORDER BY action
");

$admins = $conn->query("
    SELECT DISTINCT username
    FROM audit_logs
    ORDER BY username
");

//------------------------------------------------------
// Count Matching Records
//------------------------------------------------------

$countSQL = "
    SELECT COUNT(*) total
    FROM audit_logs
    {$whereSQL}
";

$stmt = $conn->prepare($countSQL);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$totalRows = $stmt
    ->get_result()
    ->fetch_assoc()['total'];

$totalPages = max(1, ceil($totalRows / $perPage));

//------------------------------------------------------
// Fetch Audit Logs
//------------------------------------------------------

$listSQL = "
SELECT *

FROM audit_logs

{$whereSQL}

ORDER BY created_at DESC

LIMIT ?

OFFSET ?
";

$stmt = $conn->prepare($listSQL);

$listParams = $params;
$listTypes = $types . "ii";

$listParams[] = $perPage;
$listParams[] = $offset;

$stmt->bind_param($listTypes, ...$listParams);

$stmt->execute();

$auditLogs = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs</title>

    <link href="../assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/fontawesome/css/all.min.css" rel="stylesheet">
</head>

<body>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h3>
            <i class="fas fa-history text-primary"></i>
            Audit Logs
        </h3>

        <div>

            <a href="export_audit_csv.php?<?= http_build_query($_GET) ?>" class="btn btn-success">
                <i class="fas fa-file-csv"></i>
                CSV
            </a>

            <a href="export_audit_excel.php" class="btn btn-primary">
                <i class="fas fa-file-excel"></i>
                Excel
            </a>

        </div>

    </div>

    <!-- Statistics -->

    <div class="row mb-4">

        <div class="col-lg-3 col-md-6 mb-3">

            <div class="card border-primary shadow-sm">

                <div class="card-body">

                    <h6 class="text-muted">Total Logs</h6>

                    <h2><?= number_format($totalLogs) ?></h2>

                </div>

            </div>

        </div>

        <div class="col-lg-3 col-md-6 mb-3">

            <div class="card border-success shadow-sm">

                <div class="card-body">

                    <h6 class="text-muted">Today's Logs</h6>

                    <h2><?= number_format($todayLogs) ?></h2>

                </div>

            </div>

        </div>

        <div class="col-lg-3 col-md-6 mb-3">

            <div class="card border-warning shadow-sm">

                <div class="card-body">

                    <h6 class="text-muted">Active Admins</h6>

                    <h2><?= number_format($activeAdmins) ?></h2>

                </div>

            </div>

        </div>

        <div class="col-lg-3 col-md-6 mb-3">

            <div class="card border-danger shadow-sm">

                <div class="card-body">

                    <h6 class="text-muted">Modules Logged</h6>

                    <h2><?= number_format($modulesLogged) ?></h2>

                </div>

            </div>

        </div>

    </div>

    <!-- Filters -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>Filter Audit Logs</strong>

        </div>

        <div class="card-body">

            <form method="GET">

                <div class="row">

                    <div class="col-md-3 mb-3">

                        <input
                            type="text"
                            class="form-control"
                            name="keyword"
                            placeholder="Keyword"
                            value="<?= htmlspecialchars($keyword) ?>">

                    </div>

                    <div class="col-md-2 mb-3">

                        <select class="form-select" name="module">

                            <option value="">All Modules</option>

                            <?php while($row = $modules->fetch_assoc()): ?>

                                <option
                                    value="<?= htmlspecialchars($row['module']) ?>"
                                    <?= $module == $row['module'] ? 'selected' : '' ?>>

                                    <?= htmlspecialchars($row['module']) ?>

                                </option>

                            <?php endwhile; ?>

                        </select>

                    </div>

                    <div class="col-md-2 mb-3">

                        <select class="form-select" name="action">

                            <option value="">All Actions</option>

                            <?php while($row = $actions->fetch_assoc()): ?>

                                <option
                                    value="<?= htmlspecialchars($row['action']) ?>"
                                    <?= $action == $row['action'] ? 'selected' : '' ?>>

                                    <?= htmlspecialchars($row['action']) ?>

                                </option>

                            <?php endwhile; ?>

                        </select>

                    </div>

                    <div class="col-md-2 mb-3">

                        <select class="form-select" name="admin">

                            <option value="">All Admins</option>

                            <?php while($row = $admins->fetch_assoc()): ?>

                                <option
                                    value="<?= htmlspecialchars($row['username']) ?>"
                                    <?= $admin == $row['username'] ? 'selected' : '' ?>>

                                    <?= htmlspecialchars($row['username']) ?>

                                </option>

                            <?php endwhile; ?>

                        </select>

                    </div>

                    <div class="col-md-1 mb-3">

                        <input
                            type="date"
                            class="form-control"
                            name="date_from"
                            value="<?= $dateFrom ?>">

                    </div>

                    <div class="col-md-1 mb-3">

                        <input
                            type="date"
                            class="form-control"
                            name="date_to"
                            value="<?= $dateTo ?>">

                    </div>

                    <div class="col-md-1 mb-3">

                        <button class="btn btn-primary w-100">

                            <i class="fas fa-search"></i>

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- Audit Table -->

    <div class="card shadow">

        <div class="card-header">

            <strong>Audit History</strong>

        </div>

        <div class="table-responsive">

            <table class="table table-striped table-hover align-middle mb-0">

                <thead class="table-dark">

                    <tr>

                        <th>Date</th>
                        <th>Admin</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                        <th>View</th>

                    </tr>

                </thead>

                <tbody>

                <?php if($auditLogs->num_rows == 0): ?>

                    <tr>

                        <td colspan="7" class="text-center py-4">

                            No audit records found.

                        </td>

                    </tr>

                <?php endif; ?>

                <?php while($log = $auditLogs->fetch_assoc()): ?>

                    <tr>

                        <td>

                            <?= date(
                                "d M Y H:i",
                                strtotime($log['created_at'])
                            ) ?>

                        </td>

                        <td><?= htmlspecialchars($log['username']) ?></td>

                        <td>

                            <span class="badge bg-info">

                                <?= htmlspecialchars($log['module']) ?>

                            </span>

                        </td>

                        <td><?= htmlspecialchars($log['action']) ?></td>

                        <td><?= htmlspecialchars($log['description']) ?></td>

                        <td><?= htmlspecialchars($log['ip_address']) ?></td>

                        <td>

                            <button
                                class="btn btn-sm btn-outline-primary viewAudit"
                                data-id="<?= $log['id'] ?>">

                                <i class="fas fa-eye"></i>

                            </button>

                        </td>

                    </tr>

                <?php endwhile; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- Pagination -->

    <nav class="mt-4">

        <ul class="pagination justify-content-center">

            <?php for($i = 1; $i <= $totalPages; $i++): ?>

                <li class="page-item <?= $page == $i ? 'active' : '' ?>">

                    <a
                        class="page-link"
                        href="?page=<?= $i ?>&per_page=<?= $perPage ?>">

                        <?= $i ?>

                    </a>

                </li>

            <?php endfor; ?>

        </ul>

    </nav>

</div>
<div class="modal fade"
id="auditModal">

<div class="modal-dialog modal-lg">

<div class="modal-content">

<div id="auditContent">

Loading...

</div>

</div>

</div>

</div>

<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll(".viewAudit").forEach(btn=>{

btn.addEventListener("click",function(){

const id=this.dataset.id;

fetch("audit_details.php?id="+id,{

headers:{
"X-Requested-With":"XMLHttpRequest"
}

})

.then(r=>r.text())

.then(html=>{

document.getElementById("auditContent").innerHTML=html;

new bootstrap.Modal(
document.getElementById("auditModal")
).show();

});

});

});
</script>

</body>

</html>