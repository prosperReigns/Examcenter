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
    public static function connection(){
        return self::getInstance()->getConnection();
    }
    private function __clone() {}
    public function __wakeup() {}
}
?>


