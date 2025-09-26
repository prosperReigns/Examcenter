<?php
session_start();
require_once '../db.php';

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

    // Initialize stats array
    $stats = [
        'total_questions' => 0,
        'total_students' => 0,
        'completed_exams' => 0,
        'total_teachers' => 0,
        'question_distribution' => [],
        'performance_data' => []
    ];

    // Get total questions count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM new_questions");
    $stmt->execute();
    $stats['total_questions'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    // Get total students count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM students");
    $stmt->execute();
    $stats['total_students'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    // Get total teachers count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM teachers");
    $stmt->execute();
    $stats['total_teachers'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    // Get completed exams count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM results");
    $stmt->execute();
    $stats['completed_exams'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    // Get question distribution by subject (top 3)
    $stmt = $conn->prepare("SELECT subject, COUNT(*) as count FROM new_questions GROUP BY subject ORDER BY count DESC LIMIT 3");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $stats['question_distribution'][strtolower($row['subject'])] = $row['count'];
    }
    $stmt->close();

    // Get performance data for chart
    $selected_class = isset($_GET['class']) && in_array($_GET['class'], ['SS1', 'SS2', 'SS3', 'JSS1', 'JSS2', 'JSS3']) ? $_GET['class'] : 'all';
    $performance_query = "SELECT t.subject, AVG(r.score / r.total_questions * 100) as average_score 
                         FROM results r 
                         JOIN tests t ON r.test_id = t.id 
                         JOIN students s ON r.user_id = s.id 
                         WHERE 1=1";
    $params = [];
    $types = '';
    if ($selected_class !== 'all') {
        $performance_query .= " AND s.class = ?";
        $params[] = $selected_class;
        $types .= 's';
    }
    $performance_query .= " GROUP BY t.subject";
    $stmt = $conn->prepare($performance_query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chart_labels = [];
    $chart_data = [];
    while ($row = $result->fetch_assoc()) {
        $chart_labels[] = $row['subject'];
        $chart_data[] = round($row['average_score'], 1);
    }
    $stmt->close();

    // Get recent exam results
    $recent_results = [];
    $stmt = $conn->prepare("SELECT r.user_id, s.name, r.created_at, (r.score / r.total_questions * 100) as score, 
                                   s.class, r.status 
                            FROM results r 
                            JOIN students s ON r.user_id = s.id 
                            ORDER BY r.created_at DESC LIMIT 10");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_results[] = $row;
    }
    $stmt->close();

    // Get pending exams count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM results WHERE status = 'Pending'");
    $stmt->execute();
    $pending_exams = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    // Get recent activities
    $recent_activities = [];
    $stmt = $conn->prepare("SELECT activity, created_at, ip_address FROM activities_log 
                            ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    die("System error");
}
$conn->close();

function time_ago($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
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
            <a href="add_question.php"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Admin Dashboard</h2>
            <div class="d-flex gap-3">
                <a href="view_results.php" class="btn btn-secondary"><i class="fas fa-chart-bar me-2"></i>View Results</a>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-question-circle text-primary"></i>
                    <div class="count"><?php echo $stats['total_questions']; ?></div>
                    <div class="text-muted">Total Questions</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-user-graduate text-success"></i>
                    <div class="count"><?php echo $stats['total_students']; ?></div>
                    <div class="text-muted">Total Students</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-chalkboard-teacher text-info"></i>
                    <div class="count"><?php echo $stats['total_teachers']; ?></div>
                    <div class="text-muted">Total Teachers</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-check-circle text-warning"></i>
                    <div class="count"><?php echo $stats['completed_exams']; ?></div>
                    <div class="text-muted">Exams Completed</div>
                </div>
            </div>
        </div>

        <!-- Charts and Distribution -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Question Distribution</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats['question_distribution'])): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p class="text-muted">No questions found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($stats['question_distribution'] as $subject => $count): ?>
                                <div class="subject-card" data-subject="<?php echo htmlspecialchars($subject); ?>">
                                    <h6 class="mb-1"><?php echo htmlspecialchars(ucfirst($subject)); ?></h6>
                                    <p class="text-muted mb-0"><?php echo $count; ?> Questions</p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Performance Overview</h5>
                        <form id="classFilterForm">
                            <select id="classSelector" name="class" class="form-select form-select-sm w-auto">
                                <option value="all" <?php echo $selected_class === 'all' ? 'selected' : ''; ?>>All Classes</option>
                                <option value="JSS1" <?php echo $selected_class === 'JSS1' ? 'selected' : ''; ?>>JSS1</option>
                                <option value="JSS2" <?php echo $selected_class === 'JSS2' ? 'selected' : ''; ?>>JSS2</option>
                                <option value="JSS3" <?php echo $selected_class === 'JSS3' ? 'selected' : ''; ?>>JSS3</option>
                                <option value="SS1" <?php echo $selected_class === 'SS1' ? 'selected' : ''; ?>>SS1</option>
                                <option value="SS2" <?php echo $selected_class === 'SS2' ? 'selected' : ''; ?>>SS2</option>
                                <option value="SS3" <?php echo $selected_class === 'SS3' ? 'selected' : ''; ?>>SS3</option>
                            </select>
                        </form>
                    </div>
                    <div class="card-body position-relative">
                        <div class="chart-container">
                            <canvas id="performanceChart"></canvas>
                            <div id="chartEmptyState" class="empty-state" style="display: none;">
                                <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                <p class="text-muted">No performance data available</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Exams Table -->
        <div class="card bg-white border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Exam Results</h5>
            </div>
            <div class="card-body">
                <table id="resultsTable" class="table table-hover" style="width:100%">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Exam Date</th>
                            <th>Score</th>
                            <th>Class</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_results as $result): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($result['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($result['name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($result['created_at'])); ?></td>
                                <td><?php echo round($result['score'], 1); ?>%</td>
                                <td><?php echo htmlspecialchars($result['class']); ?></td>
                       <td>
    <span class="badge <?php 
        echo $result['score'] >= 50 ? 'badge-passed' : 'badge-failed';
    ?>">
        <?php echo $result['score'] >= 50 ? 'Passed' : 'Failed'; ?>
    </span>
</td>


                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions and Activities -->
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="add_question.php" class="btn-action">
                                <div class="action-content">
                                    <i class="fas fa-plus-circle me-2"></i>
                                    <div>
                                        <h6 class="mb-1">Add Questions</h6>
                                        <small>Current count: <?php echo $stats['total_questions']; ?></small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <a href="manage_teachers.php" class="btn-action">
                                <div class="action-content">
                                    <i class="fas fa-user-plus me-2"></i>
                                    <div>
                                        <h6 class="mb-1">Manage Teachers</h6>
                                        <small>Active: <?php echo $stats['total_teachers']; ?></small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <a href="view_results.php" class="btn-action">
                                <div class="action-content">
                                    <i class="fas fa-file-export me-2"></i>
                                    <div>
                                        <h6 class="mb-1">Export Results</h6>
                                        <small>Available: <?php echo $stats['completed_exams']; ?></small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Recent Activities</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="activity-item d-flex align-items-center">
                                    <div class="activity-icon me-3">
                                        <i class="fas fa-info-circle text-primary"></i>
                                    </div>
                                    <div class="activity-content">
                                        <p class="mb-1"><?php echo htmlspecialchars($activity['activity']); ?></p>
                                        <small>
                                            <?php echo time_ago($activity['created_at']); ?> 
                                            • <?php echo htmlspecialchars($activity['ip_address'] ?? 'Unknown IP'); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bell-slash fa-2x mb-2"></i>
                                <p class="text-muted">No recent activities</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
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

            // Form validation
            $('#classFilterForm').validate({
                rules: {
                    class: {
                        required: true,
                        regex: /^(all|JSS[1-3]|SS[1-3])$/ // Allow 'all' or valid class names
                    }
                },
                messages: {
                    class: {
                        required: 'Please select a class',
                        regex: 'Invalid class selection'
                    }
                },
                errorElement: 'div',
                errorClass: 'invalid-feedback',
                highlight: function(element) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element) {
                    $(element).removeClass('is-invalid');
                },
                submitHandler: function(form) {
                    const selectedClass = $('#classSelector').val();
                    window.location.href = '?class=' + encodeURIComponent(selectedClass);
                }
            });

            // Initialize DataTable
            $('#resultsTable').DataTable({
                responsive: true,
                order: [[2, 'desc']],
                language: {
                    emptyTable: '<div class="text-center py-4 empty-state"><i class="fas fa-inbox fa-2x mb-2"></i><p class="text-muted">No exam results found</p></div>'
                }
            });

            // Initialize Chart
            const ctx = document.getElementById('performanceChart').getContext('2d');
            const chartLabels = <?php echo !empty($chart_labels) ? json_encode($chart_labels) : '[]' ?>;
            const chartData = <?php echo !empty($chart_data) ? json_encode($chart_data) : '[]' ?>;

            if (chartLabels.length === 0 || chartData.length === 0) {
                $('#performanceChart').hide();
                $('#chartEmptyState').show();
            } else {
                $('#chartEmptyState').hide();
                const performanceChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Average Score (%)',
                            data: chartData,
                            backgroundColor: '#4361ee',
                            borderColor: '#3f37c9',
                            borderWidth: 1,
                            barThickness: 30,
                            minBarLength: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    callback: function(value) { return value + '%'; },
                                    stepSize: 20
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y.toFixed(1) + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Update chart on class selection
            $('#classSelector').change(function() {
                $('#classFilterForm').submit();
            });

            // Animated counters
            $('.count').each(function() {
                const $this = $(this);
                const target = parseInt($this.text());
                $this.text('0');
                $({ countNum: 0 }).animate({ countNum: target }, {
                    duration: 2000,
                    easing: 'swing',
                    step: function() {
                        $this.text(Math.floor(this.countNum));
                    },
                    complete: function() {
                        $this.text(target);
                    }
                });
            });
        });
    </script>
</body>
</html>