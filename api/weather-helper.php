<?php
/**
 * Weather Helper Utility
 * Provides reusable weather fetching functions for prescriptive analytics
 * Uses Open-Meteo API (free, no API key required)
 */

require_once __DIR__ . '/geocoding.php';
require_once __DIR__ . '/weather-external.php';

class WeatherHelper {
    private $geocoding;
    private $weatherAPI;
    private static $cache = [];
    private static $cacheTimeout = 3600; // 1 hour cache
    
    public function __construct() {
        $this->geocoding = new GeocodingAPI();
        $this->weatherAPI = new ExternalWeatherAPI();
    }
    
    /**
     * Get weather forecast for a location (by name or coordinates)
     * @param string|float $location Location name or latitude
     * @param float|null $longitude Longitude (if first param is latitude)
     * @param int $days Number of days to forecast (default: 14)
     * @return array Forecast data with dates, rainfall, wind, temperature
     */
    public function getWeatherForecast($location, $longitude = null, $days = 14) {
        try {
            // Determine if location is a string (name) or float (latitude)
            $latitude = null;
            if (is_string($location)) {
                // Geocode location name to coordinates
                $geoResult = $this->geocoding->geocode($location);
                if (!$geoResult['success']) {
                    error_log("Geocoding failed for location: $location - " . ($geoResult['message'] ?? 'Unknown error'));
                    // Fallback to Manila coordinates
                    $latitude = 14.5995;
                    $longitude = 120.9842;
                } else {
                    $latitude = $geoResult['data']['latitude'];
                    $longitude = $geoResult['data']['longitude'];
                }
            } else {
                $latitude = (float)$location;
            }
            
            if ($latitude === null || $longitude === null) {
                throw new Exception('Invalid location or coordinates');
            }
            
            // Check cache first
            $cacheKey = "forecast_{$latitude}_{$longitude}_{$days}";
            if (isset(self::$cache[$cacheKey])) {
                $cached = self::$cache[$cacheKey];
                if (time() - $cached['timestamp'] < self::$cacheTimeout) {
                    return $cached['data'];
                }
            }
            
            // Fetch forecast from Open-Meteo API
            $forecastResult = $this->weatherAPI->getForecast($latitude, $longitude, min($days, 16)); // Max 16 days
            
            if (!$forecastResult['success']) {
                error_log("Weather API failed: " . ($forecastResult['message'] ?? 'Unknown error'));
                // Return fallback forecast
                return $this->getFallbackForecast($days);
            }
            
            // Transform forecast data to match expected format
            $forecastData = [];
            foreach ($forecastResult['data'] as $day) {
                $forecastData[] = [
                    'date' => $day['date'],
                    'predicted_temperature' => ($day['temperature_max'] + $day['temperature_min']) / 2,
                    'predicted_rainfall' => $day['precipitation'] ?? $day['rainfall'] ?? 0,
                    'predicted_wind' => $day['wind_speed'] ?? 10,
                    'temperature_max' => $day['temperature_max'] ?? 28,
                    'temperature_min' => $day['temperature_min'] ?? 22,
                    'confidence' => 85 // Open-Meteo is generally reliable
                ];
            }
            
            // Cache the result
            self::$cache[$cacheKey] = [
                'data' => $forecastData,
                'timestamp' => time()
            ];
            
            return $forecastData;
            
        } catch (Exception $e) {
            error_log("WeatherHelper error: " . $e->getMessage());
            return $this->getFallbackForecast($days);
        }
    }
    
    /**
     * Get weather forecast for a date range
     * @param string $startDate Y-m-d format
     * @param string $endDate Y-m-d format
     * @param string|float $location Location name or latitude
     * @param float|null $longitude Longitude (if location is latitude)
     * @return array Forecast data
     */
    public function getWeatherForecastRange($startDate, $endDate, $location, $longitude = null) {
        try {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $days = $start->diff($end)->days + 1;
            
            $forecast = $this->getWeatherForecast($location, $longitude, $days);
            
            // Filter to date range
            $filtered = [];
            foreach ($forecast as $day) {
                $dayDate = new DateTime($day['date']);
                if ($dayDate >= $start && $dayDate <= $end) {
                    $filtered[] = $day;
                }
            }
            
            return $filtered;
            
        } catch (Exception $e) {
            error_log("WeatherHelper range error: " . $e->getMessage());
            return $this->getFallbackForecastRange($startDate, $endDate);
        }
    }
    
    /**
     * Get coordinates from location name
     * @param string $location Location name
     * @return array ['latitude' => float, 'longitude' => float] or null on failure
     */
    public function getCoordinates($location) {
        try {
            $geoResult = $this->geocoding->geocode($location);
            if ($geoResult['success']) {
                return [
                    'latitude' => $geoResult['data']['latitude'],
                    'longitude' => $geoResult['data']['longitude']
                ];
            }
        } catch (Exception $e) {
            error_log("Geocoding error: " . $e->getMessage());
        }
        
        // Fallback to Manila
        return [
            'latitude' => 14.5995,
            'longitude' => 120.9842
        ];
    }
    
    /**
     * Get fallback forecast when API fails
     * @param int $days Number of days
     * @return array Fallback forecast data
     */
    private function getFallbackForecast($days) {
        $forecast = [];
        $baseDate = new DateTime();
        
        for ($i = 0; $i < $days; $i++) {
            $date = clone $baseDate;
            $date->add(new DateInterval('P' . $i . 'D'));
            
            // Use seasonal averages for Philippines
            $month = (int)$date->format('n');
            $isRainySeason = ($month >= 6 && $month <= 10); // June-October
            
            $forecast[] = [
                'date' => $date->format('Y-m-d'),
                'predicted_temperature' => 28,
                'predicted_rainfall' => $isRainySeason ? rand(5, 15) : rand(0, 5),
                'predicted_wind' => 10,
                'temperature_max' => 30,
                'temperature_min' => 25,
                'confidence' => 50 // Low confidence for fallback
            ];
        }
        
        return $forecast;
    }
    
    /**
     * Get fallback forecast for date range
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getFallbackForecastRange($startDate, $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $days = $start->diff($end)->days + 1;
        
        return $this->getFallbackForecast($days);
    }
    
    /**
     * Analyze weather risks from forecast
     * @param array $forecast Forecast data
     * @return array Risk analysis
     */
    public function analyzeWeatherRisks($forecast) {
        $risks = [
            'heavy_rain' => [],
            'wind_damage' => [],
            'flood_risk' => [],
            'lodging_risk' => [],
            'overall_risk_level' => 'low',
            'highest_risk_date' => null,
            'highest_risk_severity' => 'low'
        ];
        
        $maxRiskSeverity = 'low';
        $maxRiskDate = null;
        
        foreach ($forecast as $day) {
            $date = $day['date'];
            $rainfall = $day['predicted_rainfall'] ?? 0;
            $wind = $day['predicted_wind'] ?? 0;
            
            // Heavy rain risk (>30mm)
            if ($rainfall > 30) {
                $severity = $rainfall > 50 ? 'high' : ($rainfall > 40 ? 'medium' : 'low');
                $risks['heavy_rain'][] = [
                    'date' => $date,
                    'rainfall' => $rainfall,
                    'severity' => $severity
                ];
                if ($severity === 'high' || ($severity === 'medium' && $maxRiskSeverity === 'low')) {
                    $maxRiskSeverity = $severity;
                    $maxRiskDate = $date;
                }
            }
            
            // Wind damage risk (>25 km/h)
            if ($wind > 25) {
                $severity = $wind > 35 ? 'high' : 'medium';
                $risks['wind_damage'][] = [
                    'date' => $date,
                    'wind_speed' => $wind,
                    'severity' => $severity
                ];
                if ($severity === 'high' && $maxRiskSeverity !== 'high') {
                    $maxRiskSeverity = 'high';
                    $maxRiskDate = $date;
                }
            }
            
            // Flood risk (>50mm)
            if ($rainfall > 50) {
                $risks['flood_risk'][] = [
                    'date' => $date,
                    'rainfall' => $rainfall,
                    'severity' => 'high'
                ];
                if ($maxRiskSeverity !== 'high') {
                    $maxRiskSeverity = 'high';
                    $maxRiskDate = $date;
                }
            }
            
            // Lodging risk (heavy rain + wind)
            if ($rainfall > 35 && $wind > 20) {
                $risks['lodging_risk'][] = [
                    'date' => $date,
                    'rainfall' => $rainfall,
                    'wind_speed' => $wind,
                    'severity' => 'high'
                ];
                if ($maxRiskSeverity !== 'high') {
                    $maxRiskSeverity = 'high';
                    $maxRiskDate = $date;
                }
            }
        }
        
        $risks['overall_risk_level'] = $maxRiskSeverity;
        $risks['highest_risk_date'] = $maxRiskDate;
        $risks['highest_risk_severity'] = $maxRiskSeverity;
        
        return $risks;
    }
    
    /**
     * Clear cache
     */
    public static function clearCache() {
        self::$cache = [];
    }
}

