<?php
// Start output buffering to catch any unwanted output
if (!ob_get_level()) {
    ob_start();
}

// Disable error display but log errors instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Only send headers if called directly (not when included)
if (basename($_SERVER['PHP_SELF']) === 'weather-external.php') {
    // Clean any output that might have been generated
    if (ob_get_level() > 0) {
        $output = ob_get_contents();
        if (!empty($output) && trim($output) !== '') {
            error_log('Unexpected output before JSON: ' . substr($output, 0, 500));
            ob_clean();
        }
    }
    
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

/**
 * External Weather API Integration
 * Uses Open-Meteo (free, no API key required)
 * Documentation: https://open-meteo.com/en/docs
 */

class ExternalWeatherAPI {
    
    /**
     * Get current weather for a location
     * @param float $latitude
     * @param float $longitude
     * @return array
     */
    public function getCurrentWeather($latitude, $longitude) {
        try {
            // Open-Meteo API - Current weather
            $url = "https://api.open-meteo.com/v1/forecast?" . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'current' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,weather_code',
                'timezone' => 'Asia/Manila',
                'forecast_days' => 1
            ]);
            
            $response = null;
            $httpCode = 0;
            $error = null;
            
            // Try curl first
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'CropAlertSystem/1.0');
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
            } else {
                // Fallback to file_get_contents if curl is not available
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 10,
                        'user_agent' => 'CropAlertSystem/1.0',
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                
                $response = @file_get_contents($url, false, $context);
                if ($response === false) {
                    $error = error_get_last()['message'] ?? 'Failed to fetch data';
                } else {
                    $httpCode = 200;
                }
            }
            
            if ($httpCode !== 200 || !$response) {
                $errorMsg = $error ? $error : "HTTP $httpCode";
                error_log("Open-Meteo API Error: $errorMsg, URL: $url");
                throw new Exception('Failed to fetch weather data: ' . $errorMsg);
            }
            
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['current'])) {
                error_log("Open-Meteo API: Invalid response format. Response: " . substr($response, 0, 200));
                throw new Exception('Invalid weather data format from API');
            }
            
            $current = $data['current'];
            
            // Validate required fields exist
            if (!isset($current['temperature_2m']) || !isset($current['relative_humidity_2m'])) {
                error_log("Open-Meteo API: Missing required fields in response. Available keys: " . implode(', ', array_keys($current)));
                throw new Exception('Invalid weather data format - missing required fields');
            }
            
            return [
                'success' => true,
                'data' => [
                    'temperature' => round($current['temperature_2m'], 1),
                    'humidity' => (int)($current['relative_humidity_2m'] ?? 70),
                    'rainfall' => round($current['precipitation'] ?? 0, 2),
                    'wind_speed' => round($current['wind_speed_10m'] ?? 10, 1),
                    'condition' => $this->getWeatherCondition($current['weather_code'] ?? 0),
                    'weather_code' => $current['weather_code'] ?? 0,
                    'recorded_at' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Weather API Exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get weather forecast for multiple days
     * @param float $latitude
     * @param float $longitude
     * @param int $days (1-16)
     * @return array
     */
    public function getForecast($latitude, $longitude, $days = 7) {
        try {
            $days = min(max(1, $days), 16); // Limit between 1-16 days
            
            $url = "https://api.open-meteo.com/v1/forecast?" . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,weather_code',
                'timezone' => 'Asia/Manila',
                'forecast_days' => $days
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                throw new Exception('Failed to fetch forecast data');
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['daily'])) {
                throw new Exception('Invalid forecast data format');
            }
            
            $daily = $data['daily'];
            $forecast = [];
            
            for ($i = 0; $i < count($daily['time']); $i++) {
                $forecast[] = [
                    'date' => $daily['time'][$i],
                    'temperature' => round($daily['temperature_2m_max'][$i], 1), // For compatibility
                    'temperature_max' => round($daily['temperature_2m_max'][$i], 1),
                    'temperature_min' => round($daily['temperature_2m_min'][$i], 1),
                    'temp' => round($daily['temperature_2m_max'][$i], 1), // Alternative key
                    'precipitation' => round($daily['precipitation_sum'][$i], 2),
                    'rainfall' => round($daily['precipitation_sum'][$i], 2), // Alternative key
                    'wind_speed' => round($daily['wind_speed_10m_max'][$i], 1),
                    'condition' => $this->getWeatherCondition($daily['weather_code'][$i]),
                    'weather_code' => $daily['weather_code'][$i]
                ];
            }
            
            return [
                'success' => true,
                'data' => $forecast
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get historical weather data
     * @param float $latitude
     * @param float $longitude
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getHistoricalWeather($latitude, $longitude, $startDate, $endDate) {
        try {
            $url = "https://api.open-meteo.com/v1/forecast?" . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'timezone' => 'Asia/Manila'
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                throw new Exception('Failed to fetch historical weather data');
            }
            
            $data = json_decode($response, true);
            
            return [
                'success' => true,
                'data' => $data
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Convert weather code to condition string
     * @param int $code
     * @return string
     */
    private function getWeatherCondition($code) {
        $conditions = [
            0 => 'Clear Sky',
            1 => 'Mainly Clear',
            2 => 'Partly Cloudy',
            3 => 'Overcast',
            45 => 'Foggy',
            48 => 'Depositing Rime Fog',
            51 => 'Light Drizzle',
            53 => 'Moderate Drizzle',
            55 => 'Dense Drizzle',
            56 => 'Light Freezing Drizzle',
            57 => 'Dense Freezing Drizzle',
            61 => 'Slight Rain',
            63 => 'Moderate Rain',
            65 => 'Heavy Rain',
            66 => 'Light Freezing Rain',
            67 => 'Heavy Freezing Rain',
            71 => 'Slight Snow',
            73 => 'Moderate Snow',
            75 => 'Heavy Snow',
            77 => 'Snow Grains',
            80 => 'Slight Rain Showers',
            81 => 'Moderate Rain Showers',
            82 => 'Violent Rain Showers',
            85 => 'Slight Snow Showers',
            86 => 'Heavy Snow Showers',
            95 => 'Thunderstorm',
            96 => 'Thunderstorm with Hail',
            99 => 'Thunderstorm with Heavy Hail'
        ];
        
        return $conditions[$code] ?? 'Unknown';
    }
}

// Handle the request (only if called directly, not when included)
if (basename($_SERVER['PHP_SELF']) === 'weather-external.php') {
    // Clean any buffered output before starting
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'current';
        $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
        $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;
        
        $weatherAPI = new ExternalWeatherAPI();
        
        switch ($action) {
            case 'current':
                if ($latitude === null || $longitude === null) {
                    if (ob_get_level() > 0) {
                        ob_clean();
                    }
                    echo json_encode([
                        'success' => false,
                        'message' => 'Latitude and longitude are required'
                    ]);
                    exit;
                }
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                echo json_encode($weatherAPI->getCurrentWeather($latitude, $longitude));
                break;
                
            case 'forecast':
                if ($latitude === null || $longitude === null) {
                    if (ob_get_level() > 0) {
                        ob_clean();
                    }
                    echo json_encode([
                        'success' => false,
                        'message' => 'Latitude and longitude are required'
                    ]);
                    exit;
                }
                $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                echo json_encode($weatherAPI->getForecast($latitude, $longitude, $days));
                break;
                
            case 'historical':
                if ($latitude === null || $longitude === null) {
                    if (ob_get_level() > 0) {
                        ob_clean();
                    }
                    echo json_encode([
                        'success' => false,
                        'message' => 'Latitude and longitude are required'
                    ]);
                    exit;
                }
                $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
                $endDate = $_GET['end_date'] ?? date('Y-m-d');
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                echo json_encode($weatherAPI->getHistoricalWeather($latitude, $longitude, $startDate, $endDate));
                break;
                
            default:
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid action'
                ]);
        }
    } else {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
    }
    
    // End output buffering if it was started
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
// If included, do nothing - just provide the class

