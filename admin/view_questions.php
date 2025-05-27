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
$questions_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $questions_per_page;

// Initialize filter variables
$class_filter = $_GET['class'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$search_term = $_GET['search'] ?? '';

// Build the base query for counting total questions
$count_query = "SELECT COUNT(*) as total 
                FROM new_questions q
                JOIN tests t ON q.test_id = t.id
                WHERE 1=1";

$select_query = "SELECT q.*, t.title as test_title, t.class, t.subject 
                 FROM new_questions q
                 JOIN tests t ON q.test_id = t.id
                 WHERE 1=1";

$params = [];
$types = '';

// Apply filters
if (!empty($class_filter)) {
    $count_query .= " AND t.class = ?";
    $select_query .= " AND t.class = ?";
    $params[] = $class_filter;
    $types .= 's';
}

if (!empty($subject_filter)) {
    $count_query .= " AND t.subject = ?";
    $select_query .= " AND t.subject = ?";
    $params[] = $subject_filter;
    $types .= 's';
}

if (!empty($search_term)) {
    $count_query .= " AND (q.question_text LIKE ? OR t.title LIKE ?)";
    $select_query .= " AND (q.question_text LIKE ? OR t.title LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types .= 'ss';
}

// Get total questions
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_questions = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = ceil($total_questions / $questions_per_page);
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $questions_per_page;
}

// Fetch questions for current page
$select_query .= " ORDER BY t.class, t.subject, q.id DESC LIMIT ? OFFSET ?";
$params[] = $questions_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($select_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$questions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all unique classes and subjects for filter dropdowns
$classes_result = $conn->query("SELECT DISTINCT class FROM tests ORDER BY class");
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

$subjects_result = $conn->query("SELECT DISTINCT subject FROM tests ORDER BY subject");
$subjects = $subjects_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Questions</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.min.css">
    <link rel="stylesheet" href="../css/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 80vh;
            color: var(--dark);
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
            background-color: #f8f9fa;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .question-card {
            background-color: white;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .question-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .correct-option {
            background-color: rgba(40, 167, 69, 0.1);
            border-left: 3px solid #28a745;
            padding: 0.5rem;
            border-radius: 4px;
        }
        
        .badge-subject {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .badge-class {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }
        
        .action-buttons .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .question-text {
            font-weight: 500;
            margin-bottom: 1rem;
        }
        
        .options-container {
            margin-left: 1.5rem;
        }
        
        .question-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .question-type-badge {
            background-color: rgba(108, 117, 125, 0.1);
            color: #6c757d;
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
                <h1 class="mb-0">View Questions</h1>
                <div class="d-flex gap-3">
                    <a href="add_question.php" class="btn btn-light">
                        <i class="fas fa-plus me-2"></i>Add New
                    </a>
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Filter Card -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Questions</h5>
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Class</label>
                        <select class="form-select" name="class">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= htmlspecialchars($class['class']) ?>" <?= $class_filter == $class['class'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Subject</label>
                        <select class="form-select" name="subject">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['subject']) ?>" <?= $subject_filter == $subject['subject'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" placeholder="Search questions..." value="<?= htmlspecialchars($search_term) ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Apply
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Summary -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>
                <?php if ($total_questions > 0): ?>
                    Showing <?= count($questions) ?> of <?= $total_questions ?> question<?= $total_questions !== 1 ? 's' : '' ?>
                    (Page <?= $current_page ?> of <?= $total_pages ?>)
                    <?php if (!empty($class_filter)): ?>
                        for <?= htmlspecialchars($class_filter) ?>
                    <?php endif; ?>
                    <?php if (!empty($subject_filter)): ?>
                        in <?= htmlspecialchars($subject_filter) ?>
                    <?php endif; ?>
                <?php else: ?>
                    No questions found
                <?php endif; ?>
            </h5>
            <?php if (!empty($class_filter) || !empty($subject_filter) || !empty($search_term)): ?>
                <a href="view_questions.php" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-2"></i>Clear Filters
                </a>
            <?php endif; ?>
        </div>

        <!-- Questions List -->
        <?php if (!empty($questions)): ?>
            <?php foreach ($questions as $question): ?>
                <div class="question-card animate__animated animate__fadeIn">
                    <div class="question-meta">
                        <div>
                            <span class="badge badge-class me-2"><?= htmlspecialchars($question['class']) ?></span>
                            <span class="badge badge-subject me-2"><?= htmlspecialchars($question['subject']) ?></span>
                            <span class="badge question-type-badge">
                                <?= ucwords(str_replace('_', ' ', $question['question_type'])) ?>
                            </span>
                        </div>
                        <div>
                            <small class="text-muted">
                                Test: <?= htmlspecialchars($question['test_title']) ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="question-text">
                        <?= nl2br(htmlspecialchars($question['question_text'])) ?>
                    </div>
                    
                    <?php
                    // Get question options based on type
                    $options = [];
                    switch ($question['question_type']) {
                        case 'multiple_choice_single':
                            $option_query = "SELECT option1, option2, option3, option4, correct_answer, image_path 
                                            FROM single_choice_questions 
                                            WHERE question_id = ?";
                            break;
                        case 'multiple_choice_multiple':
                            $option_query = "SELECT option1, option2, option3, option4, correct_answers, image_path 
                                            FROM multiple_choice_questions 
                                            WHERE question_id = ?";
                            break;
                        case 'true_false':
                            $option_query = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
                            break;
                        case 'fill_blanks':
                            $option_query = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
                            break;
                        default:
                            $option_query = null;
                            break;
                    }
                    
                    if ($option_query) {
                        $option_stmt = $conn->prepare($option_query);
                        if (!$option_stmt) {
                            die("Prepare failed: " . $conn->error);
                        }
                        $option_stmt->bind_param("i", $question['id']);
                        $option_stmt->execute();
                        $options = $option_stmt->get_result()->fetch_assoc();
                        $option_stmt->close();
                    }
                    
                    if (!empty($options['image_path'])): ?>
                        <div class="mb-3">
                            <img src="../<?= htmlspecialchars($options['image_path']) ?>" class="img-fluid rounded" style="max-height: 200px;">
                        </div>
                    <?php endif; ?>
                    
                    <div class="options-container">
                        <?php if ($question['question_type'] === 'multiple_choice_single'): ?>
                            <?php if ($options): ?>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <div class="<?= $options['correct_answer'] == $i ? 'correct-option' : '' ?> mb-2">
                                        <strong>Option <?= $i ?>:</strong> 
                                        <?= htmlspecialchars($options['option' . $i]) ?>
                                    </div>
                                <?php endfor; ?>
                            <?php else: ?>
                                <div class="text-muted">No options found</div>
                            <?php endif; ?>
                        <?php elseif ($question['question_type'] === 'multiple_choice_multiple'): ?>
                            <?php if ($options): ?>
                                <?php 
                                $correct_answers = explode(',', $options['correct_answers']);
                                for ($i = 1; $i <= 4; $i++): ?>
                                    <div class="<?= in_array($i, $correct_answers) ? 'correct-option' : '' ?> mb-2">
                                        <strong>Option <?= $i ?>:</strong> 
                                        <?= htmlspecialchars($options['option' . $i]) ?>
                                    </div>
                                <?php endfor; ?>
                            <?php else: ?>
                                <div class="text-muted">No options found</div>
                            <?php endif; ?>
                        <?php elseif ($question['question_type'] === 'true_false'): ?>
                            <div class="correct-option">
                                <strong>Correct Answer:</strong> 
                                <?= $options ? htmlspecialchars($options['correct_answer']) : 'No answer found' ?>
                            </div>
                        <?php elseif ($question['question_type'] === 'fill_blanks'): ?>
                            <div class="correct-option">
                                <strong>Correct Answer:</strong> 
                                <?= $options ? htmlspecialchars($options['correct_answer']) : 'No answer found' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="action-buttons mt-3">
                        <a href="add_question.php?edit=<?= $question['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                        <form method="POST" action="delete_question.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                            <input type="hidden" name="question_id" value="<?= $question['id'] ?>">
                            <input type="hidden" name="question_type" value="<?= $question['question_type'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination">
                        <!-- Previous Button -->
                        <li class="page-item <?= $current_page == 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $current_page - 1 ?>&class=<?= urlencode($class_filter) ?>&subject=<?= urlencode($subject_filter) ?>&search=<?= urlencode($search_term) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo; Previous</span>
                            </a>
                        </li>
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        if ($start_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=1&class=<?= urlencode($class_filter) ?>&subject=<?= urlencode($subject_filter) ?>&search=<?= urlencode($search_term) ?>">1</a></li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&class=<?= urlencode($class_filter) ?>&subject=<?= urlencode($subject_filter) ?>&search=<?= urlencode($search_term) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?>&class=<?= urlencode($class_filter) ?>&subject=<?= urlencode($subject_filter) ?>&search=<?= urlencode($search_term) ?>"><?= $total_pages ?></a></li>
                        <?php endif; ?>
                        <!-- Next Button -->
                        <li class="page-item <?= $current_page == $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $current_page + 1 ?>&class=<?= urlencode($class_filter) ?>&subject=<?= urlencode($subject_filter) ?>&search=<?= urlencode($search_term) ?>" aria-label="Next">
                                <span aria-hidden="true">Next &raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <h4>No questions found</h4>
                <p>Try adjusting your filters or add new questions</p>
                <a href="add_question.php" class="btn btn-primary mt-3">
                    <i class="fas fa-plus me-2"></i>Add New Question
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>

    
</body>
</html>