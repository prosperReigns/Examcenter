<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../vendor/autoload.php'; // Adjust path if PHPWord is elsewhere

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
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

    // Fetch teacher profile and assigned subjects
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // fetch classes dynamically
    $stmt = $conn->prepare("
    SELECT DISTINCT c.class_name, ts.subject
    FROM classes c
    JOIN academic_levels al ON al.id = c.academic_level_id
    JOIN subject_levels sl ON sl.class_level = al.class_group
    JOIN subjects s ON s.id = sl.subject_id
    JOIN teacher_subjects ts ON ts.subject = s.subject_name
    WHERE ts.teacher_id = ?
    ");

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $class_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch assigned subjects
    $stmt = $conn->prepare("
        SELECT subject 
        FROM teacher_subjects
        WHERE teacher_id = ?
    ");
    if (!$stmt) {
        error_log("Prepare failed for assigned subjects: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();


    if (empty($assigned_subjects)) {
        $assigned_subjects = ['__no_subject__'];
        $error = "No subjects assigned to you. Contact your admin.";
    }

    // The hardcoded subject arrays have been removed.

    // Pagination settings
    $results_per_page = 10;
    $current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
    if ($current_page < 1) $current_page = 1;
    $offset = ($current_page - 1) * $results_per_page;

    // Initialize filter variables
    $class_filter = trim($_GET['selected_class'] ?? '');
    $subject_filter = trim($_GET['selected_subject'] ?? '');
    $test_title_filter = trim($_GET['selected_title'] ?? '');
    $year_filter = trim($_GET['selected_year'] ?? '');
    $student_name_filter = trim($_GET['student_name'] ?? '');

    // Initialize error/success messages
    $error = $success = '';

    // Handle export to Word
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['export_results'])) {
        try {
            $export_query = "SELECT r.*, s.name AS student_name, s.class AS student_class, 
                            t.subject, t.title AS test_title, c.class_name AS test_class, t.year 
                            FROM results r
                            JOIN students s ON r.user_id = s.id
                            JOIN tests t ON r.test_id = t.id
                            JOIN classes c ON t.academic_level_id = c.academic_level_id
                            WHERE t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")";
            $export_params = $assigned_subjects;
            $export_types = str_repeat('s', count($assigned_subjects));

            if (!empty($test_title_filter)) {
                $export_query .= " AND t.title = ?";
                $export_params[] = $test_title_filter;
                $export_types .= 's';
            }
            if (!empty($class_filter)) {
                $export_query .= " AND s.class = ?";
                $export_params[] = $class_filter;
                $export_types .= 's';
            }
            if (!empty($subject_filter)) {
                $export_query .= " AND t.subject = ?";
                $export_params[] = $subject_filter;
                $export_types .= 's';
            }
            if (!empty($year_filter)) {
                $export_query .= " AND t.year = ?";
                $export_params[] = $year_filter;
                $export_types .= 's';
            }
            if (!empty($student_name_filter)) {
                $export_query .= " AND s.name LIKE ?";
                $export_params[] = "%$student_name_filter%";
                $export_types .= 's';
            }

            $stmt = $conn->prepare($export_query);
            if (!$stmt) {
                throw new Exception("Prepare failed for export: " . $conn->error);
            }
            if (!empty($export_params)) {
                $stmt->bind_param($export_types, ...$export_params);
            }
            $stmt->execute();
            $export_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Create Word document
            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            $section->addTitle('Exam Results Report', 1);
            $section->addText('Generated on: ' . date('F j, Y g:i A'));
            $section->addText('Teacher: ' . $teacher['last_name']);
            if ($test_title_filter) $section->addText('Test: ' . $test_title_filter);
            if ($class_filter) $section->addText('Class: ' . $class_filter);
            if ($subject_filter) $section->addText('Subject: ' . $subject_filter);
            if ($year_filter) $section->addText('Year: ' . $year_filter);
            if ($student_name_filter) $section->addText('Student: ' . $student_name_filter);

            $table = $section->addTable(['borderSize' => 1, 'borderColor' => '999999', 'cellMargin' => 80]);
            $table->addRow();
            $table->addCell(2000)->addText('Student', ['bold' => true]);
            $table->addCell(1500)->addText('Class', ['bold' => true]);
            $table->addCell(2000)->addText('Test Title', ['bold' => true]);
            $table->addCell(1500)->addText('Subject', ['bold' => true]);
            $table->addCell(1000)->addText('Score', ['bold' => true]);
            $table->addCell(1000)->addText('Percentage', ['bold' => true]);
            $table->addCell(1500)->addText('Date', ['bold' => true]);
            $table->addCell(1000)->addText('Year', ['bold' => true]);

            foreach ($export_results as $result) {
                $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
                $table->addRow();
                $table->addCell(2000)->addText(htmlspecialchars($result['student_name']));
                $table->addCell(1500)->addText(htmlspecialchars($result['test_class']));
                $table->addCell(2000)->addText(htmlspecialchars($result['test_title']));
                $table->addCell(1500)->addText(htmlspecialchars($result['subject']));
                $table->addCell(1000)->addText($result['score'] . '/' . $result['total_questions']);
                $table->addCell(1000)->addText($percentage . '%');
                $table->addCell(1500)->addText(date('M j, Y g:i A', strtotime($result['created_at'])));
                $table->addCell(1000)->addText(htmlspecialchars($result['year']));
            }

            // Save and download
            $filename = 'Exam_Results_' . date('Ymd_His') . '.docx';
            $temp_file = tempnam(sys_get_temp_dir(), 'phpword');
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($temp_file);

            // Log activity
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            $activity = "Teacher {$teacher['username']} exported results for " . ($test_title_filter ?: 'all tests') . ($class_filter ? " in $class_filter" : '') . ($subject_filter ? " ($subject_filter)" : '') . ($year_filter ? " ($year_filter)" : '') . ($student_name_filter ? " for $student_name_filter" : '');
            $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, teacher_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
            $stmt_log->execute();
            $stmt_log->close();

            // Send file
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($temp_file));
            readfile($temp_file);
            unlink($temp_file);
            exit;
        } catch (Exception $e) {
            error_log("Export error: " . $e->getMessage());
            $error = "Error exporting results: " . $e->getMessage();
        }
    }

    // Build queries for results
    $count_query = "SELECT COUNT(*) as total 
                    FROM results r
                    JOIN students s ON r.user_id = s.id
                    JOIN tests t ON r.test_id = t.id
                    WHERE t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")";
    $select_query = "SELECT r.*, s.name AS student_name, s.class AS student_class, 
                            t.subject, t.title AS test_title, c.class_name AS test_class, t.year 
                    FROM results r
                    JOIN students s ON r.user_id = s.id
                    JOIN tests t ON r.test_id = t.id
                    JOIN classes c ON t.academic_level_id = c.academic_level_id
                    WHERE t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")";

    $params = $assigned_subjects;
    $types = str_repeat('s', count($assigned_subjects));

    // Apply filters
    if (!empty($test_title_filter)) {
        $count_query .= " AND t.title = ?";
        $select_query .= " AND t.title = ?";
        $params[] = $test_title_filter;
        $types .= 's';
    }
    if (!empty($class_filter)) {
        $count_query .= " AND s.class = ?";
        $select_query .= " AND s.class = ?";
        $params[] = $class_filter;
        $types .= 's';
    }
    if (!empty($subject_filter)) {
        $count_query .= " AND t.subject = ?";
        $select_query .= " AND t.subject = ?";
        $params[] = $subject_filter;
        $types .= 's';
    }
    if (!empty($year_filter)) {
        $count_query .= " AND t.year = ?";
        $select_query .= " AND t.year = ?";
        $params[] = $year_filter;
        $types .= 's';
    }
    if (!empty($student_name_filter)) {
        $count_query .= " AND s.name LIKE ?";
        $select_query .= " AND s.name LIKE ?";
        $params[] = "%$student_name_filter%";
        $types .= 's';
    }

    // Get total results
    $stmt = $conn->prepare($count_query);
    if (!$stmt) {
        error_log("Prepare failed for count query: " . $conn->error);
        die("Database error");
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_results = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $total_pages = ceil($total_results / $results_per_page);
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $results_per_page;
    }

    // Fetch results for current page
    $select_query .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $results_per_page;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($select_query);
    if (!$stmt) {
        error_log("Prepare failed for select query: " . $conn->error);
        die("Database error");
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get unique classes, years, and test titles for filters (restricted to assigned subjects)
    $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
    $stmt = $conn->prepare("
        SELECT DISTINCT c.class_name 
        FROM classes c 
        JOIN tests t ON c.academic_level_id = t.academic_level_id
        JOIN results r ON t.id = r.test_id
        WHERE t.subject IN ($placeholders) 
        ORDER BY c.class_name
    ");
    $stmt->bind_param(str_repeat('s', count($assigned_subjects)), ...$assigned_subjects);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT DISTINCT t.year FROM tests t JOIN results r ON t.id = r.test_id JOIN classes c ON t.academic_level_id = c.academic_level_id WHERE t.subject IN ($placeholders) ORDER BY t.year DESC");
    $stmt->bind_param(str_repeat('s', count($assigned_subjects)), ...$assigned_subjects);
    $stmt->execute();
    $years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT DISTINCT t.title FROM tests t JOIN results r ON t.id = r.test_id WHERE t.subject IN ($placeholders) ORDER BY t.title");
    $stmt->bind_param(str_repeat('s', count($assigned_subjects)), ...$assigned_subjects);
    $stmt->execute();
    $test_titles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} catch (Exception $e) {
    error_log("View results error: " . $e->getMessage());
    echo "<pre>System error: " . $e->getMessage() . "</pre>"; // dev only
    // die();
    die("System error");
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
        <link rel="stylesheet" href="../css/admin-dashboard.css">
        <link rel="stylesheet" href="../css/view_results.css">
        <style>
            .filter-card { background: #f8f9fa; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .results-table { background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .form-group-spacing { margin-bottom: 1.5rem; }
            .percentage-cell.high { color: #28a745; }
            .percentage-cell.medium { color: #ffc107; }
            .percentage-cell.low { color: #dc3545; }
            .empty-state { text-align: center; padding: 3rem; color: #6c757d; }
            .pagination .page-link { color: #4361ee; }
            .pagination .page-item.active .page-link { background-color: #4361ee; border-color: #4361ee; color: white; }
        </style>
    </head>
    <body>
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-brand">
                <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
                <div class="admin-info">
                    <small>Welcome back,</small>
                    <h6><?php echo htmlspecialchars($teacher['last_name']); ?></h6>
                </div>
            </div>
            <div class="sidebar-menu mt-4">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
                <a href="add_question.php"><i class="fas fa-plus-circle"></i>Add Questions</a>
                <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
                <a href="manage_test.php"><i class="fas fa-list"></i>Manage Test</a>
                <a href="view_results.php" class="active"><i class="fas fa-chart-bar"></i>Exam Results</a>
                <a href="manage_students.php" style="text-decoration: line-through"><i class="fas fa-users"></i>Manage Students</a>
                <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
                <a href="my-profile.php"><i class="fas fa-user"></i>My Profile</a>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">View Results</h2>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="container-fluid">
                <!-- Filter Card -->
                <div class="filter-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Results</h5>
                    <form method="GET" action="">
                        <div class="row g-3">
                            <div class="col-md-3 form-group-spacing">
                                <label class="form-label fw-bold">Test Title</label>
                                <select class="form-select" name="selected_title">
                                    <option value="">All Tests</option>
                                    <?php foreach ($test_titles as $title): ?>
                                        <option value="<?php echo htmlspecialchars($title['title']); ?>" <?php echo $test_title_filter == $title['title'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($title['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group-spacing">
                                <label class="form-label fw-bold">Class</label>
                                <select class="form-select" name="selected_class" id="selectedClass">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class['class_name']); ?>" 
                                            <?php echo $class_filter == $class['class_name'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group-spacing">
                                <label class="form-label fw-bold">Subject</label>
                                <select class="form-select" name="selected_subject" id="selectedSubject">
                                    <option value="">All Subjects</option>
                                    <?php
                                    foreach ($assigned_subjects as $subject): ?>
                                        <option value="<?php echo htmlspecialchars($subject); ?>" <?php echo $subject_filter == $subject ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group-spacing">
                                <label class="form-label fw-bold">Year</label>
                                <select class="form-select" name="selected_year">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year['year']); ?>" <?php echo $year_filter == $year['year'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['year']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group-spacing">
                                <label class="form-label fw-bold">Student Name</label>
                                <input type="text" class="form-control" name="student_name" value="<?php echo htmlspecialchars($student_name_filter); ?>" placeholder="Enter student name">
                            </div>
                            <div class="col-md-3 d-flex align-items-end form-group-spacing">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Apply</button>
                            </div>
                        </div>
                    </form>
                    <?php if ($total_results > 0): ?>
                        <form method="POST" action="" class="mt-3">
                            <input type="hidden" name="selected_title" value="<?php echo htmlspecialchars($test_title_filter); ?>">
                            <input type="hidden" name="selected_class" value="<?php echo htmlspecialchars($class_filter); ?>">
                            <input type="hidden" name="selected_subject" value="<?php echo htmlspecialchars($subject_filter); ?>">
                            <input type="hidden" name="selected_year" value="<?php echo htmlspecialchars($year_filter); ?>">
                            <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_name_filter); ?>">
                            <input type="hidden" name="export_results" value="1">
                            <button type="submit" class="btn btn-success"><i class="fas fa-file-word me-2"></i>Export to Word</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5>
                        <?php if ($total_results > 0): ?>
                            Showing <?php echo count($results); ?> of <?php echo $total_results; ?> result<?php echo $total_results !== 1 ? 's' : ''; ?>
                            (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
                            <?php if (!empty($test_title_filter)): ?>
                                for "<?php echo htmlspecialchars($test_title_filter); ?>"
                            <?php endif; ?>
                            <?php if (!empty($class_filter)): ?>
                                in <?php echo htmlspecialchars($class_filter); ?>
                            <?php endif; ?>
                            <?php if (!empty($subject_filter)): ?>
                                - <?php echo htmlspecialchars($subject_filter); ?>
                            <?php endif; ?>
                            <?php if (!empty($year_filter)): ?>
                                - Year <?php echo htmlspecialchars($year_filter); ?>
                            <?php endif; ?>
                            <?php if (!empty($student_name_filter)): ?>
                                - Student <?php echo htmlspecialchars($student_name_filter); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            No results found
                        <?php endif; ?>
                    </h5>
                    <?php if (!empty($test_title_filter) || !empty($class_filter) || !empty($subject_filter) || !empty($year_filter) || !empty($student_name_filter)): ?>
                        <a href="view_results.php" class="btn btn-outline-secondary"><i class="fas fa-times me-2"></i>Clear Filters</a>
                    <?php endif; ?>
                </div>

                <!-- Results Table -->
                <?php if (!empty($results)): ?>
                    <div class="results-table table-responsive">
                        <table class="table table-hover">
                            <thead class="bg-primary text-white">
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Test Title</th>
                                    <th>Subject</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Date</th>
                                    <th>Year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): 
                                    $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
                                    $percentage_class = $percentage >= 70 ? 'high' : ($percentage >= 50 ? 'medium' : 'low');
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                        <td><span class="badge bg-primary text-white"><?php echo htmlspecialchars($result['test_class']); ?></span></td>
                                        <td><?php echo htmlspecialchars($result['test_title']); ?></td>
                                        <td><span class="badge bg-secondary text-white"><?php echo htmlspecialchars($result['subject']); ?></span></td>
                                        <td><?php echo $result['score']; ?> / <?php echo $result['total_questions']; ?></td>
                                        <td class="percentage-cell <?php echo $percentage_class; ?>">
                                            <?php echo $percentage; ?>%
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($result['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($result['year']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_year=<?php echo urlencode($year_filter); ?>&student_name=<?php echo urlencode($student_name_filter); ?>" aria-label="Previous">
                                        <span aria-hidden="true">« Previous</span>
                                    </a>
                                </li>
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                if ($start_page > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?page=1&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_year=<?php echo urlencode($year_filter); ?>&student_name=<?php echo urlencode($student_name_filter); ?>">1</a></li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_year=<?php echo urlencode($year_filter); ?>&student_name=<?php echo urlencode($student_name_filter); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_year=<?php echo urlencode($year_filter); ?>&student_name=<?php echo urlencode($student_name_filter); ?>"><?php echo $total_pages; ?></a></li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&selected_class=<?php echo urlencode($class_filter); ?>&selected_subject=<?php echo urlencode($subject_filter); ?>&selected_title=<?php echo urlencode($test_title_filter); ?>&selected_year=<?php echo urlencode($year_filter); ?>&student_name=<?php echo urlencode($student_name_filter); ?>" aria-label="Next">
                                        <span aria-hidden="true">Next »</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
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

        <!-- Scripts -->
        <script src="../js/jquery-3.7.0.min.js"></script>
        <script src="../js/bootstrap.bundle.min.js"></script>
        <script>
            $(document).ready(function() {
                // Sidebar toggle
                $('#sidebarToggle').click(function() {
                    $('.sidebar').toggleClass('active');
                });

                // Class-subject mapping
                const classSubjectMapping = <?php echo json_encode($class_subjects); ?>;

                const assignedSubjects = <?php echo json_encode($assigned_subjects); ?>;

                // Update subjects when class changes
                const classSelect = document.getElementById('selectedClass');
                const subjectSelect = document.getElementById('selectedSubject');
                if (classSelect && subjectSelect) {
                    classSelect.addEventListener('change', function() {
                        const selectedClass = this.value;
                        subjectSelect.innerHTML = '<option value="">All Subjects</option>';
                        if (selectedClass && classSubjectMapping[selectedClass]) {
                            classSubjectMapping[selectedClass].filter(subject => assignedSubjects.includes(subject)).forEach(subject => {
                                const option = document.createElement('option');
                                option.value = subject;
                                option.textContent = subject;
                                subjectSelect.appendChild(option);
                            });
                        }
                    });

                    // Trigger change event to populate subjects on page load if class is selected
                    if (classSelect.value) {
                        classSelect.dispatchEvent(new Event('change'));
                        subjectSelect.value = '<?php echo addslashes($subject_filter); ?>';
                    }
                }
            });
        </script>
    </body>
    </html>