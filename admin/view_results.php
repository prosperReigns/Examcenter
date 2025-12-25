<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php'; // Adjust path if PHPWord is elsewhere

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

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

    $subjects = $conn->query("SELECT subject_name FROM subjects ORDER BY subject_name");
    $available_subjects = [];
    if ($subjects) {
        while ($row = $subjects->fetch_assoc()) {
            $available_subjects[] = $row['subject_name'];
        }
    }
    // The hardcoded subject arrays have been removed.

    // Pagination settings
    $results_per_page = 10;
    $current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
    if ($current_page < 1) $current_page = 1;
    $offset = ($current_page - 1) * $results_per_page;

    // Filters
    $class_filter = trim($_GET['selected_class'] ?? '');
    $subject_filter = trim($_GET['selected_subject'] ?? '');
    $test_title_filter = trim($_GET['selected_title'] ?? '');
    $year_filter = trim($_GET['selected_year'] ?? '');
    $student_name_filter = trim($_GET['student_name'] ?? '');

    // Export to Word
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['export_results'])) {
        $export_query = "SELECT r.*, s.name AS student_name, s.class AS student_class,
                         t.subject, t.title AS test_title, t.class AS test_class, t.year
                         FROM results r
                         JOIN students s ON r.user_id = s.id
                         JOIN tests t ON r.test_id = t.id
                         WHERE 1=1";

        $export_params = [];
        $export_types = '';

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
        if (!$stmt) die("Database error: " . $conn->error);
        if (!empty($export_params)) $stmt->bind_param($export_types, ...$export_params);
        $stmt->execute();
        $export_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Create Word document
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addTitle('Exam Results Report', 1);
        $section->addText('Generated on: ' . date('F j, Y g:i A'));
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
            $table->addCell(1500)->addText(htmlspecialchars($result['student_class']));
            $table->addCell(2000)->addText(htmlspecialchars($result['test_title']));
            $table->addCell(1500)->addText(htmlspecialchars($result['subject']));
            $table->addCell(1000)->addText($result['score'] . '/' . $result['total_questions']);
            $table->addCell(1000)->addText($percentage . '%');
            $table->addCell(1500)->addText(date('M j, Y g:i A', strtotime($result['created_at'])));
            $table->addCell(1000)->addText(htmlspecialchars($result['year']));
        }

        $filename = 'Exam_Results_' . date('Ymd_His') . '.docx';
        $temp_file = tempnam(sys_get_temp_dir(), 'phpword');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($temp_file);

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        readfile($temp_file);
        unlink($temp_file);
        exit;
    }

    // Count total results for pagination
    $count_query = "SELECT COUNT(*) as total 
                    FROM results r
                    JOIN students s ON r.user_id = s.id
                    JOIN tests t ON r.test_id = t.id
                    WHERE 1=1";

    $select_query = "SELECT r.*, s.name AS student_name, s.class AS student_class,
                     t.subject, t.title AS test_title, t.class AS test_class, t.year
                     FROM results r
                     JOIN students s ON r.user_id = s.id
                     JOIN tests t ON r.test_id = t.id
                     WHERE 1=1";

    $params = [];
    $types = '';

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
    error_log("Total results: $total_results"); // Debug log
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
    error_log("Fetched results count: " . count($results)); // Debug log
    $stmt->close();

    // Get unique classes, years, and test titles for filters (no subject restriction for admin)
    $stmt = $conn->prepare("SELECT DISTINCT s.class FROM students s JOIN results r ON s.id = r.user_id JOIN tests t ON r.test_id = t.id ORDER BY s.class");
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT DISTINCT t.year FROM tests t JOIN results r ON t.id = r.test_id ORDER BY t.year DESC");
    $stmt->execute();
    $years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT DISTINCT t.title FROM tests t JOIN results r ON t.id = r.test_id ORDER BY t.title");
    $stmt->execute();
    $test_titles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} catch (Exception $e) {
    error_log("View results error: " . $e->getMessage());
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
                <h6><?php echo htmlspecialchars($admin['username']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php" class="active"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
            <a href="manage_session.php"><i class="fas fa-user-plus"></i>manage session</a>
            <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
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

        <div class="container-fluid">
            <!-- Filter Card -->
            <div class="filter-card mb-4">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Results</h5>
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-2 form-group-spacing">
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
                        <div class="col-md-2 form-group-spacing">
                            <label class="form-label fw-bold">Class</label>
                            <select class="form-select" name="selected_class" id="selectedClass">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $class_filter == $class['class'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 form-group-spacing">
                            <label class="form-label fw-bold">Subject</label>   
                            <select class="form-select" name="selected_subject" id="selectedSubject">
                                <option value="">All Subjects</option>
                                <?php
                                foreach ($available_subjects as $subject): ?>
                                    <option value="<?php echo htmlspecialchars($subject); ?>" <?php echo $subject_filter == $subject ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 form-group-spacing">
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
                        <div class="col-md-2 form-group-spacing">
                            <label class="form-label fw-bold">Student Name</label>
                            <input type="text" class="form-control" name="student_name" value="<?php echo htmlspecialchars($student_name_filter); ?>" placeholder="Enter student name">
                        </div>
                        <div class="col-md-2 d-flex align-items-end form-group-spacing">
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
                                    <td><span class="badge bg-primary text-white"><?php echo htmlspecialchars($result['student_class']); ?></span></td>
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
            const classSubjectMapping = {
                'JSS1': <?php echo json_encode($jss_subjects); ?>,
                'JSS2': <?php echo json_encode($jss_subjects); ?>,
                'JSS3': <?php echo json_encode($jss_subjects); ?>,
                'SS1': <?php echo json_encode($ss_subjects); ?>,
                'SS2': <?php echo json_encode($ss_subjects); ?>,
                'SS3': <?php echo json_encode($ss_subjects); ?>
            };
            const assignedSubjects = <?php echo json_encode($available_subjects); ?>;

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