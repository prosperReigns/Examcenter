<?php
require_once '../db.php';
require_once '../includes/system_guard.php';

if(isset($_POST['student_id'])) {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    $student_id = $conn->real_escape_string($_POST['student_id']);
    
    $query = "SELECT * FROM exam_results WHERE student_id = '$student_id' ORDER BY exam_date DESC LIMIT 1";
    $result = $conn->query($query);
    
    if($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Return formatted HTML
        echo "<div class='row'><div class='col-md-6'>...result details here...</div></div>";
    } else {
        echo "<div class='text-center py-4'><i class='fas fa-exclamation-circle fa-2x'></i><p>No results found</p></div>";
    }
    
    $conn->close();
}
?>