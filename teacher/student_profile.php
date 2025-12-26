<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

header('Content-Type: text/html; charset=UTF-8');

// Enable error reporting (dev)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

/**
 * AUTH CHECK
 */
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'teacher'
) {
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

/**
 * VALIDATE STUDENT ID
 */
if (!isset($_GET['student_id']) || !is_numeric($_GET['student_id'])) {
    die("Invalid student selected.");
}

$student_id = (int) $_GET['student_id'];

try {
    /**
     * DB CONNECTION
     */
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("DB Connection failed: " . $conn->connect_error);
    }

    $error = $success = '';

    /**
     * FETCH TEACHER
     */
    $teacher_id = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name FROM teachers WHERE id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    /**
     * FETCH ASSIGNED CLASSES
     */
    $stmt = $conn->prepare("
        SELECT class_id
        FROM teacher_classes
        WHERE teacher_id = ?
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $assigned_class_ids = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_class_ids[] = (int) $row['class_id'];
    }
    $stmt->close();

    if (empty($assigned_class_ids)) {
        die("You are not assigned to any class.");
    }

    /**
     * PREPARE PLACEHOLDERS
     */
    $placeholders = implode(',', array_fill(0, count($assigned_class_ids), '?'));
    $types = str_repeat('i', count($assigned_class_ids));

    /**
     * FETCH & AUTHORIZE STUDENT
     */
    // $stmt = $conn->prepare("
    //     SELECT 
    //         s.id,
    //         s.name,
    //         s.email,
    //         s.class,
    //         c.id AS class_id,
    //         c.class_name
    //     FROM students s
    //     JOIN classes c ON c.class_name = s.class
    //     WHERE s.id = ?
    //       AND c.id IN ($placeholders)
    // ");

    $stmt = $conn->prepare("
        SELECT 
            s.id,
            s.name,
            s.email,
            s.class AS class_id,
            c.class_name
        FROM students s
        JOIN classes c ON c.id = s.class
        WHERE s.id = ?
        AND s.class IN ($placeholders)
    ");

    $stmt->bind_param(
        "i" . $types,
        $student_id,
        ...$assigned_class_ids
    );

    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        die("You are not authorized to view this student.");
    }

    /**
     * FETCH ACTIVE ACADEMIC YEAR / TERM
     */
    $stmt = $conn->prepare("
        SELECT id, year, session
        FROM academic_years
        WHERE status = 'active'
        LIMIT 1
    ");
    $stmt->execute();
    $active_term = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$active_term) {
        die("No active academic term found.");
    }

    $academic_year_id = (int) $active_term['id'];

    /**
     * FETCH SUBJECTS FOR STUDENT CLASS
     */
    $stmt = $conn->prepare("
        SELECT s.id, s.subject_name
        FROM subjects s
        JOIN subject_levels sl ON sl.subject_id = s.id
        JOIN academic_levels al ON al.class_group = sl.class_level
        JOIN classes c ON c.academic_level_id = al.id
        WHERE c.id = ?
        ORDER BY s.subject_name
    ");
    $stmt->bind_param("i", $student['class_id']);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * FETCH EXISTING SCORES
     */
    $stmt = $conn->prepare("
        SELECT *
        FROM student_subject_scores
        WHERE student_id = ?
          AND academic_year_id = ?
    ");
    $stmt->bind_param("ii", $student_id, $academic_year_id);
    $stmt->execute();

    $scores_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $scores = [];
    foreach ($subjects as $subject) {
        $subject_id = $subject['id'];

        // Initialize scores array
        $scores[$subject_id] = [
            'subject_id' => $subject_id,
            'ca1' => 0,
            'ca2' => 0,
            'ca3' => 0,
            'ca4' => 0,
            'exam' => 0
        ];

        // Populate from student_subject_scores if available
        foreach ($scores_raw as $row) {
            if ($row['subject_id'] == $subject_id) {
                $scores[$subject_id] = [
                    'subject_id' => $subject_id,
                    'ca1' => (int)$row['ca1'],
                    'ca2' => (int)$row['ca2'],
                    'ca3' => (int)$row['ca3'],
                    'ca4' => (int)$row['ca4'],
                    'exam' => (int)$row['exam_score']
                ];
            }
        }

        // Auto-populate exam score from `results` table if empty
        if ($scores[$subject_id]['exam'] === 0) {
            $stmt_exam = $conn->prepare("
                SELECT score
                FROM results r
                JOIN tests t ON t.id = r.test_id
                WHERE r.user_id = ? 
                AND t.subject = ? 
                AND t.year = ?
                LIMIT 1
            ");
            $stmt_exam->bind_param('iss', $student_id, $subject['subject_name'], $active_term['year']);
            $stmt_exam->execute();
            $exam_result = $stmt_exam->get_result()->fetch_assoc();
            $stmt_exam->close();

            if ($exam_result) {
                $scores[$subject_id]['exam'] = (int)$exam_result['score'];
            }
        }
    }

} catch (Exception $e) {
    error_log("Student profile error: " . $e->getMessage());
    die("System error occurred. Please try again later.");
}
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
        .score-input {
            width: 70px;
            text-align: center;
        }
        .total-cell {
            font-weight: bold;
        }
        .grade-cell {
            font-weight: bold;
        }
        .profile-card img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
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

        <!-- ================= STUDENT PROFILE ================= -->
        <div class="card mb-4 profile-card">
            <div class="card-body d-flex align-items-center gap-4">
                <img src="/EXAMCENTER/uploads/students/default.png" alt="Student Photo">
                <div>
                    <h4><?= htmlspecialchars($student['name']) ?></h4>
                    <p class="mb-1"><strong>Class:</strong> <?= htmlspecialchars($student['class_name']) ?></p>
                    <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($student['email']) ?></p>
                    <p class="mb-0">
                        <strong>Academic Session:</strong>
                        <?= htmlspecialchars($active_term['year']) ?> —
                        <?= htmlspecialchars($active_term['session']) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- ================= TERM SWITCH ================= -->
        <div class="mb-3">
            <label class="form-label"><strong>Select Term</strong></label>
            <!-- Replace existing select -->
            <select id="termSwitcher" class="form-select w-auto" data-student-id="<?= $student_id ?>">
                <option value="<?= $active_term['id'] ?>"><?= htmlspecialchars($active_term['session']) ?></option>
                <!-- other terms will be dynamically loaded -->
            </select>
        </div>

        <!-- ================= RESULT TABLE ================= -->
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3">Student Performance</h5>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th>Subject</th>
                                <th>1st CA (10)</th>
                                <th>2nd CA (10)</th>
                                <th>3rd CA (10)</th>
                                <th>4th CA (10)</th>
                                <th>Exam (60)</th>
                                <th>Total (100)</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>

                        <?php foreach ($subjects as $subject): 
                            $subjectScore = $scores[$subject['id']] ?? [];
                        ?>
                            <tr data-subject-id="<?= $subject['id'] ?>">
                                <td class="text-start"><?= htmlspecialchars($subject['subject_name']) ?></td>

                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td>
                                        <input
                                            type="number"
                                            class="form-control score-input ca-score"
                                            min="0"
                                            max="10"
                                            value="<?= $subjectScore["ca$i"] ?? '' ?>"
                                        >
                                    </td>
                                <?php endfor; ?>

                                <td>
                                    <input
                                        type="number"
                                        class="form-control score-input exam-score"
                                        min="0"
                                        max="60"
                                        value="<?= $subjectScore['exam'] ?? '' ?>"
                                    >
                                </td>

                                <td class="total-cell">0</td>
                                <td class="grade-cell">-</td>
                            </tr>
                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= TEACHER REMARK ================= -->
        <div class="card mt-4">
            <div class="card-body">
                <h5>Teacher’s Remark</h5>
                <textarea
                    class="form-control"
                    rows="4"
                    placeholder="Enter remark for this student..."
                ></textarea>
            </div>
        </div>

        <!-- ================= SUMMARY & ACTIONS ================= -->
        <div class="card mt-4">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">

                <div>
                    <h5>Final Percentage</h5>
                    <p class="fs-4 fw-bold" id="finalPercentage">0%</p>
                </div>

                <div class="d-flex gap-2">
                <button class="btn btn-success" id="saveResultsBtn">Save Results</button>
                <button class="btn btn-primary" id="downloadReportBtn">Download Report</button>
                <button class="btn btn-secondary" id="emailReportBtn">Email Report</button>
                </div>

            </div>
        </div>

    </div>

    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });
        });
    </script>
   <script>
        document.addEventListener('DOMContentLoaded', () => {

            const gradeScale = [
                { min: 85, grade: 'A' },
                { min: 75, grade: 'B' },
                { min: 65, grade: 'C' },
                { min: 50, grade: 'D' },
                { min: 0, grade: 'F' }
            ];

            const finalPercentageEl = document.getElementById('finalPercentage');
            const saveBtn = document.getElementById('saveResultsBtn');
            const termSwitcher = document.getElementById('termSwitcher');
            const studentId = termSwitcher.dataset.studentId;

            // ======== CALCULATION FUNCTIONS ========
            function calculateRow(row) {
                let total = 0;
                row.querySelectorAll('.ca-score').forEach(input => total += parseFloat(input.value) || 0);
                total += parseFloat(row.querySelector('.exam-score').value) || 0;
                row.querySelector('.total-cell').textContent = total;

                const gradeCell = row.querySelector('.grade-cell');
                for (let g of gradeScale) {
                    if (total >= g.min) {
                        gradeCell.textContent = g.grade;
                        break;
                    }
                }
            }

            function calculateFinalPercentage() {
                const rows = document.querySelectorAll('tbody tr');
                let totalScore = 0, maxScore = 0;
                rows.forEach(row => {
                    totalScore += parseFloat(row.querySelector('.total-cell').textContent) || 0;
                    maxScore += 100;
                });
                finalPercentageEl.textContent = maxScore ? ((totalScore / maxScore) * 100).toFixed(2) + '%' : '0%';
            }

            // ======== INITIAL CALCULATION ========
            document.querySelectorAll('tbody tr').forEach(row => calculateRow(row));
            calculateFinalPercentage();

            // ======== INPUT CHANGE EVENTS ========
            document.querySelectorAll('.ca-score, .exam-score').forEach(input => {
                input.addEventListener('input', () => {
                    const row = input.closest('tr');
                    calculateRow(row);
                    calculateFinalPercentage();
                });
            });

            // ======== SAVE RESULTS AJAX ========
            saveBtn.addEventListener('click', () => {
                const scores = [];
                document.querySelectorAll('tbody tr').forEach(row => {
                    const subjectId = row.dataset.subjectId;
                    const caInputs = row.querySelectorAll('.ca-score');
                    const ca1 = caInputs[0].value || 0;
                    const ca2 = caInputs[1].value || 0;
                    const ca3 = caInputs[2].value || 0;
                    const ca4 = caInputs[3].value || 0;

                    // const ca1 = row.querySelector('.ca-score:nth-of-type(1)').value || 0;
                    // const ca2 = row.querySelector('.ca-score:nth-of-type(2)').value || 0;
                    // const ca3 = row.querySelector('.ca-score:nth-of-type(3)').value || 0;
                    // const ca4 = row.querySelector('.ca-score:nth-of-type(4)').value || 0;
                    const exam = row.querySelector('.exam-score').value || 0;
                    scores.push({ subject_id: subjectId, ca1, ca2, ca3, ca4, exam });
                });

                const remark = document.querySelector('textarea').value;
                const termId = termSwitcher.value;

                fetch('/EXAMCENTER/ajax/save_student_scores.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ student_id: studentId, academic_year_id: termId, scores, remark })
                })
                .then(res => res.json())
                .then(data => alert(data.message))
                .catch(err => console.error(err));
            });

            // ======== TERM SWITCHING ========
            termSwitcher.addEventListener('change', () => {
                const termId = termSwitcher.value;
                fetch(`/EXAMCENTER/ajax/load_student_scores.php?student_id=${studentId}&academic_year_id=${termId}`)
                    .then(res => res.json())
                    .then(data => {
                        // Populate scores table
                        data.scores.forEach(score => {
                            const row = document.querySelector(`tr[data-subject-id="${score.subject_id}"]`);
                            if (row) {
                                row.querySelectorAll('.ca-score')[0].value = score.ca1 || '';
                                row.querySelectorAll('.ca-score')[1].value = score.ca2 || '';
                                row.querySelectorAll('.ca-score')[2].value = score.ca3 || '';
                                row.querySelectorAll('.ca-score')[3].value = score.ca4 || '';
                                row.querySelector('.exam-score').value = score.exam || '';
                                calculateRow(row);
                            }
                        });
                        calculateFinalPercentage();
                        document.querySelector('textarea').value = data.remark || '';
                    });
            });

            // ======== DOWNLOAD / EMAIL BUTTONS ========
            document.getElementById('downloadReportBtn').addEventListener('click', () => {
                window.location.href = `/EXAMCENTER/ajax/download_student_report.php?student_id=${studentId}&academic_year_id=${termSwitcher.value}`;
            });

            document.getElementById('emailReportBtn').addEventListener('click', () => {
                fetch(`/EXAMCENTER/ajax/email_student_report.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ student_id: studentId, academic_year_id: termSwitcher.value })
                })
                .then(res => res.json())
                .then(data => alert(data.message))
                .catch(err => console.error(err));
            });

        });
</script>


</body>
</html>