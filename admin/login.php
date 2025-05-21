<?php
session_start();
require_once '../db.php';

if(isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    Logger::log("Successful login from " . $_SERVER['REMOTE_ADDR']);
    exit();
}
// $_SESSION['admin_id'] = $admin['id'];


$conn = Database::getInstance()->getConnection();
$error = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);
    
    $sql = "SELECT * FROM admins WHERE username = '$username'";
    $result = mysqli_query($conn, $sql);
    
    if (mysqli_num_rows($result) == 1) {
        $row = mysqli_fetch_assoc($result);
        if (password_verify($password, $row['password'])) {
            $_SESSION['admin_id'] = $row['id'];
            $_SESSION['admin_username'] = $row['username'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid login credentials";
        }
    } else {
        $error = "Invalid login credentials";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-Portal | Admin Login</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/toastr.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            opacity: 0;
            animation: fadeIn 0.5s forwards;
        }
        @keyframes fadeIn {
            to { opacity: 1; }
        }
        
        /* Add this to prevent any scaling on click */
.login-container:active,
.login-container:focus,
.login-container *:active,
.login-container *:focus {
    transform: none !important;
    font-size: inherit !important;
    -webkit-tap-highlight-color: transparent;
}

/* Disable text size adjustment on mobile */
.login-container {
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
    touch-action: manipulation;
}

/* Specifically target form elements */
.login-container form * {
    user-select: none;
    -webkit-user-select: none;
}


/* Disable any hover/active scaling effects */
.btn-primary:active,
.btn-primary:focus,
.form-control:active,
.form-control:focus {
    transform: none !important;
    box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.15) !important;
}
        .login-container {
            max-width: 450px;
            margin: 5rem auto;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            background: white;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px);
             font-size: 15px;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, #4361ee, #3f37c9);
             font-size: 15px;
        }
        
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #4361ee;
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.15);
        }
        
        .btn-primary {
            background: #4361ee;
            border: none;
            padding: 0.75rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            font-size: 15px;
        }
        
        .fa-user-shield {
            background: linear-gradient(to right, #4361ee, #3f37c9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="login-container mx-auto">
        <div class="text-center mb-5">
            <i class="fas fa-user-shield fa-4x mb-3"></i>
            <h2 class="fw-bold">Admin Portal</h2>
            <p class="text-muted mb-0">Secure Access to Examination Management</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-danger mb-4" id="loginError">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="loginForm" novalidate>
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold">Username</label>
                <input type="text" 
                       id="username"
                       name="username"
                       class="form-control form-control-lg"
                       required
                       autocomplete="username"
                       autofocus
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold">Password</label>
                <input type="password" 
                       id="password"
                       name="password"
                       class="form-control form-control-lg"
                       required
                       autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 btn-lg" id="loginBtn">
                <span class="btn-text">Login</span>
                <i class="fas fa-sign-in-alt ms-2"></i>
            </button>
        </form>
    </div>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
<script src="../js/toastr.min.js"></script>
<script src="../js/gsap-public/minified/gsap.min.js"></script>
<script src="../js/login-animation.js"></script>
</body>
</html>