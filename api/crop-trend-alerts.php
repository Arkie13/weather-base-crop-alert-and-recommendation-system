<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/weather.php';

/**
 * Crop Trend Alerts API
 * Analyzes weather trends for users' planted crops
 * Generates crop-specific alerts based on weather conditions
 */

class CropTrendAlertsAPI {
    private $conn;
    
    // Crop requirements database
    private $cropRequirements = [
        'rice' => [
            'name' => 'Rice',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 70,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2500,
            'optimal_wind_max' => 15,
            'water_requirement' => 'high',
            'drought_threshold' => 3, // mm per day
            'flood_threshold' => 50, // mm per day
            'frost_sensitive' => true,
            'heat_stress_threshold' => 38
        ],
        'corn' => [
            'name' => 'Corn',
            'optimal_temp_min' => 18,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1500,
            'optimal_wind_max' => 20,
            'water_requirement' => 'medium',
            'drought_threshold' => 2,
            'flood_threshold' => 40,
            'frost_sensitive' => true,
            'heat_stress_threshold' => 35
        ],
        'tomato' => [
            'name' => 'Tomato',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 28,
            'optimal_humidity_min' => 40,
            'optimal_humidity_max' => 70,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'water_requirement' => 'medium',
            'drought_threshold' => 2,
            'flood_threshold' => 30,
            'frost_sensitive' => true,
            'heat_stress_threshold' => 32
        ],
        'eggplant' => [
            'name' => 'Eggplant',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 600,
            'optimal_rainfall_max' => 1000,
            'optimal_wind_max' => 15,
            'water_requirement' => 'medium',
            'drought_threshold' => 2.5,
            'flood_threshold' => 35,
            'frost_sensitive' => true,
            'heat_stress_threshold' => 35
        ],
        'okra' => [
            'name' => 'Okra',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 85,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1200,
            'optimal_wind_max' => 15,
            'water_requirement' => 'medium',
            'drought_threshold' => 2,
            'flood_threshold' => 40,
            'frost_sensitive' => true,
            'heat_stress_threshold' => 38
        ],
        'pepper' => [
            'name' => 'Pepper',
            'optimal_temp_min' => 18,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 75,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1000,
            'optimal_wind_max' => 12,
            'water_requirement' => 'medium',
            'drought_threshold' => 2,
            'flood_threshold' => 30,
            'frost_sensitive' => true,
            'heat_stress_threshold' => 33
        ],
        'cabbage' => [
            'name' => 'Cabbage',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1000,
            'optimal_wind_max' => 10,
            'water_requirement' => 'high',
            'drought_threshold' => 3,
            'flood_threshold' => 35,
            'frost_sensitive' => false,
            'heat_stress_threshold' => 28
        ],
        'onion' => [
            'name' => 'Onion',
            'optimal_temp_min' => 13,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 70,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'water_requirement' => 'medium',
            'drought_threshold' => 2,
            'flood_threshold' => 30,
            'frost_sensitive' => false,
            'heat_stress_threshold' => 30
        ]
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Analyze crop trends and generate alerts for all users' crops
     */
    public function analyzeCropTrends() {
        try {
            // Get all active crops from all users
            $activeCrops = $this->getActiveCrops();
            
            if (empty($activeCrops)) {
                return [
                    'success' => true,
                    'message' => 'No active crops found',
                    'data' => [],
                    'alerts_created' => 0
                ];
            }
            
            // Get current weather data and trends
            $weatherData = $this->getCurrentWeatherData();
            $weatherTrends = $this->getWeatherTrends();
            
            if (!$weatherData) {
                return [
                    'success' => false,
                    'message' => 'No weather data available',
                    'data' => []
                ];
            }
            
            // Analyze each crop and generate alerts
            $alertsCreated = 0;
            $cropAlerts = [];
            
            foreach ($activeCrops as $crop) {
                $cropAlertsForCrop = $this->analyzeCropWeatherConditions($crop, $weatherData, $weatherTrends);
                
                if (!empty($cropAlertsForCrop)) {
                    $cropAlerts = array_merge($cropAlerts, $cropAlertsForCrop);
                    
                    // Store alerts in database
                    foreach ($cropAlertsForCrop as $alert) {
                        $stored = $this->storeCropAlert($alert, $crop);
                        if ($stored) {
                            $alertsCreated++;
                        }
                    }
                }
            }
            
            return [
                'success' => true,
                'data' => $cropAlerts,
                'alerts_created' => $alertsCreated,
                'crops_analyzed' => count($activeCrops),
                'last_updated' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("Crop trend analysis error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to analyze crop trends: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Get all active crops from all users
     */
    private function getActiveCrops() {
        try {
            $query = "SELECT uc.*, u.id as user_id, u.full_name, u.location, u.latitude, u.longitude
                     FROM user_crops uc
                     INNER JOIN users u ON uc.user_id = u.id
                     WHERE uc.status IN ('planted', 'growing', 'harvesting')
                     ORDER BY uc.planting_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Failed to get active crops: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get current weather data
     */
    private function getCurrentWeatherData() {
        try {
            $query = "SELECT * FROM weather_data 
                     ORDER BY recorded_at DESC 
                     LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Failed to get current weather data: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get weather trends (average over last 7 days)
     */
    private function getWeatherTrends() {
        try {
            $query = "SELECT 
                        AVG(temperature) as avg_temperature,
                        AVG(humidity) as avg_humidity,
                        AVG(rainfall) as avg_rainfall,
                        AVG(wind_speed) as avg_wind_speed,
                        SUM(rainfall) as total_rainfall,
                        MAX(temperature) as max_temperature,
                        MIN(temperature) as min_temperature,
                        MAX(rainfall) as max_rainfall,
                        COUNT(*) as days_count
                     FROM weather_data 
                     WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Failed to get weather trends: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Analyze weather conditions for a specific crop
     */
    private function analyzeCropWeatherConditions($crop, $weatherData, $weatherTrends) {
        $alerts = [];
        $cropName = strtolower($crop['crop_name']);
        
        // Get crop requirements
        $requirements = $this->getCropRequirements($cropName);
        
        if (!$requirements) {
            // Use default requirements if crop not found
            $requirements = $this->getDefaultRequirements();
        }
        
        // Analyze current conditions
        $currentTemp = $weatherData['temperature'] ?? null;
        $currentHumidity = $weatherData['humidity'] ?? null;
        $currentRainfall = $weatherData['rainfall'] ?? null;
        $currentWindSpeed = $weatherData['wind_speed'] ?? null;
        
        // Analyze trends
        $avgRainfall = $weatherTrends['avg_rainfall'] ?? 0;
        $totalRainfall = $weatherTrends['total_rainfall'] ?? 0;
        $avgTemp = $weatherTrends['avg_temperature'] ?? null;
        $maxTemp = $weatherTrends['max_temperature'] ?? null;
        $minTemp = $weatherTrends['min_temperature'] ?? null;
        $daysCount = $weatherTrends['days_count'] ?? 0;
        
        // Check for drought conditions
        if ($avgRainfall < $requirements['drought_threshold'] && $daysCount >= 3) {
            $alerts[] = [
                'type' => 'crop_drought',
                'severity' => $requirements['water_requirement'] === 'high' ? 'high' : 'medium',
                'title' => "Drought Alert - {$requirements['name']}",
                'description' => "Low rainfall detected for your {$requirements['name']} crop. Average rainfall: {$avgRainfall}mm/day (optimal: {$requirements['optimal_rainfall_min']}-{$requirements['optimal_rainfall_max']}mm). Consider irrigation and water conservation measures.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'drought',
                'recommendation' => 'Increase irrigation frequency and monitor soil moisture levels.'
            ];
        }
        
        // Check for flood/heavy rainfall
        if ($currentRainfall > $requirements['flood_threshold']) {
            $alerts[] = [
                'type' => 'crop_flood',
                'severity' => 'high',
                'title' => "Heavy Rainfall Alert - {$requirements['name']}",
                'description' => "Heavy rainfall detected ({$currentRainfall}mm) which may cause flooding for your {$requirements['name']} crop. Ensure proper drainage and protect crops from waterlogging.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'flood',
                'recommendation' => 'Improve drainage, raise beds if possible, and monitor for waterlogging.'
            ];
        }
        
        // Check for heat stress
        if ($currentTemp > $requirements['heat_stress_threshold']) {
            $alerts[] = [
                'type' => 'crop_heat_stress',
                'severity' => 'high',
                'title' => "Heat Stress Alert - {$requirements['name']}",
                'description' => "High temperature detected ({$currentTemp}°C) which exceeds optimal range for {$requirements['name']} ({$requirements['optimal_temp_min']}-{$requirements['optimal_temp_max']}°C). This may cause heat stress and reduce yield.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'heat_stress',
                'recommendation' => 'Provide shade, increase irrigation, and monitor for wilting or leaf burn.'
            ];
        }
        
        // Check for cold stress / frost
        if ($requirements['frost_sensitive'] && $minTemp < 10) {
            $alerts[] = [
                'type' => 'crop_frost',
                'severity' => 'high',
                'title' => "Frost Warning - {$requirements['name']}",
                'description' => "Low temperature detected (minimum: {$minTemp}°C) which may cause frost damage to your {$requirements['name']} crop. {$requirements['name']} is sensitive to frost.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'frost',
                'recommendation' => 'Cover crops with protective material, use frost protection methods, and monitor temperature closely.'
            ];
        }
        
        // Check for low temperature (below optimal)
        if ($currentTemp && $currentTemp < $requirements['optimal_temp_min']) {
            $alerts[] = [
                'type' => 'crop_cold',
                'severity' => 'medium',
                'title' => "Low Temperature Alert - {$requirements['name']}",
                'description' => "Temperature ({$currentTemp}°C) is below optimal range for {$requirements['name']} ({$requirements['optimal_temp_min']}-{$requirements['optimal_temp_max']}°C). Growth may be slowed.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'cold',
                'recommendation' => 'Monitor growth rate and consider protective measures if temperature continues to drop.'
            ];
        }
        
        // Check for high wind damage
        if ($currentWindSpeed > $requirements['optimal_wind_max']) {
            $alerts[] = [
                'type' => 'crop_wind_damage',
                'severity' => 'medium',
                'title' => "High Wind Alert - {$requirements['name']}",
                'description' => "High wind speed detected ({$currentWindSpeed} km/h) which exceeds optimal for {$requirements['name']} (max: {$requirements['optimal_wind_max']} km/h). May cause physical damage to crops.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'wind_damage',
                'recommendation' => 'Secure crops with stakes or windbreaks, and inspect for physical damage after wind subsides.'
            ];
        }
        
        // Check for low humidity
        if ($currentHumidity && $currentHumidity < $requirements['optimal_humidity_min']) {
            $alerts[] = [
                'type' => 'crop_low_humidity',
                'severity' => 'low',
                'title' => "Low Humidity Alert - {$requirements['name']}",
                'description' => "Low humidity detected ({$currentHumidity}%) which is below optimal for {$requirements['name']} ({$requirements['optimal_humidity_min']}-{$requirements['optimal_humidity_max']}%). May increase water stress.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'low_humidity',
                'recommendation' => 'Increase irrigation frequency and monitor for signs of water stress.'
            ];
        }
        
        // Check for high humidity (disease risk)
        if ($currentHumidity && $currentHumidity > $requirements['optimal_humidity_max']) {
            $alerts[] = [
                'type' => 'crop_high_humidity',
                'severity' => 'medium',
                'title' => "High Humidity Alert - {$requirements['name']}",
                'description' => "High humidity detected ({$currentHumidity}%) which exceeds optimal for {$requirements['name']} ({$requirements['optimal_humidity_min']}-{$requirements['optimal_humidity_max']}%). Increased risk of fungal diseases.",
                'crop_id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'user_id' => $crop['user_id'],
                'condition' => 'high_humidity',
                'recommendation' => 'Improve air circulation, apply fungicides preventively, and monitor for disease symptoms.'
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Get crop requirements
     */
    private function getCropRequirements($cropName) {
        // Normalize crop name
        $cropName = strtolower(trim($cropName));
        
        // Try exact match first
        if (isset($this->cropRequirements[$cropName])) {
            return $this->cropRequirements[$cropName];
        }
        
        // Try partial match
        foreach ($this->cropRequirements as $key => $requirements) {
            if (strpos($cropName, $key) !== false || strpos($key, $cropName) !== false) {
                return $requirements;
            }
        }
        
        return null;
    }
    
    /**
     * Get default requirements for unknown crops
     */
    private function getDefaultRequirements() {
        return [
            'name' => 'Crop',
            'optimal_temp_min' => 18,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1500,
            'optimal_wind_max' => 15,
            'water_requirement' => 'medium',
            'drought_threshold' => 2,
            'flood_threshold' => 40,
            'frost_sensitive' => true,
            'heat_stress_threshold' => 35
        ];
    }
    
    /**
     * Store crop alert in database
     */
    private function storeCropAlert($alert, $crop) {
        try {
            // Check if alert already exists (by type, crop_id, and date)
            $checkQuery = "SELECT id FROM alerts 
                          WHERE type = :type 
                          AND description LIKE :description 
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          LIMIT 1";
            
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':type', $alert['type']);
            $checkStmt->bindValue(':description', '%' . substr($alert['description'], 0, 50) . '%');
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                // Alert already exists
                return false;
            }
            
            // Insert alert
            $query = "INSERT INTO alerts 
                     (type, severity, description, status, created_at) 
                     VALUES (:type, :severity, :description, 'active', NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':type', $alert['type']);
            $stmt->bindValue(':severity', $alert['severity']);
            $stmt->bindValue(':description', $alert['description'] . ' Recommendation: ' . ($alert['recommendation'] ?? ''));
            
            if ($stmt->execute()) {
                $alertId = $this->conn->lastInsertId();
                
                // Link alert to user via alert_farmers table (if exists)
                $this->linkAlertToUser($alertId, $alert['user_id']);
                
                // Store crop-specific alert data (if crop_alerts table exists, otherwise use notes)
                $this->storeCropAlertData($alertId, $alert, $crop);
                
                return $alertId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Failed to store crop alert: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Link alert to user
     * Users and farmers are the same - farmers are users with role='farmer'
     * The alert_farmers table uses farmer_id which references user.id
     */
    private function linkAlertToUser($alertId, $userId) {
        try {
            // Link alert to user via alert_farmers table
            // farmer_id in alert_farmers references user.id (users and farmers are the same)
            $query = "INSERT INTO alert_farmers (alert_id, farmer_id, created_at) 
                     VALUES (:alert_id, :user_id, NOW())
                     ON DUPLICATE KEY UPDATE created_at = NOW()";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':alert_id', $alertId);
            $stmt->bindValue(':user_id', $userId);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to link alert to user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Store crop-specific alert data
     */
    private function storeCropAlertData($alertId, $alert, $crop) {
        try {
            // Store weather conditions that triggered the alert
            $weatherData = $this->getCurrentWeatherData();
            
            if ($weatherData) {
                $query = "INSERT INTO weather_conditions 
                         (alert_id, temperature, humidity, rainfall, wind_speed, `condition`, recorded_at) 
                         VALUES (:alert_id, :temperature, :humidity, :rainfall, :wind_speed, :condition, NOW())";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(':alert_id', $alertId);
                $stmt->bindValue(':temperature', $weatherData['temperature'] ?? null);
                $stmt->bindValue(':humidity', $weatherData['humidity'] ?? null);
                $stmt->bindValue(':rainfall', $weatherData['rainfall'] ?? null);
                $stmt->bindValue(':wind_speed', $weatherData['wind_speed'] ?? null);
                $stmt->bindValue(':condition', $weatherData['condition'] ?? null);
                $stmt->execute();
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to store crop alert data: " . $e->getMessage());
            return false;
        }
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? $_POST['action'] ?? 'analyze';
    
    $cropTrendAPI = new CropTrendAlertsAPI();
    
    switch ($action) {
        case 'analyze':
        default:
            $result = $cropTrendAPI->analyzeCropTrends();
            break;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}
?>

