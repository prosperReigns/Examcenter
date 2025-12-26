<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

$conn = Database::getInstance()->getConnection();

// Get filter parameters from GET request
$class_filter = $_GET['selected_class'] ?? '';
$subject_filter = $_GET['selected_subject'] ?? '';
$test_title_filter = $_GET['selected_title'] ?? '';

// Build query to fetch all results
$query = "SELECT r.*, s.name AS student_name, s.class AS student_class, 
                 t.subject, t.title AS test_title, t.class AS test_class 
          FROM results r
          JOIN students s ON r.user_id = s.id
          JOIN tests t ON r.test_id = t.id
          WHERE 1=1";

$params = [];
$types = '';

// Apply filters if provided
if (!empty($test_title_filter)) {
    $query .= " AND t.title = ?";
    $params[] = $test_title_filter;
    $types .= 's';
}

if (!empty($class_filter)) {
    $query .= " AND s.class = ?";
    $params[] = $class_filter;
    $types .= 's';
}

if (!empty($subject_filter)) {
    $query .= " AND t.subject = ?";
    $params[] = $subject_filter;
    $types .= 's';
}

$query .= " ORDER BY r.created_at DESC";

// Prepare and execute
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$results = $result->fetch_all(MYSQLI_ASSOC);

// Generate Word export
$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Test Results Export</title>
<style>
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #4361ee; color: white; font-weight: bold; }
tr:nth-child(even) { background-color: #f2f2f2; }
</style>
</head>
<body>
<h1>Test Results</h1>';

// Add applied filters info
$html .= '<p><strong>Filters Applied:</strong><br>';
if (!empty($test_title_filter)) $html .= 'Test Title: '.htmlspecialchars($test_title_filter).'<br>';
if (!empty($class_filter)) $html .= 'Class: '.htmlspecialchars($class_filter).'<br>';
if (!empty($subject_filter)) $html .= 'Subject: '.htmlspecialchars($subject_filter);
$html .= '</p>';

// Results table
$html .= '<table>
<thead>
<tr>
<th>Student Name</th>
<th>Class</th>
<th>Test Title</th>
<th>Subject</th>
<th>Score</th>
<th>Percentage</th>
<th>Date</th>
</tr>
</thead>
<tbody>';

foreach ($results as $result) {
    $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
    $style = $percentage >= 70 ? 'color: green; font-weight: bold;' : ($percentage >= 50 ? 'color: orange;' : 'color: red;');
    
    $html .= '<tr>
    <td>'.htmlspecialchars($result['student_name']).'</td>
    <td>'.htmlspecialchars($result['student_class']).'</td>
    <td>'.htmlspecialchars($result['test_title']).'</td>
    <td>'.htmlspecialchars($result['subject']).'</td>
    <td>'.$result['score'].'/'.$result['total_questions'].'</td>
    <td style="'.$style.'">'.$percentage.'%</td>
    <td>'.date('M j, Y g:i A', strtotime($result['created_at'])).'</td>
    </tr>';
}

$html .= '</tbody></table></body></html>';

// Output headers for Word download
header('Content-Type: application/vnd.ms-word');
header('Content-Disposition: attachment;filename="admin_test_results_'.date('Y-m-d').'.doc"');
header('Cache-Control: max-age=0');

echo $html;
exit;
