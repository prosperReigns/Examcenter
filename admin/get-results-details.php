<?php
require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/universal_architecture.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['student_id'])) {
    http_response_code(400);
    exit('Invalid request.');
}

$student_id = filter_var($_POST['student_id'], FILTER_VALIDATE_INT);
if (!$student_id || $student_id <= 0) {
    http_response_code(400);
    exit('Invalid student ID.');
}

$database = Database::getInstance();
$conn = $database->getConnection();

$universal_available = examcenterUniversalTableExists($conn, 'organizational_units')
    && examcenterUniversalTableExists($conn, 'courses')
    && examcenterUniversalTableExists($conn, 'academic_periods')
    && examcenterUniversalTableExists($conn, 'programmes')
    && examcenterUniversalTableExists($conn, 'programme_levels')
    && examcenterUniversalColumnExists($conn, 'tests', 'organizational_unit_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'course_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'academic_period_id')
    && examcenterUniversalColumnExists($conn, 'results', 'academic_period_id');

$select = "
    SELECT
        r.id AS result_id,
        r.score,
        r.total_questions,
        r.status,
        r.created_at,
        s.full_name AS student_name,
        t.title AS test_title,
        t.subject,
        t.year,
        t.duration,
        c.class_name";

$joins = "
    FROM results r
    INNER JOIN students s ON s.id = r.user_id
    INNER JOIN tests t ON t.id = r.test_id
    LEFT JOIN classes c ON s.class = CAST(c.id AS CHAR) OR s.class = c.class_name";

if ($universal_available) {
    $select .= ",
        ou.unit_name,
        co.course_code,
        co.course_name,
        p.programme_name,
        pl.level_name AS programme_level_name,
        ap.period_name,
        ap.period_type";
    $joins .= "
        LEFT JOIN organizational_units ou ON ou.id = t.organizational_unit_id
        LEFT JOIN courses co ON co.id = t.course_id
        LEFT JOIN programmes p ON p.id = t.programme_id
        LEFT JOIN programme_levels pl ON pl.id = t.programme_level_id
        LEFT JOIN academic_periods ap
            ON ap.id = COALESCE(r.academic_period_id, t.academic_period_id)";
}

$query = $select . $joins . "
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 1";

$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare result details query.');
}

$stmt->bind_param('i', $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo "<div class='text-center py-4'><i class='fas fa-exclamation-circle fa-2x'></i><p>No results found</p></div>";
    exit;
}

function resultDetailEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$score = (float)($row['score'] ?? 0);
$total_questions = (int)($row['total_questions'] ?? 0);
$percentage = $total_questions > 0 ? round(($score / $total_questions) * 100, 2) : 0;

echo "<div class='row g-3'>";
echo "<div class='col-md-6'><strong>Student:</strong> " . resultDetailEscape($row['student_name']) . "</div>";
echo "<div class='col-md-6'><strong>Test:</strong> " . resultDetailEscape($row['test_title']) . "</div>";
echo "<div class='col-md-6'><strong>Subject:</strong> " . resultDetailEscape($row['subject']) . "</div>";
echo "<div class='col-md-6'><strong>Class/Unit:</strong> " . resultDetailEscape($row['unit_name'] ?? $row['class_name'] ?? 'Not assigned') . "</div>";
echo "<div class='col-md-6'><strong>Score:</strong> " . resultDetailEscape($score . '/' . $total_questions) . "</div>";
echo "<div class='col-md-6'><strong>Percentage:</strong> " . resultDetailEscape($percentage . '%') . "</div>";

if ($universal_available) {
    echo "<div class='col-md-6'><strong>Course:</strong> " . resultDetailEscape($row['course_name'] ?? 'General assessment') . "</div>";
    echo "<div class='col-md-6'><strong>Programme:</strong> " . resultDetailEscape($row['programme_name'] ?? 'Not specified') . "</div>";
    echo "<div class='col-md-6'><strong>Academic Period:</strong> " . resultDetailEscape($row['period_name'] ?? 'Not specified') . "</div>";
}

echo "<div class='col-md-6'><strong>Date:</strong> " . resultDetailEscape($row['created_at'] ?? 'Not specified') . "</div>";
echo "</div>";
?>