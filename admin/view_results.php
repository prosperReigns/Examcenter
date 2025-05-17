<?php
session_start();
require_once '../db.php';

// Check if admin is logged in
if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Define available classes and subjects
$classes = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];
$jss_subjects = ['Mathematics', 'English', 'ICT', 'Agriculture', 'History', 'Civic Education', 'Basic Science', 'Basic Technology'];
$ss_subjects = ['Mathematics', 'English', 'Data Processing', 'Economics', 'Government', 'Accounting', 'Physics', 'Chemistry', 'Biology'];

// Get selected class and subject from POST
$selected_class = isset($_POST['selected_class']) ? $_POST['selected_class'] : '';
$selected_subject = isset($_POST['selected_subject']) ? $_POST['selected_subject'] : '';

// Get all test titles
$test_titles_query = "SELECT DISTINCT title FROM tests ORDER BY id DESC";
$test_titles_result = mysqli_query($conn, $test_titles_query);
$test_titles = mysqli_fetch_all($test_titles_result, MYSQLI_ASSOC);

// Get selected test title from POST
$selected_title = isset($_POST['selected_title']) ? $_POST['selected_title'] : '';

// Update the SQL query to include test title filter
$sql = "SELECT r.*, s.name AS student_name, s.class AS student_class, t.subject, t.title AS test_title, t.class AS test_class 
        FROM results r
        JOIN students s ON r.user_id = s.id
        JOIN tests t ON r.test_id = t.id";

// Apply filters
if ($selected_title) {
    $sql .= " WHERE t.title = '" . mysqli_real_escape_string($conn, $selected_title) . "'";
    if ($selected_class) {
        $sql .= " AND s.class = '" . mysqli_real_escape_string($conn, $selected_class) . "'";
    }
    if ($selected_subject) {
        $sql .= " AND t.subject = '" . mysqli_real_escape_string($conn, $selected_subject) . "'";
    }
} else if ($selected_class && $selected_subject) {
    $sql .= " WHERE s.class = '" . mysqli_real_escape_string($conn, $selected_class) . "' 
              AND t.subject = '" . mysqli_real_escape_string($conn, $selected_subject) . "'";
}

$sql .= " ORDER BY r.created_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Query failed: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results - CBT Application</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">CBT Admin</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <h2>View Results</h2>
        <form method="POST" class="mb-4">
            <div class="row">
                <div class="col-md-3">
                    <label class="form-label">Test Title</label>
                    <select class="form-select" name="selected_title">
                        <option value="">All Tests</option>
                        <?php foreach($test_titles as $title): ?>
                            <option value="<?php echo htmlspecialchars($title['title']); ?>" 
                                    <?php echo $selected_title === $title['title'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($title['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="selected_class" class="form-label">Select Class</label>
                    <select class="form-select" name="selected_class" id="selected_class">
                        <option value="">All Classes</option>
                        <?php foreach($classes as $class): ?>
                            <option value="<?php echo $class; ?>" <?php echo $selected_class === $class ? 'selected' : ''; ?>>
                                <?php echo $class; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="selected_subject" class="form-label">Select Subject</label>
                    <select class="form-select" name="selected_subject" id="selected_subject">
                        <option value="">All Subjects</option>
                        <?php 
                        // Determine which subject list to use based on class
                        $available_subjects = [];
                        if (strpos($selected_class, 'JSS') === 0) {
                            $available_subjects = $jss_subjects;
                        } elseif (strpos($selected_class, 'SS') === 0) {
                            $available_subjects = $ss_subjects;
                        }
                        
                        foreach($available_subjects as $subject): ?>
                            <option value="<?php echo $subject; ?>" 
                                    <?php echo $selected_subject === $subject ? 'selected' : ''; ?>>
                                <?php echo $subject; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter Results</button>
                </div>
            </form>
            </div>
        </div>

        <?php if(mysqli_num_rows($result) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Score</th>
                            <th>Total Questions</th>
                            <th>Percentage</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_class']); ?></td>
                                <td><?php echo htmlspecialchars($row['subject']); ?></td>
                                <td><?php echo $row['score']; ?></td>
                                <td><?php echo $row['total_questions']; ?></td>
                                <td><?php echo round(($row['score'] / $row['total_questions']) * 100, 2); ?>%</td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                No results found for the selected criteria.
            </div>
        <?php endif; ?>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
    window.jssSubjects = <?php echo json_encode($jss_subjects); ?>;
    window.ssSubjects = <?php echo json_encode($ss_subjects); ?>;
    </script>
    <script src="../js/updateSubjects.js"></script>

</body>
</html>