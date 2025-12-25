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
$classes = [];
$stmt = $conn->query("
    SELECT c.id, al.level_code, s.stream_name 
    FROM classes c
    JOIN academic_levels al ON c.academic_level_id = al.id
    JOIN streams s ON c.stream_id = s.id
    WHERE c.is_active = 1
    ORDER BY al.level_code, s.stream_name
");
while ($row = $stmt->fetch_assoc()) {
    $classes[] = [
        'id' => $row['id'],
        'name' => $row['level_code'] . ' ' . $row['stream_name']
    ];
}

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

// === 3. Fetch subjects grouped by class level (NEW STRUCTURE) ===
$jss_subjects = $ss_subjects = [];

$stmt = $conn->prepare("
    SELECT s.subject_name, sl.class_level
    FROM subject_levels sl
    JOIN subjects s ON sl.subject_id = s.id
    ORDER BY s.subject_name
");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($row['class_level'] === 'JSS') {
        $jss_subjects[] = $row['subject_name'];
    } elseif ($row['class_level'] === 'SS') {
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

$jss = [];
$ss  = [];

$stmt = $conn->query("
    SELECT s.id, s.subject_name, sl.class_level
    FROM subject_levels sl
    JOIN subjects s ON sl.subject_id = s.id
");

while ($r = $stmt->fetch_assoc()) {
    if ($r['class_level'] === 'JSS') $jss[] = $r;
    if ($r['class_level'] === 'SS')  $ss[]  = $r;
}

// Form values
$name = $class = $subject = $test_title = $exam_year = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name       = trim($_POST['name'] ?? '');
    $class      = $_POST['class'] ?? '';
    $subject    = (int)trim($_POST['subject_id'] ?? 0);
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
    $class_ids = array_column($classes, 'id');
    if (!in_array($class, $class_ids)) {
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
        $stmt = $conn->prepare("SELECT t.id 
            FROM tests t
            JOIN academic_levels al ON t.academic_level_id = al.id
            WHERE t.title = ? AND t.subject.id = ? AND t.year = ? AND al.id = (
                SELECT academic_level_id FROM classes WHERE id = ?
            )");
        $stmt->bind_param("sisi", $test_title, $subject_id, $exam_year, $class);
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
                $_SESSION['student_subject_id'] = $subject_id;
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
                                    <option value="<?= $c['id'] ?>" <?= $class==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Subject -->
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <select name="subject_id" id="subject" class="form-select" required>
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
const jssSubjects = <?= json_encode($jss) ?>;
const ssSubjects  = <?= json_encode($ss) ?>;
const activeSubjects = <?= json_encode($active_subjects) ?>; // optional filter

function updateSubjects() {
    const classVal = document.getElementById('class').value;
    const subjectSelect = document.getElementById('subject');
    subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';

    if (!classVal) return;

    const allowed = classVal.includes('JSS') ? jssSubjects : ssSubjects;

    allowed.forEach(subj => {
        const opt = document.createElement('option');
        opt.value = subj.id;
        opt.textContent = subj.subject_name;
        subjectSelect.appendChild(opt);
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