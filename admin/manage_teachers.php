<?php
session_start();
require_once '../db.php';

// Check if admin is logged in
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
$success = $error = '';

// Define available classes and subjects (same as in add_question.php)
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];
$jss_subjects = ['Mathematics', 'English', 'ICT', 'Agriculture', 'History', 'Civic Education', 'Basic Science', 'Basic Technology', 'Business studies', 'Agricultural sci', 'Physical Health Edu', 'Cultural and Creative Art', 'Social Studies', 'Security Edu', 'Yoruba', 'french', 'Coding and Robotics', 'C.R.S', 'I.R.S', 'Chess'];
$ss_subjects = ['Mathematics', 'English', 'Civic Edu', 'Data Processing', 'Economics', 'Government', 'Commerce', 'Accounting', 'Financial Accounting', 'Dyeing and Bleaching', 'Physics', 'Chemistry', 'Biology', 'Agricultural Sci', 'Geography', 'technical Drawing', 'yoruba Lang', 'French Lang', 'Further Maths', 'Literature in English', 'C.R.S', 'I.R.S'];

// Handle teacher creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_teacher'])) {
    // $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    // Process selected subjects and classes
    $selected_subjects = isset($_POST['subjects']) ? $_POST['subjects'] : [];
    $subjects = implode(',', $selected_subjects);
    
    $selected_classes = isset($_POST['classes']) ? $_POST['classes'] : [];
    $classes_str = implode(',', $selected_classes);
    
    // Check if username or email already exists
    $check_sql = "SELECT id FROM teachers WHERE fullname = '$full_name' OR email = '$email'";
    $check_result = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error = "Username or email already exists";
    } else {
        // Insert new teacher
        $insert_sql = "INSERT INTO teachers (password, full_name, email, subjects, classes) 
                      VALUES ('$password', '$full_name', '$email', '$subjects', '$classes_str')";
        
        if (mysqli_query($conn, $insert_sql)) {
            $success = "Teacher added successfully!";
        } else {
            $error = "Error adding teacher: " . mysqli_error($conn);
        }
    }
}

// Handle teacher status update (activate/deactivate)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $teacher_id = mysqli_real_escape_string($conn, $_POST['teacher_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['status']);
    
    $update_sql = "UPDATE teachers SET status = '$new_status' WHERE id = $teacher_id";
    
    if (mysqli_query($conn, $update_sql)) {
        $success = "Teacher status updated successfully!";
    } else {
        $error = "Error updating teacher status: " . mysqli_error($conn);
    }
}

// Fetch all teachers
$teachers_sql = "SELECT * FROM teachers ORDER BY created_at DESC";
$teachers_result = mysqli_query($conn, $teachers_sql);
$teachers = mysqli_fetch_all($teachers_result, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/dashboard.css" rel="stylesheet">
</head>
<body>
    <!-- navigation bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <button class="btn btn-primary btn-sm toggle-sidebar" id="sidebarToggle">
                <i class="bi bi-list"></i>☰
            </button>
            <a class="navbar-brand" href="dashboard.php">CBT Admin</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar col-md-3 col-lg-2" id="sidebar">
                <div class="p-3">
                    <h5 class="mb-3">Admin Menu</h5>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">Dashboard</a>
                        </li>
                        <!-- <li class="nav-item">
                            <a class="nav-link" href="add_question.php">Add Questions</a>
                        </li> -->
                        <li class="nav-item">
                            <a class="nav-link active" href="manage_teachers.php">Manage Teachers</a>
                        </li>
                        <!-- <li class="nav-item">
                            <a class="nav-link" href="set_academic_year.php">Set Academic Year</a>
                        </li> -->
                        <!-- <li class="nav-item">
                            <a class="nav-link" href="view_results.php">View Results</a>
                        </li> -->
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="main-content col-md-9 col-lg-10 p-3">
                <?php if($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Add New Teacher</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <!-- <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control"  name="username" required>
                                </div> -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" class="form-control" name="password" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" name="full_name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Classes</label>
                                    <div class="border p-2 rounded" style="max-height: 150px; overflow-y: auto;">
                                        <?php foreach($classes as $class): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="classes[]" value="<?php echo $class; ?>">
                                                <label class="form-check-label"><?php echo $class; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Subjects</label>
                                    <div class="border p-2 rounded" style="max-height: 150px; overflow-y: auto;">
                                        <h6 class="small">JSS Subjects</h6>
                                        <?php foreach($jss_subjects as $subject): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="subjects[]" value="<?php echo $subject; ?>">
                                                <label class="form-check-label"><?php echo $subject; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <hr>
                                        <h6 class="small">SS Subjects</h6>
                                        <?php foreach($ss_subjects as $subject): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="subjects[]" value="<?php echo $subject; ?>">
                                                <label class="form-check-label"><?php echo $subject; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_teacher" class="btn btn-primary">Add Teacher</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Manage Teachers</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Classes</th>
                                        <th>Subjects</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($teachers) > 0): ?>
                                        <?php foreach($teachers as $teacher): ?>
                                            <tr>
                                                <td><?php echo $teacher['id']; ?></td>
                                                <td><?php echo htmlspecialchars($teacher['username']); ?></td>
                                                <td><?php echo htmlspecialchars($teacher['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                                <td><?php echo htmlspecialchars($teacher['classes']); ?></td>
                                                <td><?php echo htmlspecialchars($teacher['subjects']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $teacher['status'] == 'active' ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($teacher['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="POST" action="" class="d-inline">
                                                        <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                        <input type="hidden" name="status" value="<?php echo $teacher['status'] == 'active' ? 'inactive' : 'active'; ?>">
                                                        <button type="submit" name="update_status" class="btn btn-sm btn-<?php echo $teacher['status'] == 'active' ? 'warning' : 'success'; ?>">
                                                            <?php echo $teacher['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No teachers found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.querySelector('.main-content').classList.toggle('expanded');
        });
    </script>
</body>
</html>