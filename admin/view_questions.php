<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// 
header('Content-Type: text/html; charset=UTF-8');

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
    $activity = "Admin {$admin['username']} accessed view questions page.";
    $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();

} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}

// The hardcoded subject arrays have been removed.

// Initialize variables
$error = $success = '';

// Handle question deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_question'])) {
    $question_id = intval($_POST['question_id'] ?? 0);
    $question_type = trim($_POST['question_type'] ?? '');
    if ($question_id && $question_type) {
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
            $result = $stmt->get_result()->fetch_assoc();
            if ($result && !empty($result['image_path'])) {
                $file_path = '../' . $result['image_path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
        $stmt->bind_param("i", $question_id);
        if ($stmt->execute()) {
            $success = "Question deleted successfully!";
            $activity = "Admin deleted question ID: $question_id ($question_type)";
            $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
        } else {
            $error = "Error deleting question: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Invalid question ID or type.";
    }
}

// Pagination settings
$questions_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $questions_per_page;

// Initialize filter variables
$class_filter = trim($_GET['class'] ?? '');
$subject_filter = trim($_GET['subject'] ?? '');
$search_term = trim($_GET['search'] ?? '');

// Build queries
$count_query = "SELECT COUNT(*) as total 
                FROM new_questions q 
                JOIN tests t ON q.test_id = t.id 
                JOIN classes c ON t.academic_level_id = c.academic_level_id 
                WHERE 1=1";

$select_query = "SELECT q.*, t.title as test_title, c.class_name AS class, t.subject
                 FROM new_questions q 
                 JOIN tests t ON q.test_id = t.id 
                 JOIN classes c ON t.academic_level_id = c.academic_level_id 
                 WHERE 1=1";
$params = [];
$types = '';

if ($class_filter) {
    $count_query .= " AND q.class = ?";
    $select_query .= " AND q.class = ?";
    $params[] = $class_filter;
    $types .= 's';
}
if ($subject_filter) {
    $count_query .= " AND LOWER(t.subject) = ?";
    $select_query .= " AND LOWER(t.subject) = ?";
    $params[] = strtolower($subject_filter);
    $types .= 's';
}
if ($search_term) {
    $count_query .= " AND (q.question_text LIKE ? OR t.title LIKE ?)";
    $select_query .= " AND (q.question_text LIKE ? OR t.title LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types .= 'ss';
}

// Get total questions
$stmt = $conn->prepare($count_query);
if ($params) {
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

// Fetch questions
$select_query .= " ORDER BY q.class, t.subject, q.id DESC LIMIT ? OFFSET ?";
$params[] = $questions_per_page;
$params[] = $offset;
$types .= 'ii';
$stmt = $conn->prepare($select_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch classes and subjects
$stmt = $conn->prepare("SELECT c.id, c.class_name 
                        FROM classes c 
                        JOIN academic_levels al ON c.academic_level_id = al.id 
                        ORDER BY al.level_code, c.class_name");
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch subjects with class levels
$stmt = $conn->prepare("
    SELECT s.subject_name, sl.class_level
    FROM subjects s
    JOIN subject_levels sl ON s.id = sl.subject_id
    ORDER BY sl.class_level, s.subject_name
");
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Questions | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/view_questions.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <style>
        .question-card {
            padding: 15px;
            border-left: 4px solid #4361ee;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .correct-option {
            background-color: rgba(40,167,69,0.1);
            padding: 5px;
            border-radius: 4px;
        }
        .badge-subject, .badge-class {
            padding: 5px 10px;
            background-color: #e9ecef;
            color: #212529;
        }
        .options-container {
            margin-left: 20px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #4361ee;
            color: white !important;
            border-color: #4361ee;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><b><?php echo htmlspecialchars($admin['username']); ?></b></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php" class="active"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
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
            <h2 class="mb-0">View Questions</h2>
            <div class="d-flex gap-3">
                <a href="add_question.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add New</a>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card bg-white border-0 shadow-sm filter-card mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Questions</h5>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold" for="classFilter">Class</label>
                            <select class="form-select" name="class" id="classFilter">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= htmlspecialchars($class['class']) ?>" <?= $class_filter == $class['class'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['class']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a valid class.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold" for="subjectFilter">Subject</label>
                            <select class="form-select" name="subject" id="subjectFilter">
                                <option value="">All Subjects</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= htmlspecialchars($subject['subject_name']) ?>" <?= $subject_filter == $subject['subject_name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($subject['subject_name']) ?> (<?= htmlspecialchars($subject['class_level']) ?>)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                            <div class="invalid-feedback">Please select a valid subject.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold" for="searchFilter">Search</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" id="searchFilter" placeholder="Search questions..." value="<?= htmlspecialchars($search_term) ?>">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                            </div>
                            <div class="invalid-feedback">Search term contains invalid characters.</div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Apply</button>
                        </div>
                    </div>
                    <?php if ($class_filter || $subject_filter || $search_term): ?>
                        <a href="view_questions.php" class="btn btn-outline-secondary mt-3"><i class="fas fa-times me-2"></i>Clear Filters</a>
                    <?php endif; ?>
                </form>
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

        <!-- Questions Table -->
        <div class="card bg-white border-0 shadow-sm">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0">Questions List (<?php echo $total_questions; ?> total)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($questions)): ?>
                    <table id="questionsTable" class="table table-striped table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Test</th>
                                <th>Question</th>
                                <th>Type</th>
                                <th>Options</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $question): ?>
                                <tr>
                                    <td><span class="badge badge-class"><?php echo htmlspecialchars($question['class']); ?></span></td>
                                    <td><span class="badge badge-subject"><?php echo htmlspecialchars($question['subject']); ?></span></td>
                                    <td><?php echo htmlspecialchars($question['test_title']); ?></td>
                                    <td><?php echo nl2br(htmlspecialchars($question['question_text'])); ?></td>
                                    <td><span class="badge bg-primary"><?php echo ucwords(str_replace('_', ' ', $question['question_type'])); ?></span></td>
                                    <td class="options-container">
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
                                        if (!empty($options['image_path'])) {
                                            echo '<img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid rounded mb-2" style="max-height: 100px;">';
                                        }
                                        if ($question['question_type'] === 'multiple_choice_single' && $options) {
                                            for ($i = 1; $i <= 4; $i++) {
                                                echo '<div class="' . ($options['correct_answer'] == $i ? 'correct-option' : '') . ' mb-1">';
                                                echo htmlspecialchars($options['option' . $i]);
                                                echo '</div>';
                                            }
                                        } elseif ($question['question_type'] === 'multiple_choice_multiple' && $options) {
                                            $correct_answers = explode(',', $options['correct_answers']);
                                            for ($i = 1; $i <= 4; $i++) {
                                                echo '<div class="' . (in_array($i, $correct_answers) ? 'correct-option' : '') . ' mb-1">';
                                                echo htmlspecialchars($options['option' . $i]);
                                                echo '</div>';
                                            }
                                        } elseif ($question['question_type'] === 'true_false' && $options) {
                                            echo '<div class="correct-option mb-1">' . htmlspecialchars($options['correct_answer']) . '</div>';
                                        } elseif ($question['question_type'] === 'fill_blanks' && $options) {
                                            echo '<div class="correct-option mb-1">' . htmlspecialchars($options['correct_answer']) . '</div>';
                                        } else {
                                            echo '<div class="text-muted">No options</div>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="add_question.php?edit=<?php echo $question['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <input type="hidden" name="question_type" value="<?php echo $question['question_type']; ?>">
                                            <input type="hidden" name="delete_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-question-circle fa-3x mb-3"></i>
                        <h4>No Questions Found</h4>
                        <p>Try adjusting your filters or add new questions.</p>
                        <a href="add_question.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add New Question</a>
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

            // Form validation
            $('#filterForm').validate({
                rules: {
                    class: {
                        regex: /^(JSS[1-3]|SS[1-3])$/ // Allow valid class names or empty
                    },
                    subject: {
                        regex: /^[a-zA-Z0-9\s\-\.]+$/ // Allow letters, numbers, spaces, hyphens, dots
                    },
                    search: {
                        maxlength: 100,
                        regex: /^[a-zA-Z0-9\s\-\.\,\?\!]*$/ // Allow common characters for search
                    }
                },
                messages: {
                    class: {
                        regex: 'Please select a valid class (e.g., JSS1, SS2)'
                    },
                    subject: {
                        regex: 'Please select a valid subject'
                    },
                    search: {
                        maxlength: 'Search term cannot exceed 100 characters',
                        regex: 'Search term contains invalid characters'
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
                    const classVal = $('#classFilter').val().trim();
                    const subjectVal = $('#subjectFilter').val().trim();
                    const searchVal = $('#searchFilter').val().trim();
                    if (!classVal && !subjectVal && !searchVal) {
                        window.location.href = 'view_questions.php';
                    } else {
                        form.submit();
                    }
                }
            });

            // Initialize DataTables
            $('#questionsTable').DataTable({
                pageLength: <?php echo $questions_per_page; ?>,
                searching: false, // Disable DataTables search since we use server-side filtering
                lengthChange: false,
                columnDefs: [
                    { orderable: false, targets: [5, 6] } // Disable sorting on Options and Actions
                ],
                language: {
                    paginate: {
                        previous: '« Previous',
                        next: 'Next »'
                    },
                    emptyTable: '<div class="text-center py-4 empty-state"><i class="fas fa-question-circle fa-3x mb-3"></i><h4>No Questions Found</h4><p>Try adjusting your filters or add new questions.</p><a href="add_question.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add New Question</a></div>'
                }
            });

            // Log filter application
            <?php if ($class_filter || $subject_filter || $search_term): ?>
                console.log('Filters applied: Class=<?php echo htmlspecialchars($class_filter); ?>, Subject=<?php echo htmlspecialchars($subject_filter); ?>, Search=<?php echo htmlspecialchars($search_term); ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>