<?php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once "../backup/backup_scheduler.php";

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'admin'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $admin_id = (int) $_SESSION['user_id'];


    /*
    |--------------------------------------------------------------------------
    | HELPER FUNCTIONS
    |--------------------------------------------------------------------------
    */

    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);

        $result = $conn->query(
            "SHOW TABLES LIKE '{$table}'"
        );

        return $result && $result->num_rows > 0;
    }


    function columnExists(mysqli $conn, string $table, string $column): bool
    {
        if (!tableExists($conn, $table)) {
            return false;
        }

        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
        );

        return $result && $result->num_rows > 0;
    }


    function getCount(mysqli $conn, string $table): int
    {
        if (!tableExists($conn, $table)) {
            return 0;
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

        $result = $conn->query(
            "SELECT COUNT(*) AS total FROM `{$table}`"
        );

        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();

        return (int) ($row['total'] ?? 0);
    }


    function getScalar(mysqli $conn, string $sql, $default = 0)
    {
        $result = $conn->query($sql);

        if (!$result) {
            return $default;
        }

        $row = $result->fetch_assoc();

        if (!$row) {
            return $default;
        }

        return array_values($row)[0] ?? $default;
    }


    function safeDate($value, string $format = 'd M Y'): string
    {
        if (empty($value)) {
            return 'N/A';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return 'N/A';
        }

        return date($format, $timestamp);
    }


    function formatBytesSafe($bytes): string
    {
        if (!is_numeric($bytes) || $bytes <= 0) {
            return '0 B';
        }

        $bytes = (float) $bytes;

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $power = floor(
            log($bytes, 1024)
        );

        $power = min($power, count($units) - 1);

        return number_format(
            $bytes / pow(1024, $power),
            $power === 0 ? 0 : 2
        ) . ' ' . $units[$power];
    }


    /*
    |--------------------------------------------------------------------------
    | ADMIN PROFILE
    |--------------------------------------------------------------------------
    */

    $admin = null;

    if (tableExists($conn, 'admins')) {

        $stmt = $conn->prepare(
            "SELECT username FROM admins WHERE id = ? LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare admin profile query."
            );
        }

        $stmt->bind_param("i", $admin_id);
        $stmt->execute();

        $admin = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();
    }


    if (!$admin) {

        error_log(
            "No admin found for user_id={$admin_id}"
        );

        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    if (tableExists($conn, 'activities_log')) {

        $ip_address = filter_var(
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            FILTER_VALIDATE_IP
        ) ?: '0.0.0.0';

        $user_agent =
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $activity =
            "Admin {$admin['username']} accessed the dashboard.";

        $stmt = $conn->prepare(
            "
            INSERT INTO activities_log
            (
                activity,
                admin_id,
                ip_address,
                user_agent,
                created_at
            )
            VALUES (?, ?, ?, ?, NOW())
            "
        );

        if ($stmt) {

            $stmt->bind_param(
                "siss",
                $activity,
                $admin_id,
                $ip_address,
                $user_agent
            );

            $stmt->execute();
            $stmt->close();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC BACKUP SCHEDULER
    |--------------------------------------------------------------------------
    */

    try {

        runBackupScheduler(
            $conn,
            $_SESSION['user_id']
        );

    } catch (Throwable $backupSchedulerError) {

        error_log(
            "Backup scheduler error: " .
            $backupSchedulerError->getMessage()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CORE STATISTICS
    |--------------------------------------------------------------------------
    */

    $totalQuestions =
        getCount($conn, 'new_questions');

    $totalSubjects =
        getCount($conn, 'subjects');

    $totalTests =
        getCount($conn, 'tests');

    $totalStudents =
        getCount($conn, 'students');


    /*
    |--------------------------------------------------------------------------
    | AGENTS
    |--------------------------------------------------------------------------
    |
    | The current Examcenter architecture uses agents rather than
    | the older teacher-management concept.
    |
    */

    $totalAgents = 0;

    if (tableExists($conn, 'agents')) {

        $totalAgents =
            getCount($conn, 'agents');

    } elseif (tableExists($conn, 'teachers')) {

        /*
        | Backward compatibility for installations that still have
        | the old teachers table.
        */

        $totalAgents =
            getCount($conn, 'teachers');
    }


    /*
    |--------------------------------------------------------------------------
    | QUESTION BANK
    |--------------------------------------------------------------------------
    */

    $questionsWithImages = 0;

    if (tableExists($conn, 'image_questions')) {

        $questionsWithImages = (int) getScalar(
            $conn,
            "
            SELECT COUNT(*)
            FROM image_questions
            WHERE image_path IS NOT NULL
            AND image_path <> ''
            "
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RESULTS
    |--------------------------------------------------------------------------
    */

    $totalResults =
        getCount($conn, 'results');

    $pendingResults = 0;

    if (tableExists($conn, 'results') &&
        columnExists($conn, 'results', 'status')
    ) {

        $pendingResults = (int) getScalar(
            $conn,
            "
            SELECT COUNT(*)
            FROM results
            WHERE status = 'pending'
            "
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BACKUP STATISTICS
    |--------------------------------------------------------------------------
    */

    $totalBackups =
        getCount($conn, 'backups');

    $backupStorage = 0;

    $latestBackup = null;

    $recentBackups = null;

    if (tableExists($conn, 'backups')) {

        if (columnExists($conn, 'backups', 'file_size')) {

            $backupStorage = (int) getScalar(
                $conn,
                "
                SELECT IFNULL(
                    SUM(file_size),
                    0
                )
                FROM backups
                "
            );
        }

        if (columnExists($conn, 'backups', 'created_at')) {

            $result = $conn->query(
                "
                SELECT *
                FROM backups
                ORDER BY created_at DESC
                LIMIT 1
                "
            );

            if ($result) {
                $latestBackup =
                    $result->fetch_assoc();
            }

            $recentBackups =
                $conn->query(
                    "
                    SELECT *
                    FROM backups
                    ORDER BY created_at DESC
                    LIMIT 5
                    "
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | AUDIT LOG STATISTICS
    |--------------------------------------------------------------------------
    */

    $totalAuditLogs =
        getCount($conn, 'audit_logs');

    $todayAuditLogs = 0;

    $failedLogins = 0;

    if (tableExists($conn, 'audit_logs')) {

        if (columnExists($conn, 'audit_logs', 'created_at')) {

            $todayAuditLogs = (int) getScalar(
                $conn,
                "
                SELECT COUNT(*)
                FROM audit_logs
                WHERE DATE(created_at) = CURDATE()
                "
            );
        }

        if (columnExists($conn, 'audit_logs', 'action')) {

            $failedLogins = (int) getScalar(
                $conn,
                "
                SELECT COUNT(*)
                FROM audit_logs
                WHERE action = 'Failed Login'
                "
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | LICENSE
    |--------------------------------------------------------------------------
    |
    | Examcenter is now connected to the external license system.
    |
    | The dashboard therefore treats the local license record as
    | local activation state rather than attempting to reproduce
    | the complete license-server logic here.
    |
    */

    $license = null;

    $licenseStatus = 'Not Activated';

    $licensePlan = 'N/A';

    $licenseExpiry = null;

    $daysRemaining = 0;

    $licenseVersion = 'N/A';

    $licenseIsActive = false;

    if (tableExists($conn, 'licenses')) {

        $licenseResult = $conn->query(
            "
            SELECT *
            FROM licenses
            ORDER BY id DESC
            LIMIT 1
            "
        );

        if ($licenseResult) {

            $license =
                $licenseResult->fetch_assoc();
        }
    }


    if ($license) {

        if (isset($license['status'])) {

            $licenseStatus =
                (string) $license['status'];
        }

        /*
        | Support both the older expires_at naming and
        | possible expiry_at naming.
        */

        if (!empty($license['expires_at'])) {

            $licenseExpiry =
                $license['expires_at'];

        } elseif (!empty($license['expiry_at'])) {

            $licenseExpiry =
                $license['expiry_at'];
        }


        if (!empty($license['plan_name'])) {

            $licensePlan =
                $license['plan_name'];

        } elseif (!empty($license['license_type'])) {

            $licensePlan =
                $license['license_type'];
        }


        if (!empty($license['version'])) {

            $licenseVersion =
                $license['version'];
        }


        if (!empty($licenseExpiry)) {

            try {

                $today =
                    new DateTime();

                $expiry =
                    new DateTime($licenseExpiry);

                if ($expiry > $today) {

                    $daysRemaining =
                        (int) $today
                            ->diff($expiry)
                            ->days;

                } else {

                    $daysRemaining = 0;
                }

            } catch (Throwable $e) {

                $daysRemaining = 0;
            }
        }


        $normalizedLicenseStatus =
            strtolower(trim($licenseStatus));

        $licenseIsActive =
            in_array(
                $normalizedLicenseStatus,
                [
                    'active',
                    'activated',
                    'valid'
                ],
                true
            );
    }


    /*
    |--------------------------------------------------------------------------
    | LICENSE ALERT
    |--------------------------------------------------------------------------
    */

    $alerts = [];


    if (!$license) {

        $alerts[] = [
            'type' => 'danger',
            'icon' => 'fa-key',
            'message' => 'No license has been activated on this installation.'
        ];

    } elseif (!$licenseIsActive) {

        $alerts[] = [
            'type' => 'danger',
            'icon' => 'fa-key',
            'message' =>
                'The current license status is ' .
                $licenseStatus . '.'
        ];

    } elseif ($daysRemaining <= 30) {

        $alerts[] = [
            'type' => 'warning',
            'icon' => 'fa-clock',
            'message' =>
                "License expires in {$daysRemaining} day(s)."
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | BACKUP ALERT
    |--------------------------------------------------------------------------
    */

    if ($latestBackup) {

        if (!empty($latestBackup['created_at'])) {

            try {

                $lastBackupDate =
                    new DateTime(
                        $latestBackup['created_at']
                    );

                $today =
                    new DateTime();

                $daysSinceBackup =
                    (int) $today
                        ->diff($lastBackupDate)
                        ->days;

                if ($daysSinceBackup >= 3) {

                    $alerts[] = [
                        'type' => 'danger',
                        'icon' => 'fa-database',
                        'message' =>
                            "No database backup has been created in {$daysSinceBackup} day(s)."
                    ];
                }

            } catch (Throwable $e) {
                // Ignore malformed backup dates.
            }
        }

    } elseif ($totalBackups === 0) {

        $alerts[] = [
            'type' => 'warning',
            'icon' => 'fa-database',
            'message' =>
                'No database backup has been created yet.'
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | FAILED LOGIN ALERT
    |--------------------------------------------------------------------------
    */

    if ($failedLogins > 0) {

        $alerts[] = [
            'type' => 'warning',
            'icon' => 'fa-shield-alt',
            'message' =>
                "{$failedLogins} failed login attempt(s) have been recorded."
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | DATABASE SIZE
    |--------------------------------------------------------------------------
    */

    $dbSize = getScalar(
        $conn,
        "
        SELECT
            ROUND(
                SUM(data_length + index_length)
                / 1024 / 1024,
                2
            )
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        ",
        0
    );

    $dbSize = (float) $dbSize;


    if ($dbSize > 800) {

        $alerts[] = [
            'type' => 'info',
            'icon' => 'fa-database',
            'message' =>
                "Database size is {$dbSize} MB."
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | RECENT TESTS
    |--------------------------------------------------------------------------
    */

    $recentTests = null;

    if (tableExists($conn, 'tests')) {

        $recentTests =
            $conn->query(
                "
                SELECT *
                FROM tests
                ORDER BY created_at DESC
                LIMIT 5
                "
            );
    }


    /*
    |--------------------------------------------------------------------------
    | RECENT AUDIT LOGS
    |--------------------------------------------------------------------------
    */

    $recentAuditLogs = null;

    if (tableExists($conn, 'audit_logs')) {

        $recentAuditLogs =
            $conn->query(
                "
                SELECT *
                FROM audit_logs
                ORDER BY created_at DESC
                LIMIT 8
                "
            );
    }


    /*
    |--------------------------------------------------------------------------
    | QUESTION DISTRIBUTION
    |--------------------------------------------------------------------------
    */

    $questionSubjectData = [];

    if (
        tableExists($conn, 'subjects') &&
        tableExists($conn, 'new_questions')
    ) {

        $result = $conn->query(
            "
            SELECT
                s.subject_name,
                COUNT(q.id) AS total
            FROM subjects s
            LEFT JOIN new_questions q
                ON s.subject_name = q.subject
            GROUP BY
                s.id,
                s.subject_name
            ORDER BY
                s.subject_name
            "
        );

        if ($result) {

            while ($row = $result->fetch_assoc()) {

                $questionSubjectData[] = $row;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | MONTHLY TESTS
    |--------------------------------------------------------------------------
    */

    $monthlyTests = [];

    if (
        tableExists($conn, 'tests') &&
        columnExists($conn, 'tests', 'created_at')
    ) {

        $result = $conn->query(
            "
            SELECT
                DATE_FORMAT(created_at, '%b') AS month,
                COUNT(*) AS total
            FROM tests
            WHERE YEAR(created_at) = YEAR(CURDATE())
            GROUP BY MONTH(created_at)
            ORDER BY MONTH(created_at)
            "
        );

        if ($result) {

            while ($row = $result->fetch_assoc()) {

                $monthlyTests[] = $row;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | AUDIT ACTIVITY
    |--------------------------------------------------------------------------
    */

    $auditActivity = [];

    if (
        tableExists($conn, 'audit_logs') &&
        columnExists($conn, 'audit_logs', 'module')
    ) {

        $result = $conn->query(
            "
            SELECT
                module,
                COUNT(*) AS total
            FROM audit_logs
            GROUP BY module
            ORDER BY total DESC
            LIMIT 10
            "
        );

        if ($result) {

            while ($row = $result->fetch_assoc()) {

                $auditActivity[] = $row;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESULT DISTRIBUTION
    |--------------------------------------------------------------------------
    */

    $resultDistribution = [];

    if (
        tableExists($conn, 'results') &&
        columnExists($conn, 'results', 'score')
    ) {

        $result = $conn->query(
            "
            SELECT
                score,
                COUNT(*) AS total
            FROM results
            GROUP BY score
            ORDER BY score
            "
        );

        if ($result) {

            while ($row = $result->fetch_assoc()) {

                $resultDistribution[] = $row;
            }
        }
    }


} catch (Throwable $e) {

    error_log(
        "Dashboard error: " .
        $e->getMessage()
    );

    echo '<pre>';
    echo "Dashboard Error: " .
        htmlspecialchars($e->getMessage());
    echo "\n\n";
    echo htmlspecialchars(
        $e->getTraceAsString()
    );
    echo '</pre>';

    exit();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Admin Dashboard | Examcenter
    </title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/view_results.css">
    <link rel="stylesheet" href="../css/sidebar.css">


    <style>

        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        body {
            background: #f5f7fb;
        }

        .main-content {
            min-height: 100vh;
            padding-bottom: 40px;
        }


        /*
        |--------------------------------------------------------------------------
        | HEADER
        |--------------------------------------------------------------------------
        */

        .dashboard-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .dashboard-header-left h2 {
            margin: 0;
            font-weight: 700;
        }

        .dashboard-header-left p {
            margin: 5px 0 0;
            color: #6c757d;
        }

        .dashboard-header-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR TOGGLE
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | The toggle is deliberately on the RIGHT-HAND side.
        |
        */

        #sidebarToggle {
            width: 46px;
            height: 42px;
            border: none;
            border-radius: 7px;
            background: #0d6efd;
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
            transition: .2s ease;
        }

        #sidebarToggle:hover {
            background: #0b5ed7;
            transform: translateY(-1px);
        }

        #sidebarToggle:focus {
            outline: 3px solid rgba(13, 110, 253, .25);
        }

        #sidebarToggle i {
            font-size: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | STAT CARDS
        |--------------------------------------------------------------------------
        */

        .dashboard-stat-card {
            border: 0;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .06);
            transition: transform .2s ease,
                        box-shadow .2s ease;
        }

        .dashboard-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 20px rgba(0, 0, 0, .09);
        }

        .dashboard-stat-card .card-body {
            padding: 22px;
        }

        .stat-label {
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 700;
            margin-top: 5px;
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
        }

        .icon-blue {
            background: #e7f0ff;
            color: #0d6efd;
        }

        .icon-green {
            background: #e8f7ee;
            color: #198754;
        }

        .icon-orange {
            background: #fff3df;
            color: #fd7e14;
        }

        .icon-purple {
            background: #f0eaff;
            color: #6f42c1;
        }


        /*
        |--------------------------------------------------------------------------
        | DASHBOARD CARDS
        |--------------------------------------------------------------------------
        */

        .dashboard-card {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .06);
            overflow: hidden;
        }

        .dashboard-card .card-header {
            border: 0;
            padding: 15px 18px;
            font-weight: 600;
        }

        .dashboard-card .card-body {
            padding: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | QUICK ACTIONS
        |--------------------------------------------------------------------------
        */

        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 82px;
            padding: 14px;
            border-radius: 10px;
            text-decoration: none;
            color: #212529;
            background: #f8f9fa;
            border: 1px solid #edf0f3;
            transition: .2s ease;
        }

        .quick-action:hover {
            color: #212529;
            background: #eef3f8;
            transform: translateY(-2px);
        }

        .quick-action-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #e9f1ff;
            color: #0d6efd;
        }

        .quick-action-title {
            font-weight: 600;
            font-size: 14px;
        }

        .quick-action-description {
            display: block;
            color: #6c757d;
            font-size: 12px;
            margin-top: 2px;
        }


        /*
        |--------------------------------------------------------------------------
        | LICENSE CARD
        |--------------------------------------------------------------------------
        */

        .license-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .license-active {
            color: #198754;
            background: #e8f7ee;
        }

        .license-warning {
            color: #fd7e14;
            background: #fff3df;
        }

        .license-danger {
            color: #dc3545;
            background: #fdebed;
        }

        .license-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }


        /*
        |--------------------------------------------------------------------------
        | SYSTEM INFO
        |--------------------------------------------------------------------------
        */

        .system-info {
            padding: 16px;
            text-align: center;
            border-right: 1px solid #eee;
        }

        .system-info:last-child {
            border-right: 0;
        }

        .system-info-label {
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .system-info-value {
            font-weight: 600;
        }


        /*
        |--------------------------------------------------------------------------
        | TABLES
        |--------------------------------------------------------------------------
        */

        .dashboard-table th {
            font-size: 12px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            white-space: nowrap;
        }

        .dashboard-table td {
            vertical-align: middle;
        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .empty-state {
            padding: 35px 15px;
            text-align: center;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 30px;
            margin-bottom: 10px;
            opacity: .5;
        }


        /*
        |--------------------------------------------------------------------------
        | ALERTS
        |--------------------------------------------------------------------------
        */

        .system-alert {
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .system-alert:last-child {
            margin-bottom: 0;
        }


        /*
        |--------------------------------------------------------------------------
        | CHART
        |--------------------------------------------------------------------------
        */

        .chart-container {
            position: relative;
            height: 300px;
        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 991.98px) {

            #sidebarToggle {
                display: flex;
            }

            .dashboard-header {
                align-items: flex-start;
            }

            .dashboard-header-right {
                flex-shrink: 0;
            }

        }


        @media (max-width: 575.98px) {

            .dashboard-header {
                flex-direction: row;
            }

            .dashboard-header-left h2 {
                font-size: 21px;
            }

            .dashboard-header-left p {
                font-size: 12px;
            }

            .dashboard-header-right .btn-results {
                display: none;
            }

            .system-info {
                border-right: 0;
                border-bottom: 1px solid #eee;
            }

            .system-info:last-child {
                border-bottom: 0;
            }
        }

    </style>

</head>


<body>


<!-- ============================================================
     SIDEBAR
============================================================ -->

<div
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <h3>
            <i class="fas fa-graduation-cap me-2"></i>
            Examcenter
        </h3>

        <div class="admin-info">

            <small>
                <b>Welcome back,</b>
            </small>

            <h6>
                <b>
                    <?= htmlspecialchars(
                        $admin['username']
                    ) ?>
                </b>
            </h6>

        </div>

    </div>


    <div class="sidebar-menu mt-4">

        <a
            href="dashboard.php"
            class="active"
        >
            <i class="fas fa-tachometer-alt"></i>
            Dashboard
        </a>


        <a href="bank.php">
            <i class="fas fa-database"></i>
            Question Bank
        </a>


        <a href="view_questions.php">
            <i class="fas fa-list"></i>
            View Questions
        </a>


        <a href="view_results.php">
            <i class="fas fa-chart-bar"></i>
            Exam Results
        </a>


        <a href="manage_students.php">
            <i class="fas fa-user-graduate"></i>
            Manage Students
        </a>


        <a href="manage_classes.php">
            <i class="fas fa-users"></i>
            Manage Classes
        </a>


        <a href="manage_session.php">
            <i class="fas fa-calendar-alt"></i>
            Academic Session
        </a>


        <a href="manage_subject.php">
            <i class="fas fa-book"></i>
            Manage Subjects
        </a>


        <a href="manage_teachers.php">
            <i class="fas fa-user-tie"></i>
            Manage Agents
        </a>


        <a href="manage_test.php">
            <i class="fas fa-file-alt"></i>
            Manage Tests
        </a>


        <a href="settings.php">
            <i class="fas fa-cog"></i>
            Settings
        </a>


        <a href="license.php">
            <i class="fas fa-key"></i>
            License
        </a>


        <a href="audit_logs.php">
            <i class="fas fa-shield-alt"></i>
            Audit Logs
        </a>


        <a href="../backup/backup_list.php">
            <i class="fas fa-database"></i>
            Backup
        </a>


        <a
            href="logout.php"
            class="logout-btn"
        >
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>

    </div>

</div>


<!-- ============================================================
     MAIN CONTENT
============================================================ -->

<div class="main-content">


    <!-- ========================================================
         HEADER
    ========================================================= -->

    <div class="dashboard-header">

        <div class="dashboard-header-left">

            <h2>
                Admin Dashboard
            </h2>

            <p>
                Monitor your Examcenter installation,
                examinations, license and system activity.
            </p>

        </div>


        <!-- RIGHT SIDE -->

        <div class="dashboard-header-right">

            <a
                href="view_results.php"
                class="btn btn-secondary btn-results"
            >
                <i class="fas fa-chart-bar me-2"></i>
                View Results
            </a>


            <!--
            =====================================================
            SIDEBAR TOGGLE
            RIGHT-HAND SIDE
            =====================================================
            -->

            <button
                type="button"
                id="sidebarToggle"
                aria-label="Toggle navigation"
                aria-expanded="false"
                title="Toggle navigation"
            >
                <i class="fas fa-bars"></i>
            </button>

        </div>

    </div>


    <!-- ========================================================
         MAIN STATISTICS
    ========================================================= -->

    <div
        class="row g-3 mb-4"
        id="dashboardStats"
    >


        <!-- QUESTIONS -->

        <div class="col-xl-3 col-md-6">

            <div class="card dashboard-stat-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="stat-label">
                                Question Bank
                            </div>

                            <div class="stat-value">
                                <?= number_format(
                                    $totalQuestions
                                ) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-blue">

                            <i class="fas fa-database"></i>

                        </div>

                    </div>

                    <small class="text-muted">
                        <?= number_format(
                            $questionsWithImages
                        ) ?>
                        image question(s)
                    </small>

                </div>

            </div>

        </div>


        <!-- TESTS -->

        <div class="col-xl-3 col-md-6">

            <div class="card dashboard-stat-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="stat-label">
                                Tests
                            </div>

                            <div class="stat-value">
                                <?= number_format(
                                    $totalTests
                                ) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-green">

                            <i class="fas fa-file-alt"></i>

                        </div>

                    </div>

                    <small class="text-muted">
                        Tests configured in the system
                    </small>

                </div>

            </div>

        </div>


        <!-- STUDENTS -->

        <div class="col-xl-3 col-md-6">

            <div class="card dashboard-stat-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="stat-label">
                                Students
                            </div>

                            <div class="stat-value">
                                <?= number_format(
                                    $totalStudents
                                ) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-orange">

                            <i class="fas fa-user-graduate"></i>

                        </div>

                    </div>

                    <small class="text-muted">
                        Registered candidates
                    </small>

                </div>

            </div>

        </div>


        <!-- AGENTS -->

        <div class="col-xl-3 col-md-6">

            <div class="card dashboard-stat-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="stat-label">
                                Agents
                            </div>

                            <div class="stat-value">
                                <?= number_format(
                                    $totalAgents
                                ) ?>
                            </div>

                        </div>

                        <div class="stat-icon icon-purple">

                            <i class="fas fa-user-tie"></i>

                        </div>

                    </div>

                    <small class="text-muted">
                        Administrative exam agents
                    </small>

                </div>

            </div>

        </div>

    </div>


    <!-- ========================================================
         SYSTEM OVERVIEW
    ========================================================= -->

    <div class="row g-4">


        <!-- SYSTEM ALERTS -->

        <div class="col-lg-6">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-danger text-white">

                    <i class="fas fa-exclamation-triangle me-2"></i>

                    System Alerts

                </div>


                <div class="card-body">

                    <?php if (empty($alerts)): ?>

                        <div class="alert alert-success mb-0">

                            <i class="fas fa-check-circle me-2"></i>

                            No system alerts.

                        </div>

                    <?php else: ?>

                        <?php foreach ($alerts as $alert): ?>

                            <div
                                class="alert alert-<?= htmlspecialchars(
                                    $alert['type']
                                ) ?> system-alert"
                            >

                                <i
                                    class="fas <?= htmlspecialchars(
                                        $alert['icon']
                                    ) ?> me-2"
                                ></i>

                                <?= htmlspecialchars(
                                    $alert['message']
                                ) ?>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- QUICK ACTIONS -->

        <div class="col-lg-6">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-primary text-white">

                    <i class="fas fa-bolt me-2"></i>

                    Quick Actions

                </div>


                <div class="card-body">

                    <div class="row g-3">


                        <div class="col-sm-6">

                            <a
                                href="../teacher/add_question.php"
                                class="quick-action"
                            >

                                <div class="quick-action-icon">
                                    <i class="fas fa-plus-circle"></i>
                                </div>

                                <div>

                                    <div class="quick-action-title">
                                        Create Test
                                    </div>

                                    <span class="quick-action-description">
                                        Create examination content
                                    </span>

                                </div>

                            </a>

                        </div>


                        <div class="col-sm-6">

                            <a
                                href="bank.php"
                                class="quick-action"
                            >

                                <div class="quick-action-icon">
                                    <i class="fas fa-database"></i>
                                </div>

                                <div>

                                    <div class="quick-action-title">
                                        Question Bank
                                    </div>

                                    <span class="quick-action-description">
                                        Manage examination questions
                                    </span>

                                </div>

                            </a>

                        </div>


                        <div class="col-sm-6">

                            <a
                                href="import_questions.php"
                                class="quick-action"
                            >

                                <div class="quick-action-icon">
                                    <i class="fas fa-file-import"></i>
                                </div>

                                <div>

                                    <div class="quick-action-title">
                                        Import Questions
                                    </div>

                                    <span class="quick-action-description">
                                        Import questions into the bank
                                    </span>

                                </div>

                            </a>

                        </div>


                        <div class="col-sm-6">

                            <a
                                href="../backup/create_backup.php"
                                class="quick-action"
                            >

                                <div class="quick-action-icon">
                                    <i class="fas fa-save"></i>
                                </div>

                                <div>

                                    <div class="quick-action-title">
                                        Create Backup
                                    </div>

                                    <span class="quick-action-description">
                                        Back up the local database
                                    </span>

                                </div>

                            </a>

                        </div>


                        <div class="col-sm-6">

                            <a
                                href="../backup/backup_list.php"
                                class="quick-action"
                            >

                                <div class="quick-action-icon">
                                    <i class="fas fa-history"></i>
                                </div>

                                <div>

                                    <div class="quick-action-title">
                                        Backups
                                    </div>

                                    <span class="quick-action-description">
                                        View and manage backups
                                    </span>

                                </div>

                            </a>

                        </div>


                        <div class="col-sm-6">

                            <a
                                href="audit_logs.php"
                                class="quick-action"
                            >

                                <div class="quick-action-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>

                                <div>

                                    <div class="quick-action-title">
                                        Audit Logs
                                    </div>

                                    <span class="quick-action-description">
                                        Review system activity
                                    </span>

                                </div>

                            </a>

                        </div>


                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ========================================================
         TESTS + LICENSE
    ========================================================= -->

    <div class="row g-4 mt-1">


        <!-- RECENT TESTS -->

        <div class="col-lg-8">

            <div class="card dashboard-card">

                <div class="card-header bg-success text-white">

                    <i class="fas fa-file-alt me-2"></i>

                    Recent Tests

                </div>


                <div class="table-responsive">

                    <table class="table table-hover mb-0 dashboard-table">

                        <thead>

                            <tr>

                                <th>Test</th>

                                <th>Year</th>

                                <th>Questions</th>

                                <th>Created</th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php if (
                            $recentTests &&
                            $recentTests->num_rows > 0
                        ): ?>

                            <?php while (
                                $test =
                                    $recentTests->fetch_assoc()
                            ): ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars(
                                            $test['title']
                                            ?? 'Untitled Test'
                                        ) ?>
                                    </td>


                                    <td>

                                        <span class="badge bg-success">

                                            <?= htmlspecialchars(
                                                $test['year']
                                                ?? $test['academic_year']
                                                ?? 'N/A'
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= number_format(
                                            (int) (
                                                $test['question_count']
                                                ?? 0
                                            )
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= safeDate(
                                            $test['created_at']
                                            ?? null
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="4">

                                    <div class="empty-state">

                                        <i class="fas fa-file-alt d-block"></i>

                                        No tests have been created yet.

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>


        <!-- LICENSE -->

        <div class="col-lg-4">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-dark text-white">

                    <i class="fas fa-key me-2"></i>

                    License

                </div>


                <div class="card-body">


                    <div class="mb-3">

                        <?php

                        if ($licenseIsActive) {

                            $licenseClass =
                                'license-active';

                        } elseif ($license) {

                            $licenseClass =
                                'license-danger';

                        } else {

                            $licenseClass =
                                'license-danger';
                        }

                        ?>

                        <span
                            class="license-status <?= $licenseClass ?>"
                        >

                            <span class="license-dot"></span>

                            <?= htmlspecialchars(
                                $licenseStatus
                            ) ?>

                        </span>

                    </div>


                    <table class="table table-borderless mb-0">

                        <tr>

                            <th>
                                Plan
                            </th>

                            <td class="text-end">
                                <?= htmlspecialchars(
                                    $licensePlan
                                ) ?>
                            </td>

                        </tr>


                        <tr>

                            <th>
                                Expires
                            </th>

                            <td class="text-end">

                                <?= safeDate(
                                    $licenseExpiry
                                ) ?>

                            </td>

                        </tr>


                        <tr>

                            <th>
                                Days Left
                            </th>

                            <td class="text-end">

                                <strong>
                                    <?= number_format(
                                        $daysRemaining
                                    ) ?>
                                </strong>

                            </td>

                        </tr>


                        <tr>

                            <th>
                                Version
                            </th>

                            <td class="text-end">

                                <?= htmlspecialchars(
                                    $licenseVersion
                                ) ?>

                            </td>

                        </tr>

                    </table>


                    <div class="d-grid mt-3">

                        <a
                            href="license.php"
                            class="btn btn-outline-primary"
                        >

                            <i class="fas fa-key me-2"></i>

                            Manage License

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ========================================================
         BACKUP + AUDIT
    ========================================================= -->

    <div class="row g-4 mt-1">


        <!-- BACKUPS -->

        <div class="col-lg-4">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-warning">

                    <i class="fas fa-database me-2"></i>

                    Backup Overview

                </div>


                <div class="card-body">


                    <div class="row text-center mb-3">

                        <div class="col-6">

                            <div class="text-muted small">
                                Total Backups
                            </div>

                            <div class="fs-4 fw-bold">
                                <?= number_format(
                                    $totalBackups
                                ) ?>
                            </div>

                        </div>


                        <div class="col-6">

                            <div class="text-muted small">
                                Storage Used
                            </div>

                            <div class="fs-4 fw-bold">
                                <?= formatBytesSafe(
                                    $backupStorage
                                ) ?>
                            </div>

                        </div>

                    </div>


                    <hr>


                    <?php if (
                        $recentBackups &&
                        $recentBackups->num_rows > 0
                    ): ?>

                        <div class="table-responsive">

                            <table class="table table-sm mb-0">

                                <thead>

                                    <tr>

                                        <th>
                                            Date
                                        </th>

                                        <th>
                                            Size
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php while (
                                    $backup =
                                        $recentBackups->fetch_assoc()
                                ): ?>

                                    <tr>

                                        <td>

                                            <?= safeDate(
                                                $backup['created_at']
                                                ?? null,
                                                'd M H:i'
                                            ) ?>

                                        </td>


                                        <td>

                                            <?= formatBytesSafe(
                                                $backup['file_size']
                                                ?? 0
                                            ) ?>

                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="fas fa-database d-block"></i>

                            No backups available.

                        </div>

                    <?php endif; ?>


                    <div class="d-grid mt-3">

                        <a
                            href="../backup/backup_list.php"
                            class="btn btn-outline-dark"
                        >

                            <i class="fas fa-history me-2"></i>

                            Manage Backups

                        </a>

                    </div>

                </div>

            </div>

        </div>


        <!-- AUDIT -->

        <div class="col-lg-8">

            <div class="card dashboard-card h-100">

                <div class="card-header bg-dark text-white">

                    <i class="fas fa-history me-2"></i>

                    Recent Audit Activity

                </div>


                <div class="table-responsive">

                    <table class="table table-striped mb-0 dashboard-table">

                        <thead>

                            <tr>

                                <th>
                                    Time
                                </th>

                                <th>
                                    Admin
                                </th>

                                <th>
                                    Module
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php if (
                            $recentAuditLogs &&
                            $recentAuditLogs->num_rows > 0
                        ): ?>

                            <?php while (
                                $log =
                                    $recentAuditLogs->fetch_assoc()
                            ): ?>

                                <tr>

                                    <td>

                                        <?= safeDate(
                                            $log['created_at']
                                            ?? null,
                                            'd M H:i'
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $log['username']
                                            ?? 'System'
                                        ) ?>

                                    </td>


                                    <td>

                                        <span class="badge bg-secondary">

                                            <?= htmlspecialchars(
                                                $log['module']
                                                ?? 'System'
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $log['action']
                                            ?? 'Activity'
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="4">

                                    <div class="empty-state">

                                        <i class="fas fa-history d-block"></i>

                                        No audit activity recorded yet.

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>


    <!-- ========================================================
         SYSTEM INFORMATION
    ========================================================= -->

    <div class="card dashboard-card mt-4">

        <div class="card-body p-0">

            <div class="row g-0">


                <div class="col-md-3">

                    <div class="system-info">

                        <div class="system-info-label">
                            Database Size
                        </div>

                        <div class="system-info-value">
                            <?= number_format(
                                $dbSize,
                                2
                            ) ?>
                            MB
                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="system-info">

                        <div class="system-info-label">
                            PHP Version
                        </div>

                        <div class="system-info-value">
                            <?= htmlspecialchars(
                                PHP_VERSION
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="system-info">

                        <div class="system-info-label">
                            MySQL Version
                        </div>

                        <div class="system-info-value">

                            <?= htmlspecialchars(
                                $conn->server_info
                            ) ?>

                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="system-info">

                        <div class="system-info-label">
                            Server Time
                        </div>

                        <div class="system-info-value">

                            <?= date(
                                "d M Y H:i:s"
                            ) ?>

                        </div>

                    </div>

                </div>


            </div>

        </div>

    </div>


    <!-- ========================================================
         CHARTS
    ========================================================= -->

    <div class="row g-4 mt-1">


        <!-- QUESTIONS PER SUBJECT -->

        <div class="col-lg-6">

            <div class="card dashboard-card">

                <div class="card-header">

                    <i class="fas fa-chart-bar me-2"></i>

                    Questions Per Subject

                </div>


                <div class="card-body">

                    <div class="chart-container">

                        <canvas id="subjectChart"></canvas>

                    </div>

                </div>

            </div>

        </div>


        <!-- MONTHLY TESTS -->

        <div class="col-lg-6">

            <div class="card dashboard-card">

                <div class="card-header">

                    <i class="fas fa-chart-line me-2"></i>

                    Tests Created This Year

                </div>


                <div class="card-body">

                    <div class="chart-container">

                        <canvas id="testChart"></canvas>

                    </div>

                </div>

            </div>

        </div>


        <!-- AUDIT -->

        <div class="col-lg-6">

            <div class="card dashboard-card">

                <div class="card-header">

                    <i class="fas fa-shield-alt me-2"></i>

                    Audit Activity

                </div>


                <div class="card-body">

                    <div class="chart-container">

                        <canvas id="auditChart"></canvas>

                    </div>

                </div>

            </div>

        </div>


        <!-- RESULTS -->

        <div class="col-lg-6">

            <div class="card dashboard-card">

                <div class="card-header">

                    <i class="fas fa-chart-pie me-2"></i>

                    Result Distribution

                </div>


                <div class="card-body">

                    <div class="chart-container">

                        <canvas id="resultChart"></canvas>

                    </div>

                </div>

            </div>

        </div>

    </div>


</div>


<!-- ============================================================
     SCRIPTS
============================================================ -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/chart.min.js"></script>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR TOGGLE
        |--------------------------------------------------------------------------
        |
        | This is intentionally independent of jQuery.
        | Therefore it will still work even if another JS file
        | has a problem.
        |
        */

        const sidebar =
            document.getElementById('sidebar');

        const sidebarToggle =
            document.getElementById('sidebarToggle');


        if (sidebar && sidebarToggle) {

            sidebarToggle.addEventListener(
                'click',
                function () {

                    sidebar.classList.toggle('active');

                    const isOpen =
                        sidebar.classList.contains(
                            'active'
                        );

                    sidebarToggle.setAttribute(
                        'aria-expanded',
                        isOpen ? 'true' : 'false'
                    );

                }
            );


            /*
            | Close the sidebar when a menu item is
            | selected on smaller screens.
            */

            sidebar
                .querySelectorAll('a')
                .forEach(function (link) {

                    link.addEventListener(
                        'click',
                        function () {

                            if (
                                window.innerWidth <= 991
                            ) {

                                sidebar.classList.remove(
                                    'active'
                                );

                                sidebarToggle.setAttribute(
                                    'aria-expanded',
                                    'false'
                                );
                            }

                        }
                    );

                });

        }


        /*
        |--------------------------------------------------------------------------
        | QUESTION CHART
        |--------------------------------------------------------------------------
        */

        const subjectData =
            <?= json_encode(
                $questionSubjectData,
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            ) ?>;


        const subjectCanvas =
            document.getElementById(
                'subjectChart'
            );


        if (
            subjectCanvas &&
            subjectData.length > 0
        ) {

            new Chart(
                subjectCanvas,
                {
                    type: 'bar',

                    data: {

                        labels:
                            subjectData.map(
                                item =>
                                    item.subject_name
                            ),

                        datasets: [

                            {
                                label:
                                    'Questions',

                                data:
                                    subjectData.map(
                                        item =>
                                            Number(
                                                item.total
                                            )
                                    )
                            }

                        ]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false,

                        plugins: {

                            legend: {
                                display: false
                            }

                        }

                    }

                }
            );

        }


        /*
        |--------------------------------------------------------------------------
        | MONTHLY TEST CHART
        |--------------------------------------------------------------------------
        */

        const monthlyTests =
            <?= json_encode(
                $monthlyTests,
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            ) ?>;


        const testCanvas =
            document.getElementById(
                'testChart'
            );


        if (
            testCanvas &&
            monthlyTests.length > 0
        ) {

            new Chart(
                testCanvas,
                {

                    type: 'line',

                    data: {

                        labels:
                            monthlyTests.map(
                                item =>
                                    item.month
                            ),

                        datasets: [

                            {
                                label: 'Tests',

                                data:
                                    monthlyTests.map(
                                        item =>
                                            Number(
                                                item.total
                                            )
                                    ),

                                fill: false,

                                tension: .3
                            }

                        ]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false

                    }

                }
            );

        }


        /*
        |--------------------------------------------------------------------------
        | AUDIT CHART
        |--------------------------------------------------------------------------
        */

        const auditData =
            <?= json_encode(
                $auditActivity,
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            ) ?>;


        const auditCanvas =
            document.getElementById(
                'auditChart'
            );


        if (
            auditCanvas &&
            auditData.length > 0
        ) {

            new Chart(
                auditCanvas,
                {

                    type: 'doughnut',

                    data: {

                        labels:
                            auditData.map(
                                item =>
                                    item.module
                            ),

                        datasets: [

                            {
                                data:
                                    auditData.map(
                                        item =>
                                            Number(
                                                item.total
                                            )
                                    )
                            }

                        ]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false

                    }

                }
            );

        }


        /*
        |--------------------------------------------------------------------------
        | RESULT CHART
        |--------------------------------------------------------------------------
        */

        const resultData =
            <?= json_encode(
                $resultDistribution,
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            ) ?>;


        const resultCanvas =
            document.getElementById(
                'resultChart'
            );


        if (
            resultCanvas &&
            resultData.length > 0
        ) {

            new Chart(
                resultCanvas,
                {

                    type: 'pie',

                    data: {

                        labels:
                            resultData.map(
                                item =>
                                    item.score
                            ),

                        datasets: [

                            {
                                data:
                                    resultData.map(
                                        item =>
                                            Number(
                                                item.total
                                            )
                                    )
                            }

                        ]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false

                    }

                }
            );

        }

    }
);

</script>

</body>

</html>

<?php

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

?>