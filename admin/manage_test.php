<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

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

    // Fetch teacher profile and assigned subjects
    $admin_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, role FROM admins WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for admin profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin) {
        error_log("No admin found for user_id=$admin_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

// Admin sees all tests
$result = $conn->query("SELECT t.id, t.title, t.subject, t.duration, 
                               CONCAT(al.level_code, ' ', s.stream_name) AS class
                        FROM tests t
                        JOIN academic_levels al ON t.academic_level_id = al.id
                        JOIN classes c ON c.academic_level_id = al.id
                        JOIN streams s ON c.stream_id = s.id
                        GROUP BY t.id
                        ORDER BY t.id DESC");



} catch (Exception $e) {
    error_log("View results error: " . $e->getMessage());
    die("System error");
}
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin | manage Tests</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/add_question.css"> 
    <!-- <link rel="stylesheet" href="../css/sidebar.css"> -->
     <style>
        .card{
            border-radius:12px;
        }
        .card-header{
            border-radius:12px 12px 0 0 !important;
        }
        .table td{
            vertical-align:middle;
        }
        .badge{
            font-size:14px;
            padding:8px 12px;
        }
        .btn{
            border-radius:8px;
        }
        .table-hover tbody tr:hover{
            background:#f8f9fa;
        }
    </style>
</head>
<body class="container py-5">
<div class="sidebar">
        <div class="sidebar-brand">
            <h3><i class="fas fa-graduation-cap me-2"></i>Examcenter</h3>
            <div class="admin-info">
                <small>Welcome back,</small>
                <h6><?php echo htmlspecialchars($admin['username']); ?></h6>
            </div>
        </div>
        <div class="sidebar-menu mt-4">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
            <a href="add_teacher.php"><i class="fas fa-user-plus"></i>Add Teachers</a>
            <a href="view_questions.php"><i class="fas fa-list"></i>View Questions</a>
            <a href="view_results.php"><i class="fas fa-chart-bar"></i>Exam Results</a>
            <a href="manage_classes.php"><i class="fas fa-users"></i>Manage Classes</a>
            <a href="manage_session.php"><i class="fas fa-user-plus"></i>manage session</a>
            <a href="manage_subject.php"><i class="fas fa-users"></i>Manage Subject</a>
            <a href="manage_teachers.php"><i class="fas fa-users"></i>Manage Teachers</a>
            <a href="manage_test.php" class="active"><i class="fas fa-users"></i>Manage Tests</a>
            <a href="exam_schedule.php"><i class="fas fa-calendar-check"></i>Timestable</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            <a href="../license/index.php">
            <i class="fas fa-key"></i>
                License
            </a>
            <a href="audit_logs.php">
                <i class="fas fa-shield-alt"></i>
                Audit Logs
            </a>
            <a href="../backup/backup_list.php">
                <i class="fas fa-database"></i>
                Backup
            </a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
        </div>
    </div>

        <!-- Header -->
        <div class="header d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Manage Test</h2>
            <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-file-signature me-2"></i>
                Available Tests
            </h5>

            <span class="badge bg-light text-dark">
                <?= $result ? $result->num_rows : 0 ?> Tests
            </span>
        </div>

        <div class="card-body p-0">
            <table class="table table-hover table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th><i class="fas fa-file-alt me-1"></i> Test</th>
                        <th><i class="fas fa-school me-1"></i> Class</th>
                        <th><i class="fas fa-book me-1"></i> Subject</th>
                        <th><i class="fas fa-clock me-1"></i> Duration</th>
                        <th class="text-center"><i class="fas fa-cogs me-1"></i> Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['title']) ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($row['class']) ?></span></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['subject']) ?></span></td>
                        <td><i class="fas fa-clock text-warning"></i><?= htmlspecialchars($row['duration']) ?>mins</td>
                        <td class="text-center">
                            <a class="btn btn-sm btn-outline-primary" 
                            href="download.php?class=<?= urlencode($row['class']) ?>&subject=<?= urlencode($row['subject']) ?>&title=<?= urlencode($row['title']) ?>"><i class="fas fa-download"></i>
                            Download
                            </a>
                            <button class="btn btn-sm btn-warning edit-duration" 
                                    data-id="<?= $row['id'] ?>" 
                                    data-duration="<?= htmlspecialchars($row['duration']) ?>"
                                    data-title="<?= htmlspecialchars($row['title']) ?>">
                                Edit Duration
                            </button>
                            <button class="btn btn-sm btn-outline-danger delete-test" 
                                    data-id="<?= $row['id'] ?>" 
                                    data-title="<?= htmlspecialchars($row['title']) ?>"><i class="fas fa-trash-alt"></i>
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">
                                    No Tests Found
                                </h5>
                                <p class="text-muted">
                                    You have not created any tests yet.
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Duration Modal -->
    <div class="modal fade" id="editDurationModal" tabindex="-1" aria-labelledby="editDurationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="editDurationForm">
        <div class="modal-content">
            <div class="modal-header">
            <h5 class="modal-title" id="editDurationModalLabel">Edit Duration</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
            <input type="hidden" id="editTestId" name="id">
            <div class="mb-3">
                <label for="editDurationInput" class="form-label">Duration (minutes)</label>
                <input type="number" class="form-control" id="editDurationInput" name="duration" min="1" required>
            </div>
            </div>
            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Duration</button>
            </div>
        </div>
        </form>
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
        });
    </script>
 <script>
$(document).ready(function() {
    $('.delete-test').click(function() {
        const testId = $(this).data('id');
        const testTitle = $(this).data('title');

        if (confirm(`Are you sure you want to delete the test "${testTitle}"? This action cannot be undone.`)) {
            $.ajax({
                url: 'delete_test.php',
                type: 'POST',
                data: { id: testId},
                success: function(response) {
                    const res = JSON.parse(response);
                    if (res.success) {
                        alert('Test deleted successfully.');
                        location.reload();
                    } else {
                        alert('Error: ' + res.error);
                    }
                },
                error: function() {
                    alert('An unexpected error occurred.');
                }
            });
        }
    });
});
</script>
<script>
    $(document).ready(function() {
    // Open Edit Duration modal
    $('.edit-duration').click(function() {
        const testId = $(this).data('id');
        const duration = $(this).data('duration');
        const title = $(this).data('title');

        $('#editTestId').val(testId);
        $('#editDurationInput').val(duration);
        $('#editDurationModal .modal-title').text('Edit Duration for "' + title + '"');

        var editModal = new bootstrap.Modal(document.getElementById('editDurationModal'));
        editModal.show();
    });

    // Submit updated duration
    $('#editDurationForm').submit(function(e) {
        e.preventDefault();
        const testId = $('#editTestId').val();
        const duration = $('#editDurationInput').val();

        $.ajax({
            url: 'update_test_duration.php',
            type: 'POST',
            data: { id: testId, duration: duration },
            success: function(response) {
                const res = JSON.parse(response);
                if (res.success) {
                    alert('Test duration updated successfully.');
                    location.reload();
                } else {
                    alert('Error: ' + res.error);
                }
            },
            error: function() {
                alert('An unexpected error occurred.');
            }
        });
    });
});

</script>

</body>
</html>
