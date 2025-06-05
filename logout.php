<?php
session_start();

// Log the logout activity
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    require_once 'db.php';
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['user_role'];
    Logger::log("User logout: ID=$user_id, Role=$role");
}

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: index.php");
exit();
?>