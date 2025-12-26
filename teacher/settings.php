<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

// Initialize database connection
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    // Fetch teacher profile
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name, password FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Initialize variables
    $error = $success = '';
    $current_password = $new_password = $confirm_password = '';

    // Fetch current settings
    $settings = [];
    $settings_query = "SELECT setting_name, setting_value FROM settings WHERE setting_name = 'show_results_immediately'";
    $settings_result = $conn->query($settings_query);
    if ($settings_result) {
        while ($row = $settings_result->fetch_assoc()) {
            $settings[$row['setting_name']] = $row['setting_value'];
        }
    }

    // Handle password change
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
        $current_password = trim($_POST['current_password']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);

        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all password fields.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters long.";
        } elseif (!preg_match("/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/", $new_password)) {
            $error = "New password must contain both letters and numbers.";
        } else {
            try {
                // Verify current password
                if (password_verify($current_password, $teacher['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE teachers SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashed_password, $teacher_id);
                    if ($stmt->execute()) {
                        // Log activity
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        $activity = "Teacher {$teacher['username']} changed their password.";
                        $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, teacher_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                        $stmt_log->execute();
                        $stmt_log->close();

                        $success = "Password changed successfully!";
                        $current_password = $new_password = $confirm_password = '';
                    } else {
                        $error = "Error updating password: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Current password is incorrect.";
                }
            } catch (Exception $e) {
                error_log("Password change error: " . $e->getMessage());
                $error = "Error changing password: " . $e->getMessage();
            }
        }
    }

    // Handle system settings update
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
        $show_results = isset($_POST['show_results']) ? 1 : 0;

        try {
            // Update or insert setting
            $stmt = $conn->prepare("INSERT INTO settings (setting_name, setting_value) VALUES (?, ?) 
                                    ON DUPLICATE KEY UPDATE setting_value = ?");
            $setting_name = 'show_results_immediately';
            $stmt->bind_param("sii", $setting_name, $show_results, $show_results);
            if ($stmt->execute()) {
                // Log activity
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $activity = "Teacher {$teacher['username']} updated system setting 'show_results_immediately' to $show_results.";
                $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, teacher_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                $stmt_log->execute();
                $stmt_log->close();

                $success = "System settings updated successfully!";
            } else {
                $error = "Error updating settings: " . $conn->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Settings update error: " . $e->getMessage());
            $error = "Error updating settings: " . $e->getMessage();
        }
    }

    // Determine active tab
    $active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['password', 'system']) ? $_GET['tab'] : 'password';

} catch (Exception $e) {
    error_log("Settings error: " . $e->getMessage());
    die("System error");
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/settings.css">
    <style>
        .settings-card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group-spacing { margin-bottom: 1.5rem; }
        .password-strength { height: 5px; margin-top: 5px; border-radius: 2px; }
        .strength-weak { background-color: #dc3545; width: 30%; }
        .strength-medium { background-color: #ffc107; width: 60%; }
        .strength-strong { background-color: #28a745; width: 100%; }
        .nav-pills .nav-link.active { background-color: #4361ee; }
        .nav-pills .nav-link { color: #4361ee; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($teacher['last_name']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="manage_test.php"><i class="fas fa-list"></i>Manage Test</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="manage_students.php" style="text-decoration: line-through"><i class="fas fa-users"></i>Manage Students</a>
            <a href="settings.php" class="active"><i class="fas fa-cog"></i>Settings</a>
            <a href="my-profile.php"><i class="fas fa-user"></i>My Profile</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Settings</h2>
            <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="settings-card">
                        <ul class="nav nav-pills mb-4" id="settingsTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <a class="nav-link <?php echo $active_tab === 'password' ? 'active' : ''; ?>" href="?tab=password">
                                    <i class="fas fa-lock me-2"></i>Change Password
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link <?php echo $active_tab === 'system' ? 'active' : ''; ?>" href="?tab=system">
                                    <i class="fas fa-cog me-2"></i>System Settings
                                </a>
                            </li>
                        </ul>

                        <div class="tab-content" id="settingsTabsContent">
                            <!-- Password Change Tab -->
                            <div class="tab-pane fade <?php echo $active_tab === 'password' ? 'show active' : ''; ?>" id="password" role="tabpanel">
                                <h5 class="mb-4">Change Your Password</h5>
                                <form method="POST" action="settings.php">
                                    <input type="hidden" name="tab" value="password">
                                    <div class="row g-3">
                                        <div class="col-12 form-group-spacing">
                                            <label class="form-label fw-bold">Current Password</label>
                                            <input type="password" class="form-control" name="current_password" required>
                                        </div>
                                        <div class="col-md-6 form-group-spacing">
                                            <label class="form-label fw-bold">New Password</label>
                                            <input type="password" class="form-control" name="new_password" id="newPassword" required oninput="checkPasswordStrength(this.value)">
                                            <div id="password-strength" class="password-strength"></div>
                                            <small class="text-muted">Minimum 8 characters, including letters and numbers</small>
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
                            <div class="tab-pane fade <?php echo $active_tab === 'system' ? 'show active' : ''; ?>" id="system" role="tabpanel">
                                <h5 class="mb-4">System Configuration</h5>
                                <form method="POST" action="settings.php">
                                    <input type="hidden" name="tab" value="system">
                                    <div class="row g-3">
                                        <div class="col-12 form-group-spacing">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="show_results" id="showResults" <?php echo ($settings['show_results_immediately'] ?? 0) ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-bold" for="showResults">Show Results Immediately</label>
                                                <small class="form-text text-muted">Enable to display exam results to students immediately after submission.</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-end gap-3 mt-4">
                                        <button type="reset" class="btn btn-secondary">Reset</button>
                                        <button type="submit" name="update_settings" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save Settings
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            console.log('jQuery and DOM ready');
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                console.log('Sidebar toggle clicked');
                $('.sidebar').toggleClass('active');
            });

            // Check password strength
            function checkPasswordStrength(password) {
                const strengthIndicator = $('#password-strength');
                const newPasswordInput = $('#newPassword');
                let strength = 0;

                if (password.length >= 8) strength += 1;
                if (/[A-Za-z]/.test(password)) strength += 1;
                if(/[0-9]/.test(password)) strength += 1;

                strengthIndicator.removeClass('strength-weak strength-medium strength-strong');
                if (password.length === 0) {
                    strengthIndicator.hide();
                } else if (strength <= 1) {
                    strengthIndicator.addClass('strength-weak').show();
                } else if (strength === 2) {
                    strengthIndicator.addClass('strength-medium').show();
                } else {
                    strengthIndicator.addClass('strength-strong').show();
                }
            }

            // Form validation
            $('form').on('submit', function(e) {
                if ($(e.originalEvent.submitter).attr('name') === 'change_password') {
                    const newPassword = $('#newPassword').val();
                    const confirmPassword = $('input[name="confirm_password"]').val();

                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return false;
                    }
                    if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('New password must be at least 8 characters long!');
                        return false;
                    }
                    if (!/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/.test(newPassword)) {
                        e.preventDefault();
                        alert('New password must contain both letters and numbers!');
                        return false;
                    }
                }
            });

            // Auto-hide alerts
            setTimeout(() => {
                $('.alert').alert('close');
            }, 5000);
        });
    </script>
</body>
</html>