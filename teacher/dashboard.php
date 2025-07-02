<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("Redirecting to login: No user_id in session");
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

    // Verify user is a teacher and get their subjects
    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher role check: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'teacher') {
        error_log("Unauthorized access attempt by user_id=$user_id, role=" . ($user['role'] ?? 'none'));
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch teacher's assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    if (empty($assigned_subjects)) {
        error_log("No subjects assigned to teacher_id=$user_id");
        die("No subjects assigned to this teacher");
    }
    // Prepare IN clause for subjects
    $subjects_in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $assigned_subjects)) . "'";

} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    die("System error");
}

$teacher_username = $_SESSION['user_username'];
$stmt = $conn->prepare("SELECT last_name FROM teachers WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed for teacher last_name: " . $conn->error);
    $teacher_last_name = 'Teacher'; // Fallback
} else {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacher = $result->fetch_assoc();
    $teacher_last_name = $teacher['last_name'] ?? 'Teacher'; // Fallback if null
    $stmt->close();
}

// Initialize stats array with default values
$stats = [
    'total_questions' => 0,
    'active_students' => 0,
    'completed_exams' => 0,
    'question_distribution' => [],
    'performance_data' => []
];

// Log activity function (modified for teachers, assuming admin_id is nullable)
function log_activity($conn, $activity, $teacher_id, $ip_address, $user_agent) {
    $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, NULL, ?, ?, NOW())");
    $stmt->bind_param("sss", $activity, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
}

// Log login activity
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($conn, "Teacher $teacher_username logged in", $user_id, $ip_address, $user_agent);

// Time ago function (unchanged)
function time_ago($datetime) {
    $now = new DateTime;
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

// Get total questions count (filtered by teacher's subjects)
$query = "SELECT COUNT(*) as count FROM new_questions WHERE subject IN ($subjects_in)";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $stats['total_questions'] = $row['count'];
    $result->free();
}

// Get active students count (students who took exams in teacher's subjects)
$query = "SELECT COUNT(DISTINCT r.user_id) as count 
          FROM results r 
          JOIN tests t ON r.test_id = t.id 
          WHERE t.subject IN ($subjects_in)";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $stats['active_students'] = $row['count'];
    $result->free();
}

// Get completed exams count (filtered by teacher's subjects)
$query = "SELECT COUNT(*) as count 
          FROM results r 
          JOIN tests t ON r.test_id = t.id 
          WHERE t.subject IN ($subjects_in)";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $stats['completed_exams'] = $row['count'];
    $result->free();
}

// Get question distribution by subject (filtered by teacher's subjects)
$query = "SELECT subject, COUNT(*) as count 
          FROM new_questions 
          WHERE subject IN ($subjects_in) 
          GROUP BY subject 
          ORDER BY count DESC 
          LIMIT 3";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $stats['question_distribution'][$row['subject']] = $row['count'];
    }
    $result->free();
}

// Get performance data for chart (filtered by teacher's subjects)
$query = "SELECT t.subject, AVG(r.score) as average_score 
          FROM results r 
          JOIN tests t ON r.test_id = t.id 
          WHERE t.subject IN ($subjects_in) 
          GROUP BY t.subject";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $stats['performance_data'][$row['subject']] = round($row['average_score'], 1);
    }
    $result->free();
}

// Get recent exam results (filtered by teacher's subjects)
$recent_results = [];
$query = "SELECT 
            r.user_id, 
            s.name, 
            r.created_at, 
            r.score, 
            s.class,
            r.status
          FROM results r
          JOIN students s ON r.user_id = s.id
          JOIN tests t ON r.test_id = t.id
          WHERE t.subject IN ($subjects_in)
          ORDER BY r.created_at DESC 
          LIMIT 10";
$result = $conn->query($query);
if (!$result) {
    echo "Query failed: " . $conn->error;
} else {
    while ($row = $result->fetch_assoc()) {
        $recent_results[] = $row;
    }
    $result->free();
}

// Get pending exams count for notifications (filtered by teacher's subjects)
$pending_exams = 0;
$query = "SELECT COUNT(*) as count 
          FROM results r 
          JOIN tests t ON r.test_id = t.id 
          WHERE r.status = 'pending' 
          AND t.subject IN ($subjects_in)";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $pending_exams = $row['count'];
    $result->free();
}

// Get recent activities (filtered by teacher-related actions if needed)
$recent_activities = [];
$query = "SELECT activity, created_at, ip_address 
          FROM activities_log 
          WHERE admin_id IS NULL 
          ORDER BY created_at DESC 
          LIMIT 5";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
    $result->free();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($teacher_last_name); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php" class="active">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="add_question.php">
                <i class="fas fa-plus-circle"></i>
                Add Questions
            </a>
            <a href="view_questions.php">
                <i class="fas fa-list"></i>
                View Questions
            </a>
            <a href="view_results.php">
                <i class="fas fa-chart-bar"></i>
                Exam Results
            </a>
            <a href="manage_students.php" style="text-decoration: line-through">
                <i class="fas fa-users"></i>
                Manage Students
            </a>
            <a href="settings.php">
                <i class="fas fa-cog"></i>
                Settings
            </a>
            <a href="my-profile.php">
                <i class="fas fa-user"></i>
                My profile
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Teacher Dashboard</h2>
            <div class="header-actions">
                <button class="btn btn-primary d-lg-none" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="notification-dropdown">
                    <button class="notification-icon" id="notificationDropdown">
                        <i class="fas fa-bell"></i>
                        <?php if ($pending_exams > 0): ?>
                            <span class="badge bg-danger pulse"><?php echo $pending_exams; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-menu">
                        <div class="notification-header">
                            <h6>Recent Activities</h6>
                            <?php if ($pending_exams > 0): ?>
                                <span class="badge bg-danger"><?php echo $pending_exams; ?> Pending</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($recent_activities)): ?>
                            <div class="notification-list">
                                <?php foreach ($recent_activities as $activity): ?>
                                <div class="notification-item">
                                    <div class="activity-icon bg-primary">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="activity-content">
                                        <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                        <small><?php echo time_ago($activity['created_at']); ?> • <?php echo htmlspecialchars($activity['ip_address']); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bell-slash"></i>
                                <p>No recent activities</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card bg-white">
                    <i class="fas fa-question-circle text-primary"></i>
                    <div class="count"><?php echo $stats['total_questions']; ?></div>
                    <div class="text-muted">Total Questions</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-white">
                    <i class="fas fa-user-graduate text-success"></i>
                    <div class="count"><?php echo $stats['active_students']; ?></div>
                    <div class="text-muted">Active Students</div> <!-- Fixed label -->
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-white">
                    <i class="fas fa-check-circle text-info"></i>
                    <div class="count"><?php echo $stats['completed_exams']; ?></div>
                    <div class="text-muted">Exams Completed</div>
                </div>
            </div>
        </div>
        
        <!-- Question Distribution and Performance Chart -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Question Distribution</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats['question_distribution'])): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p class="text-muted">No questions found for your subjects</p>
                            </div>
                        <?php else: ?>
                            <div class="stats-grid">
                                <?php foreach ($stats['question_distribution'] as $subject => $count): ?>
                                <div class="stat-card subject-card" data-subject="<?php echo strtolower($subject); ?>">
                                    <h3><?php echo htmlspecialchars($subject); ?></h3>
                                    <p><?php echo $count; ?> Questions</p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Performance Overview</h5>
                        <select id="classSelector" class="form-select form-select-sm w-auto">
                            <option value="all">All Classes</option>
                            <option value="JS1">JS1</option>
                            <option value="JS2">JS2</option>
                            <option value="JS3">JS3</option>
                            <option value="SS1">SS1</option>
                            <option value="SS2">SS2</option>
                            <option value="SS3">SS3</option>
                        </select>
                    </div>
                    <div class="card-body position-relative">
                        <div class="chart-container">
                            <div class="loading-spinner"></div>
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Exams Table -->
        <div class="card data-table mb-4">
            <div class="card-header bg-white">
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
                            <td><?php echo $result['score']; ?>%</td>
                            <td><?php echo htmlspecialchars($result['class']); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $result['status'] === 'passed' ? 'badge-passed' : 
                                        ($result['status'] === 'failed' ? 'badge-failed' : 'badge-pending');
                                ?>">
                                    <?php echo htmlspecialchars(ucfirst($result['status'])); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                        <div class="d-grid gap-2">
                            <a href="add_question.php" class="btn btn-action">
                                <div class="action-content">
                                    <i class="fas fa-plus-circle me-2"></i>
                                    <div>
                                        <h6>Add Questions</h6>
                                        <small>Current count: <?php echo $stats['total_questions']; ?></small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <a href="manage_students.php" class="btn btn-action disabled">
                                <div class="action-content">
                                    <i class="fas fa-user-plus me-2"></i>
                                    <div>
                                        <h6>Manage Students</h6>
                                        <small>Active: <?php echo $stats['active_students']; ?></small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <a href="view_results.php" class="btn btn-action">
                                <div class="action-content">
                                    <i class="fas fa-file-export me-2"></i>
                                    <div>
                                        <h6>Export Results</h6>
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
                <div class="p-3 card bg-white border-0 shadow-sm">
                    <div class="d-flex align-items-center ms-2 ps-2">
                        <div class="me-2"><i class="fas fa-info-circle"></i></div>    
                        <h4 class="mb-0"><b>Recent Activities</b></h4>
                    </div>
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item d-flex align-items-center ms-2 ps-2">
                            <div class="activity-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="activity-content">
                                <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                <small><?php echo time_ago($activity['created_at']); ?> • <?php echo htmlspecialchars($activity['ip_address']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <p>No recent activities</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal and Scripts -->
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/jquery.dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/chart.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#resultsTable').DataTable({
                responsive: true,
                order: [[2, 'desc']],
                language: {
                    emptyTable: '<div class="text-center py-4 empty-state">' +
                                '<i class="fas fa-inbox"></i>' +
                                '<p class="text-muted">No exam results found</p>' +
                                '</div>'
                },
                createdRow: function(row, data, index) {
                    $(row).children().each(function(index) {
                        $(this).attr('data-dt-column', index);
                    });
                }
            });

            // Toggle sidebar on mobile
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Chart configuration
            const chartConfig = {
                type: 'bar',
                data: {
                    labels: ['Loading...'],
                    datasets: [{
                        label: 'Average Score',
                        data: [0],
                        backgroundColor: 'rgba(67, 97, 238, 0.7)',
                        borderColor: 'rgba(67, 97, 238, 1)',
                        borderWidth: 1
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
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    }
                }
            };

            const ctx = document.getElementById('performanceChart').getContext('2d');
            const performanceChart = new Chart(ctx, chartConfig);

            // Function to fetch chart data with class parameter
            function fetchChartData(selectedClass = 'all') {
                const spinner = document.querySelector('.chart-container .loading-spinner');
                spinner.style.display = 'block';
                
                fetch(`chart-data.php?class=${selectedClass}`)
                    .then(response => response.json())
                    .then(data => {
                        spinner.style.display = 'none';
                        document.querySelector('.chart-container').classList.add('chart-loaded');
                        
                        if (data.labels.length === 0) {
                            performanceChart.data.labels = ['No data available'];
                            performanceChart.data.datasets[0].data = [0];
                        } else {
                            performanceChart.data.labels = data.labels;
                            performanceChart.data.datasets[0].data = data.data;
                        }
                        
                        performanceChart.update();
                    })
                    .catch(error => {
                        spinner.style.display = 'none';
                        console.error('Error fetching chart data:', error);
                        performanceChart.data.labels = ['Error loading data'];
                        performanceChart.data.datasets[0].data = [0];
                        performanceChart.update();
                    });
            }

            // Event listener for class selector
            document.getElementById('classSelector').addEventListener('change', function() {
                fetchChartData(this.value);
            });

            // Initial load
            fetchChartData();

            // Animated number counters
            document.querySelectorAll('.count').forEach(el => {
                const target = +el.innerText;
                let current = 0;
                const increment = target / 100;
                
                const updateCount = () => {
                    if (current < target) {
                        current += increment;
                        el.innerText = Math.ceil(current);
                        requestAnimationFrame(updateCount);
                    } else {
                        el.innerText = target;
                    }
                }
                
                requestAnimationFrame(updateCount);
            });
        });
    </script>
</body>
</html>