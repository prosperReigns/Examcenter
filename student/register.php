<?php
session_start();
require_once '../db.php';

// Initialize error variable
$error = '';

// Verify database connection
$conn = Database::getInstance()->getConnection();
if (!$conn) {
    $error = "Database connection failed. Please try again later.";
}

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

// Fetch students grouped by class
$students_by_class = [];
if (empty($error)) {
    $stmt = $conn->prepare("SELECT id, full_name, class FROM students WHERE class IS NOT NULL ORDER BY full_name ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $students_by_class[$row['class']][] = [
                'id' => $row['id'],
                'name' => $row['full_name']
            ];            
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
        } elseif (!preg_match("/^[a-zA-Z\s]+$/", $name)) {
            $error = "Name must contain only letters and spaces";
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

                $stmt = $conn->prepare("
                    SELECT id 
                    FROM tests
                    WHERE title = ?
                    AND academic_level_id = ?
                    AND year = ?
                ");
                if ($stmt) {
                    $stmt->bind_param(
                        "sis",
                        $test_title,
                        $selected_level_id,
                        $exam_year
                    );
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result->num_rows === 0) {
                            $error = "No test available for this combination";
                        } else {
                           $student_id = $_POST['student_id'] ?? null;

                            if (!$student_id) {
                                $error = "Please select a valid student from the list.";
                            } else {
                                // Verify student exists
                                $stmt = $conn->prepare("SELECT id FROM students WHERE id = ? AND class = ?");
                                $stmt->bind_param("ii", $student_id, $class);
                                $stmt->execute();
                                $student = $stmt->get_result()->fetch_assoc();
                                $stmt->close();

                                if (!$student) {
                                    $error = "Invalid student selected.";
                                } else {
                                    // ✅ USE EXISTING STUDENT
                                    $_SESSION['student_id'] = $student_id;
                                    $_SESSION['student_name'] = $name;
                                    $_SESSION['student_class'] = $class;
                                    $_SESSION['student_subject'] = $final_subject;
                                    $_SESSION['test_title'] = $test_title;
                                    $_SESSION['exam_year'] = $exam_year;

                                    header("Location: take_exam.php");
                                    exit();
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
    <title>Student Registration - CBT Application</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="text-center">Student Registration</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php elseif (empty($active_subjects)): ?>
                            <div class="alert alert-warning">No exams scheduled for today.</div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

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
                                <label for="name" class="form-label">Full Name</label>
                                <input list="student_names" class="form-control" id="name" name="name" pattern="[A-Za-z\s]+" value="<?php echo htmlspecialchars($name); ?>" required>
                                <datalist id="student_names"></datalist>
                                <input type="hidden" name="student_id" id="student_id">
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
    </div>

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

const studentsByClass = <?php echo json_encode($students_by_class); ?>;

function updateStudentNames() {
    const classSelect = document.getElementById('class');
    const datalist = document.getElementById('student_names');
    datalist.innerHTML = '';

    const selectedClassId = classSelect.value;
    if (!selectedClassId || !studentsByClass[selectedClassId]) return;

    studentsByClass[selectedClassId].forEach(student => {
        const option = document.createElement('option');
        option.value = student.name;
        option.dataset.id = student.id;
        datalist.appendChild(option);
    });
}

document.getElementById('name').addEventListener('input', function () {
    const value = this.value;
    const options = document.querySelectorAll('#student_names option');
    const hiddenIdInput = document.getElementById('student_id');

    hiddenIdInput.value = ''; // reset

    options.forEach(option => {
        if (option.value === value) {
            hiddenIdInput.value = option.dataset.id;
        }
    });
});


document.getElementById('class').addEventListener('change', updateStudentNames);

// Populate on page load if a class is pre-selected
document.addEventListener('DOMContentLoaded', updateStudentNames);

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
