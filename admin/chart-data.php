<?php
require_once '../db.php';
header('Content-Type: application/json');

$database = Database::getInstance();
$conn = $database->getConnection();

// Get the selected class from query parameters
$selectedClass = isset($_GET['class']) && $_GET['class'] !== 'all' ? $_GET['class'] : null;

$query = "
    SELECT t.subject, AVG(r.score) as average_score
    FROM results r
    JOIN tests t ON r.test_id = t.id
    ";

// Add join and condition if a specific class is selected
if ($selectedClass) {
    $query .= "
    JOIN students s ON r.user_id = s.id
    WHERE s.class = ?
    ";
}

$query .= "
    GROUP BY t.subject
";

$stmt = $conn->prepare($query);

if ($selectedClass) {
    $stmt->bind_param('s', $selectedClass);
}

$stmt->execute();
$result = $stmt->get_result();

$data = ['labels' => [], 'data' => []];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['subject'];
        $data['data'][] = round($row['average_score'], 1);
    }
    $result->free();
} else {
    $data['error'] = $conn->error;
}

// Ensure we always return valid data structure even if empty
if (empty($data['labels'])) {
    $data['labels'] = ['No data'];
    $data['data'] = [0];
}

echo json_encode($data);
$conn->close();
?>