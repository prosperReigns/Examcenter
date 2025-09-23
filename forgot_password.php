<?php
session_start();
require_once 'db.php';
require_once 'Mailer.php'; // Your mail sending class

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $_SESSION['fp_error'] = "Email is required.";
        header("Location: login.php");
        exit();
    }

    $conn = Database::getInstance()->getConnection();
    
    // Check if email exists in admins or teachers
    $stmt = $conn->prepare("SELECT id, role FROM admins WHERE email = ? UNION SELECT id, role FROM teachers WHERE email = ?");
    $stmt->bind_param("ss", $email, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        // Generate password reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        
        // Store token in a password_resets table
        $stmt2 = $conn->prepare("INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)");
        $stmt2->bind_param("isss", $user['id'], $email, $token, $expires);
        $stmt2->execute();
        $stmt2->close();
        
        // Send email with reset link
        $resetLink = "http://{$_SERVER['HTTP_HOST']}/EXAMCENTER/reset_password.php?token=$token";
        Mailer::send($email, "Password Reset Request", "Click this link to reset your password: $resetLink");
        
        $_SESSION['fp_success'] = "A password reset link has been sent to your email.";
    } else {
        $_SESSION['fp_error'] = "No account found with that email.";
    }
    header("Location: login.php");
}
?>