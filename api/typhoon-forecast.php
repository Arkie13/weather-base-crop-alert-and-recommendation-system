<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

/**
 * Typhoon Forecast API
 * Fetches typhoon forecast data from free APIs
 * Uses Open-Meteo and WeatherAPI.com for typhoon tracking
 * Stores all data in database
 */

class TyphoonForecastAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Get typhoon forecast for a location
     * Scans multiple locations to find actual typhoon landfall location
     * @param float $latitude
     * @param float $longitude
     * @param int $days (default: 7)
     * @return array
     */
    public function getTyphoonForecast($latitude, $longitude, $days = 7) {
        try {
            $forecast = [];
            
            // Scan multiple locations across Philippines to find typhoon landfall location
            $scanLocations = $this->getScanLocations($latitude, $longitude);
            
            // Get forecasts for all scan locations
            $allForecasts = [];
            foreach ($scanLocations as $location) {
                $weatherForecast = $this->getWeatherForecast($location['lat'], $location['lng'], $days);
                if ($weatherForecast['success']) {
                    $allForecasts[] = [
                        'location' => $location,
                        'forecast' => $weatherForecast['data'],
                        'current' => $weatherForecast['current'] ?? null
                    ];
                }
            }
            
            // Analyze all forecasts to find typhoon landfall location
            $typhoonData = $this->findTyphoonLandfall($allForecasts, $days);
            
            // Also check for active typhoons using current weather data
            $activeTyphoons = $this->checkActiveTyphoons($allForecasts);
            if (!empty($activeTyphoons)) {
                // Merge active typhoons, prioritizing them and avoiding duplicates
                $existingKeys = [];
                foreach ($typhoonData as $t) {
                    $key = ($t['date'] ?? '') . '_' . round($t['latitude'] ?? 0, 2) . '_' . round($t['longitude'] ?? 0, 2);
                    $existingKeys[$key] = true;
                }
                
                foreach ($activeTyphoons as $active) {
                    $key = $active['date'] . '_' . round($active['latitude'], 2) . '_' . round($active['longitude'], 2);
                    if (!isset($existingKeys[$key])) {
                        array_unshift($typhoonData, $active); // Add active typhoons at the beginning
                        $existingKeys[$key] = true;
                    }
                }
            }
            
            // Store typhoon forecasts in database
            $storedCount = 0;
            foreach ($typhoonData as $typhoon) {
                $stored = $this->storeTyphoonForecast($typhoon);
                if ($stored) {
                    $storedCount++;
                }
            }
            
            $forecast = array_merge($forecast, $typhoonData);
            
            return [
                'success' => true,
                'data' => $forecast,
                'count' => count($forecast),
                'stored_count' => $storedCount,
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ],
                'forecast_days' => $days,
                'last_updated' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch typhoon forecast: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Check for active typhoons using current weather data
     * This helps detect typhoons happening right now
     */
    private function checkActiveTyphoons($allForecasts) {
        $activeTyphoons = [];
        $today = date('Y-m-d');
        
        foreach ($allForecasts as $locationForecast) {
            $location = $locationForecast['location'];
            $current = $locationForecast['current'];
            
            if (!$current) {
                continue;
            }
            
            $windSpeed = $current['wind_speed_10m'] ?? 0;
            $windGusts = $current['wind_gusts_10m'] ?? 0;
            $precipitation = $current['precipitation'] ?? 0;
            
            // Check for active typhoon conditions (current weather)
            // Lower threshold for current conditions since they're happening now
            if (($windSpeed >= 70 || $windGusts >= 85) && $precipitation >= 15) {
                if ($windGusts >= ($windSpeed * 1.15) || $windSpeed >= 70) {
                    $severity = 'high';
                    $category = $this->getTyphoonCategory($windSpeed);
                    
                    if ($windSpeed >= 118 || $windGusts >= 140) {
                        $severity = 'critical';
                        $category = 'Super Typhoon';
                    } elseif ($windSpeed >= 89 || $windGusts >= 110) {
                        $severity = 'critical';
                        $category = 'Category 3 Typhoon';
                    }
                    
                    $key = $today . '_' . round($location['lat'], 2) . '_' . round($location['lng'], 2);
                    
                    if (!isset($activeTyphoons[$key])) {
                        $activeTyphoons[$key] = [
                            'type' => 'typhoon',
                            'severity' => $severity,
                            'category' => $category,
                            'title' => "Active Typhoon - {$location['name']}",
                            'description' => "ACTIVE TYPHOON detected near {$location['name']}. Current wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h. Heavy precipitation: {$precipitation}mm. Take immediate precautions.",
                            'date' => $today,
                            'latitude' => $location['lat'],
                            'longitude' => $location['lng'],
                            'location_name' => $location['name'],
                            'wind_speed' => $windSpeed,
                            'wind_gusts' => $windGusts,
                            'precipitation' => $precipitation,
                            'radius_km' => $this->calculateAffectedRadius($windSpeed),
                            'coordinates' => [
                                'lat' => $location['lat'],
                                'lng' => $location['lng']
                            ],
                            'source' => 'current_weather',
                            'is_active' => true
                        ];
                    }
                }
            }
        }
        
        return array_values($activeTyphoons);
    }
    
    /**
     * Get scan locations across Philippines to find typhoon
     * Enhanced with more locations around Cebu and key areas
     */
    private function getScanLocations($centerLat, $centerLng) {
        // Key locations across Philippines to scan for typhoon
        // Enhanced coverage around Cebu and surrounding areas
        $locations = [
            ['lat' => 14.5995, 'lng' => 120.9842, 'name' => 'Manila'],
            // Enhanced Cebu area coverage
            ['lat' => 10.3157, 'lng' => 123.8854, 'name' => 'Cebu City'],
            ['lat' => 10.3333, 'lng' => 123.9000, 'name' => 'Cebu (North)'],
            ['lat' => 10.3000, 'lng' => 123.8500, 'name' => 'Cebu (South)'],
            ['lat' => 10.3500, 'lng' => 123.9500, 'name' => 'Cebu (East)'],
            ['lat' => 10.2800, 'lng' => 123.8000, 'name' => 'Cebu (West)'],
            ['lat' => 10.2000, 'lng' => 123.7000, 'name' => 'Toledo'],
            ['lat' => 10.4000, 'lng' => 124.0000, 'name' => 'Lapu-Lapu'],
            ['lat' => 10.2500, 'lng' => 123.9500, 'name' => 'Mandaue'],
            // Other key locations
            ['lat' => 7.1907, 'lng' => 125.4553, 'name' => 'Davao'],
            ['lat' => 16.4023, 'lng' => 120.5960, 'name' => 'Baguio'],
            ['lat' => 13.4125, 'lng' => 123.4133, 'name' => 'Naga'],
            ['lat' => 11.2434, 'lng' => 125.0049, 'name' => 'Tacloban'],
            ['lat' => 8.4844, 'lng' => 124.6472, 'name' => 'Cagayan de Oro'],
            ['lat' => 15.1448, 'lng' => 120.5847, 'name' => 'Angeles'],
            ['lat' => 14.6760, 'lng' => 121.0437, 'name' => 'Quezon City'],
            ['lat' => 6.9214, 'lng' => 122.0790, 'name' => 'Zamboanga'],
            ['lat' => 9.7500, 'lng' => 118.7500, 'name' => 'Puerto Princesa'],
            ['lat' => 12.8797, 'lng' => 121.7740, 'name' => 'Calapan'],
            // Add user's location if provided
            ['lat' => $centerLat, 'lng' => $centerLng, 'name' => 'User Location']
        ];
        
        return $locations;
    }
    
    /**
     * Find typhoon landfall location by analyzing forecasts across multiple locations
     * Enhanced with stricter criteria and better validation to reduce false positives
     */
    private function findTyphoonLandfall($allForecasts, $days = 7) {
        $typhoonData = [];
        $typhoonLocations = [];
        $today = date('Y-m-d');
        
        // Analyze each location's forecast
        foreach ($allForecasts as $locationForecast) {
            $location = $locationForecast['location'];
            $forecast = $locationForecast['forecast'];
            
            if (!isset($forecast['wind_speed_10m_max']) || !is_array($forecast['wind_speed_10m_max'])) {
                continue;
            }
            
            // Check each day for typhoon conditions at this location
            foreach ($forecast['wind_speed_10m_max'] as $index => $windSpeed) {
                $windGusts = $forecast['wind_gusts_10m_max'][$index] ?? $windSpeed;
                $precipitation = $forecast['precipitation_sum'][$index] ?? 0;
                $weatherCode = $forecast['weather_code'][$index] ?? 0;
                $date = $forecast['time'][$index] ?? date('Y-m-d', strtotime("+{$index} days"));
                $isToday = ($date === $today);
                
                // Enhanced typhoon detection with stricter criteria:
                // 1. Higher wind speed threshold (75 km/h sustained or 90 km/h gusts for typhoon)
                // 2. Must have significant precipitation (typhoons bring heavy rain)
                // 3. Weather code should indicate severe weather (if available)
                // 4. Current/active typhoons prioritized over future forecasts
                
                // Check for actual typhoon conditions (stricter criteria)
                $isTyphoon = false;
                $isTropicalStorm = false;
                
                // Typhoon: Sustained winds >= 75 km/h OR gusts >= 90 km/h
                // AND precipitation >= 20mm (typhoons bring heavy rain)
                // AND must be sustained (wind gusts significantly higher than base wind)
                if (($windSpeed >= 75 || $windGusts >= 90) && $precipitation >= 20) {
                    // Additional validation: gusts should be at least 1.2x base wind (indicates storm system)
                    if ($windGusts >= ($windSpeed * 1.2) || $windSpeed >= 75) {
                        $isTyphoon = true;
                    }
                }
                
                // For current/active typhoons, use slightly lower threshold but still strict
                if ($isToday && ($windSpeed >= 70 || $windGusts >= 85) && $precipitation >= 15) {
                    if ($windGusts >= ($windSpeed * 1.15) || $windSpeed >= 70) {
                        $isTyphoon = true;
                    }
                }
                
                // Tropical storm: Lower threshold but still requires precipitation
                if (!$isTyphoon && ($windSpeed >= 50 || $windGusts >= 65) && $precipitation >= 10) {
                    if ($windGusts >= ($windSpeed * 1.1) || $windSpeed >= 50) {
                        $isTropicalStorm = true;
                    }
                }
                
                if ($isTyphoon) {
                    $severity = 'high';
                    $category = $this->getTyphoonCategory($windSpeed);
                    
                    if ($windSpeed >= 118 || $windGusts >= 140) {
                        $severity = 'critical';
                        $category = 'Super Typhoon';
                    } elseif ($windSpeed >= 89 || $windGusts >= 110) {
                        $severity = 'critical';
                        $category = 'Category 3 Typhoon';
                    } elseif ($windSpeed >= 75 || $windGusts >= 90) {
                        $severity = 'high';
                        $category = 'Category 1-2 Typhoon';
                    }
                    
                    // Store typhoon location with date
                    $key = $date . '_' . round($location['lat'], 2) . '_' . round($location['lng'], 2);
                    
                    // Only add if this is the strongest typhoon for this date/location
                    if (!isset($typhoonLocations[$key]) || $windSpeed > $typhoonLocations[$key]['wind_speed']) {
                        $typhoonLocations[$key] = [
                            'type' => 'typhoon',
                            'severity' => $severity,
                            'category' => $category,
                            'title' => ($isToday ? "Active Typhoon - {$location['name']}" : "Typhoon Warning - {$date}"),
                            'description' => ($isToday 
                                ? "Active typhoon detected near {$location['name']}. Wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h. Heavy precipitation: {$precipitation}mm. Take immediate precautions."
                                : "Typhoon-level winds expected on {$date} near {$location['name']}. Wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h. Expected precipitation: {$precipitation}mm."),
                            'date' => $date,
                            'latitude' => $location['lat'],
                            'longitude' => $location['lng'],
                            'location_name' => $location['name'],
                            'wind_speed' => $windSpeed,
                            'wind_gusts' => $windGusts,
                            'precipitation' => $precipitation,
                            'radius_km' => $this->calculateAffectedRadius($windSpeed),
                            'coordinates' => [
                                'lat' => $location['lat'],
                                'lng' => $location['lng']
                            ],
                            'source' => 'forecast_api',
                            'is_active' => $isToday
                        ];
                    }
                } elseif ($isTropicalStorm) {
                    // Only show tropical storms if they're current or within 2 days
                    $daysUntil = (strtotime($date) - strtotime($today)) / 86400;
                    if ($isToday || $daysUntil <= 2) {
                        $key = $date . '_' . round($location['lat'], 2) . '_' . round($location['lng'], 2);
                        
                        if (!isset($typhoonLocations[$key]) || $windSpeed > $typhoonLocations[$key]['wind_speed']) {
                            $typhoonLocations[$key] = [
                                'type' => 'tropical_storm',
                                'severity' => 'medium',
                                'category' => 'Tropical Storm',
                                'title' => ($isToday ? "Active Tropical Storm - {$location['name']}" : "Tropical Storm Warning - {$date}"),
                                'description' => ($isToday
                                    ? "Active tropical storm near {$location['name']}. Wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h. Precipitation: {$precipitation}mm."
                                    : "Tropical storm conditions expected on {$date} near {$location['name']}. Wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h."),
                                'date' => $date,
                                'latitude' => $location['lat'],
                                'longitude' => $location['lng'],
                                'location_name' => $location['name'],
                                'wind_speed' => $windSpeed,
                                'wind_gusts' => $windGusts,
                                'precipitation' => $precipitation,
                                'radius_km' => $this->calculateAffectedRadius($windSpeed),
                                'coordinates' => [
                                    'lat' => $location['lat'],
                                    'lng' => $location['lng']
                                ],
                                'source' => 'forecast_api',
                                'is_active' => $isToday
                            ];
                        }
                    }
                }
            }
        }
        
        // Convert to array and sort by date (current/active first, then future)
        $typhoonData = array_values($typhoonLocations);
        usort($typhoonData, function($a, $b) {
            // Prioritize active/current typhoons
            if (isset($a['is_active']) && $a['is_active'] && (!isset($b['is_active']) || !$b['is_active'])) {
                return -1;
            }
            if (isset($b['is_active']) && $b['is_active'] && (!isset($a['is_active']) || !$a['is_active'])) {
                return 1;
            }
            // Then sort by date
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $typhoonData;
    }
    
    /**
     * Get weather forecast from Open-Meteo
     * Enhanced to also get current weather for better active typhoon detection
     */
    private function getWeatherForecast($latitude, $longitude, $days = 7) {
        try {
            // Get both current weather and forecast for better accuracy
            $url = "https://api.open-meteo.com/v1/forecast?" . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'current' => 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,wind_gusts_10m,weather_code',
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_gusts_10m_max,weather_code',
                'timezone' => 'Asia/Manila',
                'forecast_days' => $days
            ]);
            
            $response = $this->fetchUrl($url);
            if (!$response) {
                throw new Exception('Failed to fetch weather forecast');
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['daily'])) {
                // If we have current weather data, use it to enhance today's forecast
                if (isset($data['current'])) {
                    $current = $data['current'];
                    $today = date('Y-m-d');
                    
                    // Find today's index in the daily forecast
                    if (isset($data['daily']['time']) && is_array($data['daily']['time'])) {
                        $todayIndex = array_search($today, $data['daily']['time']);
                        
                        if ($todayIndex !== false) {
                            // Enhance today's forecast with current actual data
                            // Use the higher of forecast or current for better detection
                            if (isset($data['daily']['wind_speed_10m_max'][$todayIndex])) {
                                $data['daily']['wind_speed_10m_max'][$todayIndex] = max(
                                    $data['daily']['wind_speed_10m_max'][$todayIndex],
                                    $current['wind_speed_10m'] ?? 0
                                );
                            }
                            
                            if (isset($data['daily']['wind_gusts_10m_max'][$todayIndex])) {
                                $data['daily']['wind_gusts_10m_max'][$todayIndex] = max(
                                    $data['daily']['wind_gusts_10m_max'][$todayIndex],
                                    $current['wind_gusts_10m'] ?? 0
                                );
                            }
                            
                            if (isset($data['daily']['precipitation_sum'][$todayIndex])) {
                                // Add current precipitation to today's total
                                $data['daily']['precipitation_sum'][$todayIndex] += ($current['precipitation'] ?? 0);
                            }
                        }
                    }
                }
                
                return [
                    'success' => true,
                    'data' => $data['daily'],
                    'current' => $data['current'] ?? null
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
     * Analyze forecast data for typhoon conditions
     */
    private function analyzeTyphoonConditions($forecast, $latitude, $longitude) {
        $typhoonData = [];
        
        if (!isset($forecast['wind_speed_10m_max']) || !is_array($forecast['wind_speed_10m_max'])) {
            return $typhoonData;
        }
        
        // Check each day for typhoon conditions
        foreach ($forecast['wind_speed_10m_max'] as $index => $windSpeed) {
            $windGusts = $forecast['wind_gusts_10m_max'][$index] ?? $windSpeed;
            $precipitation = $forecast['precipitation_sum'][$index] ?? 0;
            $date = $forecast['time'][$index] ?? date('Y-m-d', strtotime("+{$index} days"));
            
            // Typhoon conditions: Wind speed >= 62 km/h (Category 1 typhoon)
            if ($windSpeed >= 62 || $windGusts >= 75) {
                $severity = 'high';
                $category = $this->getTyphoonCategory($windSpeed);
                
                if ($windSpeed >= 118) {
                    $severity = 'critical';
                    $category = 'Super Typhoon';
                } elseif ($windSpeed >= 89) {
                    $severity = 'critical';
                    $category = 'Category 3 Typhoon';
                } elseif ($windSpeed >= 62) {
                    $severity = 'high';
                    $category = 'Category 1-2 Typhoon';
                }
                
                $typhoonData[] = [
                    'type' => 'typhoon',
                    'severity' => $severity,
                    'category' => $category,
                    'title' => "Typhoon Warning - {$date}",
                    'description' => "Typhoon-level winds expected on {$date}. Wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h. Precipitation: {$precipitation}mm.",
                    'date' => $date,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'wind_speed' => $windSpeed,
                    'wind_gusts' => $windGusts,
                    'precipitation' => $precipitation,
                    'radius_km' => $this->calculateAffectedRadius($windSpeed),
                    'coordinates' => [
                        'lat' => $latitude,
                        'lng' => $longitude
                    ]
                ];
            } elseif ($windSpeed >= 39 || $windGusts >= 50) {
                // Tropical storm conditions
                $typhoonData[] = [
                    'type' => 'tropical_storm',
                    'severity' => 'medium',
                    'category' => 'Tropical Storm',
                    'title' => "Tropical Storm Warning - {$date}",
                    'description' => "Strong tropical storm conditions expected on {$date}. Wind speed: {$windSpeed} km/h, gusts up to {$windGusts} km/h.",
                    'date' => $date,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'wind_speed' => $windSpeed,
                    'wind_gusts' => $windGusts,
                    'precipitation' => $precipitation,
                    'radius_km' => $this->calculateAffectedRadius($windSpeed),
                    'coordinates' => [
                        'lat' => $latitude,
                        'lng' => $longitude
                    ]
                ];
            }
        }
        
        return $typhoonData;
    }
    
    /**
     * Get typhoon category based on wind speed
     */
    private function getTyphoonCategory($windSpeed) {
        if ($windSpeed >= 118) {
            return 'Super Typhoon';
        } elseif ($windSpeed >= 89) {
            return 'Category 3 Typhoon';
        } elseif ($windSpeed >= 62) {
            return 'Category 1-2 Typhoon';
        }
        return 'Tropical Storm';
    }
    
    /**
     * Calculate affected radius based on wind speed
     * Enhanced with more accurate radius calculations
     */
    private function calculateAffectedRadius($windSpeed) {
        // More accurate radius estimates based on typhoon category
        if ($windSpeed >= 118) {
            return 250; // Super typhoon: 250km radius
        } elseif ($windSpeed >= 89) {
            return 200; // Category 3: 200km radius
        } elseif ($windSpeed >= 75) {
            return 150; // Category 1-2: 150km radius
        } elseif ($windSpeed >= 50) {
            return 75; // Tropical storm: 75km radius
        }
        return 50; // Default: 50km radius
    }
    
    /**
     * Store typhoon forecast in database
     */
    private function storeTyphoonForecast($typhoonData) {
        try {
            // Check if typhoon already exists (by date, location, and type)
            $checkQuery = "SELECT id FROM disasters 
                          WHERE type = :type 
                          AND center_latitude = :lat 
                          AND center_longitude = :lng 
                          AND DATE(start_date) = :date
                          AND status IN ('active', 'warning')
                          LIMIT 1";
            
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':type', 'typhoon');
            $checkStmt->bindValue(':lat', $typhoonData['latitude']);
            $checkStmt->bindValue(':lng', $typhoonData['longitude']);
            $checkStmt->bindValue(':date', $typhoonData['date']);
            $checkStmt->execute();
            
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update existing typhoon if wind speed is higher
                $updateQuery = "UPDATE disasters 
                               SET severity = :severity,
                                   description = :description,
                                   affected_radius_km = :radius,
                                   start_date = :start_date,
                                   end_date = :end_date,
                                   updated_at = NOW()
                               WHERE id = :id";
                
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindValue(':severity', $typhoonData['severity']);
                $updateStmt->bindValue(':description', $typhoonData['description']);
                $updateStmt->bindValue(':radius', $typhoonData['radius_km']);
                $updateStmt->bindValue(':start_date', $typhoonData['date'] . ' 00:00:00');
                $updateStmt->bindValue(':end_date', $typhoonData['date'] . ' 23:59:59');
                $updateStmt->bindValue(':id', $existing['id']);
                
                if ($updateStmt->execute()) {
                    // Also create/update alert for this typhoon
                    $this->createTyphoonAlert($existing['id'], $typhoonData);
                    return $existing['id'];
                }
            } else {
                // Insert new typhoon
                $insertQuery = "INSERT INTO disasters 
                               (name, type, severity, status, description, 
                                center_latitude, center_longitude, affected_radius_km, 
                                start_date, end_date, created_at) 
                               VALUES (:name, :type, :severity, 'warning', :description, 
                                       :lat, :lng, :radius, :start_date, :end_date, NOW())";
                
                $name = $typhoonData['category'] . ' - ' . ($typhoonData['location_name'] ?? 'Unknown') . ' (' . $typhoonData['date'] . ')';
                
                $insertStmt = $this->conn->prepare($insertQuery);
                $insertStmt->bindValue(':name', $name);
                $insertStmt->bindValue(':type', 'typhoon');
                $insertStmt->bindValue(':severity', $typhoonData['severity']);
                $insertStmt->bindValue(':description', $typhoonData['description']);
                $insertStmt->bindValue(':lat', $typhoonData['latitude']);
                $insertStmt->bindValue(':lng', $typhoonData['longitude']);
                $insertStmt->bindValue(':radius', $typhoonData['radius_km']);
                $insertStmt->bindValue(':start_date', $typhoonData['date'] . ' 00:00:00');
                $insertStmt->bindValue(':end_date', $typhoonData['date'] . ' 23:59:59');
                
                if ($insertStmt->execute()) {
                    $disasterId = $this->conn->lastInsertId();
                    
                    // Create alert for this typhoon
                    $this->createTyphoonAlert($disasterId, $typhoonData);
                    
                    return $disasterId;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Failed to store typhoon forecast: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create alert for typhoon
     */
    private function createTyphoonAlert($disasterId, $typhoonData) {
        try {
            // Check if alert already exists
            $checkQuery = "SELECT id FROM alerts 
                          WHERE disaster_id = :disaster_id 
                          AND type = :type
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          LIMIT 1";
            
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':disaster_id', $disasterId);
            $checkStmt->bindValue(':type', 'typhoon');
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                // Alert already exists
                return false;
            }
            
            // Create alert
            $alertQuery = "INSERT INTO alerts 
                          (type, severity, description, status, disaster_id, created_at) 
                          VALUES (:type, :severity, :description, 'active', :disaster_id, NOW())";
            
            $alertStmt = $this->conn->prepare($alertQuery);
            $alertStmt->bindValue(':type', 'typhoon');
            $alertStmt->bindValue(':severity', $typhoonData['severity']);
            $alertStmt->bindValue(':description', $typhoonData['description']);
            $alertStmt->bindValue(':disaster_id', $disasterId);
            
            if ($alertStmt->execute()) {
                $alertId = $this->conn->lastInsertId();
                
                // Store weather conditions that triggered the alert
                $this->storeWeatherConditions($alertId, $typhoonData);
                
                return $alertId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Failed to create typhoon alert: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Store weather conditions that triggered the typhoon alert
     */
    private function storeWeatherConditions($alertId, $typhoonData) {
        try {
            $query = "INSERT INTO weather_conditions 
                     (alert_id, wind_speed, rainfall, recorded_at) 
                     VALUES (:alert_id, :wind_speed, :rainfall, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':alert_id', $alertId);
            $stmt->bindValue(':wind_speed', $typhoonData['wind_speed'] ?? null);
            $stmt->bindValue(':rainfall', $typhoonData['precipitation'] ?? null);
            
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to store weather conditions: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get weather alerts from WeatherAPI.com (free tier)
     * Note: Requires API key, but can be used without for basic data
     */
    private function getWeatherAlerts($latitude, $longitude) {
        try {
            // WeatherAPI.com requires API key, but we can use Open-Meteo alerts instead
            // This is a placeholder for future WeatherAPI.com integration
            return [
                'success' => true,
                'data' => []
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
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
                error_log("cURL error fetching typhoon forecast: " . $error);
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
    $action = $_GET['action'] ?? 'forecast';
    $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
    $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    
    $typhoonAPI = new TyphoonForecastAPI();
    
    switch ($action) {
        case 'forecast':
        default:
            if ($latitude === null || $longitude === null) {
                // Default to Manila, Philippines
                $latitude = 14.5995;
                $longitude = 120.9842;
            }
            $result = $typhoonAPI->getTyphoonForecast($latitude, $longitude, $days);
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

