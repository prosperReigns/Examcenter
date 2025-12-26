<?php
require_once '../vendor/autoload.php';
require_once '../db.php';

$student_id = (int)$_GET['student_id'];
$academic_year_id = (int)$_GET['academic_year_id'];

$db = Database::getInstance()->getConnection();

// Fetch student info
$stmt = $db->prepare("SELECT name, class FROM students WHERE id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch scores
$stmt = $db->prepare("
    SELECT s.subject_name, ss.ca1, ss.ca2, ss.ca3, ss.ca4, ss.exam_score
    FROM student_subject_scores ss
    JOIN subjects s ON s.id = ss.subject_id
    WHERE ss.student_id = ? AND ss.academic_year_id = ?
");
$stmt->bind_param('ii', $student_id, $academic_year_id);
$stmt->execute();
$scores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch remark
$stmt = $db->prepare("SELECT remark FROM student_remarks WHERE student_id = ? AND academic_year_id = ? LIMIT 1");
$stmt->bind_param('ii', $student_id, $academic_year_id);
$stmt->execute();
$remark = $stmt->get_result()->fetch_assoc()['remark'] ?? '';
$stmt->close();

$html = '<h1>Student Report</h1>';
$html .= '<p>Name: ' . htmlspecialchars($student['name']) . '</p>';
$html .= '<p>Class: ' . htmlspecialchars($student['class']) . '</p>';
$html .= '<table border="1" cellpadding="5"><tr><th>Subject</th><th>CA1</th><th>CA2</th><th>CA3</th><th>CA4</th><th>Exam</th><th>Total</th></tr>';

$totalSum = 0;
foreach ($scores as $row) {
    $total = array_sum([$row['ca1'], $row['ca2'], $row['ca3'], $row['ca4'], $row['exam_score']]);
    $totalSum += $total;
    $html .= "<tr>
        <td>{$row['subject_name']}</td>
        <td>{$row['ca1']}</td>
        <td>{$row['ca2']}</td>
        <td>{$row['ca3']}</td>
        <td>{$row['ca4']}</td>
        <td>{$row['exam_score']}</td>
        <td>$total</td>
    </tr>";
}
$percentage = count($scores) ? round($totalSum / count($scores), 2) : 0;
$html .= "</table><p>Final Score: $percentage%</p><p>Teacher Remark: $remark</p>";

$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML($html);
$mpdf->Output('Student_Report.pdf', 'D'); // force download
?>