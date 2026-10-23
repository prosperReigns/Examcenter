<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

if (!isset($_GET['test_id']) || !isset($_GET['user_id']) || !isset($_SESSION['student_name'])) {
    header("Location: register.php");
    exit();
}

$conn = Database::getInstance()->getConnection();
$test_id = (int)$_GET['test_id'];
$user_id = (int)$_GET['user_id'];
$student_name = $_SESSION['student_name'];

// Fetch result
$stmt = $conn->prepare("SELECT score,total_questions,status,objective_score,objective_total,theory_score,theory_grading_status FROM results WHERE user_id = ? AND test_id = ? ORDER BY created_at DESC,id DESC LIMIT 1");
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
$grading_status = (string)($result_data['theory_grading_status'] ?? 'not_required');
$objective_score = $result_data['objective_score'] !== null ? (float)$result_data['objective_score'] : (float)$score;
$objective_total = $result_data['objective_total'] !== null ? (float)$result_data['objective_total'] : (float)$total_questions;
$percentage = $total_questions > 0 ? ((float)$score / (float)$total_questions) * 100 : 0;

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
    <?php if ($show_results && in_array($grading_status, ['pending','processing'], true)): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
    <title>Exam Results | Examcenter</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        :root{--result-ink:#172b4d;--result-muted:#66758b;--result-line:#e2e8f0;--result-blue:#2459a6}
        body{min-height:100vh;background:radial-gradient(ellipse at 80% 0%,rgba(50,105,190,.12),transparent 34rem),#f3f6fb;color:var(--result-ink);padding:28px 0}
        .result-card{overflow:hidden;border:1px solid var(--result-line);border-radius:20px;box-shadow:0 18px 50px rgba(20,43,77,.09)}
        .result-card .card-header{padding:22px 24px;background:linear-gradient(125deg,#2459a6,#183e78)!important;border:0}
        .result-card .card-header h3{margin:0;font-size:1.25rem;font-weight:750;letter-spacing:-.02em}
        .result-card .card-body{padding:clamp(22px,5vw,42px)}
        .result-card h4{font-weight:750;letter-spacing:-.025em}
        .result-card .display-4{font-size:clamp(2.25rem,7vw,4rem);font-weight:800;letter-spacing:-.05em;color:var(--result-blue)}
        .result-card .lead{color:var(--result-muted)}
        .result-card .alert{border-radius:12px;border:1px solid transparent}
        .result-card .btn{border-radius:10px;padding:11px 18px;font-weight:700}
        .btn-next-student{background:linear-gradient(135deg,#2459a6,#183e78);border:0;color:#fff;box-shadow:0 8px 18px rgba(36,89,166,.18)}
        .btn-next-student:hover{color:#fff;filter:brightness(1.06)}
        @media(max-width:575px){body{padding:14px 0}.result-card .card-body{padding:22px 18px}}
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
                        <h4><?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <p class="lead"><?php echo in_array($grading_status, ['pending', 'processing', 'failed'], true) ? 'Your exam submission has been saved.' : 'Your exam has been completed.'; ?></p>

                        <?php if (!empty($_SESSION['theory_grading_notice'])): ?>
                            <div class="alert alert-warning text-start" role="alert">
                                <strong>Theory grading needs attention.</strong>
                                <?= htmlspecialchars((string)$_SESSION['theory_grading_notice'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php unset($_SESSION['theory_grading_notice']); ?>
                        <?php endif; ?>

                        <?php if ($show_results): ?>
                            <hr>
                            <?php if (in_array($grading_status, ['pending','processing','failed'], true)): ?>
                                <h5>Provisional Objective Score</h5>
                                <p class="display-4"><?= htmlspecialchars((string)$objective_score) ?> / <?= htmlspecialchars((string)$objective_total) ?></p>
                                <div class="alert alert-warning text-start" role="status">
                                    <strong>Overall result is not final.</strong>
                                    <?php if ($grading_status === 'failed'): ?>
                                        Theory grading needs teacher attention. Your submitted answers are saved and can be retried.
                                    <?php else: ?>
                                        Your theory answers are saved and awaiting offline AI grading. You do not need to submit the exam again.
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <h5>Final Score</h5>
                                <p class="display-4"><?php echo htmlspecialchars((string)$score); ?> / <?php echo htmlspecialchars((string)$total_questions); ?></p>
                                <p class="lead">Percentage: <?php echo number_format($percentage, 2); ?>%</p>
                            <?php endif; ?>
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
