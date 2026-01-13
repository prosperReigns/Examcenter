<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

$database = Database::getInstance();
$conn = $database->getConnection();

// Fetch admin info from DB
$admin_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, role FROM admins WHERE id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if (!$admin || strtolower($admin['role']) !== 'admin') {
    session_destroy();
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

$error = $success = '';

// Fetch ALL students grouped by class (ADMIN VIEW)
$students_by_class = [];

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
    ORDER BY c.class_name, s.full_name
");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $students_by_class[$row['class_name']][] = $row;
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students | Admin</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><b><?php echo htmlspecialchars($admin['username']); ?></b></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
            <a href="manage_session.php"><i class="fas fa-users"></i>Manage Session</a>
            <a href="manage_subject.php" class="active"><i class="fas fa-users"></i>Manage Subject</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="manage_test.php"><i class="fas fa-users"></i>Manage Tests</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="header d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Manage Students</h2>
        <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <div id="adminAlert" class="alert d-none"></div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

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

                                    <td class="d-flex gap-1 flex-wrap">
                                        <a href="student_profile.php?id=<?php echo $student['id']; ?>"
                                        class="btn btn-sm btn-primary">Profile</a>

                                        <button 
                                            class="btn btn-sm btn-info btn-reattempt"
                                            data-student-id="<?= $student['id'] ?>">
                                            Reschedule Exam
                                        </button>

                                        <a href="promote_student.php?student_id=<?php echo $student['id']; ?>"
                                        class="btn btn-sm btn-success">Promote</a>

                                        <button
                                            data-url="/EXAMCENTER/teacher/delete_student.php?id=<?php echo $student['id']; ?>"
                                            class="btn btn-sm btn-danger btn-delete-student">
                                            Delete
                                        </button>
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

        <!-- Reschedule Exam Modal -->
        <div class="modal fade" id="reattemptModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule Exam</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="reattemptModalBody">
                <!-- AJAX content loads here -->
            </div>
            </div>
        </div>
        </div>

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

            $('.btn-reattempt').on('click', function () {
                const studentId = $(this).data('student-id');

                $.ajax({
                    url: 'fetch_student_tests.php',
                    type: 'GET',
                    data: { student_id: studentId },
                    success: function (response) {
                        $('#reattemptModalBody').html(response);
                        $('#reattemptModal').modal('show');
                    }
                });
            });

            $('.btn-promote-student').on('click', function (e) {
                e.preventDefault();

                const studentId = $(this).data('student-id');

                if (!confirm('Are you sure you want to promote this student to the next class?')) {
                    return;
                }

                $.ajax({
                    url: 'promote_student.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        student_id: studentId
                    },
                    success: function (res) {
                        if (res.success) {
                            alert(res.message);

                            // OPTIONAL:
                            // reload page to reflect new class
                            location.reload();

                        } else {
                            alert(res.message || 'Promotion failed');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error(error);
                        alert('Server error while promoting student');
                    }
                });
            });

            $(document).on('submit', '#reattemptForm', function (e) {
                e.preventDefault();

                $.ajax({
                    url: 'schedule_reattempt.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        const alertBox = $('#adminAlert');

                        alertBox
                            .removeClass('d-none alert-success alert-danger')
                            .addClass(res.success ? 'alert-success' : 'alert-danger')
                            .text(res.message);

                        if (res.success) {
                            $('#reattemptModal').modal('hide');
                        }

                        // Auto-hide alert after 5s
                        setTimeout(() => {
                            alertBox.addClass('d-none');
                        }, 5000);
                    },
                    error: function () {
                        const alertBox = $('#adminAlert');
                        alertBox
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .text('Server error while scheduling reattempt');

                        setTimeout(() => {
                            alertBox.addClass('d-none');
                        }, 5000);
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