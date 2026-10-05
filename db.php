<?php
declare(strict_types=1);

class Database {
    private static ?self $instance = null;
    private mysqli $conn;

    private function __construct() {
        $host = getenv('EXAMCENTER_DB_HOST') ?: '127.0.0.1';
        $username = getenv('EXAMCENTER_DB_USER') ?: 'root';
        $password = getenv('EXAMCENTER_DB_PASSWORD') ?: '';
        $database = getenv('EXAMCENTER_DB_NAME') ?: 'cbt_app_db';
        $port = (int)(getenv('EXAMCENTER_DB_PORT') ?: 3307);

        $this->conn = mysqli_connect($host, $username, $password, $database, $port);

        if (!$this->conn) {
            throw new RuntimeException('Database connection failed: ' . mysqli_connect_error());
        }

        if (!$this->conn->set_charset('utf8mb4')) {
            throw new RuntimeException('Unable to configure utf8mb4: ' . $this->conn->error);
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): mysqli {
        return $this->conn;
    }

    public static function connection(): mysqli {
        return self::getInstance()->getConnection();
    }

    private function __clone() {}
    public function __wakeup(): void {}
}
