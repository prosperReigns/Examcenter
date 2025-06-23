<?php
session_start();
require_once '../db.php';

if (!isset($_GET['test_id']) || !isset($_GET['user_id']) || !isset($_SESSION['student_name'])) {
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
$test_id = (int)$_GET['test_id'];
$user_id = (int)$_GET['user_id'];
$student_name = $_SESSION['student_name'];

// Fetch result
$stmt = $conn->prepare("SELECT score, total_questions, status FROM results WHERE user_id = ? AND test_id = ?");
$stmt->bind_param("ii", $user_id, $test_id);
$stmt->execute();
$result = $stmt->get_result();
$result_data = $result->fetch_assoc();
$stmt->close();

if (!$result_data) {
    header("Location: register.php");
    exit();
}

$score = $result_data['score'];
$total_questions = $result_data['total_questions'];
$percentage = $total_questions > 0 ? ($score / $total_questions) * 100 : 0;

// Fetch show_results_immediately setting
$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_name = ?");
$setting_name = 'show_results_immediately';
$stmt->bind_param("s", $setting_name);
$stmt->execute();
$setting_result = $stmt->get_result();
$show_results = $setting_result->fetch_assoc()['setting_value'] ?? '0';
$show_results = in_array($show_results, ['1', 'true', 'yes'], true) ? true : false;
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results - D-Portal</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .result-card {
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .btn-next-student {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card result-card">
                    <div class="card-header bg-primary text-white text-center">
                        <h3>Exam Results</h3>
                    </div>
                    <div class="card-body text-center">
                        <h4>Congratulations, <?php echo htmlspecialchars($student_name); ?>!</h4>
                        <p class="lead">You have completed your exam.</p>

                        <?php if ($show_results): ?>
                            <hr>
                            <h5>Your Score</h5>
                            <p class="display-4"><?php echo $score; ?> / <?php echo $total_questions; ?></p>
                            <p class="lead">Percentage: <?php echo number_format($percentage, 2); ?>%</p>
                            <?php if ($result_data['status'] === 'terminated'): ?>
                                <p class="text-danger">Note: Your exam was terminated due to multiple tab switches.</p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <a href="register.php" class="btn btn-next-student mt-3" onclick="endSession()">
                            <i class="bi bi-arrow-right-circle"></i> Next Student
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        function endSession() {
            localStorage.removeItem('examTimeLeft_<?php echo $test_id; ?>_<?php echo $user_id; ?>');
            localStorage.removeItem('examState_<?php echo $test_id; ?>_<?php echo $user_id; ?>');
        }
    </script>
</body>
</html>
