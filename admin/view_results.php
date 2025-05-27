<?php
session_start();
require_once '../db.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Define subjects by category
$jss_subjects = [
    'Mathematics', 'English', 'ICT', 'Agriculture', 'History', 
    'Civic Education', 'Basic Science', 'Basic Technology', 
    'Business studies', 'Agricultural sci', 'Physical Health Edu',
    'Cultural and Creative Art', 'Social Studies', 'Security Edu', 
    'Yoruba', 'french', 'Coding and Robotics', 'C.R.S', 'I.R.S', 'Chess'
];

$ss_subjects = [
    'Mathematics', 'English', 'Civic Edu', 'Data Processing', 'Economics',
    'Government', 'Commerce', 'Accounting', 'Financial Accounting', 
    'Dyeing and Bleaching', 'Physics', 'Chemistry', 'Biology', 
    'Agricultural Sci', 'Geography', 'technical Drawing', 'yoruba Lang',
    'French Lang', 'Further Maths', 'Literature in English', 'C.R.S', 'I.R.S'
];

// Pagination settings
$results_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $results_per_page;

// Initialize filter variables
$class_filter = $_POST['selected_class'] ?? '';
$subject_filter = $_POST['selected_subject'] ?? '';
$test_title_filter = $_POST['selected_title'] ?? '';

// Get all test titles
$test_titles_query = "SELECT DISTINCT title FROM tests ORDER BY title ASC";
$test_titles_result = $conn->query($test_titles_query);
$test_titles = $test_titles_result->fetch_all(MYSQLI_ASSOC);

// Build count query for total results
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

// Get total results
$stmt = $conn->prepare($count_query);
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
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$results = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all unique classes for filter dropdown
$classes_query = "SELECT DISTINCT class FROM students ORDER BY class";
$classes_result = $conn->query($classes_query);
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.min.css">
    <link rel="stylesheet" href="../css/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
        }
        
        .gradient-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-bottom-left-radius: 35px;
            border-bottom-right-radius: 35px;
        }
        
        .filter-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .results-table {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .results-table thead {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }
        
        .results-table th {
            font-weight: 600;
            padding: 1rem;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        
        .results-table tbody tr {
            transition: background-color 0.2s;
        }
        
        .results-table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .results-table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        
        .badge-class, .badge-subject {
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
        }
        
        .badge-class {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }
        
        .badge-subject {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .percentage-cell {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .percentage-cell.high {
            color: var(--success);
        }
        
        .percentage-cell.medium {
            color: var(--warning);
        }
        
        .percentage-cell.low {
            color: var(--danger);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .pagination .page-link {
            color: var(--primary);
            border-radius: 5px;
            margin: 0 3px;
        }
        
        .pagination .page-link:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
    </style>
</head>
<body>
    <!-- Gradient Header -->
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">View Results</h1>
                <div class="d-flex gap-3">
                    <?php if ($total_results > 0): ?>
    <a href="export_results_word.php?<?php echo http_build_query([
        'selected_title' => $test_title_filter,
        'selected_class' => $class_filter,
        'selected_subject' => $subject_filter
    ]); ?>" class="btn btn-success ms-2">
        <i class="fas fa-file-word me-2"></i>Export to Word
    </a>
<?php endif; ?>
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Filter Card -->
        <div class="filter-card animate__animated animate__fadeIn">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Results</h5>
            <form method="POST" action="">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Test Title</label>
                        <select class="form-select" name="selected_title">
                            <option value="">All Tests</option>
                            <?php foreach ($test_titles as $title): ?>
                                <option value="<?= htmlspecialchars($title['title']) ?>" 
                                        <?= $test_title_filter == $title['title'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($title['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Class</label>
                        <select class="form-select" name="selected_class" id="selectedClass">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= htmlspecialchars($class['class']) ?>" 
                                        <?= $class_filter == $class['class'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Subject</label>
                        <select class="form-select" name="selected_subject" id="selectedSubject">
                            <option value="">All Subjects</option>
                            <?php 
                            $available_subjects = [];
                            if (strpos($class_filter, 'JSS') === 0) {
                                $available_subjects = $jss_subjects;
                            } elseif (strpos($class_filter, 'SS') === 0) {
                                $available_subjects = $ss_subjects;
                            }
                            foreach ($available_subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject) ?>" 
                                        <?= $subject_filter == $subject ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Summary -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>
                <?php if ($total_results > 0): ?>
                    Showing <?= count($results) ?> of <?= $total_results ?> result<?= $total_results !== 1 ? 's' : '' ?>
                    (Page <?= $current_page ?> of <?= $total_pages ?>)
                    <?php if (!empty($test_title_filter)): ?>
                        for "<?= htmlspecialchars($test_title_filter) ?>"
                    <?php endif; ?>
                    <?php if (!empty($class_filter)): ?>
                        in <?= htmlspecialchars($class_filter) ?>
                    <?php endif; ?>
                    <?php if (!empty($subject_filter)): ?>
                        - <?= htmlspecialchars($subject_filter) ?>
                    <?php endif; ?>
                <?php else: ?>
                    No results found
                <?php endif; ?>
            </h5>
            <?php if (!empty($test_title_filter) || !empty($class_filter) || !empty($subject_filter)): ?>
                <a href="view_results.php" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-2"></i>Clear Filters
                </a>
            <?php endif; ?>
        </div>

        <!-- Results Table -->
        <?php if (!empty($results)): ?>
            <div class="results-table animate__animated animate__fadeIn table-responsive">
                <table class="table table-hover">
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
                            $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
                            $percentage_class = $percentage >= 70 ? 'high' : ($percentage >= 50 ? 'medium' : 'low');
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($result['student_name']) ?></td>
                                <td><span class="badge badge-class"><?= htmlspecialchars($result['student_class']) ?></span></td>
                                <td><?= htmlspecialchars($result['test_title']) ?></td>
                                <td><span class="badge badge-subject"><?= htmlspecialchars($result['subject']) ?></span></td>
                                <td><?= $result['score'] ?> / <?= $result['total_questions'] ?></td>
                                <td class="percentage-cell <?= $percentage_class ?>">
                                    <?= $percentage ?>%
                                </td>
                                <td><?= date('M j, Y g:i A', strtotime($result['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination">
                        <li class="page-item <?= $current_page == 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $current_page - 1 ?>&selected_class=<?= urlencode($class_filter) ?>&selected_subject=<?= urlencode($subject_filter) ?>&selected_title=<?= urlencode($test_title_filter) ?>" aria-label="Previous">
                                <span aria-hidden="true">« Previous</span>
                            </a>
                        </li>
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        if ($start_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=1&selected_class=<?= urlencode($class_filter) ?>&selected_subject=<?= urlencode($subject_filter) ?>&selected_title=<?= urlencode($test_title_filter) ?>">1</a></li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&selected_class=<?= urlencode($class_filter) ?>&selected_subject=<?= urlencode($subject_filter) ?>&selected_title=<?= urlencode($test_title_filter) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?>&selected_class=<?= urlencode($class_filter) ?>&selected_subject=<?= urlencode($subject_filter) ?>&selected_title=<?= urlencode($test_title_filter) ?>"><?= $total_pages ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $current_page == $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $current_page + 1 ?>&selected_class=<?= urlencode($class_filter) ?>&selected_subject=<?= urlencode($subject_filter) ?>&selected_title=<?= urlencode($test_title_filter) ?>" aria-label="Next">
                                <span aria-hidden="true">Next »</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state animate__animated animate__fadeIn">
                <i class="fas fa-chart-bar"></i>
                <h4>No Results Found</h4>
                <p>Try adjusting your filters or check back later.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        const jssSubjects = <?php echo json_encode($jss_subjects); ?>;
        const ssSubjects = <?php echo json_encode($ss_subjects); ?>;

        document.getElementById('selectedClass').addEventListener('change', function() {
            const selectedClass = this.value;
            const subjectSelect = document.getElementById('selectedSubject');
            subjectSelect.innerHTML = '<option value="">All Subjects</option>';
            let availableSubjects = [];
            if (selectedClass.startsWith('JSS')) {
                availableSubjects = jssSubjects;
            } else if (selectedClass.startsWith('SS')) {
                availableSubjects = ssSubjects;
            }
            availableSubjects.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject;
                option.textContent = subject;
                subjectSelect.appendChild(option);
            });
        });
    </script>
</body>
</html>