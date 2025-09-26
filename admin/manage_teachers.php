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

// Initialize variables
$error = $success = '';

// Handle delete teacher
if (isset($_GET['delete_id'])) {
    $teacher_id = (int)$_GET['delete_id'];
    
    try {
        $conn->begin_transaction();
        
        // Get email to verify teacher exists
        $stmt = $conn->prepare("SELECT email FROM teachers WHERE id = ?");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $teacher = $result->fetch_assoc();
        
        if ($teacher) {
            // Delete from teacher_subjects
            $stmt = $conn->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();
            
            // Delete from teachers
            $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();
            
            $conn->commit();
            $success = "Teacher deleted successfully!";
            
            // Log deletion
            $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $activity = "Admin {$user['username']} deleted teacher ID: $teacher_id";
            $stmt = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("siss", $activity, $user_id, $ip_address, $user_agent);
            $stmt->execute();
        } else {
            $error = "Teacher not found!";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error deleting teacher: " . $e->getMessage();
    }
}

// Get all teachers with their subjects
$teachers = [];
$stmt = $conn->prepare("
    SELECT t.id, t.first_name, t.last_name, t.username, t.email, t.phone, 
           GROUP_CONCAT(ts.subject SEPARATOR ', ') as subjects
    FROM teachers t
    LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id
    GROUP BY t.id
    ORDER BY t.last_name, t.first_name
");
$stmt->execute();
$result = $stmt->get_result();
$teachers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers | D-Portal CBT</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <style>
        .subject-badge {
            background-color: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
        }
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        .search-box input {
            padding-left: 35px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #4361ee;
            color: white !important;
            border-color: #4361ee;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
            <div class="admin-info">
                <b><small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($user['username']); ?></h6></b>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_question.php" style="text-decoration: line-through"><i class="fas fa-plus-circle"></i>Add Questions</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="manage_teachers.php" class="active"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Manage Teachers</h2>
            <div class="d-flex gap-3">
                <a href="add_teacher.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add Teacher</a>
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

        <!-- Teachers Table -->
        <div class="card bg-white border-0 shadow-sm">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Teachers List (<?php echo count($teachers); ?> total)</h5>
            </div>
            <div class="card-body">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="form-control" placeholder="Search teachers..." form="searchForm">
                </div>
                <form id="searchForm"></form>
                <?php if (!empty($teachers)): ?>
                    <table id="teachersTable" class="table table-striped table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Subjects</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr class="teacher-row">
                                    <td><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['username']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['phone']); ?></td>
                                    <td>
                                        <?php if (!empty($teacher['subjects'])): ?>
                                            <?php $teacherSubjects = explode(', ', $teacher['subjects']); ?>
                                            <?php foreach ($teacherSubjects as $subject): ?>
                                                <span class="subject-badge"><?php echo htmlspecialchars($subject); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No subjects assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="add_teacher.php?edit_id=<?php echo $teacher['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger delete-btn" data-id="<?php echo $teacher['id']; ?>">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x mb-3"></i>
                        <h4>No Teachers Found</h4>
                        <p>Add a new teacher to get started.</p>
                        <a href="add_teacher.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add Teacher</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this teacher? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDelete" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery.dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Form validation for search
            $('#searchForm').validate({
                rules: {
                    searchInput: {
                        maxlength: 100,
                        regex: /^[a-zA-Z0-9\s\-\.\,\?\!]*$/ // Allow common characters
                    }
                },
                messages: {
                    searchInput: {
                        maxlength: 'Search term cannot exceed 100 characters',
                        regex: 'Search term contains invalid characters'
                    }
                },
                errorElement: 'div',
                errorClass: 'invalid-feedback',
                highlight: function(element) {
                    $(element).addClass('is-invalid');
                },
                unhighlight: function(element) {
                    $(element).removeClass('is-invalid');
                },
                submitHandler: function(form) {
                    // Search is handled client-side, so no submission needed
                    return false;
                }
            });

            // Initialize DataTables
            $('#teachersTable').DataTable({
                pageLength: 10,
                searching: false, // Disable DataTables search since we use custom search
                lengthChange: false,
                columnDefs: [
                    { orderable: false, targets: [4, 5] } // Disable sorting on Subjects and Actions
                ],
                language: {
                    paginate: {
                        previous: '« Previous',
                        next: 'Next »'
                    },
                    emptyTable: '<div class="text-center py-4 empty-state"><i class="fas fa-users fa-3x mb-3"></i><h4>No Teachers Found</h4><p>Add a new teacher to get started.</p><a href="add_teacher.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add Teacher</a></div>'
                }
            });

            // Search functionality
            $('#searchInput').on('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = $('.teacher-row');
                
                rows.each(function() {
                    const text = $(this).text().toLowerCase();
                    $(this).toggle(text.includes(searchTerm));
                });
                
                // Update DataTable to reflect visible rows
                $('#teachersTable').DataTable().draw();
            });

            // Delete confirmation
            $('.delete-btn').on('click', function() {
                const teacherId = $(this).data('id');
                $('#confirmDelete').attr('href', `manage_teachers.php?delete_id=${teacherId}`);
                
                const modal = new bootstrap.Modal($('#deleteModal')[0]);
                modal.show();
            });

            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                $('.alert').each(function() {
                    new bootstrap.Alert(this).close();
                });
            }, 5000);
        });
    </script>
</body>
</html>