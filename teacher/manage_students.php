<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// 
header('Content-Type: text/html; charset=UTF-8');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

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

      // Initialize variables
      $error = $success = '';
    // Fetch teacher profile and assigned subjects
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name FROM teachers WHERE id = ?");
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

    // Get teacher's assigned class IDs
    $stmt = $conn->prepare("
    SELECT class_id 
    FROM teacher_classes 
    WHERE teacher_id = ?
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_class_ids = [];
    while ($row = $result->fetch_assoc()) {
    $assigned_class_ids[] = $row['class_id'];
    }
    $stmt->close();

    // If no classes assigned
    if (empty($assigned_class_ids)) {
    $error = "You are not assigned to any class yet.";
    }

    // If teacher has exactly ONE class, auto-select it
    $default_class_id = null;
    if (count($assigned_class_ids) === 1) {
        $default_class_id = (int)$assigned_class_ids[0];
    }

     // HANDLE ADD STUDENT
    // ===============================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {

        $full_name = trim($_POST['full_name']);
        $reg_no    = trim($_POST['reg_no']);
        $email   = trim($_POST['email'] ?? null);
        $phone   = trim($_POST['phone'] ?? null);
        $address = trim($_POST['address'] ?? null);

        $class_id = $default_class_id;

        if (!$class_id) {
            $error = "Unable to determine class for this teacher.";
            return;
        }


        if (empty($full_name)) {
            $error = "Student name is required.";
        } else {

            // Prevent duplicate student in same class
            $check = $conn->prepare("
                SELECT id FROM students 
                WHERE full_name = ? AND class = ?
            ");
            $check->bind_param("si", $full_name, $class_id);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $error = "Student already exists in this class.";
            } else {

                $stmt = $conn->prepare("
                    INSERT INTO students 
                (full_name, reg_no, email, phone, address, photo, class, created_via) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'class_management')");
                $stmt->bind_param(
                    "ssssssi",
                    $full_name,
                    $reg_no,
                    $email,
                    $phone,
                    $address,
                    $photo_path,
                    $class_id
                );                
                if ($stmt->execute()) {
                    $success = "Student added successfully.";
                } else {
                    $error = "Failed to add student.";
                }
                $stmt->close();
            }
        }

        $photo_path = null;

        if (!empty($_FILES['photo']['name'])) {
            $upload_dir = '../uploads/students/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('student_') . '.' . $ext;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
                $photo_path = 'uploads/students/' . $filename;
            }
        }
    }

    // Fetch students for the assigned classes
    $students_by_class = [];
    if (!empty($assigned_class_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_class_ids), '?'));
    $types = str_repeat('i', count($assigned_class_ids));

    $stmt = $conn->prepare("
    SELECT 
        s.id,
        s.full_name,
        s.email,
        s.photo,
        s.phone,
        s.reg_no,
        s.class AS class_id,
        c.class_name
    FROM students s
    JOIN classes c ON s.class = c.id
    WHERE s.class IN ($placeholders)
    ORDER BY c.class_name, s.full_name
");


    $stmt->bind_param($types, ...$assigned_class_ids);
    $stmt->execute();
    $students = $stmt->get_result();

    while ($row = $students->fetch_assoc()) {
            $students_by_class[$row['class_name']][] = $row;
        }
    $stmt->close();
    }
} catch (Exception $e) {
    error_log("Manage student error: ". $e->getMessage());
     echo "<pre>System error: " . $e->getMessage() . "</pre>";
    die("Unable to fetch student details. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <style>
        .profile-image-wrapper {
    width: 140px;
    height: 140px;
}

.profile-image {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #dee2e6;
}

.edit-btn {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 36px;
    height: 36px;
    background: #4361ee;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,.25);
}

.edit-btn:hover {
    background: #364fc7;
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
                <h6><?php echo htmlspecialchars($teacher['last_name']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" class="active"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="manage_test.php"><i class="fas fa-list"></i>Manage Test</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="manage_students.php"><i class="fas fa-users"></i>Manage Students</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="my-profile.php"><i class="fas fa-user"></i>My Profile</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Manage Students</h2>
            <div class="header-actions">
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

             <!-- ADD STUDENT FORM -->
        <div class="card mb-4">
            <div class="card-header">
                <strong>Add Student to Class</strong>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="studentForm">
                <input type="hidden" name="student_id" id="student_id" value="">
                <div class="text-center mb-4">
                    <div class="profile-image-wrapper position-relative mx-auto">
                        <img src="/EXAMCENTER/uploads/students/default.png" class="profile-image" alt="Profile">

                        <!-- Edit button -->
                        <label for="profileImageInput" class="edit-btn">
                            <i class="fas fa-pen"></i>
                        </label>
                        <input type="file" id="profileImageInput" hidden accept="image/*">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <input type="text" name="full_name" class="form-control" placeholder="Student Full Name" required>
                    </div>

                    <div class="col-md-6">
                        <input type="text" name="reg_no" class="form-control" placeholder="Reg No">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <input type="email" name="email" class="form-control" placeholder="Parent Email">
                    </div>

                    <div class="col-md-6">
                        <input type="text" name="phone" class="form-control" placeholder="Parent Phone">
                    </div>
                </div>

                    <div class="col-12">
                        <textarea name="address" class="form-control mt-2" placeholder="Home Address"></textarea>
                    </div>

                    <div class="text-center mt-3">
                        <button type="submit" name="add_student" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i> 
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Edit Student Modal -->
        <div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form id="editStudentForm">
            <div class="modal-content">
                <div class="modal-header">
                <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                <input type="hidden" name="id" id="edit_student_id">
                
                <div class="mb-3">
                    <label for="edit_full_name" class="form-label">Full Name</label>
                    <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                </div>
                <div class="mb-3">
                    <label for="edit_reg_no" class="form-label">Reg No</label>
                    <input type="text" class="form-control" name="reg_no" id="edit_reg_no">
                </div>
                <div class="mb-3">
                    <label for="edit_email" class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" id="edit_email">
                </div>
                <div class="mb-3">
                    <label for="edit_phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" id="edit_phone">
                </div>
                <div class="mb-3">
                    <label for="edit_address" class="form-label">Address</label>
                    <textarea class="form-control" name="address" id="edit_address"></textarea>
                </div>
                </div>
                <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
            </form>
        </div>
        </div>

        <?php foreach ($students_by_class as $class => $students): ?>
        <div class="card mt-4">
            <?php if (!empty($students)): ?>
                <div class="card-header">
                    <strong>Class: <?php echo htmlspecialchars($class); ?></strong>
                </div>
                <div class="card-body p-0">
                <table class="table table-bordered table-striped mt-3" class="studentsTable">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr data-full_name="<?php echo htmlspecialchars($student['full_name']); ?>"
                            data-reg_no="<?php echo htmlspecialchars($student['reg_no']); ?>"
                            data-email="<?php echo htmlspecialchars($student['email']); ?>"
                            data-phone="<?php echo htmlspecialchars($student['phone']); ?>"
                            data-address="<?php echo htmlspecialchars($student['address'] ?? ""); ?>"
                            >
                                <td class="d-flex align-items-center gap-2">
                                    <img src="<?php echo $student['photo'] ? '../' . $student['photo'] : '../assets/default-avatar.png'; ?>"
                                        width="40" height="40" class="rounded-circle">
                                    <?php echo htmlspecialchars($student['full_name']); ?>
                                </td>

                                <td><?php echo htmlspecialchars($student['phone'] ?? '-'); ?></td>

                                <td><?php echo htmlspecialchars($student['email'] ?? '-'); ?></td>

                                <td>
                                    <a href="student_profile.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-primary">View Profile</a>
                                    <button data-id="<?php echo $student['id']; ?>" class="btn btn-sm btn-warning btn-edit-student">Edit</button>
                                    <button data-id="<?php echo $student['id']; ?>" data-url="/EXAMCENTER/teacher/delete_student.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-danger btn-delete-student">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p>No students found for your assigned classes.</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle sidebar on mobile
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Make the student name clickable
            $('.studentsTable tbody').on('click', 'td.student-name', function() {
                const studentId = $(this).data('id');
                if (studentId) {
                    window.location.href = `student_profile.php?id=${studentId}`;
                }
            });

            // Optional: highlight row on hover
            $('.studentsTable tbody tr').hover(
                function() { $(this).css('background-color', '#f0f0f0'); },
                function() { $(this).css('background-color', ''); }
            );

            // ===== DELETE STUDENT =====
            $('.btn-delete-student').on('click', function(e) {
                e.preventDefault();
                const url = $(this).data('url');
                if (confirm("Are you sure you want to delete this student?")) {
                    // Optional: show loader/spinner here
                    $.ajax({
                        url: url,
                        type: 'POST',
                        data: { delete: 1 }, // just a flag for backend
                        success: function(response) {
                            const res = JSON.parse(response);
                            if (res.success) {
                                alert(res.message);
                                // Remove row from table
                                $(e.target).closest('tr').fadeOut(300, function() { $(this).remove(); });
                            } else {
                                alert(res.message || "Failed to delete student.");
                            }
                        },
                        error: function(err) {
                            console.error(err);
                            alert("Error deleting student.");
                        }
                    });
                }
            });

            // ===== EDIT STUDENT =====
            $('.btn-edit-student').on('click', function (e) {
                e.preventDefault();
                const row = $(this).closest('tr');

                // Get student info from data attributes
                const studentId = $(this).data('id');
                const fullName = row.data('full_name') || '';
                const regNo = row.data('reg_no') || '';
                const email = row.data('email') || '';
                const phone = row.data('phone') || '';
                const address = row.data('address') || '';

                // Fill modal form
                $('#edit_student_id').val(studentId);
                $('#edit_full_name').val(fullName);
                $('#edit_reg_no').val(regNo);
                $('#edit_email').val(email);
                $('#edit_phone').val(phone);
                $('#edit_address').val(address);

                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('editStudentModal'));
                modal.show();
            });


            $('#editStudentForm').on('submit', function (e) {
                e.preventDefault();

                const formData = $(this).serialize();

                $.ajax({
                    url: '/EXAMCENTER/teacher/edit_student.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            alert(res.message);
                            location.reload(); // Reload table to see changes
                        } else {
                            alert(res.message || "Failed to update student");
                        }
                    },
                    error: function (xhr) {
                        console.error(xhr.responseText);
                        alert("Error updating student");
                    }
                });
            });
        });

    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const nameInput = document.querySelector('input[name="full_name"]');
            if (nameInput) {
                nameInput.focus();
            }
        });
    </script>
</body>
</html>