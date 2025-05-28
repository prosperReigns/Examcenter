<?php
session_start();
require_once '../db.php';

$conn = Database::getInstance()->getConnection();

// Define available classes
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];

// Define subject categories for filtering
$common_subjects = ['Mathematics', 'English', 'Civic Education', 'Agric science'];
$jss_specific_subjects = ['ICT', 'Agriculture', 'History', 'Civic Education', 'Basic Science', 'Basic Technology'];
$ss_specific_subjects = ['Data Processing', 'Economics', 'Government', 'Accounting', 'Physics', 'Chemistry', 'Biology'];

// Fetch active subjects for today from active_exams
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT subject FROM active_exams WHERE is_active = 1 AND exam_date = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$active_subjects = [];
while ($row = $result->fetch_assoc()) {
    $active_subjects[] = $row['subject'];
}
$stmt->close();

// Fetch available tests
$sql = "SELECT DISTINCT title FROM tests ORDER BY id DESC";
$test_result = $conn->query($sql);
$available_tests = $test_result ? $test_result->fetch_all(MYSQLI_ASSOC) : [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'] ?? '';
    $class = $_POST['class'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $test_title = $_POST['test_title'] ?? '';
    
    // Validate inputs
    if (empty($name) || empty($class) || empty($subject) || empty($test_title)) {
        $error = "All fields are required";
    } elseif (!in_array($class, $classes)) {
        $error = "Invalid class selected";
    } elseif (!in_array($subject, $active_subjects)) {
        $error = "Invalid subject selected";
    } else {
        // Verify subject is valid for the selected class
        $allowed_subjects = $class[0] === 'J' 
            ? array_merge($common_subjects, $jss_specific_subjects)
            : array_merge($common_subjects, $ss_specific_subjects);
        if (!in_array($subject, $allowed_subjects)) {
            $error = "Selected subject is not available for your class";
        } else {
            try {
                // Insert into students table
                $stmt = $conn->prepare("INSERT INTO students (name, class) VALUES (?, ?)");
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
                    $error = "Registration failed: " . $conn->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
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
                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="test_title" class="form-label">Select Exam</label>
                                <select class="form-select" id="test_title" name="test_title" required>
                                    <option value="">Select Exam</option>
                                    <?php foreach($available_tests as $test): ?>
                                        <option value="<?php echo htmlspecialchars($test['title']); ?>">
                                            <?php echo htmlspecialchars($test['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="class" class="form-label">Class</label>
                                <select class="form-select" id="class" name="class" required onchange="updateSubjects()">
                                    <option value="">Select your class</option>
                                    <?php foreach($classes as $class): ?>
                                        <option value="<?php echo $class; ?>" <?php echo (($_POST['class'] ?? '') === $class) ? 'selected' : ''; ?>>
                                            <?php echo $class; ?>
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
        // Define subject categories
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
            
            // Determine allowed subjects based on class
            let allowedSubjects = [];
            if (selectedClass.startsWith('JSS')) {
                allowedSubjects = [...commonSubjects, ...jssSpecificSubjects];
            } else if (selectedClass.startsWith('SS')) {
                allowedSubjects = [...commonSubjects, ...ssSpecificSubjects];
            }
            
            // Filter active subjects by allowed subjects
            const filteredSubjects = activeSubjects.filter(subject => allowedSubjects.includes(subject));
            
            // Add filtered subjects to dropdown
            if (filteredSubjects.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'No subjects available';
                option.disabled = true;
                subjectSelect.appendChild(option);
            } else {
                filteredSubjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject;
                    option.textContent = subject;
                    subjectSelect.appendChild(option);
                });
            }
        }
    </script>
    <script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>