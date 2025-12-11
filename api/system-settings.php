<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class SystemSettingsAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->createSystemSettingsTable();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                return $this->getSettings();
            case 'POST':
                return $this->saveSettings();
            case 'PUT':
                return $this->updateSettings();
            default:
                http_response_code(405);
                return ['success' => false, 'message' => 'Method not allowed'];
        }
    }
    
    public function getSettings() {
        try {
            $settings = [];
            
            // Get all settings from database
            $query = "SELECT setting_key, value FROM system_settings";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            // Convert to associative array
            foreach ($results as $result) {
                $settings[$result['setting_key']] = $result['value'];
            }
            
            // Set default values if not found
            $defaultSettings = $this->getDefaultSettings();
            foreach ($defaultSettings as $key => $value) {
                if (!isset($settings[$key])) {
                    $settings[$key] = $value;
                }
            }
            
            return [
                'success' => true,
                'data' => $settings
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch settings: ' . $e->getMessage()
            ];
        }
    }
    
    public function saveSettings() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                return [
                    'success' => false,
                    'message' => 'Invalid input data'
                ];
            }
            
            $this->conn->beginTransaction();
            
            foreach ($input as $key => $value) {
                $this->saveSetting($key, $value);
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Settings saved successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => 'Failed to save settings: ' . $e->getMessage()
            ];
        }
    }
    
    public function updateSettings() {
        return $this->saveSettings(); // Same functionality
    }
    
    private function saveSetting($key, $value) {
        try {
            $query = "INSERT INTO system_settings (setting_key, value, updated_at) 
                     VALUES (:key, :value, NOW()) 
                     ON DUPLICATE KEY UPDATE value = :value, updated_at = NOW()";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':key', $key);
            $stmt->bindParam(':value', $value);
            $stmt->execute();
            
        } catch (Exception $e) {
            throw new Exception("Failed to save setting $key: " . $e->getMessage());
        }
    }
    
    private function getDefaultSettings() {
        return [
            // Weather Settings
            'weather_api_key' => '3G6B8WCJSMLKY53J4HUY6DNSN',
            'default_location' => 'Manila, Philippines',
            'weather_update_interval' => '30',
            
            // Alert Thresholds
            'drought_rainfall_threshold' => '3',
            'drought_days_threshold' => '3',
            'storm_wind_threshold' => '50',
            'storm_rain_threshold' => '20',
            'heavy_rain_threshold' => '30',
            'extreme_heat_threshold' => '35',
            'extreme_cold_threshold' => '10',
            'high_humidity_threshold' => '85',
            'frost_temp_threshold' => '5',
            'heat_wave_temp_threshold' => '32',
            'heat_wave_days_threshold' => '2',
            
            // System Settings
            'data_retention_days' => '90',
            'auto_backup_enabled' => 'false',
            'max_alerts_per_page' => '50',
            'max_farmers_per_page' => '100',
            
            // Notification Settings
            'email_notifications' => 'false',
            'sms_notifications' => 'false',
            'notification_frequency' => 'immediate'
        ];
    }
    
    private function createSystemSettingsTable() {
        try {
            $query = "CREATE TABLE IF NOT EXISTS system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            
            $this->conn->exec($query);
            
        } catch (Exception $e) {
            error_log("Failed to create system_settings table: " . $e->getMessage());
        }
    }
    
    public function getSetting($key, $defaultValue = null) {
        try {
            $query = "SELECT value FROM system_settings WHERE setting_key = :key";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':key', $key);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result ? $result['value'] : $defaultValue;
            
        } catch (Exception $e) {
            return $defaultValue;
        }
    }
}

// Handle the request
$settingsAPI = new SystemSettingsAPI();
$result = $settingsAPI->handleRequest();
echo json_encode($result);
?>
