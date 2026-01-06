<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check admin authentication
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
    $stmt = $conn->prepare("SELECT username, role FROM admins WHERE id = ?");
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

// Fetch available subjects from the database
$subjects = $conn->query("
    SELECT s.id AS subject_id, s.subject_name, sl.class_level
    FROM subjects s
    JOIN subject_levels sl ON s.id = sl.subject_id
    ORDER BY s.subject_name, sl.class_level
");
$available_subjects = [];
if ($subjects) {
    while ($row = $subjects->fetch_assoc()) {
        $available_subjects[] = [
            'id' => $row['subject_id'],
            'name' => $row['subject_name'],
            'level' => $row['class_level']
        ];
    }
}


// Fetch active classes
$classes = [];
$result = $conn->query("
    SELECT c.id, c.class_name
    FROM classes c
    WHERE c.is_active = 1
    ORDER BY c.class_name
");
while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}

// Initialize variables
$error = $success = '';
$first_name = $last_name = $email = $phone = $username = '';
$selected_subjects = [];
$selected_classes = [];
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

        // Fetch assigned classes
        $stmt = $conn->prepare("
        SELECT class_id FROM teacher_classes WHERE teacher_id = ?
        ");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
        $selected_classes[] = $row['class_id'];
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
    $selected_classes  = $_POST['classes'] ?? [];

    $username = strtolower($first_name . '.' . $last_name);
    $username = preg_replace('/[^a-z.]/', '', $username);

    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = "Please fill in all required fields";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (empty($selected_subjects)) {
        $error = "Please select at least one subject";
    }else {
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

                // Clear old class assignments (edit mode)
                $stmt = $conn->prepare("DELETE FROM teacher_classes WHERE teacher_id = ?");
                $stmt->bind_param("i", $teacher_id);
                $stmt->execute();

                // Insert class assignments
                foreach ($selected_classes as $class_id) {
                    $stmt = $conn->prepare("
                        INSERT INTO teacher_classes (teacher_id, class_id)
                        VALUES (?, ?)
                    ");
                    $stmt->bind_param("ii", $teacher_id, $class_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Assign class failed");
                    }
                }

                // Insert subjects
                foreach ($selected_subjects as $subject_level) {

                    // subject_level comes like: 3_JSS
                    list($subject_id, $class_level) = explode('_', $subject_level);
                
                    // get subject name from available subjects
                    $subject_name = null;
                    foreach ($available_subjects as $sub) {
                        if ($sub['id'] == $subject_id && $sub['level'] == $class_level) {
                            $subject_name = $sub['name'] . " (" . $class_level . ")";
                            break;
                        }
                    }
                
                    if (!$subject_name) {
                        throw new Exception("Invalid subject selected");
                    }
                
                    $stmt = $conn->prepare("
                        INSERT INTO teacher_subjects (teacher_id, subject)
                        VALUES (?, ?)
                    ");
                    $stmt->bind_param("is", $teacher_id, $subject_name);
                
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
    <title><?php echo $is_edit_mode ? 'Edit' : 'Add'; ?> Teacher | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/view_results.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <style>
        h6{
            color: white !important; 
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
        .username-preview {
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: monospace;
            margin-top: 5px;
            display: inline-block;
        }
        .password-field {
            display: <?php echo $is_edit_mode ? 'none' : 'block'; ?>;
        }
        @media (max-width: 991px) {
            .subject-item {
                flex: 0 0 50%;
            }
        }
        @media (max-width: 576px) {
            .subject-item {
                flex: 0 0 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info"><b>
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($user['username']); ?></h6></b>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php" class="active"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
            <a href="manage_session.php"><i class="fas fa-user-plus"></i>manage session</a>
            <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><?php echo $is_edit_mode ? 'Edit' : 'Add'; ?> Teacher</h2>
            <div class="d-flex gap-3">
                <a href="manage_teachers.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Teachers</a>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Teacher Form Card -->
        <div class="card bg-white border-0 shadow-sm filter-card mb-4">
            <div class="card-body">
                <h5 class="mb-3"><i class="fas fa-user-plus me-2"></i>Teacher Information</h5>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?><?php echo $is_edit_mode ? '?edit_id=' . $teacher_id : ''; ?>" id="teacherForm">
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
                        <?php foreach ($available_subjects as $subject): ?>
                            <div class="subject-item">
                                <div class="form-check">
                                    <input class="form-check-input subject-checkbox" type="checkbox" 
                                        name="subjects[]" value="<?= $subject['id'] ?>_<?= $subject['level'] ?>"
                                        id="subject-<?= $subject['id'] ?>-<?= strtolower($subject['level']) ?>"
                                        <?= in_array($subject['name'].'_'.$subject['level'], $selected_subjects) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="subject-<?= $subject['id'] ?>-<?= strtolower($subject['level']) ?>">
                                        <?= htmlspecialchars($subject['name'] . ' (' . $subject['level'] . ')') ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="form-label fw-bold">Assigned Classes</label>
                        <div class="subjects-container">
                            <?php foreach ($classes as $class): ?>
                                <div class="subject-item">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                            name="classes[]"
                                            value="<?= $class['id'] ?>"
                                            id="class-<?= $class['id'] ?>"
                                            <?= isset($selected_classes) && in_array($class['id'], $selected_classes) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="class-<?= $class['id'] ?>">
                                            <?= htmlspecialchars($class['class_name']) ?>
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

    <!-- Scripts -->
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Form validation
            $('#teacherForm').validate({
                rules: {
                    first_name: { required: true, maxlength: 50 },
                    last_name: { required: true, maxlength: 50 },
                    email: { required: true, email: true, maxlength: 100 },
                    phone: { maxlength: 15 },
                    password: { 
                        required: <?php echo $is_edit_mode ? 'false' : 'true'; ?>,
                        minlength: 8 
                    },
                    confirm_password: { 
                        required: <?php echo $is_edit_mode ? 'false' : 'true'; ?>,
                        equalTo: '[name="password"]' 
                    },
                    'subjects[]': { required: true }
                },
                messages: {
                    first_name: "Please enter a first name (max 50 characters).",
                    last_name: "Please enter a last name (max 50 characters).",
                    email: "Please enter a valid email (max 100 characters).",
                    phone: "Phone number is too long (max 15 characters).",
                    password: "Password must be at least 8 characters long.",
                    confirm_password: "Passwords do not match.",
                    'subjects[]': "Please select at least one subject."
                },
                errorElement: 'div',
                errorClass: 'invalid-feedback',
                highlight: function(element) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element) {
                    $(element).removeClass('is-invalid');
                },
                errorPlacement: function(error, element) {
                    if (element.attr('name') === 'subjects[]') {
                        error.insertAfter(element.closest('.subjects-container'));
                    } else {
                        error.insertAfter(element);
                    }
                }
            });

            // Update username preview
            function updateUsernamePreview() {
                const firstName = $('input[name="first_name"]').val().trim().toLowerCase();
                const lastName = $('input[name="last_name"]').val().trim().toLowerCase();
                
                let username = '';
                if (firstName && lastName) {
                    username = (firstName + '.' + lastName).replace(/[^a-z.]/g, '');
                }
                
                $('#usernamePreview').text(username || 'username.will.generate.here');
            }

            // Check password strength
            function checkPasswordStrength(password) {
                const strengthIndicator = $('#password-strength');
                
                if (!strengthIndicator.length) return;
                
                if (password.length === 0) {
                    strengthIndicator.text('');
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
                
                strengthIndicator.text(strengthText);
                strengthIndicator.removeClass('text-danger text-warning text-success').addClass(strengthClass);
            }

            // Clear form
            function clearForm() {
                $('#usernamePreview').text('username.will.generate.here');
                $('input[name="subjects[]"]').prop('checked', false);
                $('#password-strength').text('');
                $('#teacherForm').validate().resetForm();
                $('#teacherForm').find('.is-invalid').removeClass('is-invalid');
            }
        });
    </script>
</body>
</html>