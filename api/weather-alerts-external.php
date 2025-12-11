<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/**
 * External Weather Alerts API Integration
 * Fetches severe weather alerts from free APIs
 * Uses Open-Meteo and weather condition analysis
 */

class WeatherAlertsExternal {
    
    /**
     * Get severe weather alerts for a location
     * @param float $latitude
     * @param float $longitude
     * @return array
     */
    public function getSevereWeatherAlerts($latitude, $longitude) {
        try {
            $alerts = [];
            
            // Get current weather and forecast to analyze for severe conditions
            $currentWeather = $this->getCurrentWeatherData($latitude, $longitude);
            $forecast = $this->getForecastData($latitude, $longitude, 7);
            
            if ($currentWeather['success'] && $forecast['success']) {
                // Analyze current weather for severe conditions
                $currentAlerts = $this->analyzeWeatherConditions(
                    $currentWeather['data'],
                    $forecast['data'],
                    $latitude,
                    $longitude
                );
                
                $alerts = array_merge($alerts, $currentAlerts);
            }
            
            // Check for typhoon/storm indicators
            $typhoonAlerts = $this->checkTyphoonIndicators($forecast['data'] ?? []);
            $alerts = array_merge($alerts, $typhoonAlerts);
            
            return [
                'success' => true,
                'data' => $alerts,
                'count' => count($alerts),
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ],
                'last_updated' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch weather alerts: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Get current weather data from Open-Meteo
     */
    private function getCurrentWeatherData($latitude, $longitude) {
        try {
            $url = "https://api.open-meteo.com/v1/forecast?" . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'current' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,wind_gusts_10m,weather_code',
                'timezone' => 'Asia/Manila',
                'forecast_days' => 1
            ]);
            
            $response = $this->fetchUrl($url);
            if (!$response) {
                throw new Exception('Failed to fetch current weather');
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['current'])) {
                return [
                    'success' => true,
                    'data' => $data['current']
                ];
            }
            
            throw new Exception('Invalid weather data format');
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get forecast data from Open-Meteo
     */
    private function getForecastData($latitude, $longitude, $days = 7) {
        try {
            $url = "https://api.open-meteo.com/v1/forecast?" . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_gusts_10m_max,weather_code',
                'timezone' => 'Asia/Manila',
                'forecast_days' => $days
            ]);
            
            $response = $this->fetchUrl($url);
            if (!$response) {
                throw new Exception('Failed to fetch forecast');
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['daily'])) {
                return [
                    'success' => true,
                    'data' => $data['daily']
                ];
            }
            
            throw new Exception('Invalid forecast data format');
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Analyze weather conditions for severe weather alerts
     * Enhanced to avoid false positives in high-altitude areas like Baguio
     */
    private function analyzeWeatherConditions($current, $forecast, $latitude, $longitude) {
        $alerts = [];
        
        // Check if this is a high-altitude area (Baguio area: ~16.4N, 120.6E, elevation ~1500m)
        // High-altitude areas have different weather patterns and shouldn't trigger false alerts
        $isHighAltitude = false;
        if (($latitude >= 16.0 && $latitude <= 17.0 && $longitude >= 120.0 && $longitude <= 121.0) ||
            ($latitude >= 15.0 && $latitude <= 15.5 && $longitude >= 120.3 && $longitude <= 120.8)) {
            $isHighAltitude = true;
        }
        
        // Check current conditions
        $windSpeed = $current['wind_speed_10m'] ?? 0;
        $windGusts = $current['wind_gusts_10m'] ?? 0;
        $precipitation = $current['precipitation'] ?? 0;
        $weatherCode = $current['weather_code'] ?? 0;
        
        // Typhoon/Storm Warning - Stricter criteria to reduce false positives
        // For high-altitude areas (Baguio/Angeles), use even stricter criteria to avoid false positives
        $windThreshold = $isHighAltitude ? 85 : 75; // Higher threshold for mountain areas
        $gustThreshold = $isHighAltitude ? 100 : 90;
        $precipThreshold = $isHighAltitude ? 30 : 20; // More precipitation required for mountain areas
        
        if (($windSpeed >= $windThreshold || $windGusts >= $gustThreshold) && $precipitation >= $precipThreshold) {
            // Additional validation: gusts should be at least 1.2x base wind (indicates storm system)
            // For high-altitude, require even higher ratio (1.3x) to avoid false positives
            $gustRatio = $isHighAltitude ? 1.3 : 1.2;
            if ($windGusts >= ($windSpeed * $gustRatio) || $windSpeed >= $windThreshold) {
                $alerts[] = [
                    'type' => 'typhoon',
                    'severity' => 'high',
                    'title' => 'Typhoon Warning',
                    'description' => "Typhoon conditions detected. Wind speed: {$windSpeed} km/h with gusts up to {$windGusts} km/h. Heavy precipitation: {$precipitation}mm. This indicates an active typhoon. Take immediate precautions and follow evacuation orders if issued.",
                    'category' => 'severe_weather',
                    'urgency' => 'immediate',
                    'effective' => date('Y-m-d H:i:s'),
                    'expires' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                    'area' => "Lat: {$latitude}, Lng: {$longitude}"
                ];
            }
        } elseif (($windSpeed >= 50 || $windGusts >= 65) && $precipitation >= 10) {
            // Tropical storm conditions - skip for high-altitude areas to reduce false positives
            if (!$isHighAltitude) {
                if ($windGusts >= ($windSpeed * 1.1) || $windSpeed >= 50) {
                    $alerts[] = [
                        'type' => 'storm',
                        'severity' => 'medium',
                        'title' => 'Tropical Storm Warning',
                        'description' => "Tropical storm conditions detected. Wind speed: {$windSpeed} km/h with gusts up to {$windGusts} km/h. Precipitation: {$precipitation}mm. Secure loose objects and prepare for storm conditions.",
                        'category' => 'severe_weather',
                        'urgency' => 'expected',
                        'effective' => date('Y-m-d H:i:s'),
                        'expires' => date('Y-m-d H:i:s', strtotime('+12 hours')),
                        'area' => "Lat: {$latitude}, Lng: {$longitude}"
                    ];
                }
            }
        }
        
        // Heavy Rainfall Warning
        if ($precipitation >= 50) {
            $alerts[] = [
                'type' => 'flood',
                'severity' => 'high',
                'title' => 'Heavy Rainfall Warning',
                'description' => "Heavy rainfall detected ({$precipitation}mm). Risk of flooding. Avoid low-lying areas and prepare for potential flooding.",
                'category' => 'severe_weather',
                'urgency' => 'immediate',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => date('Y-m-d H:i:s', strtotime('+6 hours')),
                'area' => "Lat: {$latitude}, Lng: {$longitude}"
            ];
        } elseif ($precipitation >= 25) {
            $alerts[] = [
                'type' => 'rain',
                'severity' => 'medium',
                'title' => 'Moderate Rainfall Warning',
                'description' => "Moderate to heavy rainfall expected ({$precipitation}mm). Monitor conditions and prepare for potential flooding in low-lying areas.",
                'category' => 'severe_weather',
                'urgency' => 'expected',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => date('Y-m-d H:i:s', strtotime('+6 hours')),
                'area' => "Lat: {$latitude}, Lng: {$longitude}"
            ];
        }
        
        // Check forecast for upcoming severe weather with stricter criteria
        if (isset($forecast['wind_speed_10m_max']) && is_array($forecast['wind_speed_10m_max'])) {
            foreach ($forecast['wind_speed_10m_max'] as $index => $maxWind) {
                $maxGusts = $forecast['wind_gusts_10m_max'][$index] ?? $maxWind;
                $forecastRain = $forecast['precipitation_sum'][$index] ?? 0;
                $date = $forecast['time'][$index] ?? date('Y-m-d', strtotime("+{$index} days"));
                
                // Stricter criteria: High winds AND significant precipitation
                if (($maxWind >= 75 || $maxGusts >= 90) && $forecastRain >= 20) {
                    if ($maxGusts >= ($maxWind * 1.2) || $maxWind >= 75) {
                        $alerts[] = [
                            'type' => 'typhoon',
                            'severity' => 'high',
                            'title' => 'Upcoming Typhoon Warning',
                            'description' => "Typhoon conditions expected on {$date}. Wind speed: {$maxWind} km/h, gusts up to {$maxGusts} km/h. Expected precipitation: {$forecastRain}mm. Prepare in advance and secure your property.",
                            'category' => 'severe_weather',
                            'urgency' => 'future',
                            'effective' => date('Y-m-d H:i:s'),
                            'expires' => $date . ' 23:59:59',
                            'area' => "Lat: {$latitude}, Lng: {$longitude}",
                            'forecast_date' => $date
                        ];
                        break; // Only alert for the first severe day
                    }
                }
            }
        }
        
        // Check for heavy rainfall in forecast
        if (isset($forecast['precipitation_sum']) && is_array($forecast['precipitation_sum'])) {
            foreach ($forecast['precipitation_sum'] as $index => $rainfall) {
                if ($rainfall >= 50) {
                    $date = $forecast['time'][$index] ?? date('Y-m-d', strtotime("+{$index} days"));
                    $alerts[] = [
                        'type' => 'flood',
                        'severity' => 'medium',
                        'title' => 'Upcoming Heavy Rainfall',
                        'description' => "Heavy rainfall expected on {$date} ({$rainfall}mm). Prepare for potential flooding.",
                        'category' => 'severe_weather',
                        'urgency' => 'future',
                        'effective' => date('Y-m-d H:i:s'),
                        'expires' => $date . ' 23:59:59',
                        'area' => "Lat: {$latitude}, Lng: {$longitude}",
                        'forecast_date' => $date
                    ];
                    break;
                }
            }
        }
        
        // Generate tomorrow's weather forecast alerts
        $tomorrowAlerts = $this->generateTomorrowForecastAlerts($forecast, $latitude, $longitude);
        $alerts = array_merge($alerts, $tomorrowAlerts);
        
        return $alerts;
    }
    
    /**
     * Generate weather forecast alerts for tomorrow
     * Creates alerts for normal weather conditions like slight rain, strong rain, sunny
     */
    private function generateTomorrowForecastAlerts($forecast, $latitude, $longitude) {
        $alerts = [];
        
        // Get tomorrow's forecast (index 0 is today, index 1 is tomorrow)
        if (!isset($forecast['precipitation_sum']) || !is_array($forecast['precipitation_sum']) || count($forecast['precipitation_sum']) < 2) {
            return $alerts;
        }
        
        // Ensure we have time array to verify dates
        if (!isset($forecast['time']) || !is_array($forecast['time']) || count($forecast['time']) < 2) {
            return $alerts;
        }
        
        $tomorrowIndex = 1;
        $todayDate = date('Y-m-d');
        $expectedTomorrowDate = date('Y-m-d', strtotime('+1 day'));
        
        // Verify we're using the correct index by checking the date
        $tomorrowDate = $forecast['time'][$tomorrowIndex] ?? $expectedTomorrowDate;
        
        // Safety check: ensure the date is actually tomorrow, not today
        // If the date matches today, skip generating the alert to avoid confusion
        if ($tomorrowDate === $todayDate) {
            error_log("Warning: Forecast date matches today ({$todayDate}), skipping tomorrow forecast alert to avoid confusion");
            return $alerts;
        }
        
        $tomorrowRainfall = $forecast['precipitation_sum'][$tomorrowIndex] ?? 0;
        $tomorrowWeatherCode = $forecast['weather_code'][$tomorrowIndex] ?? null;
        $tomorrowTempMax = $forecast['temperature_2m_max'][$tomorrowIndex] ?? null;
        
        // Determine weather condition based on precipitation and weather code
        // Weather codes: 0=Clear, 1-3=Mainly clear/partly cloudy, 45-48=Fog, 51-55=Drizzle, 56-57=Freezing drizzle, 61-65=Rain, 66-67=Freezing rain, 71-77=Snow, 80-82=Rain showers, 85-86=Snow showers, 95-99=Thunderstorm
        $isSunny = false;
        $isRainy = false;
        $rainIntensity = 'none';
        
        // Check weather code first (more reliable)
        // Weather codes: 0=Clear, 1-3=Mainly clear/partly cloudy, 45-48=Fog, 51-55=Drizzle, 56-57=Freezing drizzle, 61-65=Rain, 66-67=Freezing rain, 71-77=Snow, 80-82=Rain showers, 85-86=Snow showers, 95-99=Thunderstorm
        if ($tomorrowWeatherCode !== null) {
            if ($tomorrowWeatherCode == 0) {
                // Clear sky - truly sunny
                if ($tomorrowRainfall < 0.5) {
                    $isSunny = true;
                }
            } elseif ($tomorrowWeatherCode >= 1 && $tomorrowWeatherCode <= 3) {
                // Mainly clear/partly cloudy - NOT sunny, but not rainy either
                // Don't mark as sunny, but also don't mark as rainy unless there's precipitation
                if ($tomorrowRainfall >= 0.5) {
                    $isRainy = true;
                }
            } elseif ($tomorrowWeatherCode >= 45 && $tomorrowWeatherCode <= 48) {
                // Fog/mist - not sunny, not rainy
                if ($tomorrowRainfall >= 0.5) {
                    $isRainy = true;
                }
            } elseif ($tomorrowWeatherCode >= 51 && $tomorrowWeatherCode <= 67) {
                // Drizzle or rain
                $isRainy = true;
            } elseif ($tomorrowWeatherCode >= 61 && $tomorrowWeatherCode <= 65) {
                // Rain
                $isRainy = true;
            } elseif ($tomorrowWeatherCode >= 80 && $tomorrowWeatherCode <= 82) {
                // Rain showers
                $isRainy = true;
            } elseif ($tomorrowWeatherCode >= 95 && $tomorrowWeatherCode <= 99) {
                // Thunderstorm
                $isRainy = true;
            }
        }
        
        // Determine rain intensity based on precipitation amount
        if ($tomorrowRainfall > 0) {
            $isRainy = true;
            if ($tomorrowRainfall >= 25) {
                $rainIntensity = 'strong';
            } elseif ($tomorrowRainfall >= 10) {
                $rainIntensity = 'moderate';
            } elseif ($tomorrowRainfall >= 1) {
                $rainIntensity = 'slight';
            }
        }
        
        // Generate appropriate alert based on tomorrow's weather
        // Only generate "sunny" alert if weather code is 0 (clear) AND no significant precipitation
        if ($isSunny && $tomorrowWeatherCode == 0 && $tomorrowRainfall < 0.5) {
            // Truly sunny tomorrow (clear sky)
            $alerts[] = [
                'type' => 'forecast',
                'severity' => 'low',
                'title' => 'Sunny Tomorrow',
                'description' => "Clear and sunny weather expected tomorrow ({$tomorrowDate})." . ($tomorrowTempMax ? " High temperature: {$tomorrowTempMax}°C. " : " ") . "Perfect weather for outdoor farming activities.",
                'category' => 'weather_forecast',
                'urgency' => 'expected',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => $tomorrowDate . ' 23:59:59',
                'area' => "Lat: {$latitude}, Lng: {$longitude}",
                'forecast_date' => $tomorrowDate,
                'precipitation' => $tomorrowRainfall,
                'weather_condition' => 'sunny'
            ];
        } elseif ($isRainy && $rainIntensity === 'strong') {
            // Strong rain tomorrow
            $alerts[] = [
                'type' => 'forecast',
                'severity' => 'medium',
                'title' => 'Strong Rain Tomorrow',
                'description' => "Strong to heavy rainfall expected tomorrow ({$tomorrowDate}). Expected precipitation: {$tomorrowRainfall}mm. Prepare for wet conditions and ensure proper drainage.",
                'category' => 'weather_forecast',
                'urgency' => 'expected',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => $tomorrowDate . ' 23:59:59',
                'area' => "Lat: {$latitude}, Lng: {$longitude}",
                'forecast_date' => $tomorrowDate,
                'precipitation' => $tomorrowRainfall,
                'weather_condition' => 'strong_rain'
            ];
        } elseif ($isRainy && $rainIntensity === 'moderate') {
            // Moderate rain tomorrow
            $alerts[] = [
                'type' => 'forecast',
                'severity' => 'low',
                'title' => 'Moderate Rain Tomorrow',
                'description' => "Moderate rainfall expected tomorrow ({$tomorrowDate}). Expected precipitation: {$tomorrowRainfall}mm. Plan outdoor activities accordingly.",
                'category' => 'weather_forecast',
                'urgency' => 'expected',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => $tomorrowDate . ' 23:59:59',
                'area' => "Lat: {$latitude}, Lng: {$longitude}",
                'forecast_date' => $tomorrowDate,
                'precipitation' => $tomorrowRainfall,
                'weather_condition' => 'moderate_rain'
            ];
        } elseif ($isRainy && $rainIntensity === 'slight') {
            // Slight rain tomorrow
            $alerts[] = [
                'type' => 'forecast',
                'severity' => 'low',
                'title' => 'Slight Rain Tomorrow',
                'description' => "Slight rain or drizzle expected tomorrow ({$tomorrowDate}). Expected precipitation: {$tomorrowRainfall}mm. Light showers may occur throughout the day.",
                'category' => 'weather_forecast',
                'urgency' => 'expected',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => $tomorrowDate . ' 23:59:59',
                'area' => "Lat: {$latitude}, Lng: {$longitude}",
                'forecast_date' => $tomorrowDate,
                'precipitation' => $tomorrowRainfall,
                'weather_condition' => 'slight_rain'
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Check for typhoon indicators in weather data
     */
    private function checkTyphoonIndicators($forecast) {
        $alerts = [];
        
        if (!isset($forecast['wind_speed_10m_max']) || !is_array($forecast['wind_speed_10m_max'])) {
            return $alerts;
        }
        
        // Check for sustained high winds (typhoon indicator) with stricter criteria
        $highWindDays = 0;
        $maxWindSpeed = 0;
        $maxPrecipitation = 0;
        $typhoonDate = null;
        
        foreach ($forecast['wind_speed_10m_max'] as $index => $windSpeed) {
            $windGusts = $forecast['wind_gusts_10m_max'][$index] ?? $windSpeed;
            $precipitation = $forecast['precipitation_sum'][$index] ?? 0;
            
            // Stricter criteria: Higher wind threshold AND precipitation
            if (($windSpeed >= 75 || $windGusts >= 90) && $precipitation >= 20) {
                if ($windGusts >= ($windSpeed * 1.2) || $windSpeed >= 75) {
                    $highWindDays++;
                    if ($windSpeed > $maxWindSpeed) {
                        $maxWindSpeed = $windSpeed;
                        $maxPrecipitation = $precipitation;
                        $typhoonDate = $forecast['time'][$index] ?? date('Y-m-d', strtotime("+{$index} days"));
                    }
                }
            }
        }
        
        // If multiple days of high winds with precipitation, likely a typhoon
        if ($highWindDays >= 2 && $maxWindSpeed >= 75 && $maxPrecipitation >= 20) {
            $alerts[] = [
                'type' => 'typhoon',
                'severity' => 'high',
                'title' => 'Typhoon Alert',
                'description' => "Typhoon conditions expected. Sustained high winds ({$maxWindSpeed} km/h) with heavy precipitation ({$maxPrecipitation}mm) forecasted for multiple days starting {$typhoonDate}. Take immediate precautions: secure property, prepare emergency supplies, and follow evacuation orders if issued.",
                'category' => 'severe_weather',
                'urgency' => 'immediate',
                'effective' => date('Y-m-d H:i:s'),
                'expires' => $typhoonDate . ' 23:59:59',
                'forecast_date' => $typhoonDate,
                'wind_speed' => $maxWindSpeed,
                'duration_days' => $highWindDays
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Fetch URL with fallback methods
     */
    private function fetchUrl($url) {
        $response = null;
        
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
            
            if ($httpCode === 200 && $response) {
                return $response;
            }
            
            if ($error) {
                error_log("cURL error fetching weather alerts: " . $error);
            }
        }
        
        // Fallback to file_get_contents
        if (ini_get('allow_url_fopen')) {
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
            if ($response !== false) {
                return $response;
            }
        }
        
        return null;
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'alerts';
    $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
    $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;
    
    $alertsAPI = new WeatherAlertsExternal();
    
    switch ($action) {
        case 'alerts':
        default:
            if ($latitude === null || $longitude === null) {
                // Default to Manila, Philippines
                $latitude = 14.5995;
                $longitude = 120.9842;
            }
            $result = $alertsAPI->getSevereWeatherAlerts($latitude, $longitude);
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

