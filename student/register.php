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

// Define subject categories (lowercase to match database)
$common_subjects = ['mathematics', 'english', 'civic education', 'c.r.s', 'i.r.s', 'yoruba', 'french', 'agriculture sci',];
$jss_specific_subjects = ['ict', 'history', 'basic science', 'basic technology', 'security edu', 'cultural and creative art', 'coding and robotics', 'history', 'business studies', 'physical health edu',];
$ss_specific_subjects = ['data processing', 'economics', 'government', 'accounting', 'physics', 'chemistry', 'biology', 'coding and robotics', 'geography', 'technical drawing', 'further maths', 'literature in english',];

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
        } else {
            $error = "Database error fetching subjects. Please try again.";
        }
        $stmt->close();
    } else {
        $error = "Database error preparing statement.";
    }
}

// Fetch available tests
$available_tests = [];
if (empty($error)) {
    $stmt = $conn->prepare("SELECT DISTINCT title FROM tests ORDER BY id DESC LIMIT 50");
    if ($stmt) {
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $available_tests = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $error = "Database error fetching tests. Please try again.";
        }
        $stmt->close();
    } else {
        $error = "Database error preparing statement.";
    }
}

// Initialize form values
$name = $class = $subject = $test_title = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $class = $_POST['class'] ?? '';
    $subject = isset($_POST['subject']) ? strtolower(trim($_POST['subject'])) : '';
    $test_title = $_POST['test_title'] ?? '';
    
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
                
            if (!in_array(strtolower($subject
            ), array_map('strtolower', $allowed_subjects))) {
                $error = "No test available for this combination";
            } else {
                // Verify class-subject-test combination
                $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND LOWER(subject) = ? AND class = ?");
                if ($stmt) {
                    $stmt->bind_param("sss", $test_title, $subject, $class);
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
                                    // Store student info in session
                                    $_SESSION['student_id'] = $conn->insert_id;
                                    $_SESSION['student_name'] = $name;
                                    $_SESSION['student_class'] = $class;
                                    $_SESSION['student_subject'] = $subject;
                                    $_SESSION['test_title'] = $test_title;
                                    header("Location: take_exam.php");
                                    exit();
                                } else {
                                    $error = "Registration failed. Please try again.";
                                }
                                $stmt->close();
                            } else {
                                $error = "Database error preparing statement.";
                            }
                        }
                    } else {
                        $error = "Database error checking test combination. Please try again.";
                    }
                } else {
                    $error = "Database error preparing statement.";
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
    <style>
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #4361ee;
            color: white;
            border-radius: 10px 10px 0 0;
        }
        .btn-primary {
            background-color: #4361ee;
            border-color: #4361ee;
        }
        .btn-primary:hover {
            background-color: #3f37c9;
            border-color: #3f37c9;
        }
        .no-subjects {
            color: #dc3545;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
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
                            <div class="mb-3">
                                <label for="test_title" class="form-label">Select Exam</label>
                                <select class="form-select" id="test_title" name="test_title" required>
                                    <option value="">Select Exam</option>
                                    <?php foreach($available_tests as $test): ?>
                                        <option value="<?php echo htmlspecialchars($test['title']); ?>" <?php echo ($test_title === $test['title']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($test['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
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
                                    <?php
                                    if (!empty($class)) {
                                        // Generate subject options for the selected class
                                        $allowed_subjects = $class[0] === 'J' 
                                            ? array_unique(array_merge($common_subjects, $jss_specific_subjects))
                                            : array_unique(array_merge($common_subjects, $ss_specific_subjects));
                                        
                                        $filtered_subjects = array_intersect($active_subjects, $allowed_subjects);
                                        
                                        foreach ($filtered_subjects as $subj) {
                                            $selected = ($subject === $subj) ? 'selected' : '';
                                            echo "<option value=\"$subj\" $selected>" . ucwords($subj) . "</option>";
                                        }
                                    }
                                    ?>
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
        // Define subject categories in lowercase
        const commonSubjects = <?php echo json_encode($common_subjects); ?>;
        const jssSpecificSubjects = <?php echo json_encode($jss_specific_subjects); ?>;
        const ssSpecificSubjects = <?php echo json_encode($ss_specific_subjects); ?>;
        const activeSubjects = <?php echo json_encode($active_subjects); ?>;

        function updateSubjects() {
            const classSelect = document.getElementById('class');
            const subjectSelect = document.getElementById('subject');
            const selectedClass = classSelect.value;
            
            // Clear current options
            subjectSelect.innerHTML = '<option value="">Select your subject</option>';
            
            if (!selectedClass) return;
            
            // Determine allowed subjects based on class
            let allowedSubjects = [];
            if (selectedClass.startsWith('JSS')) {
                allowedSubjects = [...commonSubjects, ...jssSpecificSubjects];
            } else if (selectedClass.startsWith('SS')) {
                allowedSubjects = [...commonSubjects, ...ssSpecificSubjects];
            }
            
            // Filter active subjects by allowed subjects
            const filteredSubjects = activeSubjects.filter(subject => 
                allowedSubjects.includes(subject)
            );
            
            // Add filtered subjects to dropdown (with proper capitalization)
            if (filteredSubjects.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'No subjects available for this class';
                option.disabled = true;
                option.className = 'no-subjects';
                subjectSelect.appendChild(option);
            } else {
                filteredSubjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject;
                    option.textContent = subject.charAt(0).toUpperCase() + subject.slice(1); // Capitalize
                    subjectSelect.appendChild(option);
                });
            }
        }

        // Initialize subjects when page loads if class is already selected
        document.addEventListener('DOMContentLoaded', function() {
            const classSelect = document.getElementById('class');
            if (classSelect.value) {
                updateSubjects();
                
                // Set previously selected subject if available
                const prevSubject = "<?php echo $subject; ?>";
                if (prevSubject) {
                    const subjectSelect = document.getElementById('subject');
                    for (let i = 0; i < subjectSelect.options.length; i++) {
                        if (subjectSelect.options[i].value === prevSubject) {
                            subjectSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
        });
    </script>
    <script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>