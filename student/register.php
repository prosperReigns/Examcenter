<?php
session_start();
require_once '../db.php';
require_once '../includes/universal_architecture.php';

$conn = Database::getInstance()->getConnection();
if (
    examcenterUniversalTableExists($conn, 'institutions') &&
    examcenterUniversalTableExists($conn, 'tests') &&
    examcenterUniversalColumnExists($conn, 'tests', 'institution_id')
) {
    $activeInstitutionId = examcenterActiveInstitutionId($conn);
    if ($activeInstitutionId !== null) {
        header('Location: universal_register.php', true, 302);
        exit;
    }
}

// Initialize error variable
$error = '';

// Verify database connection
$conn = Database::getInstance()->getConnection();
if (!$conn) {
    $error = "Database connection failed. Please try again later.";
}

$universal_registration_available = examcenterUniversalTableExists($conn, 'institutions')
    && examcenterUniversalTableExists($conn, 'people')
    && examcenterUniversalTableExists($conn, 'institution_memberships')
    && examcenterUniversalColumnExists($conn, 'tests', 'institution_id');
$active_institution_id = $universal_registration_available
    ? examcenterActiveInstitutionId($conn)
    : null;
$universal_registration_available = $universal_registration_available
    && $active_institution_id !== null;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Define available classes
$classes = [];
if (empty($error)) {
    $stmt = $conn->prepare("SELECT id, class_name, academic_level_id FROM classes WHERE is_active = 1 ORDER BY id ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stmt2 = $conn->prepare("SELECT class_group FROM academic_levels WHERE id = ?");
            $stmt2->bind_param("i", $row['academic_level_id']);
            $stmt2->execute();
            $level = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            
            $classes[$row['id']] = [
                'name' => $row['class_name'],
                'level_id' => $row['academic_level_id'],
                'class_group' => $level['class_group']
            ];            
        }
        $stmt->close();
    }
}

// Define subject categories
$subjects_by_class_group = [
    'JSS' => [],
    'SS' => []
];

if (empty($error)) {
    $stmt = $conn->prepare("
        SELECT s.subject_name, sl.class_level
        FROM subjects s
        JOIN subject_levels sl ON s.id = sl.subject_id
    ");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $subjects_by_class_group[$row['class_level']][] = $row['subject_name'];
        }
        $stmt->close();
    }
}

// Fetch active subjects for today
$today = date('Y-m-d');
$active_subjects = [];
if (empty($error)) {
    $stmt = $conn->prepare("SELECT subject FROM active_exams WHERE is_active = 1 AND exam_date = ?");
    if ($stmt) {
        $stmt->bind_param("s", $today);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $active_subjects[] = strtolower($row['subject']);
            }
        }
        $stmt->close();
    }
}

// Fetch tests grouped by year
$tests_by_year = [];
$available_years = [];
if (empty($error)) {
    $stmt = $conn->prepare("
        SELECT DISTINCT year, title 
        FROM tests 
        WHERE title IS NOT NULL AND title != ''
        " . ($universal_registration_available ? " AND institution_id = " . (int)$active_institution_id : '') . "
        ORDER BY year DESC, title ASC
    ");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $tests_by_year[$row['year']][] = $row['title'];
            if (!in_array($row['year'], $available_years)) {
                $available_years[] = $row['year'];
            }
        }
        $stmt->close();
    }
}

// Initialize form values
$name = $class = $subject = $test_title = $exam_year = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $class = $_POST['class'] ?? '';
    $subject = isset($_POST['subject']) ? strtolower(trim($_POST['subject'])) : '';
    $test_title = $_POST['test_title'] ?? '';
    $exam_year = $_POST['exam_year'] ?? '';

    if (empty($exam_year)) {
        $error = "Exam year is required";
    } elseif (!in_array($exam_year, $available_years)) {
        $error = "Invalid exam year selected";
    }

    if (isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
        // Validate inputs
        if (empty($name) || empty($class) || empty($subject) || empty($test_title)) {
            $error = "All fields are required";
        } elseif (!preg_match("/^[\p{L}\p{M} .'-]+$/u", $name)) {
            $error = "Enter a valid student name";
        } elseif (!array_key_exists($class, $classes)) {
            $error = "Invalid class selected";
        } elseif (!in_array(strtolower($subject), array_map('strtolower',$active_subjects))) {
            $error = "No test available for this subject today";
        } else {
                $selected_level_id = $classes[$class]['level_id'];
                // Verify class-subject-test-year combination
                $stmt = $conn->prepare("
                    SELECT class_group 
                    FROM academic_levels 
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $selected_level_id);
                $stmt->execute();
                $class_group = $stmt->get_result()->fetch_assoc()['class_group'];
                $stmt->close();

                // 🔹 BUILD DB/SYSTEM SUBJECT (mathematics (JSS) or mathematics (SS))
                $final_subject = ucfirst($subject) . ' (' . $class_group . ')';

                $test_scope = $universal_registration_available
                    ? " AND institution_id = ?"
                    : '';
                $stmt = $conn->prepare("
                    SELECT id, institution_id, organizational_unit_id, course_id, academic_period_id
                    FROM tests
                    WHERE title = ?
                    AND academic_level_id = ?
                    AND year = ?
                    {$test_scope}
                    LIMIT 1
                ");
                if ($stmt) {
                    if ($universal_registration_available) {
                        $stmt->bind_param("sisi", $test_title, $selected_level_id, $exam_year, $active_institution_id);
                    } else {
                        $stmt->bind_param("sis", $test_title, $selected_level_id, $exam_year);
                    }
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result->num_rows === 0) {
                            $error = "No test available for this combination";
                        } else {
                            $selected_test = $result->fetch_assoc();
                           
				// Insert the student directly into the students table
$stmt = $conn->prepare("INSERT INTO students (full_name, class) VALUES (?, ?)");
if ($stmt) {
    $stmt->bind_param("ss", $name, $class);
    if ($stmt->execute()) {
        unset($_SESSION['exam_preview_authorized'], $_SESSION['theory_test_id'], $_SESSION['exam_questions'], $_SESSION['institution_membership_id']);
        $_SESSION['student_id'] = $conn->insert_id;
        $_SESSION['student_name'] = $name;
        $_SESSION['student_class'] = $class;
        $_SESSION['student_subject'] = $final_subject;
        $_SESSION['test_title'] = $test_title;
        $_SESSION['exam_year'] = $exam_year;
        $_SESSION['current_test_id'] = (int)$selected_test['id'];

        if ($universal_registration_available) {
            $person_code = 'student:' . $active_institution_id . ':' . $_SESSION['student_id'];
            $stmt_person = $conn->prepare(
                "INSERT INTO people (person_code, full_name, status)
                 VALUES (?, ?, 'active')"
            );
            $stmt_person->bind_param('ss', $person_code, $name);
            $stmt_person->execute();
            $person_id = $stmt_person->insert_id;
            $stmt_person->close();

            $membership_code = 'student:' . $_SESSION['student_id'];
            $role_code = 'student';
            $membership_status = 'active';
            $stmt_membership = $conn->prepare(
                "INSERT INTO institution_memberships
                 (institution_id, person_id, membership_code, role_code, status, legacy_student_id)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt_membership->bind_param('iisssi', $active_institution_id, $person_id, $membership_code, $role_code, $membership_status, $_SESSION['student_id']);
            $stmt_membership->execute();
            $membership_id = $stmt_membership->insert_id;
            $stmt_membership->close();

            if (!empty($selected_test['organizational_unit_id']) && examcenterUniversalTableExists($conn, 'unit_memberships')) {
                $membership_role = 'student';
                $stmt_unit = $conn->prepare(
                    "INSERT IGNORE INTO unit_memberships
                     (institution_membership_id, organizational_unit_id, membership_role)
                     VALUES (?, ?, ?)"
                );
                $stmt_unit->bind_param('iis', $membership_id, $selected_test['organizational_unit_id'], $membership_role);
                $stmt_unit->execute();
                $stmt_unit->close();
            }
        }

        header("Location: exam_preview.php");
        exit();
    } else {
        $error = "Registration failed. Please try again.";
    }
}
                        }
                    }
            }
        }
    } else {
        $error = "Invalid CSRF token";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration | Examcenter</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --reg-ink:#172b4d; --reg-muted:#66758b; --reg-blue:#2459a6; --reg-line:#e4eaf2; }
        body.registration-page { min-height:100vh; color:var(--reg-ink); background:radial-gradient(ellipse at 80% 0%,rgba(77,132,211,.13),transparent 34rem),linear-gradient(180deg,#f5f8fc 0%,#eef3f9 100%); }
        .registration-topbar { height:72px; display:flex; align-items:center; padding:0 clamp(20px,5vw,72px); background:rgba(255,255,255,.9); border-bottom:1px solid rgba(223,231,241,.9); }
        .registration-brand { display:flex; align-items:center; gap:11px; color:var(--reg-ink); font-size:18px; font-weight:750; letter-spacing:-.3px; text-decoration:none; }
        .registration-brand-mark { width:34px; height:34px; display:grid; place-items:center; border-radius:10px; color:#fff; background:linear-gradient(145deg,#3475ce,#1e4f95); font-size:15px; font-weight:800; box-shadow:0 5px 12px rgba(36,89,166,.22); }
        .registration-shell { max-width:900px; padding-top:clamp(30px,6vh,64px); padding-bottom:56px; }
        .registration-heading { margin-bottom:24px; }
        .registration-eyebrow { color:var(--reg-blue); font-size:12px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; }
        .registration-heading h1 { margin:8px 0 7px; font-size:clamp(27px,4vw,36px); font-weight:750; letter-spacing:-1px; }
        .registration-heading p { margin:0; color:var(--reg-muted); }
        .registration-card { overflow:hidden; border:1px solid var(--reg-line); border-radius:16px; background:#fff; box-shadow:0 20px 55px rgba(31,55,88,.09); }
        .registration-card .card-header { padding:22px 26px; border-bottom:1px solid var(--reg-line); background:#fff; }
        .registration-card .card-header h2 { margin:0 0 4px; font-size:17px; font-weight:750; }
        .registration-card .card-header p { margin:0; color:var(--reg-muted); font-size:13px; }
        .registration-card .card-body { padding:26px; }
        .registration-card .form-label { margin-bottom:7px; color:#31445f; font-size:13px; font-weight:700; }
        .registration-card .form-control,.registration-card .form-select { min-height:46px; padding:10px 12px; border-color:#d7e0eb; border-radius:8px; box-shadow:none; }
        .registration-card .form-control:focus,.registration-card .form-select:focus { border-color:#6d9bd6; box-shadow:0 0 0 3px rgba(36,89,166,.12); }
        .registration-card .btn-primary { min-height:48px; border:0; border-radius:8px; background:linear-gradient(110deg,#2b68b8,#24559b); box-shadow:0 7px 16px rgba(36,89,166,.18); font-weight:700; }
        .registration-card .alert { border-radius:9px; }
        @media(max-width:767.98px) { .registration-topbar { height:62px; } .registration-shell { padding-top:26px; } .registration-card .card-header,.registration-card .card-body { padding:20px; } }
    </style>
</head>
<body class="registration-page">
    <header class="registration-topbar">
        <a class="registration-brand" href="register.php" aria-label="Examcenter registration">
            <span class="registration-brand-mark" aria-hidden="true">E</span>
            <span>Examcenter</span>
        </a>
    </header>
    <main class="container registration-shell">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card registration-card">
                    <div class="card-header">
                        <span class="registration-eyebrow">Candidate portal</span>
                        <h2>Student Registration</h2>
                        <p>Enter your details and choose the exam you are attending.</p>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php elseif (empty($active_subjects)): ?>
                            <div class="alert alert-warning">No exams scheduled for today.</div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                            <div class="mb-3">
                                <label for="name" class="form-label">Student Name</label>
                                <input type="text" class="form-control" id="name" name="name" maxlength="150" autocomplete="name" value="<?php echo htmlspecialchars($name); ?>" required>
                            </div>

                            <!-- Year dropdown -->
                            <div class="mb-3">
                                <label for="exam_year" class="form-label">Select Exam Year</label>
                                <select class="form-select" id="exam_year" name="exam_year" required>
                                    <option value="">Select Year</option>
                                    <?php foreach($available_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo ($exam_year === $year) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Test dropdown (depends on year) -->
                            <div class="mb-3">
                                <label for="test_title" class="form-label">Select Exam</label>
                                <select class="form-select" id="test_title" name="test_title" required>
                                    <option value="">Select Exam</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="class" class="form-label">Class</label>
                                <select class="form-select" id="class" name="class" required onchange="updateSubjects()">
                                    <option value="">Select your class</option>
                                    <?php foreach ($classes as $id => $info): ?>
                                        <option value="<?php echo $id; ?>" <?php echo ($class == $id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($info['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject</label>
                                <select class="form-select" id="subject" name="subject" required>
                                    <option value="">Select your subject</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Start Exam</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    // Subject categories
    const subjectsByClassGroup = <?php echo json_encode($subjects_by_class_group); ?>;

    function updateSubjects() {
    const classSelect = document.getElementById('class');
    const subjectSelect = document.getElementById('subject');
    const selectedClassId = classSelect.value;

    // Clear existing options
    subjectSelect.innerHTML = '<option value="">Select your subject</option>';
    if (!selectedClassId) return;

    // Get selected class's data
    const classes = <?php echo json_encode($classes); ?>;
    const selectedClass = classes[selectedClassId]; // <-- use object key, not find()
    if (!selectedClass) return;

    // Determine class group (JSS / SS)
    const classGroup = selectedClass.class_group;

    const allowedSubjects = subjectsByClassGroup[classGroup] || [];

    if (allowedSubjects.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No subjects available for this class';
        option.disabled = true;
        subjectSelect.appendChild(option);
    } else {
        allowedSubjects.forEach(subj => {
            const option = document.createElement('option');
            option.value = subj;
            option.textContent = subj.charAt(0).toUpperCase() + subj.slice(1);
            subjectSelect.appendChild(option);
        });
    }
}

    document.addEventListener('DOMContentLoaded', function() {
        updateSubjects();
    });
    document.getElementById('class').addEventListener('change', updateSubjects);

    // Tests grouped by year
    const testsByYear = <?php echo json_encode($tests_by_year); ?>;
    const selectedTest = "<?php echo $test_title; ?>";

    function updateTests() {
        const yearSelect = document.getElementById('exam_year');
        const testSelect = document.getElementById('test_title');
        const selectedYear = yearSelect.value;

        testSelect.innerHTML = '<option value="">Select Exam</option>';

        if (selectedYear && testsByYear[selectedYear]) {
            testsByYear[selectedYear].forEach(title => {
                const option = document.createElement('option');
                option.value = title;
                option.textContent = title;
                if (title === selectedTest) option.selected = true;
                testSelect.appendChild(option);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', updateTests);
    document.getElementById('exam_year').addEventListener('change', updateTests);
</script>


<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
