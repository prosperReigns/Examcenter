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
    $stmt = $conn->prepare("SELECT first_name, last_name, username, email, phone, created_at, role FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacher = $result->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    // Initialize variables for form handling
    $errors = [];
    $success = '';
    $form_data = [
        'first_name' => $teacher['first_name'],
        'last_name' => $teacher['last_name'],
        'username' => $teacher['username'],
        'email' => $teacher['email'],
        'phone' => $teacher['phone']
    ];

    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $form_data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? '')
        ];

        // Server-side validation
        if (empty($form_data['first_name'])) {
            $errors[] = "First name is required";
        }
        if (empty($form_data['last_name'])) {
            $errors[] = "Last name is required";
        }
        if (empty($form_data['username'])) {
            $errors[] = "Username is required";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $form_data['username'])) {
            $errors[] = "Username must be 3-50 characters, alphanumeric or underscore";
        }
        if (empty($form_data['email'])) {
            $errors[] = "Email is required";
        } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }
        if (!empty($form_data['phone']) && !preg_match('/^[0-9+()-]{7,20}$/', $form_data['phone'])) {
            $errors[] = "Invalid phone number format";
        }

        // Check for unique username and email (excluding current teacher)
        $stmt = $conn->prepare("SELECT id FROM teachers WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $form_data['username'], $teacher_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Username is already taken";
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM teachers WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $form_data['email'], $teacher_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Email is already in use";
        }
        $stmt->close();

        if (empty($errors)) {
            // Update profile
            $stmt = $conn->prepare("UPDATE teachers SET first_name = ?, last_name = ?, username = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $form_data['first_name'], $form_data['last_name'], $form_data['username'], $form_data['email'], $form_data['phone'], $teacher_id);
            if ($stmt->execute()) {
                $success = "Profile updated successfully";
                $_SESSION['user_username'] = $form_data['username'];
                // Log activity
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $activity = "Teacher {$form_data['username']} updated profile";
                $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, NULL, ?, ?, NOW())");
                $stmt_log->bind_param("sss", $activity, $ip_address, $user_agent);
                $stmt_log->execute();
                $stmt_log->close();
                // Refresh teacher data
                $teacher = array_merge($teacher, $form_data);
            } else {
                error_log("Profile update failed: " . $stmt->error);
                $errors[] = "Failed to update profile";
            }
            $stmt->close();
        }
    }

    // Handle password change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate password change
        if (empty($current_password)) {
            $errors[] = "Current password is required";
        }
        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 8) {
            $errors[] = "New password must be at least 8 characters";
        }
        if ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }

        if (empty($errors)) {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM teachers WHERE id = ?");
            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $db_password = $result->fetch_assoc()['password'];
            $stmt->close();

            if (password_verify($current_password, $db_password)) {
                // Update password
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE teachers SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $new_password_hash, $teacher_id);
                if ($stmt->execute()) {
                    $success = "Password changed successfully";
                    // Log activity
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $user_agent = $_SERVER['HTTP_USER_AGENT'];
                    $activity = "Teacher {$teacher['username']} changed password";
                    $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, NULL, ?, ?, NOW())");
                    $stmt_log->bind_param("sss", $activity, $ip_address, $user_agent);
                    $stmt_log->execute();
                    $stmt_log->close();
                } else {
                    error_log("Password change failed: " . $stmt->error);
                    $errors[] = "Failed to change password";
                }
                $stmt->close();
            } else {
                $errors[] = "Current password is incorrect";
            }
        }
    }

} catch (Exception $e) {
    error_log("Profile error: " . $e->getMessage());
    die("System error");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
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
            <a href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </a>
            <a href="add_question.php">
                <i class="fas fa-plus-circle"></i>
                Add Questions
            </a>
            <a href="view_questions.php">
                <i class="fas fa-list"></i>
                View Questions
            </a>
            <a href="manage_test.php">
                <i class="fas fa-list"></i>
                Manage Test
            </a>
            <a href="view_results.php">
                <i class="fas fa-chart-bar"></i>
                Exam Results
            </a>
            <a href="manage_students.php" style="text-decoration: line-through">
                <i class="fas fa-users"></i>
                Manage Students
            </a>
            <a href="settings.php">
                <i class="fas fa-cog"></i>
                Settings
            </a>
            <a href="my-profile.php" class="active">
                <i class="fas fa-user"></i>
                My Profile
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">My Profile</h2>
            <div class="header-actions">
                <button class="btn btn-primary d-lg-none" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Profile Card -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>Profile Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="profileForm">
                            <input type="hidden" name="update_profile" value="1">
                            <div class="mb-3">
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($form_data['first_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($form_data['last_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($form_data['username']); ?>" required pattern="[a-zA-Z0-9_]{3,50}">
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($form_data['phone']); ?>" pattern="[0-9+()-]{7,20}">
                            </div>
                            <button type="submit" class="btn btn-primary">Update Profile</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card bg-white border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="passwordForm">
                            <input type="hidden" name="change_password" value="1">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Change Password</button>
                        </form>
                    </div>
                </div>
                <div class="card bg-white border-0 shadow-sm mt-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Assigned Subjects</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assigned_subjects)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p class="text-muted">No subjects assigned</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-group">
                                <?php foreach ($assigned_subjects as $subject): ?>
                                    <li class="list-group-item"><?php echo htmlspecialchars($subject); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <small class="text-muted">Contact your admin to update assigned subjects.</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle sidebar on mobile
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Client-side form validation
            $('#profileForm').on('submit', function(e) {
                let valid = true;
                const username = $('#username').val();
                const email = $('#email').val();
                const phone = $('#phone').val();

                if (!username.match(/^[a-zA-Z0-9_]{3,50}$/)) {
                    alert('Username must be 3-50 characters, alphanumeric or underscore');
                    valid = false;
                }
                if (!email.match(/^[^@]+@[^@]+\.[^@]+$/)) {
                    alert('Invalid email format');
                    valid = false;
                }
                if (phone && !phone.match(/^[0-9+()-]{7,20}$/)) {
                    alert('Invalid phone number format');
                    valid = false;
                }

                if (!valid) {
                    e.preventDefault();
                }
            });

            $('#passwordForm').on('submit', function(e) {
                const newPassword = $('#new_password').val();
                const confirmPassword = $('#confirm_password').val();

                if (newPassword.length < 8) {
                    alert('New password must be at least 8 characters');
                    e.preventDefault();
                } else if (newPassword !== confirmPassword) {
                    alert('Passwords do not match');
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>