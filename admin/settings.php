    <?php
    session_start();
    require_once '../db.php';

    // Enable error reporting
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', '../logs/errors.log');

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') {
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

        // Fetch admin profile
        $admin_id = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT username, password, role FROM admins WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed for admin profile: " . $conn->error);
            die("Database error");
        }
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin || strtolower($admin['role']) !== 'admin') {
            error_log("Unauthorized access attempt by user_id=$admin_id, role=" . ($admin['role'] ?? 'none'));
            session_destroy();
            header("Location: /EXAMCENTER/login.php?error=Unauthorized");
            exit();
        }

        // Log page access
        $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $activity = "Admin {$admin['username']} accessed settings page.";
        $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Page error: " . $e->getMessage());
        die("System error");
    }

    // Initialize variables
    $error = $success = '';
    $current_password = $new_password = $confirm_password = '';
    $exam_date = date('Y-m-d');
    $selected_subjects = [];

    // Fetch available subjects (lowercase to match database)
    $jss_subjects = [
        'mathematics', 'english', 'ict', 'agriculture', 'history', 
        'civic education', 'basic science', 'basic technology', 
        'business studies', 'agriculture sci', 'physical health edu',
        'cultural and creative art', 'social studies', 'security edu', 
        'yoruba', 'french', 'coding and robotics', 'c.r.s', 'i.r.s', 'chess'
    ];
    $ss_subjects = [
        'mathematics', 'english', 'civic education', 'data processing', 'economics',
        'government', 'commerce', 'accounting','dyeing and bleaching', 'physics', 'chemistry', 'biology', 'agriculture sci', 'geography', 'technical drawing', 'yoruba',
        'french', 'further maths', 'literature in english', 'c.r.s', 'i.r.s'
    ];
    $all_subjects = array_unique(array_merge($jss_subjects, $ss_subjects));

    // Fetch current settings
    $settings = [];
    try {
        $settings_query = "SELECT setting_name, setting_value FROM settings WHERE setting_name IN ('show_results_immediately')";
        $settings_result = $conn->query($settings_query);
        if ($settings_result) {
            while ($row = $settings_result->fetch_assoc()) {
                $settings[$row['setting_name']] = $row['setting_value'];
            }
            $settings_result->free();
        }
    } catch (Exception $e) {
        error_log("Error fetching settings: " . $e->getMessage());
        $error = "Failed to load system settings.";
    }

    // Handle password change
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
        $current_password = trim($_POST['current_password']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        // Server-side validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all password fields.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Za-z0-9]/', $new_password)) {
            $error = "Password must contain letters or numbers.";
        } else {
            try {
                // Verify current password
                if (password_verify($current_password, $admin['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashed_password, $admin_id);
                    if ($stmt->execute()) {
                        $success = "Password changed successfully!";
                        $current_password = $new_password = $confirm_password = '';
                        // Log activity
                        $activity = "Admin {$admin['username']} changed their password.";
                        $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
                        $stmt->execute();
                    } else {
                        $error = "Error updating password: " . $conn->error;
                    }
                } else {
                    $error = "Current password is incorrect.";
                }
                $stmt->close();
            } catch (Exception $e) {
                error_log("Error changing password: " . $e->getMessage());
                $error = "Error changing password.";
            }
        }
    }

    // Handle system settings update
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
        $show_results = isset($_POST['show_results']) ? 1 : 0;
        
        try {
            $stmt = $conn->prepare("INSERT INTO settings (setting_name, setting_value) VALUES (?, ?) 
                                    ON DUPLICATE KEY UPDATE setting_value = ?");
            $setting_name = 'show_results_immediately';
            $stmt->bind_param("sii", $setting_name, $show_results, $show_results);
            if ($stmt->execute()) {
                $success = "System settings updated successfully!";
                // Log activity
                $activity = "Admin {$admin['username']} updated system settings: show_results_immediately=$show_results.";
                $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
                $stmt->execute();
            } else {
                $error = "Error updating settings: " . $conn->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error updating settings: " . $e->getMessage());
            $error = "Error updating system settings.";
        }
    }

    // Handle daily subjects update
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_daily_subjects'])) {
        $exam_date = trim($_POST['exam_date']);
        $selected_subjects = array_unique($_POST['subjects'] ?? []);
        
        // Validate inputs
        if (empty($exam_date)) {
            $error = "Please select a date.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam_date)) {
            $error = "Invalid date format.";
        } elseif (empty($selected_subjects)) {
            $error = "Please select at least one subject.";
        } else {
            try {
                $conn->begin_transaction();
                
                // Clear existing subjects for the date
                $stmt = $conn->prepare("DELETE FROM active_exams WHERE exam_date = ?");
                $stmt->bind_param("s", $exam_date);
                $stmt->execute();
                
                // Insert new active subjects
                $stmt = $conn->prepare("INSERT INTO active_exams (subject, is_active, exam_date) VALUES (?, 1, ?)");
                $valid_subjects = array_intersect($selected_subjects, $all_subjects);
                foreach ($valid_subjects as $subject) {
                    $stmt->bind_param("ss", $subject, $exam_date);
                    $stmt->execute();
                }
                
                $conn->commit();
                $success = "Daily subjects updated successfully!";
                $selected_subjects = [];
                // Log activity
                $activity = "Admin {$admin['username']} updated daily subjects for date $exam_date: " . implode(', ', $valid_subjects);
                $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("siss", $activity, $admin_id, $ip_address, $user_agent);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Error updating daily subjects: " . $e->getMessage());
                $error = "Error updating daily subjects.";
            }
        }
    }

    // Fetch current active subjects
    try {
        $active_subjects_query = "SELECT subject, exam_date FROM active_exams WHERE is_active = 1 ORDER BY exam_date DESC, subject";
        $active_subjects_result = $conn->query($active_subjects_query);
        $active_subjects = [];
        if ($active_subjects_result) {
            while ($row = $active_subjects_result->fetch_assoc()) {
                $active_subjects[$row['exam_date']][] = $row['subject'];
            }
            $active_subjects_result->free();
        }
    } catch (Exception $e) {
        error_log("Error fetching active subjects: " . $e->getMessage());
        $error = "Failed to load active subjects.";
    }

    $conn->close();
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Settings | D-Portal CBT</title>
        <link href="../css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="../css/all.css">
        <link rel="stylesheet" href="../css/admin-dashboard.css">
        <link rel="stylesheet" href="../css/dashboard.css">
        <link rel="stylesheet" href="../css/sidebar.css">
        <style>
            .settings-card {
                background-color: white;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .nav-pills .nav-link.active {
                background-color: #4361ee;
                color: white;
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
            .admin-info small {
                font-size: 0.8rem;
                opacity: 0.7;
                color: white;
            }
            .admin-info h6{
                color: white;
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
            .subject-badge {
                background-color: #e9ecef;
                color: #212529;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.85rem;
                margin-right: 5px;
                margin-bottom: 5px;
                display: inline-block;
            }
        </style>
    </head>
    <body>
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-brand">
                <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
                <div class="admin-info">
                    <small>Welcome back,</small>
                    <h6><?php echo htmlspecialchars($admin['username']); ?></h6>
                </div>
            </div>
            <div class="sidebar-menu mt-4">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
                <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
                <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
                <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
                <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
                <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
                <a href="settings.php" class="active"><i class="fas fa-cog"></i>Settings</a>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">System Settings</h2>
                <div class="d-flex gap-3">
                    <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Settings Panel -->
            <div class="settings-card">
                <ul class="nav nav-pills mb-4" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                            <i class="fas fa-lock me-2"></i>Change Password
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system-settings" type="button" role="tab">
                            <i class="fas fa-cog me-2"></i>System Settings
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="daily-subjects-tab" data-bs-toggle="tab" data-bs-target="#daily-subjects" type="button" role="tab">
                            <i class="fas fa-calendar-day me-2"></i>Daily Subjects
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="settingsTabsContent">
                    <!-- Password Change Tab -->
                    <div class="tab-pane fade show active" id="password" role="tabpanel">
                        <h5 class="mb-4">Change Password</h5>
                        <form method="POST" id="passwordForm" action="">
                            <div class="row g-3">
                                <div class="col-12 form-group-spacing">
                                    <label class="form-label fw-bold" for="current-password">Current Password</label>
                                    <input type="password" class="form-control" id="current-password" name="current_password" required>
                                    <div class="invalid-feedback">Please enter your current password.</div>
                                </div>
                                <div class="col-md-6 form-group-spacing">
                                    <label class="form-label fw-bold" for="new-password">New Password</label>
                                    <input type="password" class="form-control" id="new-password" name="new_password" required>
                                    <div class="password-strength" id="password-strength"></div>
                                    <small class="text-muted">Minimum 8 characters, including letters or numbers</small>
                                    <div class="invalid-feedback">Please enter a valid password.</div>
                                </div>
                                <div class="col-md-6 form-group-spacing">
                                    <label class="form-label fw-bold" for="confirm-password">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm-password" name="confirm_password" required>
                                    <div class="invalid-feedback">Passwords must match.</div>
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
                    <div class="tab-pane fade" id="system-settings" role="tabpanel">
                        <h5 class="mb-4">System Configuration</h5>
                        <form method="post" id="systemSettingsForm" action="">
                            <div class="row g-3">
                                <div class="col-12 form-group-spacing">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="show_results" 
                                            id="showResults" <?php echo ($settings['show_results_immediately'] ?? '0') ? 'checked' : ''; ?>>
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
                            <h5 class="mb-3">Manage Daily Subjects</h5>
                            <form method="POST" id="dailySubjectsForm" action="">
                                <div class="row g-3">
                                    <div class="col-md-6 form-group-spacing">
                                        <label class="form-label fw-bold" for="exam-date">Date</label>
                                        <input type="date" class="form-control" id="exam-date" name="exam_date" 
                                            value="<?php echo htmlspecialchars($exam_date); ?>" required>
                                            <div class="invalid-feedback">Please select a valid date.</div>
                                    </div>

                                    <div class="col-md-6 form-group-spacing">
                                            <label class="form-label fw-bold" for="subjects-select">Subjects</label>
                                            <select class="form-select" id="subjects-select" name="subjects[]" required size="5" multiple>
                                                <?php foreach ($all_subjects as $subject): ?>
                                                    <option value="<?php echo htmlspecialchars($subject); ?>">
                                                        <?php echo htmlspecialchars(ucwords($subject)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Hold Ctrl/Cmd to select multiple subjects</small>
                                            <div class="invalid-feedback">Please select at least one subject.</div>
                                    </div>

                                </div>
                                    <div class="d-flex justify-content-end gap-3 mt-4">
                                        <button type="submit" name="save_daily_subjects" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save Schedule
                                        </button>
                                    </div>
                            </form>
                                
                                <!-- Display existing schedule -->
                                <h5 class="mt-5 mb-3">Current Schedule</h5>
                                    <?php if (!empty($active_subjects)): ?>
        <div class="table table-striped">
            <table>
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
                        <td>
                            <?php foreach ($subjects as $subject): ?>
                                <span class="subject-badge"><?php echo htmlspecialchars(ucwords($subject)); ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-calendar-minus fa-3x mb-3"></i>
            <h5>No Active Subjects</h5>
            <p>Schedule daily subjects to enable exams.</p>
        </div>
    <?php endif; ?>

                </div>

            </div>
        </div>

                    <!-- Scripts -->
                    <script src="../js/jquery-3.7.0.min.js"></script>
                    <script src="../js/bootstrap.bundle.min.js"></script>
                    <script src="../js/dataTables.min.js"></script>
                    <script src="../js/dataTables.bootstrap5.min.js"></script>
                    <script src="../js/jquery.validate.min.js"></script>
                    <script>
                        $(document).ready(function() {
                            console.log('jQuery and DOM ready');
                            // Sidebar toggle
                            $('#sidebarToggle').click(function() {
                                $('.sidebar').toggleClass('active');
                            });

                            // Password strength check
                            function checkPasswordStrength(password) {
                                const strengthIndicator = $('#password-strength');
                                if (password.length === 0) {
                                    strengthIndicator.removeClass('strength-weak strength-medium strength-strong');
                                    return;
                                }
                                if (password.length < 6) {
                                    strengthIndicator.removeClass('strength-medium strength-strong').addClass('strength-weak').css('width', '30%');
                                } else if (password.length < 8) {
                                    strengthIndicator.removeClass('strength-weak strength-strong').addClass('strength-medium').css('width', '60%');
                                } else {
                                    strengthIndicator.removeClass('strength-strong').removeClass('strength-weak strength-medium').css('width', '100%');
                                }
                            };

                            // Form validation with jQuery Validate
                            $('#passwordForm').validate({
                                rules: {
                                    current_password: {
                                        required: true,
                                        minlength: 8
                                    },
                                    new_password: {
                                        required: true,
                                        minlength: 8,
                                        regex: /^[A-Za-z0-9]+$/
                                    },
                                    confirm_password: {
                                        required: true,
                                        equalTo: '#new-password'
                                    }
                                },
                                messages: {
                                    current_password: {
                                        required: 'Please enter your current password.',
                                        minlength: 'Password must be at least 8 characters.'
                                    },
                                    new_password: {
                                        required: 'Please enter a new password.',
                                        minlength: 'Password must be at least 8 characters.',
                                        regex: 'Password must contain letters or numbers only.'
                                    },
                                    confirm_password: {
                                        required: 'Please confirm your password.',
                                        equalTo: 'Passwords must match.'
                                    }
                                },
                                errorElement: 'div',
                                errorClass: 'invalid-feedback',
                                highlight: function(element) {
                                    $(element).addClass('is-invalid');
                                },
                                unhighlight: function(element) {
                                    $(element).removeClass('is-invalid');
                                }
                            });

                            $('#systemSettingsForm').validate({
                                rules: {
                                    show_results: {
                                        required: false // Optional field
                                    }
                                },
                                errorElement: 'div',
                                errorClass: 'invalid-feedback',
                                highlight: function(element) {
                                    $(element).closest('.form-check').addClass('has-error');
                                },
                                unhighlight: function(element) {
                                    $(element).closest('.form-check').removeClass('has-error');
                                }
                            });

                            $('#dailySubjectsForm').validate({
                                rules: {
                                    exam_date: {
                                        required: true,
                                        regex: /^\d{4}-\d{2}-\d{2}$/
                                    },
                                    'subjects[]': {
                                        required: true
                                    }
                                },
                                messages: {
                                    exam_date: {
                                        required: 'Please select a date.',
                                        regex: 'Please enter a valid date format (YYYY-MM-DD).'
                                    },
                                    'subjects[]': {
                                        required: 'Please select at least one subject.'
                                    }
                                },
                                errorElement: 'div',
                                errorClass: 'invalid-feedback',
                                highlight: function(element) {
                                    $(element).addClass('is-invalid');
                                },
                                unhighlight: function(element) {
                                    $(element).removeClass('is-invalid');
                                }
                            });

                            // Bind password strength check
                            $('#new-password').on('input', function() {
                                checkPasswordStrength($(this).val());
                            });

                            // Auto-hide alerts after 3 seconds
                            setTimeout(() => {
                                $('.alert').each(function() {
                                    new bootstrap.Alert($(this)).close();
                                });
                            }, 5000);
                        });
                    </script>
    </body>
    </html>