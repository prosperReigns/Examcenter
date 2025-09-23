<?php
session_start();
require_once '../db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

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

    // Log page access
    $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $activity = "Admin {$admin['username']} accessed view results page.";
    $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}

// Define all possible classes and subjects
$all_classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];
$jss_subjects = [
    'mathematics', 'english', 'ict', 'agriculture', 'history', 
    'civic education', 'basic science', 'basic technology', 
    'business studies', 'agricultural sci', 'physical health edu',
    'cultural and creative art', 'social studies', 'security edu', 
    'yoruba', 'french', 'coding and robotics', 'c.r.s', 'i.r.s', 'chess'
];
$ss_subjects = [
    'mathematics', 'english', 'civic edu', 'data processing', 'economics',
    'government', 'commerce', 'accounting', 'financial accounting', 
    'dyeing and bleaching', 'physics', 'chemistry', 'biology', 
    'agricultural sci', 'geography', 'technical drawing', 'yoruba lang',
    'french lang', 'further maths', 'literature in english', 'c.r.s', 'i.r.s'
];

// Pagination settings
$results_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$current_page = max(1, $current_page);
$offset = ($current_page - 1) * $results_per_page;

// Initialize filter variables and messages
$test_title_filter = trim($_GET['selected_title'] ?? '');
$class_filter = trim($_GET['selected_class'] ?? '');
$subject_filter = trim($_GET['selected_subject'] ?? '');
$error = '';
$success = '';

// Validate filter inputs
if ($class_filter && !in_array($class_filter, $all_classes)) {
    $error = "Invalid class selected.";
    $class_filter = '';
}
if ($subject_filter && !in_array(strtolower($subject_filter), array_merge($jss_subjects, $ss_subjects))) {
    $error = "Invalid subject selected.";
    $subject_filter = '';
}

// Fetch test titles
try {
    $stmt = $conn->prepare("SELECT DISTINCT title FROM tests ORDER BY title ASC");
    $stmt->execute();
    $test_titles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching test titles: " . $e->getMessage());
    $error = "Failed to load test titles.";
}

// Build queries
$count_query = "SELECT COUNT(*) as total 
                FROM results r
                JOIN students s ON r.user_id = s.id
                JOIN tests t ON r.test_id = t.id
                WHERE 1=1";
$select_query = "SELECT r.*, s.name AS student_name, s.class AS student_class, 
                        t.subject, t.title AS test_title, t.class AS test_class 
                 FROM results r
                 JOIN students s ON r.user_id = s.id
                 JOIN tests t ON r.test_id = t.id
                 WHERE 1=1";
$params = [];
$types = '';

if ($test_title_filter) {
    $count_query .= " AND t.title = ?";
    $select_query .= " AND t.title = ?";
    $params[] = $test_title_filter;
    $types .= 's';
}
if ($class_filter) {
    $count_query .= " AND s.class = ?";
    $select_query .= " AND s.class = ?";
    $params[] = $class_filter;
    $types .= 's';
}
if ($subject_filter) {
    $count_query .= " AND LOWER(t.subject) = ?";
    $select_query .= " AND LOWER(t.subject) = ?";
    $params[] = strtolower($subject_filter);
    $types .= 's';
}

// Get total results
try {
    $stmt = $conn->prepare($count_query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_results = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
} catch (Exception $e) {
    error_log("Error counting results: " . $e->getMessage());
    $error = "Failed to load results count.";
}

// Adjust pagination
$total_pages = ceil($total_results / $results_per_page);
$current_page = min($current_page, max(1, $total_pages));
$offset = ($current_page - 1) * $results_per_page;

// Fetch results
try {
    $select_query .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $results_per_page;
    $params[] = $offset;
    $types .= 'ii';
    $stmt = $conn->prepare($select_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching results: " . $e->getMessage());
    $error = "Failed to load results.";
}

// Log filter application
if ($test_title_filter || $class_filter || $subject_filter) {
    $activity = "Admin applied filters: Title=$test_title_filter, Class=$class_filter, Subject=$subject_filter";
    try {
        $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error logging filter application: " . $e->getMessage());
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        .sidebar {
            background-color: #2c3e50;
            color: white;
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 20px;
            transition: transform 0.3s ease;
        }
        .sidebar.active {
            transform: translateX(-250px);
        }
        .sidebar-brand h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
       .admin-info small {
            font-size: 0.8rem;
            opacity: 0.7;
            color: white;
        }
        .admin-info h6{
            color: white;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            color: white;
            padding: 10px;
            margin-bottom: 5px;
            border-radius: 5px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: #34495e;
        }
        .sidebar-menu a i {
            margin-right: 10px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background: #f8f9fa;
        }
        .header {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filter-card {
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background-color: white;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
        }
        .results-table th {
            background-color: #4361ee;
            color: white;
            padding: 12px;
            text-align: left;
        }
        .results-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            color: #212529;
        }
        .results-table tr:hover {
            background-color: #f8f9fa;
        }
        .badge-class, .badge-subject {
            background-color: #e9ecef;
            color: #212529;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .percentage-cell.high {
            color: #28a745;
            font-weight: bold;
        }
        .percentage-cell.medium {
            color: #ffc107;
            font-weight: bold;
        }
        .percentage-cell.low {
            color: #dc3545;
            font-weight: bold;
        }
        .empty-state {
            text-align: center;
            padding: 50px;
            color: #6c757d;
        }
        .pagination .btn {
            min-width: 100px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #4361ee;
            color: white !important;
            border-color: #4361ee;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: #4361ee !important;
        }
        .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_length {
            color: #333 !important;
        }
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-250px);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <b><small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($admin['username']); ?></h6></b>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php" class="active"><i class="fas fa-chart-bar"></i>Exam Results</a>
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
            <h2 class="mb-0">Exam Results</h2>
            <div class="d-flex gap-3">
                <?php if ($total_results > 0): ?>
                    <a href="../admin/export_results_word.php?<?php echo http_build_query([
                        'selected_title' => $test_title_filter,
                        'selected_class' => $class_filter,
                        'selected_subject' => $subject_filter,
                        'admin_id' => $admin_id
                    ]); ?>" class="btn btn-success" id="exportWord">
                        <i class="fas fa-file-word me-2"></i>Export to Word
                    </a>
                <?php endif; ?>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="card bg-white border-0 shadow-sm filter-card mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Results</h5>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold" for="titleFilter">Test Title</label>
                            <select class="form-select" name="selected_title" id="titleFilter">
                                <option value="">All Tests</option>
                                <?php foreach ($test_titles as $title): ?>
                                    <option value="<?php echo htmlspecialchars($title['title']); ?>" <?php echo $test_title_filter == $title['title'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($title['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a valid test title.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold" for="classFilter">Class</label>
                            <select class="form-select" name="selected_class" id="classFilter">
                                <option value="">All Classes</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $class_filter == $class ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a valid class.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold" for="subjectFilter">Subject</label>
                            <select class="form-select" name="selected_subject" id="subjectFilter">
                                <option value="">All Subjects</option>
                            </select>
                            <div class="invalid-feedback">Please select a valid subject.</div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Apply</button>
                        </div>
                    </div>
                    <?php if ($test_title_filter || $class_filter || $subject_filter): ?>
                        <a href="view_results.php" class="btn btn-outline-secondary mt-3"><i class="fas fa-times me-2"></i>Clear Filters</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Results Table -->
        <div class="card bg-white border-0 shadow-sm">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0">Results List (<?php echo $total_results; ?> total)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($results)): ?>
                    <table id="resultsTable" class="table table-striped table-hover results-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Test Title</th>
                                <th>Subject</th>
                                <th>Score</th>
                                <th>Percentage</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): 
                                $percentage = $result['total_questions'] > 0 ? round(($result['score'] / $result['total_questions']) * 100, 2) : 0;
                                $percentage_class = $percentage >= 70 ? 'high' : ($percentage >= 50 ? 'medium' : 'low');
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                    <td><span class="badge badge-class"><?php echo htmlspecialchars($result['student_class']); ?></span></td>
                                    <td><?php echo htmlspecialchars($result['test_title']); ?></td>
                                    <td><span class="badge badge-subject"><?php echo htmlspecialchars($result['subject']); ?></span></td>
                                    <td><?php echo htmlspecialchars($result['score'] . ' / ' . $result['total_questions']); ?></td>
                                    <td class="percentage-cell <?php echo $percentage_class; ?>"><?php echo $percentage; ?>%</td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($result['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination d-flex justify-content-center mt-4">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?php echo $current_page - 1; ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>" class="btn btn-outline-primary me-2">Previous</a>
                            <?php endif; ?>
                            <span class="align-self-center">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?php echo $current_page + 1; ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>" class="btn btn-outline-primary ms-2">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar fa-3x mb-3"></i>
                        <h4>No Results Found</h4>
                        <p>Try adjusting your filters or check back later.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery.dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Define subjects arrays
            const jssSubjects = <?php echo json_encode($jss_subjects); ?>;
            const ssSubjects = <?php echo json_encode($ss_subjects); ?>;
            const allSubjects = [...new Set([...jssSubjects, ...ssSubjects])].sort();

            // Function to populate subjects
            function populateSubjects(selectedClass) {
                const subjectSelect = $('#subjectFilter');
                subjectSelect.find('option:not(:first)').remove();
                let subjects = allSubjects;
                if (selectedClass) {
                    const isJSS = selectedClass.toUpperCase().includes('JSS');
                    subjects = isJSS ? jssSubjects : ssSubjects;
                }
                subjects.forEach(subject => {
                    const displaySubject = subject.charAt(0).toUpperCase() + subject.slice(1);
                    subjectSelect.append(`<option value="${subject}">${displaySubject}</option>`);
                });
                // Restore selected subject
                const currentSubject = "<?php echo addslashes($subject_filter); ?>".toLowerCase();
                if (currentSubject) {
                    if (subjects.includes(currentSubject)) {
                        const displaySubject = currentSubject.charAt(0).toUpperCase() + currentSubject.slice(1);
                        subjectSelect.val(currentSubject);
                    } else {
                        const displaySubject = currentSubject.charAt(0).toUpperCase() + currentSubject.slice(1);
                        subjectSelect.append(`<option value="${currentSubject}">${displaySubject}</option>`);
                        subjectSelect.val(currentSubject);
                    }
                }
            }

            // Class filter change handler
            $('#classFilter').on('change', function() {
                const selectedClass = $(this).val();
                populateSubjects(selectedClass);
            });

            // Initialize subjects if class is selected
            <?php if ($class_filter): ?>
                populateSubjects("<?php echo addslashes($class_filter); ?>");
            <?php else: ?>
                populateSubjects("");
            <?php endif; ?>

            // Form validation
            $('#filterForm').validate({
                rules: {
                    selected_title: {
                        maxlength: 255
                    },
                    selected_class: {
                        regex: /^(JSS[1-3]|SS[1-3])$/ // Allow valid class names or empty
                    },
                    selected_subject: {
                        regex: /^[a-z0-9\s\-\.]+$/
                    }
                },
                messages: {
                    selected_title: {
                        maxlength: "Test title is too long."
                    },
                    selected_class: {
                        regex: "Please select a valid class (e.g., JSS1, SS2)."
                    },
                    selected_subject: {
                        regex: "Please select a valid subject."
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
                    const titleVal = $('#titleFilter').val().trim();
                    const classVal = $('#classFilter').val().trim();
                    const subjectVal = $('#subjectFilter').val().trim();
                    if (!titleVal && !classVal && !subjectVal) {
                        window.location.href = 'view_results.php';
                    } else {
                        form.submit();
                    }
                }
            });

            // Initialize DataTables
            $('#resultsTable').DataTable({
                pageLength: <?php echo $results_per_page; ?>,
                paging: false, // Disable DataTables pagination to use custom pagination
                searching: false,
                lengthChange: false,
                responsive: true,
                columnDefs: [
                    { orderable: false, targets: [4, 5] } // Disable sorting on Score and Percentage
                ],
                language: {
                    emptyTable: '<div class="text-center py-4 empty-state"><i class="fas fa-chart-bar fa-3x mb-3"></i><h4>No Results Found</h4><p>Try adjusting your filters or check back later.</p></div>'
                }
            });

            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                $('.alert').each(function() {
                    new bootstrap.Alert(this).close();
                });
            }, 5000);

            // Log filter application
            <?php if ($test_title_filter || $class_filter || $subject_filter): ?>
                console.log('Filters applied: Title=<?php echo htmlspecialchars($test_title_filter); ?>, Class=<?php echo htmlspecialchars($class_filter); ?>, Subject=<?php echo htmlspecialchars($subject_filter); ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>