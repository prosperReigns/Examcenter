<?php
require_once '../db.php';

$student_id = (int)$_GET['student_id'];
$db = Database::getInstance()->getConnection();

/* Get student's attempted exams */
$stmt = $db->prepare("
    SELECT 
        r.id AS result_id,
        t.title,
        t.subject,
        r.score,
        r.status
    FROM results r
    JOIN tests t ON r.test_id = t.id
    WHERE r.user_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$results = $stmt->get_result();
$stmt->close();
?>

<!Doctype html>
<head>
</head>
<body>
<?php if ($results->num_rows === 0): ?>
    <div class="alert alert-warning">This student has not attempted any exam yet.</div>
<?php else: ?>
    <form id="reattemptForm">
        <input type="hidden" name="student_id" value="<?= $student_id ?>">

        <div class="mb-3">
            <label class="form-label">Select Exam</label>
            <select name="result_id" class="form-select" required>
                <?php while ($row = $results->fetch_assoc()): ?>
                    <option value="<?= $row['result_id'] ?>">
                        <?= htmlspecialchars($row['title']) ?> –
                        <?= htmlspecialchars($row['subject']) ?> |
                        Score: <?= $row['score'] ?> |
                        Status: <?= $row['status'] ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">
            Reschedule Exam
        </button>
    </form>

<?php endif; ?>

<!-- <script>
    $(document).on('submit', '#reattemptForm', function (e) {
    e.preventDefault();

    $.post('schedule_reattempt.php', $(this).serialize(), function (res) {
        alert(res.message);
        if (res.success) {
            $('#reattemptModal').modal('hide');
        }
    }, 'json');
});
</script> -->
</body>
</html>