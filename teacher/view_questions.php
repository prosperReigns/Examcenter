<?php
session_start();
require_once '../db.php';

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

    // Fetch assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
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
        $error = "No subjects assigned to you. Contact your admin.";
    }

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
    $class_filter = trim($_GET['class'] ?? '');
    $subject_filter = trim($_GET['subject'] ?? '');
    $search_term = trim($_GET['search'] ?? '');

    // Initialize error/success messages
    $error = $success = '';

    // Handle question deletion
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_question'])) {
        $question_id = intval($_POST['question_id'] ?? 0);
        $question_type = $conn->real_escape_string($_POST['question_type'] ?? '');

        if ($question_id && $question_type) {
            $conn->begin_transaction();
            try {
                // Verify question belongs to an authorized test
                $stmt = $conn->prepare("SELECT q.question_text, t.subject FROM new_questions q JOIN tests t ON q.test_id = t.id WHERE q.id = ? AND t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")");
                $params = array_merge([$question_id], $assigned_subjects);
                $types = 'i' . str_repeat('s', count($assigned_subjects));
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $question = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$question) {
                    throw new Exception("Question not found or unauthorized.");
                }

                // Delete associated image and options
                $table_map = [
                    'multiple_choice_single' => 'single_choice_questions',
                    'multiple_choice_multiple' => 'multiple_choice_questions',
                    'true_false' => 'true_false_questions',
                    'fill_blanks' => 'fill_blank_questions',
                ];
                $table = $table_map[$question_type] ?? '';
                if ($table) {
                    $stmt = $conn->prepare("SELECT image_path FROM $table WHERE question_id = ?");
                    $stmt->bind_param("i", $question_id);
                    $stmt->execute();
                    $image = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($image['image_path'] && file_exists("../{$image['image_path']}")) {
                        unlink("../{$image['image_path']}");
                    }

                    $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
                    $stmt->bind_param("i", $question_id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Delete from new_questions
                $stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
                $stmt->bind_param("i", $question_id);
                $stmt->execute();
                $stmt->close();

                // Log activity
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $activity = "Teacher {$teacher['username']} deleted question ID $question_id: " . substr($question['question_text'], 0, 50);
                $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, teacher_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                $stmt_log->execute();
                $stmt_log->close();

                $conn->commit();
                $success = "Question deleted successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Delete question error: " . $e->getMessage());
                $error = "Error deleting question: " . $e->getMessage();
            }
        } else {
            $error = "Invalid question ID or type.";
        }
    }

    // Build queries for questions
    $count_query = "SELECT COUNT(*) as total 
                    FROM new_questions q
                    JOIN tests t ON q.test_id = t.id
                    WHERE t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")";
    $select_query = "SELECT q.*, t.title as test_title, t.class, t.subject 
                     FROM new_questions q
                     JOIN tests t ON q.test_id = t.id
                     WHERE t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")";

    $params = $assigned_subjects;
    $types = str_repeat('s', count($assigned_subjects));

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
    if (!$stmt) {
        error_log("Prepare failed for count query: " . $conn->error);
        die("Database error");
    }
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
    if (!$stmt) {
        error_log("Prepare failed for select query: " . $conn->error);
        die("Database error");
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get unique classes and subjects for filters (restricted to assigned subjects)
    $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
    $stmt = $conn->prepare("SELECT DISTINCT class FROM tests WHERE subject IN ($placeholders) ORDER BY class");
    $stmt->bind_param(str_repeat('s', count($assigned_subjects)), ...$assigned_subjects);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT DISTINCT subject FROM tests WHERE subject IN ($placeholders) ORDER BY subject");
    $stmt->bind_param(str_repeat('s', count($assigned_subjects)), ...$assigned_subjects);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} catch (Exception $e) {
    error_log("View questions error: " . $e->getMessage());
    die("System error");
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Questions | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/view_questions.css">
    <style>
        .question-card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; }
        .filter-card { background: #f8f9fa; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group-spacing { margin-bottom: 1.5rem; }
        .correct-option { background: rgba(40, 167, 69, 0.1); padding: 0.5rem; border-radius: 4px; }
        .options-container { margin-left: 1.5rem; margin-top: 1rem; }
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
            <a href="view_questions.php" class="active"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
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
            <h2 class="mb-0">View Questions</h2>
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
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Questions</h5>
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-3 form-group-spacing">
                            <label class="form-label fw-bold">Class</label>
                            <select class="form-select" name="class" id="classSelect">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $class_filter == $class['class'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group-spacing">
                            <label class="form-label fw-bold">Subject</label>
                            <select class="form-select" name="subject" id="subjectSelect">
                                <option value="">All Subjects</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo htmlspecialchars($subject['subject']); ?>" <?php echo $subject_filter == $subject['subject'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group-spacing">
                            <label class="form-label fw-bold">Search</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" placeholder="Search questions or tests..." value="<?php echo htmlspecialchars($search_term); ?>">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end form-group-spacing">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Apply</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Results Summary -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5>
                    <?php if ($total_questions > 0): ?>
                        Showing <?php echo count($questions); ?> of <?php echo $total_questions; ?> question<?php echo $total_questions !== 1 ? 's' : ''; ?>
                        (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
                        <?php if (!empty($class_filter)): ?>
                            for <?php echo htmlspecialchars($class_filter); ?>
                        <?php endif; ?>
                        <?php if (!empty($subject_filter)): ?>
                            in <?php echo htmlspecialchars($subject_filter); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        No questions found
                    <?php endif; ?>
                </h5>
                <?php if (!empty($class_filter) || !empty($subject_filter) || !empty($search_term)): ?>
                    <a href="view_questions.php" class="btn btn-outline-secondary"><i class="fas fa-times me-2"></i>Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Questions List -->
            <?php if (!empty($questions)): ?>
                <?php foreach ($questions as $question): ?>
                    <div class="question-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <span class="badge bg-primary text-white me-2"><?php echo htmlspecialchars($question['class']); ?></span>
                                <span class="badge bg-secondary text-white me-2"><?php echo htmlspecialchars($question['subject']); ?></span>
                                <span class="badge bg-info text-white"><?php echo ucwords(str_replace('_', ' ', $question['question_type'])); ?></span>
                            </div>
                            <small class="text-muted">Test: <?php echo htmlspecialchars($question['test_title']); ?></small>
                        </div>
                        <div class="question-text mb-3">
                            <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                        </div>
                        <?php
                        $options = [];
                        switch ($question['question_type']) {
                            case 'multiple_choice_single':
                                $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?");
                                break;
                            case 'multiple_choice_multiple':
                                $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?");
                                break;
                            case 'true_false':
                                $stmt = $conn->prepare("SELECT correct_answer FROM true_false_questions WHERE question_id = ?");
                                break;
                            case 'fill_blanks':
                                $stmt = $conn->prepare("SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?");
                                break;
                            default:
                                $stmt = null;
                        }
                        if ($stmt) {
                            $stmt->bind_param("i", $question['id']);
                            $stmt->execute();
                            $options = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                        }
                        ?>
                        <?php if (!empty($options['image_path'])): ?>
                            <div class="mb-3">
                                <img src="../<?php echo htmlspecialchars($options['image_path']); ?>" class="img-fluid rounded" style="max-height: 200px;">
                            </div>
                        <?php endif; ?>
                        <div class="options-container">
                            <?php if ($question['question_type'] === 'multiple_choice_single' && $options): ?>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <div class="<?php echo $options['correct_answer'] == $i ? 'correct-option' : ''; ?> mb-2">
                                        <strong>Option <?php echo $i; ?>:</strong> <?php echo htmlspecialchars($options['option' . $i]); ?>
                                    </div>
                                <?php endfor; ?>
                            <?php elseif ($question['question_type'] === 'multiple_choice_multiple' && $options): ?>
                                <?php $correct_answers = explode(',', $options['correct_answers']); ?>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <div class="<?php echo in_array($i, $correct_answers) ? 'correct-option' : ''; ?> mb-2">
                                        <strong>Option <?php echo $i; ?>:</strong> <?php echo htmlspecialchars($options['option' . $i]); ?>
                                    </div>
                                <?php endfor; ?>
                            <?php elseif ($question['question_type'] === 'true_false' && $options): ?>
                                <div class="correct-option">
                                    <strong>Correct Answer:</strong> <?php echo htmlspecialchars($options['correct_answer']); ?>
                                </div>
                            <?php elseif ($question['question_type'] === 'fill_blanks' && $options): ?>
                                <div class="correct-option">
                                    <strong>Correct Answer:</strong> <?php echo htmlspecialchars($options['correct_answer']); ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted">No options found</div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <a href="add_question.php?test_id=<?php echo $question['test_id']; ?>&edit=<?php echo $question['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit me-1"></i> Edit
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                <input type="hidden" name="question_type" value="<?php echo $question['question_type']; ?>">
                                <input type="hidden" name="delete_question" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash me-1"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&class=<?php echo urlencode($class_filter); ?>&subject=<?php echo urlencode($subject_filter); ?>&search=<?php echo urlencode($search_term); ?>" aria-label="Previous">
                                    <span aria-hidden="true">« Previous</span>
                                </a>
                            </li>
                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            if ($start_page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=1&class=<?php echo urlencode($class_filter); ?>&subject=<?php echo urlencode($subject_filter); ?>&search=<?php echo urlencode($search_term); ?>">1</a></li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&class=<?php echo urlencode($class_filter); ?>&subject=<?php echo urlencode($subject_filter); ?>&search=<?php echo urlencode($search_term); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&class=<?php echo urlencode($class_filter); ?>&subject=<?php echo urlencode($subject_filter); ?>&search=<?php echo urlencode($search_term); ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                            <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&class=<?php echo urlencode($class_filter); ?>&subject=<?php echo urlencode($subject_filter); ?>&search=<?php echo urlencode($search_term); ?>" aria-label="Next">
                                    <span aria-hidden="true">Next »</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle fa-3x mb-3"></i>
                    <h4>No questions found</h4>
                    <p>Try adjusting your filters or add new questions.</p>
                    <a href="add_question.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add New Question</a>
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
            const assignedSubjects = <?php echo json_encode($assigned_subjects); ?>;

            // Update subjects when class changes
            const classSelect = document.getElementById('classSelect');
            const subjectSelect = document.getElementById('subjectSelect');
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