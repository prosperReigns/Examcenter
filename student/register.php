<?php
session_start();
require_once '../db.php';

$error = '';
$conn = Database::getInstance()->getConnection();
if (!$conn) {
    $error = "Database connection failed.";
}

// Generate CSRF token
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

// Available classes
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];

// === 1. Fetch Academic Years from academic_years table ===
$academic_years = [];
$stmt = $conn->query("SELECT year FROM academic_years ORDER BY year DESC");
while ($row = $stmt->fetch_assoc()) {
    $academic_years[] = $row['year'];
}

// === 2. Fetch UNIQUE test titles only ===
$unique_test_titles = [];
$stmt = $conn->query("SELECT DISTINCT title FROM tests WHERE title != '' AND title IS NOT NULL ORDER BY title");
while ($row = $stmt->fetch_assoc()) {
    $unique_test_titles[] = $row['title'];
}

// === 3. Fetch subjects grouped by class level ===
$jss_subjects = $ss_subjects = [];
$stmt = $conn->prepare("SELECT subject_name, class_level FROM subjects ORDER BY subject_name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (strtoupper($row['class_level']) === 'JSS') {
        $jss_subjects[] = $row['subject_name'];
    } else {
        $ss_subjects[] = $row['subject_name'];
    }
}
$stmt->close();

// === 4. Get active subjects for today (optional security layer) ===
$today = date('Y-m-d');
$active_subjects = [];
$stmt = $conn->prepare("SELECT LOWER(subject) as subject FROM active_exams WHERE is_active = 1 AND exam_date = ?");
if ($stmt) {
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $active_subjects[] = $row['subject'];
    }
    $stmt->close();
}

// Form values
$name = $class = $subject = $test_title = $exam_year = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name       = trim($_POST['name'] ?? '');
    $class      = $_POST['class'] ?? '';
    $subject    = trim($_POST['subject'] ?? '');
    $test_title = trim($_POST['test_title'] ?? '');
    $exam_year  = trim($_POST['exam_year'] ?? '');

    // CSRF check
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Security error. Please try again.";
    }
    elseif (empty($name) || empty($class) || empty($subject) || empty($test_title) || empty($exam_year)) {
        $error = "All fields are required.";
    }
    elseif (!preg_match("/^[a-zA-Z\s]+$/", $name)) {
        $error = "Name can only contain letters and spaces.";
    }
    elseif (!in_array($class, $classes)) {
        $error = "Invalid class selected.";
    }
    elseif (!in_array($exam_year, $academic_years)) {
        $error = "Invalid academic year.";
    }
    elseif (!in_array($test_title, $unique_test_titles)) {
        $error = "Invalid exam selected.";
    }
    else {
        // Final validation: does this exact test exist with class + subject + year?
        $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND class = ? AND subject = ? AND year = ?");
        $stmt->bind_param("ssss", $test_title, $class, $subject, $exam_year);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $error = "No exam found for the selected combination.";
        } else {
            // Register student
            $stmt2 = $conn->prepare("INSERT INTO students (name, class) VALUES (?, ?)");
            $stmt2->bind_param("ss", $name, $class);
            if ($stmt2->execute()) {
                $_SESSION['student_id']      = $conn->insert_id;
                $_SESSION['student_name']    = $name;
                $_SESSION['student_class']   = $class;
                $_SESSION['student_subject'] = $subject;
                $_SESSION['test_title']      = $test_title;
                $_SESSION['exam_year']       = $exam_year;

                header("Location: take_exam.php");
                exit();
            } else {
                $error = "Registration failed. Try again.";
            }
            $stmt2->close();
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - CBT Exam</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .card { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white text-center">
                    <h3>Student Registration</h3>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <!-- Academic Year -->
                        <div class="mb-3">
                            <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                            <select name="exam_year" class="form-select" required>
                                <option value="">-- Select Year --</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= htmlspecialchars($year) ?>" <?= $exam_year==$year?'selected':'' ?>>
                                        <?= htmlspecialchars($year) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Exam Title (Unique only) -->
                        <div class="mb-3">
                            <label class="form-label">Exam Title <span class="text-danger">*</span></label>
                            <select name="test_title" class="form-select" required>
                                <option value="">-- Select Exam --</option>
                                <?php foreach ($unique_test_titles as $title): ?>
                                    <option value="<?= htmlspecialchars($title) ?>" <?= $test_title==$title?'selected':'' ?>>
                                        <?= htmlspecialchars($title) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Full Name -->
                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name) ?>" required>
                        </div>

                        <!-- Class -->
                        <div class="mb-3">
                            <label class="form-label">Class <span class="text-danger">*</span></label>
                            <select name="class" id="class" class="form-select" required onchange="updateSubjects()">
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c ?>" <?= $class==$c?'selected':'' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Subject -->
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <select name="subject" id="subject" class="form-select" required>
                                <option value="">-- Select Subject --</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Start Exam</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Pass data to JavaScript
const jssSubjects = <?= json_encode(array_map('strtolower', $jss_subjects)) ?>;
const ssSubjects  = <?= json_encode(array_map('strtolower', $ss_subjects)) ?>;
const activeSubjects = <?= json_encode($active_subjects) ?>; // optional filter

function updateSubjects() {
    const classVal = document.getElementById('class').value;
    const subjectSelect = document.getElementById('subject');
    subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';

    if (!classVal) return;

    const allowed = classVal.startsWith('JSS') ? jssSubjects : ssSubjects;

    allowed.forEach(subj => {
        // Optional: only show if active today
        if (activeSubjects.length === 0 || activeSubjects.includes(subj.toLowerCase())) {
            const opt = document.createElement('option');
            opt.value = subj;
            opt.textContent = subj.charAt(0).toUpperCase() + subj.slice(1);
            subjectSelect.appendChild(opt);
        }
    });

    if (subjectSelect.options.length === 1) {
        const opt = document.createElement('option');
        opt.disabled = true;
        opt.textContent = 'No subjects available';
        subjectSelect.appendChild(opt);
    }
}

// Run on load
document.addEventListener('DOMContentLoaded', updateSubjects);
</script>

<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>