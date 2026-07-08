<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once "../backup/backup_scheduler.php";


// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

// Initialize database connection
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    // Fetch admin profile
    $admin_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for admin profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin) {
        error_log("No admin found for user_id=$admin_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Log admin dashboard access
    $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $activity = "Admin {$admin['username']} accessed the dashboard.";
    $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();

    runBackupScheduler(
        $conn,
        $_SESSION['user_id']
    );

    // Initialize stats array
    $stats = [
        'total_questions' => 0,
        'total_students' => 0,
        'completed_exams' => 0,
        'total_teachers' => 0,
        'question_distribution' => [],
        'performance_data' => []
        ];
    /*
    |--------------------------------------------------------------------------
    | Dashboard Helper Functions
    |--------------------------------------------------------------------------
    */

    function getCount(mysqli $conn, string $table): int {
        $result = $conn->query("SELECT COUNT(*) total FROM {$table}");
        return (int)$result->fetch_assoc()['total'];
    }

    function getScalar(mysqli $conn, string $sql){
        $result = $conn->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        return array_values($row)[0];
    }

    /*
    |--------------------------------------------------------------------------
    | Question Bank Statistics
    |--------------------------------------------------------------------------
    */

    $totalQuestions = getCount($conn, "new_questions");

    $totalSubjects = getCount($conn, "subjects");

    $totalQuestionPapers = getCount($conn, "question_bank");

    $questionsWithImages = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM image_questions
        WHERE image_path IS NOT NULL
        AND image_path <> ''
        "
    );

    /*
    |--------------------------------------------------------------------------
    | Test Statistics
    |--------------------------------------------------------------------------
    */

    $totalTests = getCount($conn, "tests");

    $activeTests = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM academic_years
        WHERE status='active'
        "
    );

    $draftTests = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM academic_years
        WHERE status='draft'
        "
    );

    $completedTests = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM academic_years
        WHERE status='completed'
        "
    );

    /*
    |--------------------------------------------------------------------------
    | Student / Teacher
    |--------------------------------------------------------------------------
    */

    $totalStudents = getCount($conn, "students");

    $totalTeachers = getCount($conn, "teachers");

    /*
    |--------------------------------------------------------------------------
    | Results
    |--------------------------------------------------------------------------
    */

    $totalResults = getCount($conn, "results");

    $pendingResults = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM results
        WHERE status='pending'
        "
    );

    /*
    |--------------------------------------------------------------------------
    | Backup Statistics
    |--------------------------------------------------------------------------
    */

    $totalBackups = getCount($conn, "backups");

    $backupStorage = (int)getScalar(
        $conn,
        "
        SELECT IFNULL(SUM(file_size),0)
        FROM backups
        "
    );

    $latestBackup = $conn->query(
        "
        SELECT *
        FROM backups
        ORDER BY created_at DESC
        LIMIT 1
        "
    )->fetch_assoc();

    /*
    |--------------------------------------------------------------------------
    | Audit Statistics
    |--------------------------------------------------------------------------
    */

    $totalAuditLogs = getCount($conn, "audit_logs");

    $todayAuditLogs = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM audit_logs
        WHERE DATE(created_at)=CURDATE()
        "
    );

    $failedLogins = (int)getScalar(
        $conn,
        "
        SELECT COUNT(*)
        FROM audit_logs
        WHERE action='Failed Login'
        "
    );

    /*
    |--------------------------------------------------------------------------
    | License
    |--------------------------------------------------------------------------
    */

    $license = $conn->query(
        "
        SELECT *
        FROM licenses
        ORDER BY id DESC
        LIMIT 1
        "
    )->fetch_assoc();

    $licenseStatus = "Not Activated";
    $daysRemaining = 0;

    if ($license) {
        $licenseStatus = $license['status'];
        if (!empty($license['expires_at'])) {
            $today = new DateTime();
            $expiry = new DateTime($license['expires_at']);
            $daysRemaining = max(
                0,
                $today->diff($expiry)->days
            );
    }}

    /*
    |--------------------------------------------------------------------------
    | Recent Tests
    |--------------------------------------------------------------------------
    */

    $recentTests = $conn->query(
    "
    SELECT *
    FROM tests
    ORDER BY created_at DESC
    LIMIT 5
    ");

    /*
    |--------------------------------------------------------------------------
    | Recent Backups
    |--------------------------------------------------------------------------
    */

    $recentBackups = $conn->query(
    "
    SELECT *
    FROM backups
    ORDER BY created_at DESC
    LIMIT 5
    ");
    /*
    |--------------------------------------------------------------------------
    | Recent Audit Logs
    |--------------------------------------------------------------------------
    */

    $recentAuditLogs = $conn->query(
    "
    SELECT *
    FROM audit_logs
    ORDER BY created_at DESC
    LIMIT 8
    ");

    /*
    |--------------------------------------------------------------------------
    | Database Size
    |--------------------------------------------------------------------------
    */

    $dbSize = getScalar(
    $conn,
    "
    SELECT
    ROUND(SUM(data_length+index_length)/1024/1024,2)
    FROM information_schema.tables
    WHERE table_schema=DATABASE()
    "
    );

    /*
    |--------------------------------------------------------------------------
    | System Alerts
    |--------------------------------------------------------------------------
    */

    $alerts = [];

    if ($daysRemaining <= 30) {
        $alerts[] = [
            "type"=>"warning",
            "message"=>"License expires in {$daysRemaining} day(s)."
        ];
    }

    if ($latestBackup) {
        $lastBackupDate = new DateTime($latestBackup['created_at']);
        $today = new DateTime();
        $days = $today->diff($lastBackupDate)->days;

        if ($days >= 3) {
            $alerts[] = [
                "type"=>"danger",
                "message"=>"No database backup has been created in {$days} day(s)."
            ];
        }
    }

    if ($failedLogins > 0) {
        $alerts[] = [
            "type"=>"warning",
            "message"=>"{$failedLogins} failed login attempt(s) detected."
        ];
    }

    if ($dbSize > 800) {
        $alerts[] = [
            "type"=>"info",
            "message"=>"Database size is {$dbSize} MB."
        ];
    }

    // result distribution
    $resultDistribution=[];

    $sql="
    SELECT
    score,
    COUNT(*) total
    FROM results
    GROUP BY score
    ORDER BY score
    ";
    $result=$conn->query($sql);
    while($row=$result->fetch_assoc()){
        $resultDistribution[]=$row;
    }

     // audit activity
    $auditActivity=[];

    $sql="
    SELECT
    module,
    COUNT(*) total
    FROM audit_logs
    GROUP BY module
    ORDER BY total DESC
    LIMIT 10
    ";
    $result=$conn->query($sql);
    while($row=$result->fetch_assoc()){
        $auditActivity[]=$row;
    }

    //    test created by month
    $monthlyTests = [];

    $sql = "
    SELECT
        DATE_FORMAT(created_at,'%b') AS month,
        COUNT(*) total
    FROM tests
    WHERE YEAR(created_at)=YEAR(CURDATE())
    GROUP BY MONTH(created_at)
    ORDER BY MONTH(created_at)
    ";

    $result = $conn->query($sql);

    while($row=$result->fetch_assoc()){
        $monthlyTests[]=$row;
    }
   
    // question per subject
    $questionSubjectData = [];

    $sql = "
    SELECT
        s.subject_name,
        COUNT(q.id) AS total
    FROM subjects s
    LEFT JOIN new_questions q
        ON s.subject_name = q.subject
    GROUP BY s.id
    ORDER BY s.subject_name
    ";

    $result = $conn->query($sql);

    while ($row = $result->fetch_assoc()) {
        $questionSubjectData[] = $row;
    }


} catch (Exception $e) {
    // error_log("Dashboard error: " . $e->getMessage());
    // die("System error");
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "<br><br>";
    echo $e->getTraceAsString();
    echo "</pre>";
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/view_results.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <style>
        .stat-card {
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background-color: white;
            text-align: center;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .count {
            font-size: 2rem;
            font-weight: bold;
        }
        .subject-card {
            padding: 15px;
            border-left: 4px solid #4361ee;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .badge-passed {
            background-color: #28a745;
        }
        .badge-failed {
            background-color: #dc3545;
        }
        .badge-pending {
            background-color: #ffc107;
        }
        .empty-state {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .btn-action {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            text-decoration: none;
            color: #212529;
            transition: background 0.2s;
        }
        .btn-action:hover {
            background: #e9ecef;
        }
        .activity-item {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        #chartEmptyState {
            text-align: center;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        #chartEmptyState i {
            font-size: 2rem;
            color: #6c757d;
        }
        #chartEmptyState p {
            margin-top: 10px;
        }
        .badge-passed {
            background-color: #28a745;
            color: white;
        }   
        .badge-failed {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small><b>Welcome back,</b></small>
                <h6><b><?php echo htmlspecialchars($admin['username']); ?></b></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="bank.php" style="text-decoration: line-through"><i class="fas fa-database"></i>Question Bank</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
            <a href="manage_session.php"><i class="fas fa-user-plus"></i>manage session</a>
            <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
            <a href="manage_students.php"><i class="fas fa-users"></i>Manage Student</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>license</a>
            <a href="audit_logs.php"><i class="fas fa-database"></i>audit log</a>
            <a href="../backup/backup_list.php"><i class="fas fa-database"></i>backup</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Admin Dashboard</h2>
            <div class="d-flex gap-3">
                <a href="../admin/view_results.php" class="btn btn-secondary"><i class="fas fa-chart-bar me-2"></i>View Results</a>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
            
        </div>
        <div class="row g-3 mb-4">
                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <small class="text-muted">Question Bank</small>
                            <h2><?= number_format($totalQuestions) ?></h2>
                            <i class="fas fa-database text-primary fa-2x float-end"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <small class="text-muted">Tests</small>
                            <h2><?= number_format($totalTests) ?></h2>
                            <i class="fas fa-file-alt text-success fa-2x float-end"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <small class="text-muted">Students</small>
                            <h2><?= number_format($totalStudents) ?></h2>
                            <i class="fas fa-user-graduate text-warning fa-2x float-end"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <small class="text-muted">Teachers</small>
                            <h2><?= number_format($totalTeachers) ?></h2>
                            <i class="fas fa-chalkboard-teacher text-danger fa-2x float-end"></i>
                        </div>
                    </div>
                </div>
            </div>
        <div class="row mt-4">

        <!-- System Alerts -->
        <div class="col-lg-6">

            <div class="card shadow-sm h-100">

                <div class="card-header bg-danger text-white">
                    <i class="fas fa-exclamation-triangle"></i>
                    System Alerts
                </div>

                <div class="card-body">

                    <?php if(empty($alerts)): ?>

                        <div class="alert alert-success mb-0">
                            <i class="fas fa-check-circle"></i>
                            No system alerts.
                        </div>

                    <?php else: ?>

                        <?php foreach($alerts as $alert): ?>

                            <div class="alert alert-<?= $alert['type'] ?>">

                                <?= htmlspecialchars($alert['message']) ?>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- Quick Actions -->

        <div class="col-lg-6">

            <div class="card shadow-sm h-100">

                <div class="card-header bg-primary text-white">

                    <i class="fas fa-bolt"></i>

                    Quick Actions

                </div>

                <div class="card-body">

                    <div class="row g-3">

                        <div class="col-6">
                            <a href="../teacher/add_question.php" class="btn btn-success w-100">
                                <i class="fas fa-plus-circle"></i><br>
                                Create Test
                            </a>
                        </div>

                        <div class="col-6">
                            <a href="../teacher/bank.php" class="btn btn-primary w-100">
                                <i class="fas fa-database"></i><br>
                                Question Bank
                            </a>
                        </div>

                        <div class="col-6">
                            <a href="import_questions.php" class="btn btn-info w-100">
                                <i class="fas fa-file-import"></i><br>
                                Import Questions
                            </a>
                        </div>

                        <div class="col-6">
                            <a href="../backup/create_backup.php" class="btn btn-warning w-100">
                                <i class="fas fa-save"></i><br>
                                Create Backup
                            </a>
                        </div>

                        <div class="col-6">
                            <a href="backup/backup_list.php" class="btn btn-secondary w-100">
                                <i class="fas fa-history"></i><br>
                                Backups
                            </a>
                        </div>

                        <div class="col-6">
                            <a href="audit_logs.php" class="btn btn-dark w-100">
                                <i class="fas fa-clipboard-list"></i><br>
                                Audit Logs
                            </a>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>
    <div class="row mt-4">
        <!-- Recent Tests -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-file-alt"></i>
                    Recent Tests
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                        <tr>
                            <th>Test</th>
                            <th>Year</th>
                            <th>Questions</th>
                            <th>Date</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php while($test = $recentTests->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($test['title']) ?></td>
                                <td>
                                    <span class="badge bg-success">
                                        <?= htmlspecialchars($test['year']) ?>
                                    </span>
                                </td>
                                <td><?= $test['question_count'] ?? 0 ?></td>
                                <td><?= date("d M Y", strtotime($test['created_at'])) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- License -->

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <i class="fas fa-key"></i>
                    License
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <th>Status</th>
                            <td><?= htmlspecialchars($licenseStatus) ?></td>
                        </tr>
                        <tr>
                            <th>Expires</th>
                            <td><?= $license['expires_at'] ?? "N/A" ?></td>
                        </tr>
                        <tr>
                            <th>Days Left</th>
                            <td><?= $daysRemaining ?></td>
                        </tr>
                        <tr>
                            <th>Version</th>
                            <td><?= htmlspecialchars($license['version'] ?? 'N/A') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="row mt-4">

        <!-- Latest Backups -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-warning">
                    <i class="fas fa-save"></i>
                    Latest Backups
                </div>

                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Size</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php while($backup = $recentBackups->fetch_assoc()): ?>
                            <tr>
                                <td><?= date("d M", strtotime($backup['created_at'])) ?></td>
                                <td><?= formatBytes($backup['file_size']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Audit Logs -->

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-history"></i>
                    Recent Audit Logs
                </div>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th>Time</th>
                            <th>Admin</th>
                            <th>Module</th>
                            <th>Action</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php while($log = $recentAuditLogs->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?= date(
                                        "d M H:i",
                                        strtotime($log['created_at'])
                                    ) ?>
                                </td>
                                <td><?= htmlspecialchars($log['username']) ?></td>
                                <td><?= htmlspecialchars($log['module']) ?></td>
                                <td><?= htmlspecialchars($log['action']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>

    </div>
    <div class="card shadow-sm mt-4">
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-3">
                    <h6>Database Size</h6>
                    <strong><?= $dbSize ?> MB</strong>
                </div>
                <div class="col-md-3">
                    <h6>PHP Version</h6>
                    <strong><?= PHP_VERSION ?></strong>
                </div>
                <div class="col-md-3">
                    <h6>MySQL Version</h6>
                    <strong><?= $conn->server_info ?></strong>
                </div>
                <div class="col-md-3">
                    <h6>Server Time</h6>
                    <strong><?= date("d M Y H:i:s") ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header">
                    Questions Per Subject
                </div>
                <div class="card-body">
                    <canvas id="subjectChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header">
                    Monthly Tests
                </div>
                <div class="card-body">
                    <canvas id="testChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header">
                    Audit Activity
                </div>
                <div class="card-body">
                    <canvas id="auditChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow">
                <div class="card-header">
                    Result Grades
                </div>
                <div class="card-body">
                    <canvas id="resultChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Scripts -->
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/chart.min.js"></script>
    <script src="../js/jquery.dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });
        });
    </script>
    <script>
    const subjectData = <?= json_encode($questionSubjectData) ?>;
    const monthlyTests = <?= json_encode($monthlyTests) ?>;
    const auditData = <?= json_encode($auditActivity) ?>;
    const resultData = <?= json_encode($resultDistribution) ?>;

    new Chart(document.getElementById('subjectChart'),{
    type:'bar',
    data:{
    labels:subjectData.map(x=>x.subject_name),
    datasets:[{
    label:'Questions',
    data:subjectData.map(x=>x.total)
    }]
    }
    });

    new Chart(document.getElementById('testChart'),{
    type:'line',
    data:{
    labels:monthlyTests.map(x=>x.month),
    datasets:[{
    label:'Tests',
    data:monthlyTests.map(x=>x.total),
    fill:false,
    tension:.3
    }]
    }
    });

    new Chart(document.getElementById('auditChart'),{
    type:'doughnut',
    data:{
    labels:auditData.map(x=>x.module),
    datasets:[{
    data:auditData.map(x=>x.total)
    }]
    }
    });

    new Chart(document.getElementById('resultChart'),{
    type:'pie',
    data:{
    labels:resultData.map(x=>x.score),
    datasets:[{
    data:resultData.map(x=>x.total)
    }]
    }
    });

    setInterval(function(){
        $("#dashboardStats").load(location.href+" #dashboardStats>*","");
    },60000);
    </script>
</body>
</html>
$conn->close();
?>