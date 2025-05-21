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
        $database = Database::getInstance();
        $conn = $database->getConnection();
        
        $stmt = $conn->prepare("INSERT INTO activities_log 
            (activity, admin_id, ip_address, user_agent) 
            VALUES (?, ?, ?, ?)");
        
        $admin_id = $_SESSION['admin_id'] ?? 0;
        $ip = $_SERVER['REMOTE_ADDR'];
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt->bind_param("siss", $activity, $admin_id, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    }
}
?>

