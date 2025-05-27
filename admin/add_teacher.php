<?php
session_start();
require_once '../db.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Define available subjects
$subjects = [
    'Mathematics', 'English', 'ICT', 'Agriculture', 'History', 
    'Civic Education', 'Basic Science', 'Basic Technology', 
    'Business studies', 'Agricultural sci', 'Physical Health Edu',
    'Cultural and Creative Art', 'Social Studies', 'Security Edu', 
    'Yoruba', 'French', 'Coding and Robotics', 'C.R.S', 'I.R.S', 'Chess',
    'Data Processing', 'Economics', 'Government', 'Commerce', 'Accounting',
    'Financial Accounting', 'Physics', 'Chemistry', 'Biology', 'Geography',
    'Technical Drawing', 'Literature in English'
];

// Initialize variables
$error = $success = '';
$first_name = $last_name = $email = $phone = '';
$selected_subjects = [];

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_teacher'])) {
    // Get form data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $selected_subjects = $_POST['subjects'] ?? [];
    
    // Validate inputs
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif (empty($selected_subjects)) {
        $error = "Please select at least one subject";
    } else {
        // Check if email already exists
        $check_email = $conn->prepare("SELECT id FROM teachers WHERE email = ?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        $check_email->store_result();
        
        if ($check_email->num_rows > 0) {
            $error = "Email already exists";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert into staff table
                $full_name = $first_name . ' ' . $last_name;
                $role = 'staff';
                
                $stmt_staff = $conn->prepare("INSERT INTO staff (name, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt_staff->bind_param("ssss", $full_name, $email, $hashed_password, $role);
                $stmt_staff->execute();
                $staff_id = $stmt_staff->insert_id;
                
                // Insert into teachers table
                $stmt_teacher = $conn->prepare("INSERT INTO teachers (first_name, last_name, email, password, phone) VALUES (?, ?, ?, ?, ?)");
                $stmt_teacher->bind_param("sssss", $first_name, $last_name, $email, $hashed_password, $phone);
                $stmt_teacher->execute();
                $teacher_id = $stmt_teacher->insert_id;
                
                
                foreach ($selected_subjects as $subject) {
                    $stmt_subject = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject) VALUES (?, ?)");
                    $stmt_subject->bind_param("is", $teacher_id, $subject);
                    $stmt_subject->execute();
                }
                
                // Commit transaction
                $conn->commit();
                
                $success = "Teacher added successfully!";
                // Clear form
                $first_name = $last_name = $email = $phone = '';
                $selected_subjects = [];
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error = "Error adding teacher: " . $e->getMessage();
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
    <title>Add Teacher</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.min.css">
    <link rel="stylesheet" href="../css/animate.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 80vh;
            color: var(--dark);
            overflow-x: hidden;
        }
        
        .gradient-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-bottom-left-radius: 35px;
            border-bottom-right-radius: 35px;
        }
        
        .teacher-card {
            background-color: white;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .teacher-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .subject-checkbox {
            margin-right: 10px;
            margin-bottom: 8px;
        }
        
        .subjects-container {
            display: flex;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .subject-item {
            flex: 0 0 25%;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .subject-item {
                flex: 0 0 50%;
            }
        }
    </style>
</head>
<body>
    <!-- Gradient Header -->
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">Add Teacher</h1>
                <div class="d-flex gap-3">
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="teacher-card animate__animated animate__fadeIn">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <h5 class="mb-3">Teacher Information</h5>
                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">First Name</label>
                                <input type="text" class="form-control" name="first_name" 
                                       value="<?php echo htmlspecialchars($first_name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Last Name</label>
                                <input type="text" class="form-control" name="last_name" 
                                       value="<?php echo htmlspecialchars($last_name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?php echo htmlspecialchars($email); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" 
                                       value="<?php echo htmlspecialchars($phone); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Confirm Password</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="form-label fw-bold">Subjects Taught</label>
                            <div class="subjects-container">
                                <?php foreach ($subjects as $subject): ?>
                                    <div class="subject-item">
                                        <div class="form-check">
                                            <input class="form-check-input subject-checkbox" type="checkbox" 
                                                   name="subjects[]" value="<?php echo htmlspecialchars($subject); ?>"
                                                   id="subject-<?php echo preg_replace('/[^a-z0-9]/', '-', strtolower($subject)); ?>"
                                                   <?php echo in_array($subject, $selected_subjects) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="subject-<?php echo preg_replace('/[^a-z0-9]/', '-', strtolower($subject)); ?>">
                                                <?php echo htmlspecialchars($subject); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-3 mt-4">
                            <button type="reset" class="btn btn-secondary">Clear</button>
                            <button type="submit" name="add_teacher" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Add Teacher
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple password strength check
        document.querySelector('input[name="password"]').addEventListener('input', function() {
    const password = this.value;
    let strengthIndicator = document.getElementById('password-strength');

    if (password.length === 0) {
        if (strengthIndicator) strengthIndicator.remove();
        return;
    }

    if (!strengthIndicator) {
        const div = document.createElement('div');
        div.id = 'password-strength';
        div.className = 'mt-1 small';
        this.parentNode.appendChild(div);
        strengthIndicator = div;  
    }

    let strengthText = '';
    let strengthClass = '';

    if (password.length < 6) {
        strengthText = 'Weak (min 6 characters)';
        strengthClass = 'text-danger';
    } else if (password.length < 8) {
        strengthText = 'Medium';
        strengthClass = 'text-warning';
    } else {
        strengthText = 'Strong';
        strengthClass = 'text-success';
    }

    strengthIndicator.textContent = strengthText;
    strengthIndicator.className = 'mt-1 small ' + strengthClass;
});

    </script>
</body>
</html>