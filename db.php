<?php
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        $host = "localhost"; // or 127.0.0.1
        $username = "root";
        $password = "";
        $database = "cbt_app_db";

        $this->conn = mysqli_connect($host, $username, $password, $database);
        
        if (!$this->conn) {
            die("Connection failed: " . mysqli_connect_error());
        }

        // 🔴 FORCE UTF-8 (CRITICAL FOR MATH SYMBOLS)
        if (!$this->conn->set_charset("utf8mb4")) {
            die("Error loading character set utf8mb4: " . $this->conn->error);
        }
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    private function __clone() {}
    public function __wakeup() {}
}

class Logger {
    public static function log($activity) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            if (!$conn || $conn->connect_error) {
                error_log("Logger: Database connection failed: " . ($conn ? $conn->connect_error : 'No connection'));
                return false;
            }
            
            $stmt = $conn->prepare("INSERT INTO activities_log 
                (activity, user_id, ip_address, user_agent) 
                VALUES (?, ?, ?, ?)");
            
            if (!$stmt) {
                error_log("Logger: Prepare failed: " . $conn->error);
                return false;
            }
            
            $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $stmt->bind_param("siss", $activity, $user_id, $ip, $agent);
            if (!$stmt->execute()) {
                error_log("Logger: Execute failed: " . $stmt->error);
                return false;
            }
            
            $stmt->close();
            return true;
        } catch (Exception $e) {
            error_log("Logger: Exception - " . $e->getMessage());
            return false;
        }
    }
}
?>


