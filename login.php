<?php
session_start();
require_once 'db.php';

// Initialize variables
$error = '';
$conn = Database::getInstance()->getConnection();

// Check if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    redirectByRole($_SESSION['user_role']);
}

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);
    
    // Try admin login
    $sql = "SELECT id, username, password FROM admins WHERE username = ? ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = 'admin';
            Logger::log("Admin login: {$username}");
            redirectByRole('admin');
        }
    }
    
    // Try teacher login
    $sql = "SELECT id, username, password, first_name, last_name FROM teachers WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = 'teacher';
            $_SESSION['user_fullname'] = $user['first_name'] . ' ' . $user['last_name'];
            Logger::log("Teacher login: {$username}");
            redirectByRole('teacher');
        }
    }
    
    // Try student login (if you want to include this in the central system)
    // Add student login code here
    
    $error = "Invalid username or password";
}

// Function to redirect based on role
function redirectByRole($role) {
    switch ($role) {
        case 'admin':
            header("Location: admin/dashboard.php");
            exit();
        case 'teacher':
            header("Location: teacher/dashboard.php");
            exit();
        case 'student':
            header("Location: student/dashboard.php");
            exit();
        default:
            // Invalid role
            session_destroy();
            header("Location: login.php?error=invalid_role");
            exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-Portal | Login</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/all.css">
    <link rel="stylesheet" href="css/toastr.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
        }
        .login-container {
            max-width: 450px;
            margin: 5rem auto;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            background: white;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header h2 {
            color: #333;
            font-weight: 600;
        }
        .login-form .form-control {
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            margin-bottom: 1.5rem;
        }
        .login-form .btn-primary {
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            background-color: #4361ee;
            border-color: #4361ee;
            width: 100%;
            font-weight: 500;
        }
        .login-form .btn-primary:hover {
            background-color: #3a56d4;
            border-color: #3a56d4;
        }
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: #666;
        }
        .user-type-selector {
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-header">
                <h2>D-Portal Login</h2>
                <p>Enter your credentials to access your account</p>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form class="login-form" method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            
            <div class="login-footer">
                <p>© 2023 D-Portal. All rights reserved.</p>
            </div>
        </div>
    </div>
    
    <script src="js/jquery-3.7.0.min.js"></script>
    <script src="js/bootstrap.bundle.min.js"></script>
    <script src="js/toastr.min.js"></script>
</body>
</html>