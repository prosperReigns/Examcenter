<?php
session_start();
require_once '../db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();



// Initialize variables
$error = $success = '';

// Handle delete teacher
if (isset($_GET['delete_id'])) {
    $teacher_id = $_GET['delete_id'];
    
    try {
        $conn->begin_transaction();
        
        // Get email to delete from staff table
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers</title>
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
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .table-responsive {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .table th {
            background-color: var(--primary);
            color: white;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-box i {
            position: absolute;
            left: 10px;
            top: 10px;
            color: #6c757d;
        }
        
        .search-box input {
            padding-left: 35px;
        }
    </style>
</head>
<body>
    <!-- Gradient Header -->
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">Manage Teachers</h1>
                <div class="d-flex gap-3">
                    <a href="add_teacher.php" class="btn btn-light">
                        <i class="fas fa-plus me-2"></i>Add Teacher
                    </a>
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if ($error): ?>
            <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="form-control" placeholder="Search teachers...">
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
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
                                    <td class="action-buttons">
                                       <a href="add_teacher.php?edit_id=<?php echo $teacher['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <button class="btn btn-sm btn-danger delete-btn" data-id="<?php echo $teacher['id']; ?>">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($teachers)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">No teachers found. <a href="add_teacher.php">Add a teacher</a></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.teacher-row');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Delete confirmation
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const teacherId = this.getAttribute('data-id');
                const confirmDelete = document.getElementById('confirmDelete');
                confirmDelete.href = `manage_teachers.php?delete_id=${teacherId}`;
                
                const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
                modal.show();
            });
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                new bootstrap.Alert(alert).close();
            });
        }, 5000);
        document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        if (confirm("Are you sure you want to delete this teacher?")) {
            window.location.href = `manage_teachers.php?delete_id=${this.dataset.id}`;
        }
    });
});
    </script>
</body>
</html>