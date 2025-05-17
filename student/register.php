<?php
session_start();
require_once '../db.php';

$conn = Database::getInstance()->getConnection();

// Define available classes and subjects
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];
$jss_subjects = ['Mathematics', 'English', 'ICT', 'Agriculture', 'History', 'Civic Education', 'Basic Science', 'Basic Technology'];
$ss_subjects = ['Mathematics', 'English', 'Data Processing', 'Economics', 'Government', 'Accounting', 'Physics', 'Chemistry', 'Biology'];

// Fetch available tests
$sql = "SELECT DISTINCT title FROM tests ORDER BY id DESC";
$test_result = mysqli_query($conn, $sql);
$available_tests = mysqli_fetch_all($test_result, MYSQLI_ASSOC);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $class = mysqli_real_escape_string($conn, $_POST['class']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $test_title = mysqli_real_escape_string($conn, $_POST['test_title']);
    
    // Store student info in session
    $_SESSION['student_name'] = $name;
    $_SESSION['student_class'] = $class;
    $_SESSION['student_subject'] = $subject;
    $_SESSION['test_title'] = $test_title;
    
    // Insert into students table
    $sql = "INSERT INTO students (name, class) VALUES ('$name', '$class')";
    if (mysqli_query($conn, $sql)) {
        $_SESSION['student_id'] = mysqli_insert_id($conn);
        header("Location: take_exam.php");
        exit();
    } else {
        $error = "Registration failed: " . mysqli_error($conn);
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
                    <div class="card-header">
                        <h3 class="text-center">Student Registration</h3>
                    </div>
                    <div class="card-body">
                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="test_title" class="form-label">Select Exam</label>
                                <select class="form-select" name="test_title" required>
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
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label for="class" class="form-label">Class</label>
                                <select class="form-control" id="class" name="class" required onchange="updateSubjects()">
                                    <option value="">Select your class</option>
                                    <?php foreach($classes as $class): ?>
                                        <option value="<?php echo $class; ?>"><?php echo $class; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject</label>
                                <select class="form-control" id="subject" name="subject" required>
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
        // Define subjects
        const jssSubjects = <?php echo json_encode($jss_subjects); ?>;
        const ssSubjects = <?php echo json_encode($ss_subjects); ?>;

        function updateSubjects() {
            const classSelect = document.getElementById('class');
            const subjectSelect = document.getElementById('subject');
            const selectedClass = classSelect.value;
            
            // Clear current options
            subjectSelect.innerHTML = '<option value="">Select your subject</option>';
            
            // Determine which subject list to use
            let subjects = [];
            if (selectedClass.startsWith('JSS')) {
                subjects = jssSubjects;
            } else if (selectedClass.startsWith('SS')) {
                subjects = ssSubjects;
            }
            
            // Add new options
            subjects.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject;
                option.textContent = subject;
                subjectSelect.appendChild(option);
            });
        }
    </script>
    <script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>