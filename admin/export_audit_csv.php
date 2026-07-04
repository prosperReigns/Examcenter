<?php
session_start();

require_once "../db.php";

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

/*
|--------------------------------------------------------------------------
| Read Filters
|--------------------------------------------------------------------------
*/

$keyword  = trim($_GET['keyword'] ?? '');
$module   = trim($_GET['module'] ?? '');
$action   = trim($_GET['action'] ?? '');
$admin    = trim($_GET['admin'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

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

    $where[] = "module=?";

    $params[] = $module;

    $types .= "s";
}

if ($action !== "") {

    $where[] = "action=?";

    $params[] = $action;

    $types .= "s";
}

if ($admin !== "") {

    $where[] = "username=?";

    $params[] = $admin;

    $types .= "s";
}

if ($dateFrom !== "") {

    $where[] = "DATE(created_at)>=?";

    $params[] = $dateFrom;

    $types .= "s";
}

if ($dateTo !== "") {

    $where[] = "DATE(created_at)<=?";

    $params[] = $dateTo;

    $types .= "s";
}

$whereSQL = "";

if (!empty($where)) {

    $whereSQL = "WHERE " . implode(" AND ", $where);

}

/*
|--------------------------------------------------------------------------
| Query
|--------------------------------------------------------------------------
*/

$sql = "

SELECT

created_at,
username,
module,
action,
description,
ip_address,
computer_name,
user_agent

FROM audit_logs

{$whereSQL}

ORDER BY created_at DESC

";

$stmt = $conn->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param($types, ...$params);

}

$stmt->execute();

$result = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Download
|--------------------------------------------------------------------------
*/

$filename =
"audit_logs_" .
date("Ymd_His") .
".csv";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen("php://output", "w");

/*
|--------------------------------------------------------------------------
| CSV Header
|--------------------------------------------------------------------------
*/

fputcsv($output, [

"Date",

"Administrator",

"Module",

"Action",

"Description",

"IP Address",

"Computer",

"Browser"

]);

/*
|--------------------------------------------------------------------------
| Rows
|--------------------------------------------------------------------------
*/

while ($row = $result->fetch_assoc()) {

    fputcsv($output, [

        $row['created_at'],

        $row['username'],

        $row['module'],

        $row['action'],

        $row['description'],

        $row['ip_address'],

        $row['computer_name'],

        $row['user_agent']

    ]);

}

fclose($output);

exit;