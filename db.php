<?php
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        $host = "192.168.0.4";
        $username = "cbt_user";
        $password = "";  // Using the password from your config
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
    
    // Prevent cloning of the instance
    private function __clone() {}
    
    // Prevent unserializing of the instance
    public function __wakeup() {}
}
?>