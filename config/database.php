<?php
// Suppress any output that might interfere with JSON responses
if (!ob_get_level()) {
    ob_start();
}

// Database configuration
class Database {
    private $host = 'localhost';
    private $db_name = 'crop_alert_system';
    private $username = 'root';
    private $password = '';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            // Log error instead of echoing (which breaks JSON responses)
            error_log("Database connection error: " . $exception->getMessage());
            // Don't echo here as it will break JSON responses
            throw new Exception("Database connection failed: " . $exception->getMessage());
        }
        
        return $this->conn;
    }
}