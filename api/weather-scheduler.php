<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/weather-check.php';

class WeatherScheduler {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'POST':
                return $this->runWeatherCheck();
            case 'GET':
                return $this->getSchedulerStatus();
            default:
                http_response_code(405);
                return ['success' => false, 'message' => 'Method not allowed'];
        }
    }
    
    public function runWeatherCheck() {
        try {
            // Check if we need to run weather check (avoid running too frequently)
            $lastCheck = $this->getLastWeatherCheck();
            $currentTime = time();
            
            // Only run if last check was more than 30 minutes ago
            if ($lastCheck && ($currentTime - strtotime($lastCheck)) < 1800) {
                return [
                    'success' => true,
                    'message' => 'Weather check already performed recently',
                    'data' => ['last_check' => $lastCheck]
                ];
            }
            
            // Fetch fresh weather data
            require_once 'weather.php';
            $weatherAPI = new WeatherAPI();
            $weatherResult = $weatherAPI->getWeatherData();
            
            if (!$weatherResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to fetch weather data: ' . $weatherResult['message']
                ];
            }
            
            // Check weather conditions and generate alerts
            $checker = new WeatherConditionChecker();
            $alertResult = $checker->checkWeatherConditions();
            
            // Fetch external weather alerts (typhoon, storm warnings)
            $externalAlerts = $this->fetchExternalWeatherAlerts();
            
            // Analyze crop trends and generate crop-specific alerts
            $cropTrendAlerts = $this->analyzeCropTrends();
            
            // Update last check time
            $this->updateLastWeatherCheck();
            
            return [
                'success' => true,
                'message' => 'Weather check completed successfully',
                'data' => [
                    'weather_fetched' => true,
                    'alerts_generated' => $alertResult['success'] ? $alertResult['data']['alerts_created'] : 0,
                    'external_alerts_fetched' => $externalAlerts['success'] ? $externalAlerts['data']['count'] : 0,
                    'crop_alerts_generated' => $cropTrendAlerts['success'] ? $cropTrendAlerts['alerts_created'] : 0,
                    'crops_analyzed' => $cropTrendAlerts['success'] ? $cropTrendAlerts['crops_analyzed'] : 0,
                    'last_check' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to run weather check: ' . $e->getMessage()
            ];
        }
    }
    
    public function getSchedulerStatus() {
        try {
            $lastCheck = $this->getLastWeatherCheck();
            $nextCheck = $lastCheck ? date('Y-m-d H:i:s', strtotime($lastCheck) + 1800) : null;
            
            return [
                'success' => true,
                'data' => [
                    'last_check' => $lastCheck,
                    'next_check' => $nextCheck,
                    'status' => 'active'
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get scheduler status: ' . $e->getMessage()
            ];
        }
    }
    
    private function getLastWeatherCheck() {
        try {
            $query = "SELECT value FROM system_settings WHERE setting_key = 'last_weather_check'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result ? $result['value'] : null;
            
        } catch (Exception $e) {
            // If table doesn't exist, create it
            $this->createSystemSettingsTable();
            return null;
        }
    }
    
    private function updateLastWeatherCheck() {
        try {
            $currentTime = date('Y-m-d H:i:s');
            $query = "INSERT INTO system_settings (setting_key, value, updated_at) 
                     VALUES ('last_weather_check', :value, NOW()) 
                     ON DUPLICATE KEY UPDATE value = :value, updated_at = NOW()";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':value', $currentTime);
            $stmt->execute();
            
        } catch (Exception $e) {
            // If table doesn't exist, create it
            $this->createSystemSettingsTable();
            $this->updateLastWeatherCheck();
        }
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
    
    /**
     * Fetch external weather alerts from weather-alerts-external.php
     */
    private function fetchExternalWeatherAlerts() {
        try {
            require_once __DIR__ . '/weather-alerts-external.php';
            $alertsAPI = new WeatherAlertsExternal();
            
            // Get all farmers' locations
            $query = "SELECT DISTINCT latitude, longitude FROM users WHERE latitude IS NOT NULL AND longitude IS NOT NULL";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $locations = $stmt->fetchAll();
            
            // If no user locations, use default Manila location
            if (empty($locations)) {
                $locations = [['latitude' => 14.5995, 'longitude' => 120.9842]];
            }
            
            $totalAlerts = 0;
            $createdAlerts = 0;
            
            foreach ($locations as $location) {
                $result = $alertsAPI->getSevereWeatherAlerts(
                    $location['latitude'],
                    $location['longitude']
                );
                
                if ($result['success'] && !empty($result['data'])) {
                    $totalAlerts += count($result['data']);
                    
                    // Store alerts in database
                    foreach ($result['data'] as $alert) {
                        $alertId = $this->storeExternalAlert($alert);
                        if ($alertId) {
                            $createdAlerts++;
                        }
                    }
                }
            }
            
            return [
                'success' => true,
                'data' => [
                    'count' => $totalAlerts,
                    'created' => $createdAlerts
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Failed to fetch external weather alerts: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => ['count' => 0, 'created' => 0]
            ];
        }
    }
    
    /**
     * Analyze crop trends and generate alerts
     */
    private function analyzeCropTrends() {
        try {
            require_once __DIR__ . '/crop-trend-alerts.php';
            $cropTrendAPI = new CropTrendAlertsAPI();
            return $cropTrendAPI->analyzeCropTrends();
        } catch (Exception $e) {
            error_log("Failed to analyze crop trends: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to analyze crop trends: ' . $e->getMessage(),
                'data' => [],
                'alerts_created' => 0,
                'crops_analyzed' => 0
            ];
        }
    }
    
    /**
     * Store external alert in database
     */
    private function storeExternalAlert($alertData) {
        try {
            // Check if alert already exists (by type, description, and date)
            $checkQuery = "SELECT id FROM alerts 
                          WHERE type = :type 
                          AND description LIKE :description 
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          LIMIT 1";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':type', $alertData['type']);
            $checkStmt->bindValue(':description', '%' . substr($alertData['description'], 0, 50) . '%');
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                // Alert already exists, skip
                return null;
            }
            
            // Map external alert severity to database severity
            $severity = 'medium';
            if (isset($alertData['severity'])) {
                $severityMap = [
                    'low' => 'low',
                    'medium' => 'medium',
                    'high' => 'high',
                    'critical' => 'high'
                ];
                $severity = $severityMap[$alertData['severity']] ?? 'medium';
            }
            
            // Extract location from alert data if available
            $locationInfo = '';
            if (isset($alertData['area'])) {
                $locationInfo = $alertData['area'];
            } elseif (isset($alertData['location'])) {
                $locationInfo = $alertData['location'];
            }
            
            // Insert alert with location info in description if not already there
            $description = $alertData['description'] ?? $alertData['title'] ?? 'Severe weather alert';
            if ($locationInfo && strpos($description, 'Lat:') === false) {
                $description .= ' ' . $locationInfo;
            }
            
            $query = "INSERT INTO alerts (type, severity, description, status, created_at) 
                     VALUES (:type, :severity, :description, 'active', NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':type', $alertData['type'] ?? 'severe_weather');
            $stmt->bindValue(':severity', $severity);
            $stmt->bindValue(':description', $description);
            
            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Failed to store external alert: " . $e->getMessage());
            return null;
        }
    }
}

// Handle the request
$scheduler = new WeatherScheduler();
$result = $scheduler->handleRequest();
echo json_encode($result);
?>
