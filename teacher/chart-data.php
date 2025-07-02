<?php
session_start();
require_once '../db.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    header('Content-Type: application/json');
    echo json_encode(['labels' => [], 'data' => []]);
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        header('Content-Type: application/json');
        echo json_encode(['labels' => [], 'data' => []]);
        exit();
    }

    // Get teacher's assigned subjects
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    if (empty($assigned_subjects)) {
        header('Content-Type: application/json');
        echo json_encode(['labels' => [], 'data' => []]);
        exit();
    }
    $subjects_in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $assigned_subjects)) . "'";

    // Get class filter
    $selected_class = isset($_GET['class']) && $_GET['class'] !== 'all' ? $_GET['class'] : null;

    // Build query for performance data
    $query = "SELECT t.subject, AVG(r.score) as average_score 
              FROM results r 
              JOIN tests t ON r.test_id = t.id 
              WHERE t.subject IN ($subjects_in)";
    if ($selected_class) {
        $query .= " AND t.class = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $selected_class);
    } else {
        $stmt = $conn->prepare($query);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $labels = [];
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $labels[] = $row['subject'];
        $data[] = round($row['average_score'], 1);
    }

    $stmt->close();
    $conn->close();

    header('Content-Type: application/json');
    echo json_encode(['labels' => $labels, 'data' => $data]);
} catch (Exception $e) {
    error_log("Chart data error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['labels' => [], 'data' => []]);
}
?>