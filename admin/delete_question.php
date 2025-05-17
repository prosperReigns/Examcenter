<?php
session_start();
require_once '../db.php';

// Check if admin is logged in
if(!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question_id'])) {
    $conn = Database::getInstance()->getConnection();
    $question_id = mysqli_real_escape_string($conn, $_POST['question_id']);
    
    $sql = "DELETE FROM questions WHERE id = '$question_id'";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>