<?php
session_start();
require_once '../db.php';

// 
header('Content-Type: text/html; charset=UTF-8');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

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
    $stmt = $conn->prepare("
        SELECT s.id, s.subject_name, sl.class_level
        FROM teacher_subjects ts
        JOIN subjects s ON ts.subject = s.subject_name
        JOIN subject_levels sl ON s.id = sl.subject_id
        WHERE ts.teacher_id = ?
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
        $assigned_subjects[] = [
            'id' => (int)$row['id'],
            'name' => $row['subject_name'],
            'class_level' => $row['class_level']
        ];
    }
    $stmt->close();

    if (empty($assigned_subjects)) {
        $error = "No subjects assigned to you. Contact your admin.";
    }

    $class_subjects = [];
    $stmt = $conn->prepare("
        SELECT s.id, s.subject_name, sl.class_level
        FROM subject_levels sl
        JOIN subjects s ON sl.subject_id = s.id
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $class_subjects[$row['class_level']][] = [
            'id' => $row['id'],
            'name' => $row['subject_name']
        ];
    }
    
    $stmt->close();

    // Initialize variables
    $error = $success = '';
    $current_test = null;
    $questions = [];
    $total_questions = 0;
    $tests = [];

    // Fetch tests for assigned subjects
    if (!empty($assigned_subjects)) {
        $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
        $subjectIds = array_column($assigned_subjects, 'id');
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $conn->prepare("
        SELECT t.id, t.title, c.class_name, s.subject_name
            FROM tests t
            JOIN classes c ON t.academic_level_id = c.academic_level_id
            JOIN subjects s ON t.subject = s.subject_name
            JOIN subject_levels sl ON s.id = sl.subject_id
            WHERE s.id IN ($placeholders)
            ORDER BY t.created_at DESC
        "); 

        $stmt->bind_param(str_repeat('i', count($subjectIds)), ...$subjectIds);


        if (!$stmt) {
            error_log("Prepare failed for tests: " . $conn->error);
            $error = "Error fetching tests.";
        } else {
            $stmt->bind_param(str_repeat('i', count($assigned_subjects)), ...$assigned_subjects);
            $stmt->execute();
            $tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    // Fetch all classes with their level and name
    $classQuery = $conn->query("
    SELECT c.id, c.class_name, al.level_code
    FROM classes c
    JOIN academic_levels al ON c.academic_level_id = al.id
    ORDER BY al.level_code, c.class_name
    ");
    $class_mapping = [];
    while ($row = $classQuery->fetch_assoc()) {
    $level = $row['level_code'];
    $class_mapping[$level][] = $row['class_name'];
    }

    // Load current test
    if (isset($_SESSION['current_test_id'])) {
        $test_id = (int)$_SESSION['current_test_id'];
        $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));
        $stmt = $conn->prepare("
            SELECT t.id, t.title, c.class_name, t.subject, t.duration
            FROM tests t
            JOIN academic_levels al ON t.academic_level_id = al.id
            JOIN classes c ON c.academic_level_id = al.id
            WHERE t.id = ? AND t.subject IN ($placeholders)
        ");


        if (!$stmt) {
            error_log("Prepare failed for current test: " . $conn->error);
            $error = "Database error.";
        } else {
            $params = array_merge([$test_id], $assigned_subjects);
            $types = 'i' . str_repeat('s', count($assigned_subjects));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $current_test = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($current_test) {
                $stmt = $conn->prepare("SELECT id, question_text, question_type FROM new_questions WHERE test_id = ? ORDER BY id ASC");
                if (!$stmt) {
                    error_log("Prepare failed for questions: " . $conn->error);
                    $error = "Database error.";
                } else {
                    $stmt->bind_param("i", $test_id);
                    $stmt->execute();
                    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $total_questions = count($questions);
                    $stmt->close();
                }
            } else {
                unset($_SESSION['current_test_id']);
                $error = "Selected test is invalid or unauthorized.";
            }
        }
    }

    // Load messages from session
    if (isset($_SESSION['error'])) {
        $error = $_SESSION['error'];
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        $success = $_SESSION['success'];
        unset($_SESSION['success']);
    }

    // Load edit question data if set
    $edit_question = null;
    if (isset($_SESSION['edit_question'])) {
        $edit_question = $_SESSION['edit_question'];
        unset($_SESSION['edit_question']);
    }
    $edit_data = [
        'options' => $edit_question['options'] ?? [],
        'question_type' => $edit_question['question_type'] ?? ''
    ];
    if (isset($edit_question['options']['correct_answers'])) {
        $edit_data['correct_answers'] = array_map('intval', explode(',', $edit_question['options']['correct_answers']));
    } else {
        $edit_data['correct_answers'] = [];
    }

} catch (Exception $e) {
    error_log("Add question error: " . $e->getMessage());
    // echo "<pre>System error: " . $e->getMessage() . "</pre>"; 
    die("An unexpected error occurred. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/add_question.css">
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
            <a href="add_question.php" class="active"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="manage_test.php"><i class="fas fa-list"></i>Manage Test</a>
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
            <h2 class="mb-0">Add Questions</h2>
            <div class="header-actions">
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <button class="btn btn-primary <?php echo !$current_test ? 'preview-disabled' : ''; ?>" 
                        <?php echo !$current_test ? 'disabled' : ''; ?>
                        data-bs-toggle="modal" data-bs-target="#previewModal" id="previewButton">
                    <i class="fas fa-eye me-2"></i>Preview
                </button>
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

        <div class="row g-4">
            <!-- Question Form -->
            <div class="col-lg-8">
                <div class="question-card">
                    <?php if (!$current_test): ?>
                        <h5 class="mb-3">Test Setup</h5>
                        <form method="POST" id="testForm" action="handle_test.php">
                            <div class="row g-4">
                                <div class="col-md-3 form-group-spacing">
                                    <label class="form-label fw-bold" for="year">Academic Year:</label>
                                    <select class="form-select" name="year" id="year" required>
                                        <option value="">Select Academic Year</option>
                                        <?php
                                             $yearQuery = $conn->query("SELECT DISTINCT year FROM academic_years ORDER BY year ASC");
                                            while ($row = $yearQuery->fetch_assoc()) {
                                                echo '<option value="' . htmlspecialchars($row['year']) . '">' . htmlspecialchars($row['year']) . '</option>';
                                            }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group-spacing">
                                    <label class="form-label fw-bold">Test Title</label>
                                    <select class="form-select" name="test_title" required>
                                        <option value="">Select Test Title</option>
                                        <?php
                                        // Fetch sessions + exam titles from academic_years table
                                        $ayQuery = $conn->query("SELECT DISTINCT session, exam_title FROM academic_years ORDER BY session ASC");
                                        while ($row = $ayQuery->fetch_assoc()) {
                                            // Combine session + exam_title without dash or year
                                            $combinedTitle = htmlspecialchars($row['session'] . ' ' . $row['exam_title']);
                                            echo '<option value="' . $combinedTitle . '">' . $combinedTitle . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group-spacing">
                                    <label class="form-label fw-bold">Academic level</label>
                                    <select class="form-select" name="class" required id="classSelect">
                                    <?php
                                        foreach ($class_mapping as $levelName => $classes) {
                                            foreach ($classes as $className) {
                                                echo '<option value="' . htmlspecialchars($className) . '">' . htmlspecialchars($className) . '</option>';
                                            }
                                        }                                        
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group-spacing">
                                    <label class="form-label fw-bold">Subject</label>
                                    <select class="form-select" name="subject_id" required id="subjectSelect" disabled>
                                        <option value="">Select Class First</option>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group-spacing">
                                    <label class="form-label fw-bold">Duration (min)</label>
                                    <input type="number" class="form-control" name="duration" required placeholder="e.g. 30" min="1">
                                </div>
                            </div>
                            <button type="submit" name="create_test" class="btn btn-primary mt-3"><i class="fas fa-plus me-2"></i>Create Test</button>
                        </form>
                        <div class="mb-4">
                            <hr>
                            <h6>Upload Test</h6>
                            <form method="POST" id="uploadForm" enctype="multipart/form-data" action="upload.php">
                                <label class="form-label fw-bold" for="year">Academic Year:</label>
                                <select class="form-select" name="year" id="year" required>
                                    <option value="">Select Academic Year</option>
                                    <?php
                                        $yearQuery = $conn->query("SELECT year FROM academic_years ORDER BY year ASC");
                                        while ($row = $yearQuery->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($row['year']) . '">' . htmlspecialchars($row['year']) . '</option>';
                                        }
                                    ?>
                                </select>
                                <br><br>
                                <label class="form-label fw-bold">Select Test File (.docx):</label>
                                <input type="file" class="form-control" name="test_file" accept=".docx" required>
                                <button type="submit" class="btn btn-primary mt-3">
                                    <i class="fas fa-upload me-2"></i>Upload Test
                                </button>
                            </form>
                        </div>
                        <?php if (!empty($tests)): ?>
                            <hr>
                            <h6>Select Existing Test</h6>
                            <form method="POST" id="selectTestForm" action="handle_test.php">
                                <div class="form-group-spacing">
                                    <label class="form-label fw-bold">Available Tests</label>
                                    <select class="form-select" name="test_id" id="testIdSelect" required>
                                        <option value="">Select a Test</option>
                                        <?php foreach ($tests as $test): ?>
                                            <option value="<?php echo (int)$test['id']; ?>">
                                                <?php echo htmlspecialchars($test['title'] . ' (' . $test['class'] . ' - ' . $test['subject'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="select_test" class="btn btn-primary"><i class="fas fa-check me-2"></i>Select Test</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <h5 class="mb-3"><?php echo $edit_question ? 'Edit Question' : 'Add Question'; ?></h5>
                        <form method="POST" id="questionForm" enctype="multipart/form-data" action="handle_question.php">
                            <input type="hidden" name="question_id" value="<?php echo (int)($edit_question['id'] ?? ''); ?>">
                            <div class="form-group-spacing">
                                <label class="form-label fw-bold">Question Type</label>
                                <select class="form-select form-select-lg" name="question_type" id="questionType" required>
                                    <option value="multiple_choice_single" <?php echo (!$edit_question || !$edit_question['question_type'] || $edit_question['question_type'] == 'multiple_choice_single') ? 'selected' : ''; ?>>Single Choice Question</option>
                                    <option value="multiple_choice_multiple" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_multiple') ? 'selected' : ''; ?>>Multiple Choice Question</option>
                                    <option value="true_false" <?php echo ($edit_question && $edit_question['question_type'] == 'true_false') ? 'selected' : ''; ?>>True/False</option>
                                    <option value="fill_blanks" <?php echo ($edit_question && $edit_question['question_type'] == 'fill_blanks') ? 'selected' : ''; ?>>Fill in Blanks</option>
                                </select>
                            </div>
                            <div class="form-group-spacing">
                                <label class="form-label fw-bold">Question Text</label>
                                <textarea class="form-control" name="question" rows="4" placeholder="Enter your question here..." required><?php echo nl2br(htmlspecialchars($edit_question['question_text'] ?? '')); ?></textarea>
                            </div>
                            <div id="optionsContainer" class="form-group-spacing"></div>
                            <div class="d-flex justify-content-end gap-3 mt-4">
                                <button type="reset" class="btn btn-secondary">Clear</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-<?php echo $edit_question ? 'save' : 'plus'; ?> me-2"></i><?php echo $edit_question ? 'Update Question' : 'Add Question'; ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Test Overview -->
            <div class="col-lg-4">
                <div class="question-card">
                    <h5 class="mb-3">Test Overview</h5>
                    <?php if ($current_test): ?>
                        <div class="alert alert-primary">
                            <strong><?php echo htmlspecialchars($current_test['title']); ?></strong><br>
                            <span class="text-muted"><?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?></span><br>
                            <small>Duration: <?php echo (int)$current_test['duration']; ?> minutes</small>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span>Total Questions:</span>
                            <strong><?php echo $total_questions; ?></strong>
                        </div>
                        <form method="POST" action="handle_test.php">
                            <button type="submit" name="clear_test" class="btn btn-outline-danger w-100"><i class="fas fa-times me-2"></i>Clear Test Selection</button>
                        </form>
                    <?php else: ?>
                        <div class="text-center">
                            <p class="text-muted">No test selected. Create or select a test to start.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewModalLabel">Test Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body modal-preview">
                    <?php if ($current_test && !empty($questions)): ?>
                        <h6><?php echo htmlspecialchars($current_test['title']); ?> (<?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?>)</h6>
                        <p><small>Duration: <?php echo (int)$current_test['duration']; ?> minutes</small></p>
                        <hr>
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong>Question <?php echo $index + 1; ?>: <?php echo nl2br(htmlspecialchars($question['question_text'])); ?></strong>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;" action="handle_question.php">
                                            <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                            <input type="hidden" name="edit_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</button>
                                        </form>
                                        <form method="POST" style="display: inline;" action="handle_question.php" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                            <input type="hidden" name="question_type" value="<?php echo htmlspecialchars($question['question_type']); ?>">
                                            <input type="hidden" name="delete_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <span class="badge bg-primary ms-2"><?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?></span>
                                <div class="mt-2">
                                    <?php
                                    switch ($question['question_type']) {
                                        case 'multiple_choice_single':
                                            $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if ($options['image_path'] && file_exists("../{$options['image_path']}")) {
                                                echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                            } elseif ($options['image_path']) {
                                                echo '<div class="mb-3"><small class="text-muted">Image not found.</small></div>';
                                            }
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                echo "<div>" . ($options['correct_answer'] === $options[$opt] ? '<i class="fas fa-check text-success me-2"></i>' : '') .
                                                     htmlspecialchars($options[$opt] ?? '') . "</div>";
                                            }
                                            break;
                                        case 'multiple_choice_multiple':
                                            $stmt = $conn->prepare("SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if ($options['image_path'] && file_exists("../{$options['image_path']}")) {
                                                echo '<div class="mb-3"><img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;"></div>';
                                            } elseif ($options['image_path']) {
                                                echo '<div class="mb-3"><small class="text-muted">Image not found.</small></div>';
                                            }
                                            $correct = explode(',', $options['correct_answers']);
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                echo "<div>" . (in_array($options[$opt], $correct) ? '<i class="fas fa-check text-success me-2"></i>' : '') .
                                                     htmlspecialchars($options[$opt] ?? '') . "</div>";
                                            }
                                            break;
                                        case 'true_false':
                                            $stmt = $conn->prepare("SELECT correct_answer FROM true_false_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer'] ?? '') . "</div>";
                                            break;
                                        case 'fill_blanks':
                                            $stmt = $conn->prepare("SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?");
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer'] ?? '') . "</div>";
                                            break;
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No questions available to preview.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <!-- <script src="../js/questionType.js"></script> -->
    <script>
document.getElementById("classSelect").addEventListener("change", function () {
    let classLevel = this.value;

    const subjectSelect = document.getElementById("subjectSelect");
    subjectSelect.innerHTML = `<option>Loading subjects...</option>`;

    fetch("get_subjects.php?class_level=" + classLevel)
        .then(res => res.json())
        .then(data => {
            subjectSelect.innerHTML = `<option value="">Select Subject</option>`;
            data.forEach(sub => {
                subjectSelect.innerHTML += `<option value="${sub.id}" data-class="${sub.class_level}">${sub.subject_name}</option>`;
            });
            subjectSelect.disabled = false;
        })
        .catch(() => {
            subjectSelect.innerHTML = `<option>Error loading subjects</option>`;
        });
    });
    </script>
    <script>
        const editData = <?php echo json_encode($edit_data); ?>;
        const questionTemplates = {
            multiple_choice_single: `
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleImageBtn">
                        <i class="fas fa-image"></i> Add Image to Question
                    </button>
                    <div id="imageUploadContainer">
                        <label class="form-label mt-3">Question Image</label>
                        <input type="file" class="form-control" name="question_image" accept="image/*">
                        ${editData.options?.image_path ? `
                            <div class="mt-2">
                                <p>Current Image:</p>
                                <img src="../${editData.options.image_path}" style="max-height: 100px;" class="img-thumbnail">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_image" id="removeImage">
                                    <label class="form-check-label" for="removeImage">Remove current image</label>
                                </div>
                            </div>
                        ` : ''}
                        <small class="text-muted">Optional. Max size: 2MB. Formats: JPG, PNG, GIF</small>
                    </div>
                </div>
                <div class="option-group">
                    ${[1, 2, 3, 4].map(i => `
                        <div class="mb-3 option-highlight">
                            <label class="form-label">Option ${i}</label>
                            <input type="text" class="form-control" name="option${i}" required placeholder="Enter option ${i}" value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '"') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answer</label>
                        <select class="form-select" name="correct_answer" required>
                            <option value="">Select Correct Answer</option>
                            ${[1, 2, 3, 4].map(i => `
                                <option value="${i}" ${editData.options.correct_answer && editData.options['option' + editData.options.correct_answer] === editData.options['option' + i] ? 'selected' : ''}>Option ${i}</option>
                            `).join('')}
                        </select>
                    </div>
                </div>
            `,
            multiple_choice_multiple: `
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleImageBtn">
                        <i class="fas fa-image"></i> Add Image to Question
                    </button>
                    <div id="imageUploadContainer">
                        <label class="form-label mt-3">Question Image</label>
                        <input type="file" class="form-control" name="question_image" accept="image/*">
                        ${editData.options?.image_path ? `
                            <div class="mt-2">
                                <p>Current Image:</p>
                                <img src="../${editData.options.image_path}" style="max-height: 100px;" class="img-thumbnail">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_image" id="removeImage">
                                    <label class="form-check-label" for="removeImage">Remove current image</label>
                                </div>
                            </div>
                        ` : ''}
                        <small class="text-muted">Optional. Max size: 2MB. Formats: JPG, PNG, GIF</small>
                    </div>
                </div>
                <div class="option-group">
                    ${[1, 2, 3, 4].map(i => `
                        <div class="mb-3 option-highlight">
                            <label class="form-label">Option ${i}</label>
                            <input type="text" class="form-control" name="option${i}" required placeholder="Enter option ${i}" value="${editData.options['option' + i] ? editData.options['option' + i].replace(/"/g, '"') : ''}">
                        </div>
                    `).join('')}
                    <div class="mb-3">
                        <label class="form-label">Correct Answers</label>
                        <div class="form-check">
                            ${[1, 2, 3, 4].map(i => `
                                <div>
                                    <input class="form-check-input" type="checkbox" name="correct_answers[]" value="${i}" id="correct${i}" ${editData.correct_answers.includes(i) ? 'checked' : ''}>
                                    <label class="form-check-label" for="correct${i}">Option ${i}</label>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `,
            true_false: `
                <div class="mb-3">
                    <label class="form-label">Correct Answer</label>
                    <select class="form-select" name="correct_answer" required>
                        <option value="">Select Correct Answer</option>
                        <option value="True" ${editData.options?.correct_answer === 'True' ? 'selected' : ''}>True</option>
                        <option value="False" ${editData.options?.correct_answer === 'False' ? 'selected' : ''}>False</option>
                    </select>
                </div>
            `,
            fill_blanks: `
                <div class="mb-3">
                    <label class="form-label">Correct Answer</label>
                    <input type="text" class="form-control" name="correct_answer" required placeholder="Enter the correct answer" value="${editData.options?.correct_answer ? editData.options.correct_answer.replace(/"/g, '"') : ''}">
                </div>
            `
        };

        function updateOptionsContainer() {
            const questionTypeSelect = document.getElementById('questionType');
            const optionsContainer = document.getElementById('optionsContainer');
            if (questionTypeSelect && optionsContainer) {
                const questionType = questionTypeSelect.value || 'multiple_choice_single'; // Default to single choice
                optionsContainer.innerHTML = questionTemplates[questionType] || '';
                // Re-initialize image toggle functionality
                $(document).on('click', '#toggleImageBtn', function() {
                    const container = document.getElementById('imageUploadContainer');
                    if (container) {
                        container.style.display = container.style.display === 'none' ? 'block' : 'none';
                    }
                });
            }
        }

        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Initialize question form with default question type
            const questionTypeSelect = document.getElementById('questionType');
            if (questionTypeSelect) {
                // Force default to multiple_choice_single if not editing or no specific type is set
                if (!editData.question_type || !editData.question_type.trim()) {
                    questionTypeSelect.value = 'multiple_choice_single';
                }
                updateOptionsContainer(); // Initial load
            }

            // Update options when question type changes
            $('#questionType').on('change', updateOptionsContainer);

            // Handle form reset to restore default options
            $('#questionForm').on('reset', function() {
                setTimeout(() => {
                    const questionTypeSelect = document.getElementById('questionType');
                    if (questionTypeSelect) {
                        questionTypeSelect.value = 'multiple_choice_single'; // Reset to default
                        updateOptionsContainer(); // Re-render options
                    }
                }, 0); // Use setTimeout to ensure reset completes first
            });

            // Handle form submission to maintain state
            $('#questionForm').on('submit', function() {
                return true; // Allow form submission
            });

            // Image toggle
            $(document).on('click', '#toggleImageBtn', function() {
                const container = document.getElementById('imageUploadContainer');
                if (container) {
                    container.style.display = container.style.display === 'none' ? 'block' : 'none';
                }
            });

            // Class-subject mapping
            const classSubjectMapping = <?php echo json_encode($class_mapping); ?>;
            const assignedSubjects = <?php echo json_encode($assigned_subjects); ?>;

            // Update subjects when class changes
            $('#classSelect').on('change', function() {
                const selectedClass = this.value;
                const subjectSelect = document.getElementById('subjectSelect');
                if (subjectSelect) {
                    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
                    if (selectedClass && classSubjectMapping[selectedClass]) {
                        classSubjectMapping[selectedClass].filter(subject => assignedSubjects.includes(subject)).forEach(subject => {
                            const option = document.createElement('option');
                            option.value = subject;
                            option.textContent = subject;
                            subjectSelect.appendChild(option);
                        });
                    }
                }
            });

            // Form validation for Test Creation Form
            $('#testForm').validate({
                rules: {
                    test_title: { required: true },
                    class: { required: true },
                    subject: { required: true },
                    duration: { required: true, number: true, min: 1 }
                },
                messages: {
                    duration: { min: "Duration must be at least 1 minute." }
                },
                errorPlacement: function(error, element) {
                    error.appendTo(element.closest('.form-group-spacing'));
                }
            });

            // Form validation for File Upload Form
            $('#uploadForm').validate({
                rules: {
                    json_file: { required: true, accept: "application/json,.json" }
                },
                messages: {
                    json_file: { required: "Please select a file.", accept: "Please upload a valid JSON file." }
                },
                errorPlacement: function(error, element) {
                    error.appendTo(element.closest('.mb-4'));
                }
            });

            // Form validation for Select Test Form
            $('#selectTestForm').validate({
                rules: {
                    test_id: { required: true }
                },
                messages: {
                    test_id: "Please select a test."
                },
                errorPlacement: function(error, element) {
                    error.appendTo(element.closest('.form-group-spacing'));
                }
            });

            // Form validation for Question Form
            $('#questionForm').validate({
                rules: {
                    question_type: { required: true },
                    question: { required: true },
                    option1: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    option2: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    option3: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    option4: { required: { depends: () => $('#questionType').val().startsWith('multiple_choice') } },
                    correct_answer: { required: true },
                    'correct_answers[]': { required: { depends: () => $('#questionType').val() === 'multiple_choice_multiple' } }
                },
                messages: {
                    'correct_answers[]': "At least one correct answer is required."
                },
                errorPlacement: function(error, element) {
                    error.appendTo(element.closest('.form-group-spacing'));
                }
            });

            // Preview modal debug
            $('#previewButton').on('click', function() {
                if ($(this).hasClass('preview-disabled')) {
                    console.log('Preview disabled: No test selected.');
                    return false;
                }
                try {
                    $('#previewModal').modal('show');
                } catch (e) {
                    console.error('Error opening preview modal:', e);
                }
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>