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
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];

// Define subject categories
$common_subjects = ['mathematics', 'english', 'civic education', 'c.r.s', 'i.r.s', 'yoruba', 'french', 'agriculture sci'];
$jss_specific_subjects = ['ict', 'history', 'basic science', 'basic technology', 'security edu', 'cultural and creative art', 'coding and robotics', 'business studies', 'physical health edu'];
$ss_specific_subjects = ['data processing', 'economics', 'government', 'accounting', 'physics', 'chemistry', 'biology', 'coding and robotics', 'geography', 'technical drawing', 'further maths', 'literature in english'];

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
    $stmt = $conn->prepare("SELECT year, title FROM tests ORDER BY year DESC, id DESC");
    if ($stmt) {
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $tests_by_year[$row['year']][] = $row['title'];
                if (!in_array($row['year'], $available_years)) {
                    $available_years[] = $row['year'];
                }
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
        } elseif (!preg_match("/^[a-zA-Z\s]+$/", $name)) {
            $error = "Name must contain only letters and spaces";
        } elseif (!in_array($class, $classes)) {
            $error = "Invalid class selected";
        } elseif (!in_array(strtolower($subject), array_map('strtolower',$active_subjects))) {
            $error = "No test available for this subject today";
        } else {
            // Verify subject is valid for the selected class
            $allowed_subjects = $class[0] === 'J' 
                ? array_unique(array_merge($common_subjects, $jss_specific_subjects))
                : array_unique(array_merge($common_subjects, $ss_specific_subjects));
                
            if (!in_array(strtolower($subject), array_map('strtolower', $allowed_subjects))) {
                $error = "No test available for this combination";
            } else {
                // Verify class-subject-test-year combination
                $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND LOWER(subject) = ? AND class = ? AND year = ?");
                if ($stmt) {
                    $stmt->bind_param("ssss", $test_title, $subject, $class, $exam_year);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result->num_rows === 0) {
                            $error = "No test available for this combination";
                        } else {
                            // Insert into students table
                            $stmt = $conn->prepare("INSERT INTO students (name, class) VALUES (?, ?)");
                            if ($stmt) {
                                $stmt->bind_param("ss", $name, $class);
                                if ($stmt->execute()) {
                                    $_SESSION['student_id'] = $conn->insert_id;
                                    $_SESSION['student_name'] = $name;
                                    $_SESSION['student_class'] = $class;
                                    $_SESSION['student_subject'] = $subject;
                                    $_SESSION['test_title'] = $test_title;
                                    $_SESSION['exam_year'] = $exam_year;
                                    header("Location: take_exam.php");
                                    exit();
                                } else {
                                    $error = "Registration failed. Please try again.";
                                }
                                $stmt->close();
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
                                <label for="name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" pattern="[A-Za-z\s]+" value="<?php echo htmlspecialchars($name); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="class" class="form-label">Class</label>
                                <select class="form-select" id="class" name="class" required onchange="updateSubjects()">
                                    <option value="">Select your class</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo $c; ?>" <?php echo ($class === $c) ? 'selected' : ''; ?>>
                                            <?php echo $c; ?>
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
    </div>

    <script>
    // Subject categories
    const commonSubjects = <?php echo json_encode($common_subjects); ?>;
    const jssSpecificSubjects = <?php echo json_encode($jss_specific_subjects); ?>;
    const ssSpecificSubjects = <?php echo json_encode($ss_specific_subjects); ?>;
    const activeSubjects = <?php echo json_encode($active_subjects); ?>;
    const selectedSubject = "<?php echo $subject; ?>";

    function updateSubjects() {
        const classSelect = document.getElementById('class');
        const subjectSelect = document.getElementById('subject');
        const selectedClass = classSelect.value;

        // Clear existing options
        subjectSelect.innerHTML = '<option value="">Select your subject</option>';

        if (!selectedClass) return;

        // Determine allowed subjects
        let allowedSubjects = [];
        if (selectedClass.startsWith('JSS')) {
            allowedSubjects = [...commonSubjects, ...jssSpecificSubjects];
        } else if (selectedClass.startsWith('SS')) {
            allowedSubjects = [...commonSubjects, ...ssSpecificSubjects];
        }

        // Filter active subjects
        const filteredSubjects = activeSubjects.filter(s => allowedSubjects.includes(s));

        if (filteredSubjects.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No subjects available for this class';
            option.disabled = true;
            option.className = 'no-subjects';
            subjectSelect.appendChild(option);
        } else {
            filteredSubjects.forEach(subj => {
                const option = document.createElement('option');
                option.value = subj;
                option.textContent = subj.charAt(0).toUpperCase() + subj.slice(1);
                if (subj === selectedSubject) option.selected = true;
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
