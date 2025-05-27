<?php
session_start();

if (!isset($_SESSION['student_id']) || !isset($_SESSION['exam_score'])) {
    header("Location: register.php");
    exit();
}

$score = $_SESSION['exam_score'];
$total = $_SESSION['total_questions'];
$percentage = ($score / $total) * 100;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results - CBT Application</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="text-center">Your Exam Results</h3>
                    </div>
                    <div class="card-body text-center">
                        <h4>Congratulations, <?php echo htmlspecialchars($_SESSION['student_name']); ?>!</h4>
                        <p class="lead">You have completed the exam.</p>
                        
                        
                        
                        <a href="register.php" class="btn btn-primary" onclick="<?php session_destroy(); ?>">End Exam</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>