<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/weather.php';

class WeatherConditionChecker {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function checkWeatherConditions() {
        try {
            // Get latest weather data
            $weatherData = $this->getLatestWeatherData();
            
            if (!$weatherData) {
                return [
                    'success' => false,
                    'message' => 'No weather data available'
                ];
            }
            
            $alerts = [];
            
            // Check for drought conditions
            if ($this->checkDroughtCondition($weatherData)) {
                $alerts[] = $this->createDroughtAlert($weatherData);
            }
            
            // Check for storm conditions
            if ($this->checkStormCondition($weatherData)) {
                $alerts[] = $this->createStormAlert($weatherData);
            }
            
            // Check for heavy rainfall
            if ($this->checkHeavyRainfallCondition($weatherData)) {
                $alerts[] = $this->createHeavyRainfallAlert($weatherData);
            }
            
            // Check for extreme temperature
            if ($this->checkExtremeTemperatureCondition($weatherData)) {
                $alerts[] = $this->createExtremeTemperatureAlert($weatherData);
            }
            
            // Check for high humidity conditions
            if ($this->checkHighHumidityCondition($weatherData)) {
                $alerts[] = $this->createHighHumidityAlert($weatherData);
            }
            
            // Check for frost conditions
            if ($this->checkFrostCondition($weatherData)) {
                $alerts[] = $this->createFrostAlert($weatherData);
            }
            
            // Check for heat wave conditions
            if ($this->checkHeatWaveCondition($weatherData)) {
                $alerts[] = $this->createHeatWaveAlert($weatherData);
            }
            
            // Store alerts in database
            $createdAlerts = [];
            foreach ($alerts as $alert) {
                $alertId = $this->storeAlert($alert);
                if ($alertId) {
                    $createdAlerts[] = $alertId;
                }
            }
            
            return [
                'success' => true,
                'message' => 'Weather conditions checked successfully',
                'data' => [
                    'alerts_created' => count($createdAlerts),
                    'alert_ids' => $createdAlerts
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to check weather conditions: ' . $e->getMessage()
            ];
        }
    }
    
    private function getLatestWeatherData() {
        try {
            // First try to get from database
            $query = "SELECT * FROM weather_data ORDER BY recorded_at DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $dbData = $stmt->fetch();
            
            if ($dbData) {
                return $dbData;
            }
            
            // If no database data, fetch fresh from API
            $weatherAPI = new WeatherAPI();
            $weatherResult = $weatherAPI->getWeatherData();
            
            if ($weatherResult['success']) {
                return $weatherResult['data']['current'];
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Failed to get latest weather data: " . $e->getMessage());
            return null;
        }
    }
    
    private function checkDroughtCondition($weatherData) {
        // Check if rainfall is very low for extended period
        $query = "SELECT AVG(rainfall) as avg_rainfall, COUNT(*) as days_count 
                 FROM weather_data 
                 WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch();
        
        // More sensitive drought detection
        return $result['avg_rainfall'] < 3 && $result['days_count'] >= 3;
    }
    
    private function checkStormCondition($weatherData) {
        // Check for high wind speed and heavy rainfall
        return $weatherData['wind_speed'] > 50 && $weatherData['rainfall'] > 20;
    }
    
    private function checkHeavyRainfallCondition($weatherData) {
        // Check for heavy rainfall
        return $weatherData['rainfall'] > 30;
    }
    
    private function checkExtremeTemperatureCondition($weatherData) {
        // Check for extreme temperatures
        return $weatherData['temperature'] > 35 || $weatherData['temperature'] < 10;
    }
    
    private function checkHighHumidityCondition($weatherData) {
        // Check for high humidity that can cause fungal diseases
        return $weatherData['humidity'] > 85;
    }
    
    private function checkFrostCondition($weatherData) {
        // Check for frost conditions (low temperature with high humidity)
        return $weatherData['temperature'] < 5 && $weatherData['humidity'] > 70;
    }
    
    private function checkHeatWaveCondition($weatherData) {
        // Check for heat wave conditions (high temperature for extended period)
        $query = "SELECT AVG(temperature) as avg_temp, COUNT(*) as days_count 
                 FROM weather_data 
                 WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result['avg_temp'] > 32 && $result['days_count'] >= 2;
    }
    
    private function createDroughtAlert($weatherData) {
        return [
            'type' => 'Drought Warning',
            'severity' => 'high',
            'description' => 'Low rainfall detected for the past week. Consider irrigation and water conservation measures.',
            'weather_conditions' => json_encode([
                'temperature' => $weatherData['temperature'],
                'rainfall' => $weatherData['rainfall'],
                'humidity' => $weatherData['humidity']
            ])
        ];
    }
    
    private function createStormAlert($weatherData) {
        return [
            'type' => 'Storm Warning',
            'severity' => 'high',
            'description' => 'High wind speed and heavy rainfall detected. Secure crops and equipment.',
            'weather_conditions' => json_encode([
                'wind_speed' => $weatherData['wind_speed'],
                'rainfall' => $weatherData['rainfall'],
                'temperature' => $weatherData['temperature']
            ])
        ];
    }
    
    private function createHeavyRainfallAlert($weatherData) {
        return [
            'type' => 'Heavy Rainfall Warning',
            'severity' => 'medium',
            'description' => 'Heavy rainfall detected. Monitor for flooding and drainage issues.',
            'weather_conditions' => json_encode([
                'rainfall' => $weatherData['rainfall'],
                'humidity' => $weatherData['humidity']
            ])
        ];
    }
    
    private function createExtremeTemperatureAlert($weatherData) {
        $severity = $weatherData['temperature'] > 35 ? 'high' : 'medium';
        $description = $weatherData['temperature'] > 35 
            ? 'Extreme heat detected. Provide shade and increase irrigation.'
            : 'Low temperature detected. Protect sensitive crops from frost.';
            
        return [
            'type' => 'Extreme Temperature Warning',
            'severity' => $severity,
            'description' => $description,
            'weather_conditions' => json_encode([
                'temperature' => $weatherData['temperature'],
                'humidity' => $weatherData['humidity']
            ])
        ];
    }
    
    private function createHighHumidityAlert($weatherData) {
        return [
            'type' => 'High Humidity Warning',
            'severity' => 'medium',
            'description' => 'High humidity detected. Monitor crops for fungal diseases and improve ventilation.',
            'weather_conditions' => json_encode([
                'humidity' => $weatherData['humidity'],
                'temperature' => $weatherData['temperature']
            ])
        ];
    }
    
    private function createFrostAlert($weatherData) {
        return [
            'type' => 'Frost Warning',
            'severity' => 'high',
            'description' => 'Frost conditions detected. Cover sensitive crops and consider frost protection measures.',
            'weather_conditions' => json_encode([
                'temperature' => $weatherData['temperature'],
                'humidity' => $weatherData['humidity']
            ])
        ];
    }
    
    private function createHeatWaveAlert($weatherData) {
        return [
            'type' => 'Heat Wave Warning',
            'severity' => 'high',
            'description' => 'Heat wave conditions detected. Increase irrigation frequency and provide shade for sensitive crops.',
            'weather_conditions' => json_encode([
                'temperature' => $weatherData['temperature'],
                'humidity' => $weatherData['humidity']
            ])
        ];
    }
    
    private function storeAlert($alertData) {
        try {
            $query = "INSERT INTO alerts (type, severity, description, status, created_at) 
                     VALUES (:type, :severity, :description, 'active', NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':type', $alertData['type']);
            $stmt->bindParam(':severity', $alertData['severity']);
            $stmt->bindParam(':description', $alertData['description']);
            
            if ($stmt->execute()) {
                $alertId = $this->conn->lastInsertId();
                
                // Get farmers who might be affected
                $affectedFarmers = $this->getAffectedFarmers($alertData);
                
                // Link farmers to alert
                if (!empty($affectedFarmers)) {
                    $this->linkFarmersToAlert($alertId, $affectedFarmers);
                }
                
                return $alertId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Failed to store alert: " . $e->getMessage());
            return false;
        }
    }
    
    private function getAffectedFarmers($alertData) {
        try {
            // For now, return all farmers
            // In a real system, you would determine affected farmers based on location, crop type, etc.
            $query = "SELECT id FROM farmers";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $farmers = $stmt->fetchAll();
            return array_column($farmers, 'id');
            
        } catch (Exception $e) {
            error_log("Failed to get affected farmers: " . $e->getMessage());
            return [];
        }
    }
    
    private function linkFarmersToAlert($alertId, $farmerIds) {
        try {
            $query = "INSERT INTO alert_farmers (alert_id, farmer_id) VALUES (:alert_id, :farmer_id)";
            $stmt = $this->conn->prepare($query);
            
            foreach ($farmerIds as $farmerId) {
                $stmt->bindParam(':alert_id', $alertId);
                $stmt->bindParam(':farmer_id', $farmerId);
                $stmt->execute();
            }
            
        } catch (Exception $e) {
            error_log("Failed to link farmers to alert: " . $e->getMessage());
        }
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checker = new WeatherConditionChecker();
    $result = $checker->checkWeatherConditions();
    echo json_encode($result);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
