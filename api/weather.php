<?php
// Start output buffering to prevent any output before headers
ob_start();

// Set headers first - must be before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
header('Access-Control-Max-Age: 3600');

// Handle OPTIONS request for CORS preflight
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit(0);
}

// Check request method early - allow GET and POST
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($requestMethod, ['GET', 'POST', 'OPTIONS'])) {
    ob_end_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only GET and POST are supported.',
        'method' => $requestMethod
    ]);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

class WeatherAPI {
    private $conn;
    private $apiKey = '3G6B8WCJSMLKY53J4HUY6DNSN'; // Visual Crossing API key
    private $apiUrl = 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/';
    private $location;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->location = defined('DEFAULT_LOCATION') ? DEFAULT_LOCATION : 'Manila, Philippines';
    }
    
    public function getWeatherData() {
        try {
            // Try to get coordinates from location string first
            $latitude = null;
            $longitude = null;
            
            // Try to geocode location if we have a location string
            if ($this->location) {
                // Include and use GeocodingAPI class directly
                require_once __DIR__ . '/geocoding.php';
                $geocodingAPI = new GeocodingAPI();
                $geocodeResult = $geocodingAPI->geocode($this->location);
                
                if ($geocodeResult['success'] && isset($geocodeResult['data'])) {
                    $latitude = $geocodeResult['data']['latitude'];
                    $longitude = $geocodeResult['data']['longitude'];
                }
            }
            
            // Try to get user location from session or default
            if (!$latitude || !$longitude) {
                // Try to get from user session if available
                session_start();
                if (isset($_SESSION['user_id'])) {
                    $userQuery = "SELECT location, latitude, longitude FROM users WHERE id = :user_id";
                    $userStmt = $this->conn->prepare($userQuery);
                    $userStmt->bindParam(':user_id', $_SESSION['user_id']);
                    $userStmt->execute();
                    $user = $userStmt->fetch();
                    
                    if ($user && $user['latitude'] && $user['longitude']) {
                        $latitude = $user['latitude'];
                        $longitude = $user['longitude'];
                    } else if ($user && $user['location']) {
                        $geocodeResult = $geocodingAPI->geocode($user['location']);
                        if ($geocodeResult['success'] && isset($geocodeResult['data'])) {
                            $latitude = $geocodeResult['data']['latitude'];
                            $longitude = $geocodeResult['data']['longitude'];
                        }
                    }
                }
                
                // Default to Manila, Philippines if still no coordinates
                if (!$latitude || !$longitude) {
                    $latitude = 14.5995;
                    $longitude = 120.9842;
                }
            }
            
            // Use free Open-Meteo API (no API key required)
            require_once __DIR__ . '/weather-external.php';
            $externalWeatherAPI = new ExternalWeatherAPI();
            $weatherResult = $externalWeatherAPI->getCurrentWeather($latitude, $longitude);
            
            if ($weatherResult['success'] && isset($weatherResult['data'])) {
                $currentWeather = $weatherResult['data'];
                
                // Ensure we have valid temperature data
                if (!isset($currentWeather['temperature']) || !is_numeric($currentWeather['temperature'])) {
                    error_log("Invalid temperature data from Open-Meteo API. Data: " . json_encode($currentWeather));
                    throw new Exception('Invalid weather data received from API');
                }
                
                // Get forecast
                $forecastResult = $externalWeatherAPI->getForecast($latitude, $longitude, 5);
                $forecast = $forecastResult['success'] && isset($forecastResult['data']) 
                    ? $forecastResult['data'] 
                    : [];
                
                $weatherData = [
                    'current' => $currentWeather,
                    'forecast' => $forecast,
                    'source' => 'open-meteo',
                    'location' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude
                    ]
                ];
                
                // Store weather data in database
                try {
                    $this->storeWeatherData($currentWeather);
                } catch (Exception $e) {
                    // Log but don't fail if database storage fails
                    error_log("Failed to store weather data: " . $e->getMessage());
                }
                
                return [
                    'success' => true,
                    'data' => $weatherData
                ];
            } else {
                $errorMsg = $weatherResult['message'] ?? 'Unknown error';
                error_log("Open-Meteo API failed: $errorMsg");
                // Don't throw exception here, let it fall through to Visual Crossing or fallback
            }
            
            // Fallback to Visual Crossing API if Open-Meteo fails
            $apiData = $this->fetchFromVisualCrossing();
            
            if (!$apiData) {
                // Return error instead of mock data - let client handle it
                return [
                    'success' => false,
                    'message' => 'Unable to fetch weather data from external APIs. Please check your internet connection.',
                    'data' => null
                ];
            } else {
                $weatherData = $this->processVisualCrossingData($apiData);
                
                // Store weather data in database
                $this->storeWeatherData($weatherData['current']);
                
                return [
                    'success' => true,
                    'data' => $weatherData
                ];
            }
            
        } catch (Exception $e) {
            error_log("Weather API Exception: " . $e->getMessage());
            // Return a basic fallback with Manila default weather
            // This ensures the dashboard always shows something
            return [
                'success' => true,
                'data' => [
                    'current' => [
                        'temperature' => 28,
                        'humidity' => 75,
                        'rainfall' => 0,
                        'wind_speed' => 12,
                        'condition' => 'Partly Cloudy',
                        'recorded_at' => date('Y-m-d H:i:s'),
                        'source' => 'fallback'
                    ],
                    'forecast' => [],
                    'source' => 'fallback',
                    'location' => [
                        'latitude' => 14.5995,
                        'longitude' => 120.9842,
                        'address' => 'Manila, Philippines'
                    ],
                    'note' => 'Using fallback data - API unavailable'
                ],
                'message' => 'Using fallback weather data'
            ];
        }
    }
    
    private function generateForecast() {
        $forecast = [];
        $conditions = ['Sunny', 'Partly Cloudy', 'Cloudy', 'Rainy', 'Stormy'];
        
        for ($i = 1; $i <= 5; $i++) {
            $date = date('Y-m-d', strtotime("+$i days"));
            $forecast[] = [
                'date' => $date,
                'temperature' => rand(18, 32),
                'condition' => $conditions[array_rand($conditions)],
                'rainfall' => rand(0, 30)
            ];
        }
        
        return $forecast;
    }
    
    private function getRandomCondition() {
        $conditions = ['Sunny', 'Partly Cloudy', 'Cloudy', 'Rainy', 'Stormy'];
        return $conditions[array_rand($conditions)];
    }
    
    private function storeWeatherData($weatherData) {
        try {
            $query = "INSERT INTO weather_data (temperature, humidity, rainfall, wind_speed, `condition`, recorded_at) 
                     VALUES (:temperature, :humidity, :rainfall, :wind_speed, :condition, :recorded_at)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':temperature', $weatherData['temperature']);
            $stmt->bindParam(':humidity', $weatherData['humidity']);
            $stmt->bindParam(':rainfall', $weatherData['rainfall']);
            $stmt->bindParam(':wind_speed', $weatherData['wind_speed']);
            $stmt->bindParam(':condition', $weatherData['condition']);
            $stmt->bindParam(':recorded_at', $weatherData['timestamp']);
            
            $stmt->execute();
        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Failed to store weather data: " . $e->getMessage());
        }
    }
    
    private function fetchFromVisualCrossing() {
        $url = $this->apiUrl . urlencode($this->location) . 
               '?unitGroup=metric&elements=datetime%2Caddress%2Clatitude%2Clongitude%2Ctemp%2Chumidity%2Cwindspeed%2Ccloudcover%2Cconditions%2Cdescription%2Cicon&key=' . 
               $this->apiKey . '&contentType=json';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CropAlertSystem/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && !$error) {
            return json_decode($response, true);
        }
        
        error_log("Visual Crossing API Error: HTTP $httpCode, Error: $error");
        return null;
    }
    
    private function processVisualCrossingData($apiData) {
        $current = $apiData['currentConditions'];
        $days = $apiData['days'];
        
        // Process current weather
        $currentWeather = [
            'temperature' => round($current['temp']),
            'humidity' => round($current['humidity']),
            'wind_speed' => round($current['windspeed']),
            'condition' => $this->cleanCondition($current['conditions']),
            'cloud_cover' => round($current['cloudcover']),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Estimate rainfall from conditions (Visual Crossing doesn't provide rainfall directly)
        $currentWeather['rainfall'] = $this->estimateRainfall($current['conditions'], $current['humidity']);
        
        // Process 5-day forecast
        $forecast = [];
        for ($i = 0; $i < min(5, count($days)); $i++) {
            $day = $days[$i];
            $forecast[] = [
                'date' => $day['datetime'],
                'temperature' => round($day['temp']),
                'condition' => $this->cleanCondition($day['conditions']),
                'humidity' => round($day['humidity']),
                'wind_speed' => round($day['windspeed']),
                'rainfall' => $this->estimateRainfall($day['conditions'], $day['humidity'])
            ];
        }
        
        // Process location information
        $location = $this->processLocation($apiData);
        
        return [
            'current' => $currentWeather,
            'forecast' => $forecast,
            'location' => $location
        ];
    }
    
    private function processLocation($apiData) {
        $resolvedAddress = $apiData['resolvedAddress'] ?? $this->location;
        
        // Clean up the address to make it more readable
        $address = $this->cleanLocationName($resolvedAddress);
        
        return [
            'address' => $address,
            'latitude' => $apiData['latitude'] ?? null,
            'longitude' => $apiData['longitude'] ?? null,
            'timezone' => $apiData['timezone'] ?? 'Asia/Manila'
        ];
    }
    
    private function cleanLocationName($address) {
        // If it's coordinates, return a default location
        if (preg_match('/^-?\d+\.?\d*,-?\d+\.?\d*$/', $address)) {
            return 'Manila, Philippines';
        }
        
        // Clean up common location formats
        $address = str_replace(', Philippines', '', $address);
        $address = trim($address);
        
        // If it's just coordinates or unclear, use Manila
        if (empty($address) || strlen($address) < 3) {
            return 'Manila, Philippines';
        }
        
        // Add Philippines if not present
        if (!strpos($address, 'Philippines') && !strpos($address, 'PH')) {
            $address .= ', Philippines';
        }
        
        return $address;
    }
    
    private function cleanCondition($condition) {
        // Clean up condition text for better display
        $condition = str_replace(',', '', $condition);
        $condition = str_replace('Overcast', 'Cloudy', $condition);
        $condition = str_replace('Partially cloudy', 'Partly Cloudy', $condition);
        return trim($condition);
    }
    
    private function estimateRainfall($condition, $humidity) {
        // Estimate rainfall based on conditions and humidity
        $condition = strtolower($condition);
        
        if (strpos($condition, 'rain') !== false) {
            if (strpos($condition, 'storm') !== false) {
                return rand(20, 50); // Heavy rain/storms
            }
            return rand(5, 25); // Light to moderate rain
        }
        
        if ($humidity > 90) {
            return rand(1, 5); // Light drizzle
        }
        
        return 0; // No rain
    }
    
    private function getMockWeatherData() {
        // Fallback mock data
        return [
            'current' => [
                'temperature' => rand(20, 35),
                'humidity' => rand(40, 80),
                'rainfall' => rand(0, 50),
                'wind_speed' => rand(5, 25),
                'condition' => $this->getRandomCondition(),
                'timestamp' => date('Y-m-d H:i:s')
            ],
            'forecast' => $this->generateForecast(),
            'location' => [
                'address' => $this->location,
                'latitude' => null,
                'longitude' => null,
                'timezone' => 'Asia/Manila'
            ]
        ];
    }
}

// Handle the request - GET and POST are already validated above
try {
    $weatherAPI = new WeatherAPI();
    $result = $weatherAPI->getWeatherData();
    
    // Clean output buffer and return JSON response
    ob_end_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch weather data: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}
?>
