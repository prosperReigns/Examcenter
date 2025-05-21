<?php
require_once '../db.php';
header('Content-Type: application/json');

$database = Database::getInstance();
$conn = $database->getConnection();

$query = "SELECT subject, AVG(score) as average_score 
          FROM exam_results 
          GROUP BY subject";
$result = $conn->query($query);

$data = ['labels' => [], 'data' => []];

if($result) {
    while($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['subject'];
        $data['data'][] = round($row['average_score'], 1);
    }
    $result->free();
}

echo json_encode($data);
$conn->close();
?>