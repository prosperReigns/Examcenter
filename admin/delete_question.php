<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question_id'])) {
    $conn = Database::getInstance()->getConnection();
    $question_id = $_POST['question_id'];

    if (!is_numeric($question_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid question ID']);
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
    $stmt->bind_param("i", $question_id);

    if ($stmt->execute()) {
        $stmt->close();
        header('Location: view_questions.php');
        exit();
    } else {
        $_SESSION['error'] = $conn->error;
        $stmt->close();
        header('Location: view_questions.php');
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}
?>
