<?php
session_start();
require_once '../db.php';

// Initialize database connection
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    // Verify user is a teacher
    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher role check: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'teacher') {
        error_log("Unauthorized access attempt by user_id=$user_id, role=" . ($user['role'] ?? 'none'));
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    die("System error");
}

$conn = Database::getInstance()->getConnection();

// Get filter parameters from GET request
$class_filter = $_GET['selected_class'] ?? '';
$subject_filter = $_GET['selected_subject'] ?? '';
$test_title_filter = $_GET['selected_title'] ?? '';

// Build the query (same as in view_results.php)
$query = "SELECT r.*, s.name AS student_name, s.class AS student_class, 
                 t.subject, t.title AS test_title, t.class AS test_class 
          FROM results r
          JOIN students s ON r.user_id = s.id
          JOIN tests t ON r.test_id = t.id
          WHERE 1=1";

$params = [];
$types = '';

// Apply filters
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

// Prepare and execute the query
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$results = $result->fetch_all(MYSQLI_ASSOC);

// Generate Word document content
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Results Export</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4361ee;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <h1>Test Results</h1>';

// Add filter information
$html .= '<p><strong>Filters Applied:</strong><br>';
if (!empty($test_title_filter)) $html .= 'Test Title: '.htmlspecialchars($test_title_filter).'<br>';
if (!empty($class_filter)) $html .= 'Class: '.htmlspecialchars($class_filter).'<br>';
if (!empty($subject_filter)) $html .= 'Subject: '.htmlspecialchars($subject_filter);
$html .= '</p>';

// Create results table
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
    $percentage_class = '';
    if ($percentage >= 70) $percentage_class = 'color: green; font-weight: bold;';
    elseif ($percentage >= 50) $percentage_class = 'color: orange;';
    else $percentage_class = 'color: red;';

    $html .= '<tr>
        <td>'.htmlspecialchars($result['student_name']).'</td>
        <td>'.htmlspecialchars($result['student_class']).'</td>
        <td>'.htmlspecialchars($result['test_title']).'</td>
        <td>'.htmlspecialchars($result['subject']).'</td>
        <td>'.$result['score'].'/'.$result['total_questions'].'</td>
        <td style="'.$percentage_class.'">'.$percentage.'%</td>
        <td>'.date('M j, Y g:i A', strtotime($result['created_at'])).'</td>
    </tr>';
}

$html .= '</tbody></table></body></html>';

// Set headers for download
header('Content-Type: application/vnd.ms-word');
header('Content-Disposition: attachment;filename="test_results_'.date('Y-m-d').'.doc"');
header('Cache-Control: max-age=0');

// Output the HTML content
echo $html;
exit;