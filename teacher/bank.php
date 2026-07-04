<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../vendor/autoload.php'; // Adjust path if PHPWord is elsewhere

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

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

    // Fetch teacher profile and assigned subjects
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute() or die($stmt->error);
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
    if (!$stmt) {
        error_log("Prepare failed for assigned subjects: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute() or die($stmt->error);
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    if (empty($assigned_subjects)) {
        $error = "No subjects assigned to you. Contact your admin.";
    }
    $levels = [];

    $result = $conn->query("
    SELECT id, level_code
    FROM academic_levels
    ORDER BY level_code ASC
");

while($row = $result->fetch_assoc()){
    $levels[] = $row;
}

    // Search & Filters
    $search = trim($_GET['search'] ?? '');
    $class_filter = trim($_GET['class'] ?? '');
    $subject_filter = trim($_GET['subject'] ?? '');
    $type_filter = trim($_GET['type'] ?? '');

    // Fetch Question Bank
    if (!empty($assigned_subjects)) {

        $safe_subjects = array_map([$conn, 'real_escape_string'], $assigned_subjects);

        $subject_list = "'" . implode("','", $safe_subjects) . "'";

        $sql = "
            SELECT
                id,
                question_text,
                class,
                subject,
                question_type,
                created_at
            FROM new_questions
            WHERE subject IN ($subject_list)
            ";

            if ($search != "") {
                $safe = $conn->real_escape_string($search);
                $sql .= " AND question_text LIKE '%$safe%'";
            }
            if ($class_filter != "") {
                $safe = $conn->real_escape_string($class_filter);
                $sql .= " AND class='$safe'";
            }
            if ($subject_filter != "") {
                $safe = $conn->real_escape_string($subject_filter);
                $sql .= " AND subject='$safe'";
            }
            if ($type_filter != "") {

                $safe = $conn->real_escape_string($type_filter);

                $sql .= " AND question_type='$safe'";
            }
            $sql .= " ORDER BY id DESC";
            $result = $conn->query($sql);
    } else {
        $result = false;
    }

} catch (Exception $e) {
    echo "<pre>";
    echo "ERROR: " . $e->getMessage() . "<br><br>";
    echo $e->getTraceAsString();
    echo "</pre>";
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Question Bank</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/add_question.css"> 
    <!-- <link rel="stylesheet" href="../css/sidebar.css"> -->
</head>
<body class="container py-5">
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
                <a href="manage_students.php">
                    <i class="fas fa-users"></i>
                    Manage Students
                </a>
                <a href="bank.php" class="active">
                    <i class="fas fa-database"></i>
                    Question Bank
                </a>
                <a href="settings.php">
                    <i class="fas fa-cog"></i>
                    Settings
                </a>
                <a href="my-profile.php">
                    <i class="fas fa-user"></i>
                    My Profile
                </a>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>

            <!-- Header -->
            <div class="header d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Question Bank</h2>
                <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                <form method="GET">
                <div class="row">
                <div class="col-md-4">
                <input
                type="text"
                class="form-control"
                name="search"
                placeholder="Search question..."
                value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                <select class="form-select" name="class">
                <option value="">All Classes</option>
                <?php foreach($levels as $level): ?>

<option
value="<?= htmlspecialchars($level['level_code']); ?>">

<?= htmlspecialchars($level['level_code']); ?>

</option>

<?php endforeach; ?>
                </select>
                </div>
                <div class="col-md-3">
                <select class="form-select" name="subject">
                <option value="">All Subjects</option>
                <?php foreach($assigned_subjects as $sub): ?>
                <option
                value="<?= htmlspecialchars($sub) ?>"
                <?= $subject_filter==$sub?'selected':'' ?>>

                <?= htmlspecialchars($sub) ?>

                </option>

                <?php endforeach; ?>

                </select>

                </div>

                <div class="col-md-2">

                <select class="form-select" name="type">

                <option value="">All Types</option>

                <option value="multiple_choice_single">Single Choice</option>

                <option value="multiple_choice_multiple">Multiple Choice</option>

                <option value="true_false">True / False</option>

                <option value="fill_blanks">Fill Blank</option>

                </select>

                </div>

                <div class="col-md-1">

                <button class="btn btn-primary w-100">

                <i class="fas fa-search"></i>

                </button>

                </div>

                </div>

                </form>

                </div>

                </div>
            <div class="card">

            <div class="card-header d-flex justify-content-between">

                <h5>Question Bank</h5>
                <h6>Store reusable questions that can later be added to any test.</h6>

                <a href="add_question.php?mode=bank" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add Question
                </a>
            </div>

            <div class="card-body">
                <form action="add_questions_to_test.php" method="POST" id="bankForm">
                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th width="40">
                            <input
                            type="checkbox"
                            id="masterCheckbox">
                            </th>
                            <th>#</th>
                            <th>Question</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Date Added</th>
                            <th>Used In</th>
                            <th>Action</th>

                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    if($result && $result->num_rows > 0):
                        $sn = 1;
                        while($row = $result->fetch_assoc()):
                    ?>
                        <tr>
                            <td>

                            <input
                            type="checkbox"
                            name="questions[]"
                            value="<?= $row['id']; ?>"
                            class="questionCheckbox">

                            </td>
                            <td><?= $sn++; ?></td>
                            <td><?= htmlspecialchars($row['question_text']); ?></td>
                            <td><?= htmlspecialchars($row['class']); ?></td>
                            <td><?= htmlspecialchars($row['subject']); ?></td>
                            <td><?= ucwords(str_replace("_"," ",$row['question_type'])); ?></td>
                            <td><?= date("d M Y", strtotime($row['created_at'])); ?></td>
                            <td>Not Used</td>
                            <td>
                                <a href="view_question.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-info">
                                    View
                                </a>
                                <a href="edit_question.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-warning">
                                    Edit
                                </a>
                                <a href="delete_question.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-danger">
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="9" class="text-center">
                                No questions found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div class="mt-3 d-flex justify-content-between align-items-center">

                    <div>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            id="selectAllBtn">

                            Select All
                        </button>

                        <span class="ms-3 fw-bold text-primary">
                            Selected:
                            <span id="selectedCount">0</span>
                            question(s)
                        </span>
                    </div>
                    <?php if(isset($_SESSION['current_test_id'])): ?>
                    <button class="btn btn-success">
                    Add Selected To Current Test

                    </button>
                    <?php else: ?>
                    <a
                    href="add_question.php"
                    class="btn btn-warning">
                    Select/Create a Test First
                    </a>
                    <?php endif; ?>
                </div>
                </form>
            </div>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/chart.min.js"></script>
    <script src="../js/jquery.dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sidebar toggle
            $('#sidebarToggle').click(function() {
                $('.sidebar').toggleClass('active');
            });
        });
    </script>
        <script>

        const master = document.getElementById("masterCheckbox");
        const checkboxes = document.querySelectorAll(".questionCheckbox");
        const selectedCount = document.getElementById("selectedCount");

        function updateSelectedCount() {

            let count = 0;

            checkboxes.forEach(box => {
                if(box.checked){
                    count++;
                }
            });

            selectedCount.textContent = count;
        }
                master.addEventListener("change", function(){

            checkboxes.forEach(box=>{
                box.checked = master.checked;
            });

            updateSelectedCount();

        });

        document.getElementById("selectAllBtn")
        .addEventListener("click", function(){
            let allSelected = true;
            checkboxes.forEach(box=>{
                if(!box.checked){
                    allSelected = false;
                }
            });
            checkboxes.forEach(box=>{
                box.checked = !allSelected;
            });
            master.checked = !allSelected;
            updateSelectedCount();

        });

        checkboxes.forEach(box => {
            box.addEventListener("change", function(){
                let checked = 0;
                checkboxes.forEach(c=>{
                    if(c.checked){
                        checked++;
                    }
                });
                master.checked = checked === checkboxes.length;
                updateSelectedCount();
            });

        });

        updateSelectedCount();
        </script>
                    </body>
                    </html>
                    <?php
                    $conn->close();
                    ?>