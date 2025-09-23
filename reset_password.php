<?php
session_start();
require_once 'db.php';

$conn = Database::getInstance()->getConnection();
$token = $_GET['token'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $token = $_POST['token'] ?? '';
    
    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        // Check token
        $stmt = $conn->prepare("SELECT user_id, email FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            // Update password
            $stmt2 = $conn->prepare("UPDATE admins SET password=? WHERE id=?");
            $stmt2->bind_param("si", $hash, $user['user_id']);
            $stmt2->execute();
            
            // If not found in admins, try teachers
            if ($stmt2->affected_rows === 0) {
                $stmt3 = $conn->prepare("UPDATE teachers SET password=? WHERE id=?");
                $stmt3->bind_param("si", $hash, $user['user_id']);
                $stmt3->execute();
                $stmt3->close();
            }
            
            $stmt2->close();
            
            // Delete token
            $stmt4 = $conn->prepare("DELETE FROM password_resets WHERE token=?");
            $stmt4->bind_param("s", $token);
            $stmt4->execute();
            $stmt4->close();
            
            $_SESSION['reset_success'] = "Password has been reset successfully. You can now log in.";
            header("Location: login.php");
            exit();
        } else {
            $error = "Invalid or expired token.";
        }
    }
}
