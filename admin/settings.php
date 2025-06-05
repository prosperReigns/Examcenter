<?php
session_start();
require_once '../db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    error_log("Redirecting to login: No user_id in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM admins WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for admin role check: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'admin') {
        error_log("Unauthorized access attempt by user_id=$user_id, role=" . ($user['role'] ?? 'none'));
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }
} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}

$conn = Database::getInstance()->getConnection();

// Initialize variables
$error = $success = '';
$current_password = $new_password = $confirm_password = '';
$exam_date = date('Y-m-d'); // Default to today
$selected_subjects = [];

// Fetch available subjects (from add_question.php lists)
$jss_subjects = [
    'Mathematics', 'English', 'ICT', 'Agriculture', 'History', 
    'Civic Education', 'Basic Science', 'Basic Technology', 
    'Business studies', 'Agricultural sci', 'Physical Health Edu',
    'Cultural and Creative Art', 'Social Studies', 'Security Edu', 
    'Yoruba', 'french', 'Coding and Robotics', 'C.R.S', 'I.R.S', 'Chess'
];
$ss_subjects = [
    'Mathematics', 'English', 'Civic Edu', 'Data Processing', 'Economics',
    'Government', 'Commerce', 'Accounting', 'Financial Accounting', 
    'Dyeing and Bleaching', 'Physics', 'Chemistry', 'Biology', 
    'Agricultural Sci', 'Geography', 'technical Drawing', 'yoruba Lang',
    'French Lang', 'Further Maths', 'Literature in English', 'C.R.S', 'I.R.S'
];
$all_subjects = array_unique(array_merge($jss_subjects, $ss_subjects));

// Fetch current settings
$settings = [];
$settings_query = "SELECT setting_name, setting_value FROM settings WHERE setting_name IN ('show_results_immediately')";
$settings_result = $conn->query($settings_query);
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_name']] = $row['setting_value'];
    }
}

// Handle password change
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long";
    } else {
        try {
            // Get current password hash
            $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['admin_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
            
            if ($admin && password_verify($current_password, $admin['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $_SESSION['admin_id']);
                if ($stmt->execute()) {
                    $success = "Password changed successfully!";
                    $current_password = $new_password = $confirm_password = '';
                } else {
                    $error = "Error updating password: " . $conn->error;
                }
            } else {
                $error = "Current password is incorrect";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Error changing password: " . $e->getMessage();
        }
    }
}

// Handle system settings update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
    $show_results = isset($_POST['show_results']) ? 1 : 0;
    
    // Update or insert setting
    $stmt = $conn->prepare("INSERT INTO settings (setting_name, setting_value) VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sii", $setting_name, $show_results, $show_results);
    $setting_name = 'show_results_immediately';
    $stmt->execute();
    $stmt->close();
    
    $success = "System settings updated successfully!";
}

// Handle daily subjects update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_daily_subjects'])) {
    $exam_date = $_POST['exam_date'];
    $selected_subjects = array_unique($_POST['subjects'] ?? []); // Remove duplicates
    
    // Validate inputs
    if (empty($exam_date)) {
        $error = "Please select a date";
    } elseif (empty($selected_subjects)) {
        $error = "Please select at least one subject";
    } else {
        try {
            // Clear existing subjects for the date
            $stmt = $conn->prepare("DELETE FROM active_exams WHERE exam_date = ?");
            $stmt->bind_param("s", $exam_date);
            $stmt->execute();
            $stmt->close();
            
            // Insert new active subjects
            $stmt = $conn->prepare("INSERT INTO active_exams (subject, is_active, exam_date) VALUES (?, 1, ?)");
            foreach ($selected_subjects as $subject) {
                if (in_array($subject, $all_subjects)) {
                    $stmt->bind_param("ss", $subject, $exam_date);
                    $stmt->execute();
                }
            }
            $stmt->close();
            
            $success = "Daily subjects updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating daily subjects: " . $e->getMessage();
        }
    }
}

// Fetch current active subjects
$active_subjects_query = "SELECT subject, exam_date FROM active_exams WHERE is_active = 1 ORDER BY exam_date, subject";
$active_subjects_result = $conn->query($active_subjects_query);
$active_subjects = [];
if ($active_subjects_result) {
    while ($row = $active_subjects_result->fetch_assoc()) {
        $active_subjects[$row['exam_date']][] = $row['subject'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | D-portal</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/animate.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <style>
        .gradient-header {
            background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-bottom-left-radius: 35px;
            border-bottom-right-radius: 35px;
        }
        
        .settings-card {
            background-color: white;
            border-radius: 8px;
            border-left: 4px solid #4361ee;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .settings-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .nav-pills .nav-link.active {
            background-color: #4361ee;
        }
        
        .nav-pills .nav-link {
            color: #4361ee;
        }
        
        .tab-content {
            padding: 20px 0;
        }
        
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 2px;
        }
        
        .strength-weak {
            background-color: #dc3545;
            width: 30%;
        }
        
        .strength-medium {
            background-color: #ffc107;
            width: 60%;
        }
        
        .strength-strong {
            background-color: #28a745;
            width: 100%;
        }
        
        .form-group-spacing {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Gradient Header -->
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">System Settings</h1>
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
                <?php if ($error): ?>
                    <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <div class="settings-card animate__animated animate__fadeIn">
                    <ul class="nav nav-pills mb-4" id="settingsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="password-tab" data-bs-toggle="pill" data-bs-target="#password" type="button" role="tab">
                                <i class="fas fa-lock me-2"></i>Change Password
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="system-tab" data-bs-toggle="pill" data-bs-target="#system" type="button" role="tab">
                                <i class="fas fa-cog me-2"></i>System Settings
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="daily-subjects-tab" data-bs-toggle="pill" data-bs-target="#daily-subjects" type="button" role="tab">
                                <i class="fas fa-calendar-day me-2"></i>Daily Subjects
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="settingsTabsContent">
                        <!-- Password Change Tab -->
                        <div class="tab-pane fade show active" id="password" role="tabpanel">
                            <h5 class="mb-4">Change Your Password</h5>
                            <form method="POST" action="settings.php">
                                <div class="row g-3">
                                    <div class="col-12 form-group-spacing">
                                        <label class="form-label fw-bold">Current Password</label>
                                        <input type="password" class="form-control" name="current_password" required>
                                    </div>
                                    <div class="col-md-6 form-group-spacing">
                                        <label class="form-label fw-bold">New Password</label>
                                        <input type="password" class="form-control" name="new_password" required
                                               oninput="checkPasswordStrength(this.value)">
                                        <div id="password-strength" class="password-strength mt-1"></div>
                                        <small class="text-muted">Minimum 8 characters</small>
                                    </div>
                                    <div class="col-md-6 form-group-spacing">
                                        <label class="form-label fw-bold">Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password" required>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end gap-3 mt-4">
                                    <button type="reset" class="btn btn-secondary">Clear</button>
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- System Settings Tab -->
                        <div class="tab-pane fade" id="system" role="tabpanel">
                            <h5 class="mb-4">System Configuration</h5>
                            <form method="POST" action="settings.php">
                                <div class="row g-3">
                                    <div class="col-12 form-group-spacing">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="show_results" 
                                                   id="showResults" <?php echo ($settings['show_results_immediately'] ?? 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold" for="showResults">Show Results Immediately</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end gap-3 mt-4">
                                    <button type="reset" class="btn btn-secondary">Reset Defaults</button>
                                    <button type="submit" name="update_settings" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Daily Subjects Tab -->
                        <div class="tab-pane fade" id="daily-subjects" role="tabpanel">
                            <h5 class="mb-4">Manage Daily Subjects</h5>
                            <form method="POST" action="settings.php">
                                <div class="row g-3">
                                    <div class="col-md-6 form-group-spacing">
                                        <label class="form-label fw-bold">Exam Date</label>
                                        <input type="date" class="form-control" name="exam_date" 
                                               value="<?php echo htmlspecialchars($exam_date); ?>" required>
                                    </div>
                                    <div class="col-md-6 form-group-spacing">
                                        <label class="form-label fw-bold">Available Subjects</label>
                                        <select class="form-select" name="subjects[]" multiple required size="5">
                                            <?php foreach ($all_subjects as $subject): ?>
                                                <option value="<?php echo htmlspecialchars($subject); ?>">
                                                    <?php echo htmlspecialchars($subject); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Hold Ctrl/Cmd to select multiple subjects</small>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-3 mt-4">
                                    <button type="submit" name="save_daily_subjects" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Schedule
                                    </button>
                                </div>
                            </form>
                            
                            <!-- Display existing schedule -->
                            <h5 class="mt-5 mb-3">Current Daily Subjects</h5>
                            <?php if (empty($active_subjects)): ?>
                                <p class="text-muted">No active subjects scheduled.</p>
                            <?php else: ?>
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Subjects</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($active_subjects as $date => $subjects): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($date); ?></td>
                                                <td><?php echo htmlspecialchars(implode(', ', $subjects)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        // Check password strength
        function checkPasswordStrength(password) {
            const strengthIndicator = document.getElementById('password-strength');
            
            if (password.length === 0) {
                strengthIndicator.className = 'password-strength mt-1';
                return;
            }
            
            if (password.length < 5) {
                strengthIndicator.className = 'password-strength mt-1 strength-weak';
            } else if (password.length < 8) {
                strengthIndicator.className = 'password-strength mt-1 strength-medium';
            } else {
                strengthIndicator.className = 'password-strength mt-1 strength-strong';
            }
        }
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (e.submitter.name === 'change_password') {
                    const newPassword = form.querySelector('input[name="new_password"]').value;
                    const confirmPassword = form.querySelector('input[name="confirm_password"]').value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return false;
                    }
                    
                    if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long!');
                        return false;
                    }
                } else if (e.submitter.name === 'save_daily_subjects') {
                    const subjects = form.querySelector('select[name="subjects[]"]');
                    if (subjects.selectedOptions.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one subject!');
                        return false;
                    }
                }
            });
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                new bootstrap.Alert(alert).close();
            });
        }, 5000);
    </script>
</body>
</html>