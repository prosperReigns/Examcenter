<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';
require_once '../includes/bootstrap.php';

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
    header("Location: ../login.php?error=Unauthorized");
    exit();
}

/**
 * VALIDATE STUDENT ID
 */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid student selected.");
}

$student_id = (int) $_GET['id'];

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
        header("Location: ../login.php?error=Unauthorized");
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
    
// ===== FETCH STUDENT (John) =====
$placeholders = implode(',', array_fill(0, count($assigned_class_ids), '?'));
$types = str_repeat('i', count($assigned_class_ids));

$sql = "
SELECT 
    s.id,
    s.full_name,
    s.email,
    s.phone,
    s.address,
    s.class AS class_id,          -- s.class stores the numeric ID
    c.class_name AS full_class_name
FROM students s
JOIN classes c ON c.id = s.class  -- join on IDs
WHERE s.id = ?
";
$stmt = $conn->prepare($sql);

// Bind only student_id
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ===== CHECK IF TEACHER CAN ACCESS JOHN =====
if (!in_array((int)$student['class_id'], $assigned_class_ids)) {
    die("You are not authorized to view this student.");
}

    if (!$student) {
        die("You are not authorized to view this student.");
    }

    /**
     * FETCH ACTIVE ACADEMIC YEAR / TERM
     */
    $stmt = $conn->prepare("
        SELECT id, year, session, exam_title
        FROM academic_years
        WHERE status = 'active' AND session IS NOT NULL
        ORDER BY id DESC
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
     * FETCH TESTS FOR THIS CLASS
     * (Only tests that already exist)
     */

    $stmt = $conn->prepare("
    SELECT
        t.id,
        t.title,
        t.subject
    FROM tests t
    JOIN classes c
        ON c.academic_level_id = t.academic_level_id
    WHERE c.id = ?
    AND t.year = ?
    ORDER BY t.created_at
    ");

    $stmt->bind_param(
        "is",
        $student['class_id'],
        $active_term['year']
    );

    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
    SELECT DISTINCT
        t.id,
        t.title
    FROM tests t
    JOIN classes c
        ON c.academic_level_id=t.academic_level_id
    WHERE c.id=?
    AND t.year=?
    ORDER BY t.created_at
    ");

    $stmt->bind_param(
        "is",
        $student['class_id'],
        $active_term['year']
    );

    $stmt->execute();
    $tests=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt=$conn->prepare("
    SELECT
        s.id AS subject_id,
        s.subject_name,
        t.id AS test_id,
        t.title,
        r.score,
        r.total_questions
    FROM results r
    JOIN tests t
    ON r.test_id=t.id
    JOIN subjects s
    ON LOWER(TRIM(s.subject_name))
    =
    LOWER(TRIM(
    REPLACE(
    SUBSTRING_INDEX(t.subject,'(',1),
    ')',
    ''
    )
    ))
    JOIN classes c
    ON c.academic_level_id=t.academic_level_id
    WHERE
    r.user_id=?
    AND c.id=?
    AND t.year=?
    ORDER BY
    s.subject_name,
    t.created_at
    ");

    $stmt->bind_param(
        "iis",
        $student_id,
        $student['class_id'],
        $active_term['year']
    );

    $stmt->execute();

    $reportRows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    $subjects=[];
    $report=[];

    foreach($reportRows as $row){
        $subjects[$row['subject_id']]=$row['subject_name'];
        $report[$row['subject_id']][$row['test_id']]=[
            'score'=>$row['score'],
            'total'=>$row['total_questions']
        ];
    }
} catch (Exception $e) {
    error_log("Student profile error: " . $e->getMessage());
    echo "<pre>System error: " . $e->getMessage() . "</pre>"; 
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
        .profile-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.08);
        }
        .profile-photo{
            width:150px;
            height:150px;
            object-fit:cover;
            border-radius:50%;
            border:5px solid #fff;
            box-shadow:0 4px 15px rgba(0,0,0,.2);
        }
        .profile-label{
            font-size:.85rem;
            color:#6c757d;
            margin-bottom:2px;
        }
        .profile-value{
            font-weight:600;
            margin-bottom:15px;
        }
        .page-title{
            font-weight:700;
        }
        .page-subtitle{
            color:#6c757d;
            font-size:.9rem;
        }
        .info-icon{
            width:20px;
            color:#0d6efd;
        }
        .summary-card{
            border:none;
            border-radius:15px;
            box-shadow:0 .5rem 1rem rgba(0,0,0,.08);
            transition:.25s;
        }

        .summary-card:hover{
            transform:translateY(-3px);
        }
        .summary-icon{
            width:60px;
            height:60px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:24px;
            color:#fff;
        }
        .summary-number{
            font-size:28px;
            font-weight:700;
            margin-bottom:0;
        }
        .summary-title{
            color:#6c757d;
            margin-bottom:4px;
        }
        .bg-average{
            background:#0d6efd;
        }
        .bg-subject{
            background:#198754;
        }
        .bg-highest{
            background:#ffc107;
            color:#000;
        }
        .bg-lowest{
            background:#dc3545;
        }
        .percentage-good{
            color:#198754;
        }
        .percentage-average{
            color:#ffc107;
        }
        .percentage-poor{
            color:#dc3545;
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
            <a href="manage_classroom.php"><i class="fas fa-users"></i>Manage Classroom</a>
            <a href="manage_students.php"><i class="fas fa-users"></i>Manage Students</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="my-profile.php"><i class="fas fa-user"></i>My Profile</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div class="d-flex align-items-center gap-3">
            <a href="manage_students.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i>
            </a>

            <div>
                <h2 class="page-title mb-0">
                    <i class="fas fa-user-graduate text-primary me-2"></i>
                    Student Profile
                </h2>

                <div class="page-subtitle">
                    View academic information, assessment records and student profile.
                </div>
            </div>
        </div>

        <button class="btn btn-primary d-lg-none" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
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
        <div class="card profile-card mb-4">
            <div class="card-header bg-white border-bottom">
                <h5 class="mb-0">
                    <i class="fas fa-id-card text-primary me-2"></i>
                    Student Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-lg-3 text-center">
                        <img
                            src="uploads/students/default.png"
                            class="profile-photo mb-3"
                            alt="Student">

                        <h4 class="fw-bold mb-1">
                            <?= htmlspecialchars($student['full_name']) ?>
                        </h4>

                        <span class="badge bg-primary px-3 py-2">
                            <?= htmlspecialchars($student['full_class_name']) ?>
                        </span>
                    </div>

                    <div class="col-lg-9">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="profile-label">
                                    <i class="fas fa-school info-icon"></i>
                                    Class
                                </div>

                                <div class="profile-value">
                                    <?= htmlspecialchars($student['full_class_name']) ?>
                                </div>

                            </div>

                            <div class="col-md-6">
                                <div class="profile-label">
                                    <i class="fas fa-calendar-alt info-icon"></i>
                                    Academic Session
                                </div>

                                <div class="profile-value">
                                    <?= htmlspecialchars($active_term['year']) ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="profile-label">
                                    <i class="fas fa-layer-group info-icon"></i>
                                    Current Term
                                </div>

                                <div class="profile-value">
                                    <?= htmlspecialchars($active_term['session']) ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="profile-label">
                                    <i class="fas fa-envelope info-icon"></i>
                                    Parent Email
                                </div>

                                <div class="profile-value">
                                    <?= htmlspecialchars($student['email'] ?: 'Not Available') ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="profile-label">
                                    <i class="fas fa-phone info-icon"></i>
                                    Parent Phone
                                </div>

                                <div class="profile-value">
                                    <?= htmlspecialchars($student['phone'] ?: 'Not Available') ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="profile-label">
                                    <i class="fas fa-map-marker-alt info-icon"></i>
                                    Address
                                </div>

                                <div class="profile-value">
                                    <?= htmlspecialchars($student['address'] ?: 'Not Available') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ================= SUMMARY ================= -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card summary-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="summary-icon bg-average me-3">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <div class="summary-title">
                                Overall Average
                            </div>
                            <h3
                                class="summary-number"
                                id="summaryAverage">
                                0%
                            </h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="card summary-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="summary-icon bg-subject me-3">
                            <i class="fas fa-book"></i>
                        </div>
                        <div>
                            <div class="summary-title">
                                Subjects
                            </div>
                            <h3
                                class="summary-number"
                                id="summarySubjects">
                                <?= count($subjects) ?>
                            </h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="card summary-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="summary-icon bg-highest me-3">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div>
                            <div class="summary-title">
                                Highest Score
                            </div>
                            <h3
                                class="summary-number"
                                id="summaryHighest">
                                0
                            </h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="card summary-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="summary-icon bg-lowest me-3">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div>
                            <div class="summary-title">
                                Lowest Score
                            </div>
                            <h3
                                class="summary-number"
                                id="summaryLowest">
                                0
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= TERM SWITCH ================= -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-calendar-check me-2 text-primary"></i>
                            Select Academic Term
                        </label>

                        <select
                            id="termSwitcher"
                            class="form-select"
                            data-student-id="<?= $student_id ?>">

                            <option value="<?= $active_term['id'] ?>">
                                <?= htmlspecialchars($active_term['session']) ?>
                            </option>

                        </select>
                    </div>
                </div>
            </div>
        </div>

       <!-- ================= STUDENT PERFORMANCE ================= -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line text-primary me-2"></i>
                        Student Performance
                    </h5>
                    <small class="text-muted">
                        <?= htmlspecialchars($active_term['year']) ?>
                        •
                        <?= htmlspecialchars($active_term['session']) ?>
                    </small>
                </div>
                <span class="badge bg-primary fs-6">
                    <?= count($subjects) ?> Subjects
                </span>
            </div>

            <div class="card-body p-0">
                <?php if(empty($subjects)): ?>
                    <div class="text-center p-5">
                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                        <h5>No Subjects Found</h5>
                        <p class="text-muted">
                            No subjects have been assigned to this class.
                        </p>
                    </div>

                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width:220px;">Subject</th>
                            <?php foreach($tests as $test): ?>
                                <th class="text-center">
                                    <?= htmlspecialchars($test['title']) ?>
                                    <br>
                                    <small class="text-muted">
                                        / <?= $test['max_score'] ?>
                                    </small>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center">Total</th>
                            <th class="text-center">Grade</th>
                        </tr>
                        </thead>
                        <tbody>
                            <?php foreach($subjects as $subjectId=>$subjectName): ?>

                            <tr>
                            <td>
                                <strong>
                                <?= htmlspecialchars($subjectName) ?>
                                </strong>
                            </td>
                            <?php $totalScore=0; $totalPossible=0;?>
                            <?php foreach($tests as $test): ?>
                            <?php
                            $item=$report[$subjectId][$test['id']]??null;
                            ?>
                            <td class="text-center">
                                <?php
                                if($item){
                                    echo $item['score'].'/'.$item['total'];
                                    $totalScore+=$item['score'];
                                    $totalPossible+=$item['total'];
                                }else{
                                    echo '-';
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-center fw-bold">
                                <?= $totalScore ?>
                            </td>
                            <td class="text-center">
                                <?php
                                $percent=$totalPossible
                                ?($totalScore/$totalPossible)*100
                                :0;
                                if($percent>=85) echo "A";
                                elseif($percent>=75) echo "B";
                                elseif($percent>=65) echo "C";
                                elseif($percent>=50) echo "D";
                                else echo "F";
                                ?>
                            </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
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
            // ======== DOWNLOAD / EMAIL BUTTONS ========
            document.getElementById('downloadReportBtn').addEventListener('click', () => {
                window.location.href = `download_student_report.php?student_id=${studentId}&academic_year_id=${termSwitcher.value}`;
            });

            document.getElementById('emailReportBtn').addEventListener('click', () => {
                fetch(`email_student_report.php`, {
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