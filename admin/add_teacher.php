<?php
session_start();
require_once '../db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed");
}

// Define available subjects
$subjects = [
    'Mathematics', 'English', 'Civic Edu', 'Data Processing', 'Economics',
    'Government', 'Commerce', 'Financial Accounting', 'Agricultural Sci',
    'Literature in English', 'C.R.S', 'I.R.S', 'Accounting', 'Dyeing and Bleaching',
    'Physics', 'Chemistry', 'Biology', 'Geography', 'Technical Drawing', 'Yoruba Lang',
    'Further Maths', 'Basic Science', 'Basic Technology', 'Business Studies', 'PHE',
    'CCA', 'Social Studies', 'Security Edu', 'Yoruba', 'French', 'Coding & Robotics'
];

// Initialize variables
$error = $success = '';
$first_name = $last_name = $email = $phone = $username = '';
$selected_subjects = [];
$is_edit_mode = false;
$teacher_id = null;

// Check if we're in edit mode
if (isset($_GET['edit_id'])) {
    $is_edit_mode = true;
    $teacher_id = (int)$_GET['edit_id'];
    
    $stmt = $conn->prepare("
        SELECT t.id, t.first_name, t.last_name, t.username, t.email, t.phone
        FROM teachers t
        WHERE t.id = ?
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacher = $result->fetch_assoc();
    
    if ($teacher) {
        $first_name = $teacher['first_name'];
        $last_name = $teacher['last_name'];
        $username = $teacher['username'];
        $email = $teacher['email'];
        $phone = $teacher['phone'];
        
        $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $selected_subjects[] = $row['subject'];
        }
    } else {
        $error = "Teacher not found!";
        $is_edit_mode = false;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $selected_subjects = $_POST['subjects'] ?? [];

    $username = strtolower($first_name . '.' . $last_name);
    $username = preg_replace('/[^a-z.]/', '', $username);

    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = "Please fill in all required fields";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (empty($selected_subjects)) {
        $error = "Please select at least one subject";
    } else {
        try {
            // Check for duplicate email and username
            $id_check = $is_edit_mode ? $teacher_id : 0;
            $stmt = $conn->prepare("SELECT id FROM teachers WHERE (email = ? OR username = ?) AND id != ?");
            $stmt->bind_param("ssi", $email, $username, $id_check);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = "Email or username already exists";
            } else {
                $conn->begin_transaction();

                if ($is_edit_mode) {
                    $stmt = $conn->prepare("UPDATE teachers SET first_name = ?, last_name = ?, username = ?, email = ?, phone = ? WHERE id = ?");
                    $stmt->bind_param("sssssi", $first_name, $last_name, $username, $email, $phone, $teacher_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Update failed: " . $stmt->error);
                    }

                    $stmt = $conn->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
                    $stmt->bind_param("i", $teacher_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Delete subjects failed: " . $stmt->error);
                    }

                    $success = "Teacher updated successfully!";
                } else {
                    $password = $_POST['password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';

                    if (empty($password)) throw new Exception("Password is required");
                    if ($password !== $confirm_password) throw new Exception("Passwords do not match");
                    if (strlen($password) < 8) throw new Exception("Password must be at least 8 characters long");

                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Ensure unique username
                    $base_username = $username;
                    $counter = 1;
                    while (true) {
                        $stmt = $conn->prepare("SELECT id FROM teachers WHERE username = ?");
                        $stmt->bind_param("s", $username);
                        $stmt->execute();
                        if ($stmt->get_result()->num_rows == 0) break;
                        $username = $base_username . $counter;
                        $counter++;
                    }

                    $stmt = $conn->prepare("INSERT INTO teachers (first_name, last_name, username, email, password, phone, role) VALUES (?, ?, ?, ?, ?, ?, 'teacher')");
                    $stmt->bind_param("ssssss", $first_name, $last_name, $username, $email, $hashed_password, $phone);
                    if (!$stmt->execute()) {
                        throw new Exception("Insert failed: " . $stmt->error);
                    }

                    $teacher_id = $conn->insert_id;
                    $success = "Teacher added successfully! Username: $username";
                }

                // Insert subjects
                foreach ($selected_subjects as $subject) {
                    if (!in_array($subject, $subjects)) {
                        throw new Exception("Invalid subject: $subject");
                    }
                    $stmt = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject) VALUES (?, ?)");
                    $stmt->bind_param("is", $teacher_id, $subject);
                    if (!$stmt->execute()) {
                        throw new Exception("Insert subject failed: " . $stmt->error);
                    }
                }

                $conn->commit();
                error_log("Teacher added/updated: ID=$teacher_id, Username=$username, Email=$email");

                if (!$is_edit_mode) {
                    $first_name = $last_name = $email = $phone = '';
                    $selected_subjects = [];
                    $username = '';
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Add teacher failed: " . $e->getMessage() . " | SQL Error: " . $conn->error);
            $error = "Error: " . $e->getMessage();
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
    <title><?php echo $is_edit_mode ? 'Edit' : 'Add'; ?> Teacher</title>
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
        
        .username-preview {
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: monospace;
            margin-top: 5px;
            display: inline-block;
        }
        
        .alert {
            margin-top: 20px;
        }
        
        .password-field {
            display: <?php echo $is_edit_mode ? 'none' : 'block'; ?>;
        }
    </style>
</head>
<body>
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0"><?php echo $is_edit_mode ? 'Edit' : 'Add'; ?> Teacher</h1>
                <div class="d-flex gap-3">
                    <a href="manage_teachers.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Teachers
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
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?><?php echo $is_edit_mode ? '?edit_id=' . $teacher_id : ''; ?>">
                        <?php if ($is_edit_mode): ?>
                            <input type="hidden" name="teacher_id" value="<?php echo $teacher_id; ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">First Name</label>
                                <input type="text" class="form-control" name="first_name" 
                                       value="<?php echo htmlspecialchars($first_name); ?>" required
                                       oninput="updateUsernamePreview()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Last Name</label>
                                <input type="text" class="form-control" name="last_name" 
                                       value="<?php echo htmlspecialchars($last_name); ?>" required
                                       oninput="updateUsernamePreview()">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Username</label>
                                <div id="usernamePreview" class="username-preview">
                                    <?php echo !empty($username) ? htmlspecialchars($username) : 'username.will.generate.here'; ?>
                                </div>
                                <small class="text-muted">Automatically generated from first and last name</small>
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
                            
                            <?php if (!$is_edit_mode): ?>
                                <div class="col-md-6 password-field">
                                    <label class="form-label fw-bold">Password</label>
                                    <input type="password" class="form-control" name="password" required
                                           oninput="checkPasswordStrength(this.value)">
                                    <div id="password-strength" class="mt-1 small"></div>
                                </div>
                                <div class="col-md-6 password-field">
                                    <label class="form-label fw-bold">Confirm Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                            <?php endif; ?>
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
                            <button type="reset" class="btn btn-secondary" onclick="clearForm()">Clear</button>
                            <button type="submit" name="<?php echo $is_edit_mode ? 'update_teacher' : 'add_teacher'; ?>" class="btn btn-primary">
                                <i class="fas fa-<?php echo $is_edit_mode ? 'save' : 'plus'; ?> me-2"></i>
                                <?php echo $is_edit_mode ? 'Update' : 'Add'; ?> Teacher
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        function updateUsernamePreview() {
            const firstName = document.querySelector('input[name="first_name"]').value.trim().toLowerCase();
            const lastName = document.querySelector('input[name="last_name"]').value.trim().toLowerCase();
            
            let username = '';
            if (firstName && lastName) {
                username = (firstName + '.' + lastName).replace(/[^a-z.]/g, '');
            }
            
            document.getElementById('usernamePreview').textContent = username || 'username.will.generate.here';
        }
        
        function checkPasswordStrength(password) {
            const strengthIndicator = document.getElementById('password-strength');
            
            if (!strengthIndicator) return;
            
            if (password.length === 0) {
                strengthIndicator.textContent = '';
                return;
            }
            
            let strengthText = '';
            let strengthClass = '';
            
            if (password.length < 5) {
                strengthText = 'Weak (min 8 characters)';
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
        }
        
        function clearForm() {
            document.getElementById('usernamePreview').textContent = 'username.will.generate.here';
            document.querySelectorAll('input[name="subjects[]"]').forEach(cb => cb.checked = false);
            document.getElementById('password-strength').textContent = '';
        }
        
        document.querySelector('form').addEventListener('submit', function(e) {
            const isEditMode = <?php echo $is_edit_mode ? 'true' : 'false'; ?>;
            const subjects = document.querySelectorAll('input[name="subjects[]"]:checked');
            
            if (!isEditMode) {
                const password = document.querySelector('input[name="password"]').value;
                const confirm = document.querySelector('input[name="confirm_password"]').value;
                
                if (password !== confirm) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return false;
                }
                
                if (password.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters long!');
                    return false;
                }
            }
            
            if (subjects.length === 0) {
                e.preventDefault();
                alert('Please select at least one subject!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>