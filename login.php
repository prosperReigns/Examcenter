<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

try {
    require_once 'db.php';
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $error = "System error. Please try again later.";
}

$error = '';

// Helper function to validate user and get role
function getUserRole($conn, $user_id) {
    try {
        // Check admins table
        $stmt = $conn->prepare("SELECT id, role FROM admins WHERE id = ?");
        if (!$stmt) {
            error_log("getUserRole: Prepare failed for admins - " . $conn->error);
            return false;
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            $stmt->close();
            return ['id' => $user['id'], 'role' => strtolower($user['role'])];
        }

        // Check teachers table
        $stmt = $conn->prepare("SELECT id, role FROM teachers WHERE id = ?");
        if (!$stmt) {
            error_log("getUserRole: Prepare failed for teachers - " . $conn->error);
            return false;
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            $stmt->close();
            return ['id' => $user['id'], 'role' => strtolower($user['role'])];
        }

        $stmt->close();
        return false;
    } catch (Exception $e) {
        error_log("getUserRole error: " . $e->getMessage());
        return false;
    }
}

// Helper function to redirect based on role
function redirectByRole($role, $user_id) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    Logger::log("Redirecting user_id=$user_id, role=$role from $ip");
    
    if ($role === 'admin') {
        header("Location: /EXAMCENTER/admin/dashboard.php");
        exit();
    } elseif ($role === 'teacher') {
        header("Location: /EXAMCENTER/teacher/dashboard.php");
        exit();
    } else {
        error_log("Invalid role for user_id=$user_id: $role");
        session_destroy();
        return "Invalid user role";
    }
}

// Initialize database connection
try {
    $conn = Database::getInstance()->getConnection();
    if (!$conn || $conn->connect_error) {
        error_log("Database connection failed: " . ($conn ? $conn->connect_error : 'No connection'));
        $error = "Database connection failed. Please try again later.";
    }
} catch (Exception $e) {
    error_log("DB init error: " . $e->getMessage());
    $error = "System error";
}

// Check if already logged in
if (isset($_SESSION['user_id']) && !$error) {
    $user_id = (int)$_SESSION['user_id'];
    if ($user = getUserRole($conn, $user_id)) {
        $error = redirectByRole($user['role'], $user_id);
    } else {
        error_log("No user found for user_id=$user_id");
        session_destroy();
        $error = "Session invalid";
    }
}

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && !$error) {
    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = "Username and password are required";
        } else {
            // Try admins table
            $stmt = $conn->prepare("SELECT id, username, password, role FROM admins WHERE username = ?");
            if ($stmt === false) {
                error_log("Login: Prepare failed for admins - " . $conn->error);
                $error = "Database error. Please try again.";
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $row = $result->fetch_assoc();
                    if (password_verify($password, $row['password'])) {
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['user_username'] = $row['username'];
                        $role = strtolower($row['role']);
                        Logger::log("Successful login for username=$username, role=$role from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                        $error = redirectByRole($role, $row['id']);
                    } else {
                        $error = "Invalid login credentials";
                        Logger::log("Failed login attempt for username=$username: Invalid password from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                    }
                    $stmt->close();
                } else {
                    // Try teachers table
                    $stmt = $conn->prepare("SELECT id, username, password, role FROM teachers WHERE username = ?");
                    if ($stmt === false) {
                        error_log("Login: Prepare failed for teachers - " . $conn->error);
                        $error = "Database error. Please try again.";
                    } else {
                        $stmt->bind_param("s", $username);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows == 1) {
                            $row = $result->fetch_assoc();
                            if (password_verify($password, $row['password'])) {
                                $_SESSION['user_id'] = $row['id'];
                                $_SESSION['user_username'] = $row['username'];
                                $role = strtolower($row['role']);
                                Logger::log("Successful login for username=$username, role=$role from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                                $error = redirectByRole($role, $row['id']);
                            } else {
                                $error = "Invalid login credentials";
                                Logger::log("Failed login attempt for username=$username: Invalid password from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                            }
                        } else {
                            $error = "Invalid login credentials";
                            Logger::log("Failed login attempt: Username=$username not found from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                        }
                        $stmt->close();
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        $error = "System error during login";
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-Portal | Staff Login</title>
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
        
        .login-container:active,
        .login-container:focus,
        .login-container *:active,
        .login-container *:focus {
            transform: none !important;
            font-size: inherit !important;
            -webkit-tap-highlight-color: transparent;
        }

        .login-container {
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
            touch-action: manipulation;
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
            animation: slideIn 0.5s forwards 0.2s;
            font-size: 15px;
        }
        
        @keyframes slideIn {
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, #4361ee, #3f37c9);
        }
        
        .login-container form * {
            user-select: none;
            -webkit-user-select: none;
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
        
        .btn-primary:hover {
            background: #3f37c9;
        }
        
        .btn-primary:active,
        .btn-primary:focus,
        .form-control:active,
        .form-control:focus {
            transform: none !important;
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.15) !important;
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
            <h2 class="fw-bold">Staff Portal</h2>
            <p class="text-muted mb-0">Secure Access to Examination Management</p>
        </div>
        
        <?php if ($error): ?>
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