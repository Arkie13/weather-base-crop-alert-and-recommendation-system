<?php
/**
 * Early Harvest vs. Wait Decision Prescription API
 * Provides prescriptive analytics for optimal harvest timing decisions
 * 
 * Usage:
 * GET api/harvest-decision-prescription.php?crop_id=123
 * GET api/harvest-decision-prescription.php?user_id=1 (all crops)
 * POST api/harvest-decision-prescription.php (with crop_id in body)
 */

// Start output buffering to prevent any accidental output
ob_start();

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/crop-recommendation.php';
require_once __DIR__ . '/crop-prices.php';
require_once __DIR__ . '/forecasting.php';
require_once __DIR__ . '/weather-helper.php';

class HarvestDecisionPrescription {
    private $conn;
    private $cropRecommendation;
    private $cropPrices;
    private $forecasting;
    private $weatherHelper;
    
    // Weather risk thresholds
    private $riskThresholds = [
        'heavy_rain' => 30, // mm per day
        'wind_damage' => 25, // km/h
        'flood_risk' => 50, // mm per day
        'lodging_risk' => 35 // mm per day + wind > 20 km/h
    ];
    
    // Yield loss factors based on weather damage
    private $damageFactors = [
        'heavy_rain' => 0.15, // 15% yield loss
        'wind_damage' => 0.20, // 20% yield loss
        'flood' => 0.40, // 40% yield loss
        'lodging' => 0.35, // 35% yield loss
        'combined_severe' => 0.50 // 50% yield loss for severe combined events
    ];
    
    // Maturity yield factors (yield at different maturity percentages)
    private $maturityYieldFactors = [
        70 => 0.85, // 85% yield at 70% maturity
        75 => 0.88,
        80 => 0.91,
        85 => 0.94,
        90 => 0.97,
        95 => 0.99,
        100 => 1.00 // Full yield at 100% maturity
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->cropRecommendation = new CropRecommendationML();
        $this->cropPrices = new CropPricesAPI();
        $this->forecasting = new ForecastingAPI();
        $this->weatherHelper = new WeatherHelper();
    }
    
    /**
     * Get harvest decision prescription for a specific crop or all user crops
     */
    public function getHarvestPrescription($cropId = null, $userId = null) {
        try {
            // Get user ID from session if not provided
            if (!$userId) {
                session_start();
                $userId = $_SESSION['user_id'] ?? null;
            }
            
            if (!$userId && !$cropId) {
                return [
                    'success' => false,
                    'message' => 'User ID or Crop ID is required'
                ];
            }
            
            // Get crops
            $crops = $this->getCrops($cropId, $userId);
            
            if (empty($crops)) {
                return [
                    'success' => false,
                    'message' => 'No crops found'
                ];
            }
            
            // Get user location for weather forecast
            $userLocation = $crop['location'] ?? 'Manila';
            
            // Get real-time weather forecast from Open-Meteo API
            try {
                $weatherForecast = $this->getWeatherForecast(14, $userLocation); // 14-day forecast
            } catch (Exception $e) {
                error_log("Error getting weather forecast for harvest decision: " . $e->getMessage());
                // Fallback to default forecast
                $weatherForecast = $this->getDefaultForecast(14);
            }
            
            // Ensure we have forecast data
            if (empty($weatherForecast)) {
                error_log("Warning: Empty weather forecast, using default");
                $weatherForecast = $this->getDefaultForecast(14);
            }
            
            // Process each crop
            $prescriptions = [];
            foreach ($crops as $crop) {
                $prescription = $this->analyzeCrop($crop, $weatherForecast);
                if ($prescription) {
                    $prescriptions[] = $prescription;
                }
            }
            
            return [
                'success' => true,
                'data' => $prescriptions,
                'count' => count($prescriptions),
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to generate prescription: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get crops from database
     */
    private function getCrops($cropId = null, $userId = null) {
        try {
            if ($cropId) {
                $query = "SELECT uc.*, u.location 
                         FROM user_crops uc 
                         LEFT JOIN users u ON uc.user_id = u.id 
                         WHERE uc.id = :crop_id 
                         AND uc.status IN ('planted', 'growing', 'harvesting')";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':crop_id', $cropId);
            } else {
                $query = "SELECT uc.*, u.location 
                         FROM user_crops uc 
                         LEFT JOIN users u ON uc.user_id = u.id 
                         WHERE uc.user_id = :user_id 
                         AND uc.status IN ('planted', 'growing', 'harvesting')";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error getting crops: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Analyze a single crop and generate prescription
     */
    private function analyzeCrop($crop, $weatherForecast) {
        try {
            // Get crop information
            $cropName = strtolower(trim($crop['crop_name']));
            $cropData = $this->getCropData($cropName);
            
            if (!$cropData) {
                return null;
            }
            
            // Calculate current maturity
            $plantingDate = new DateTime($crop['planting_date']);
            $currentDate = new DateTime();
            $daysSincePlanting = $plantingDate->diff($currentDate)->days;
            $growthDays = $cropData['growth_days'] ?? 120;
            $maturityPercent = min(100, ($daysSincePlanting / $growthDays) * 100);
            
            // Get expected harvest date
            $expectedHarvestDate = null;
            if ($crop['expected_harvest_date']) {
                $expectedHarvestDate = new DateTime($crop['expected_harvest_date']);
            } else {
                $expectedHarvestDate = clone $plantingDate;
                $expectedHarvestDate->add(new DateInterval('P' . $growthDays . 'D'));
            }
            
            $daysToFullMaturity = $expectedHarvestDate->diff($currentDate)->days;
            
            // Analyze weather risks
            $weatherRisks = $this->analyzeWeatherRisks($weatherForecast, $cropData);
            
            // Get crop price
            $cropPrice = $this->getCropPrice($crop['crop_name']);
            
            // Calculate yield scenarios
            $scenarios = $this->calculateScenarios(
                $crop,
                $cropData,
                $maturityPercent,
                $daysToFullMaturity,
                $weatherRisks,
                $cropPrice
            );
            
            // Generate prescription
            $prescription = $this->generatePrescription(
                $crop,
                $cropData,
                $maturityPercent,
                $daysToFullMaturity,
                $weatherRisks,
                $scenarios,
                $cropPrice
            );
            
            return $prescription;
            
        } catch (Exception $e) {
            error_log("Error analyzing crop: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get crop data from crop recommendation database
     */
    private function getCropData($cropName) {
        // Use reflection to access private cropDatabase
        $reflection = new ReflectionClass('CropRecommendationML');
        $property = $reflection->getProperty('cropDatabase');
        $property->setAccessible(true);
        $cropDatabase = $property->getValue($this->cropRecommendation);
        
        // Normalize crop name
        $cropName = strtolower(trim($cropName));
        
        // Try exact match first
        if (isset($cropDatabase[$cropName])) {
            return $cropDatabase[$cropName];
        }
        
        // Try partial match
        foreach ($cropDatabase as $key => $data) {
            if (strpos($cropName, $key) !== false || strpos($key, $cropName) !== false) {
                return $data;
            }
        }
        
        // Default crop data
        return [
            'name' => ucfirst($cropName),
            'growth_days' => 90,
            'yield_potential' => 'medium',
            'optimal_wind_max' => 15
        ];
    }
    
    /**
     * Get weather forecast using real-time Open-Meteo API
     * @param int $days Number of days to forecast
     * @param string $location Location name (e.g., "Manila, Philippines")
     * @return array Forecast data with dates, rainfall, wind, temperature
     */
    private function getWeatherForecast($days = 14, $location = 'Manila') {
        try {
            // Use weather helper to get real-time forecast from Open-Meteo API
            $forecast = $this->weatherHelper->getWeatherForecast($location, null, $days);
            
            // Transform to expected format (with predicted_rainfall, predicted_wind, etc.)
            $transformedForecast = [];
            foreach ($forecast as $day) {
                $transformedForecast[] = [
                    'date' => $day['date'],
                    'predicted_temperature' => $day['predicted_temperature'] ?? 28,
                    'predicted_rainfall' => $day['predicted_rainfall'] ?? 0,
                    'predicted_wind' => $day['predicted_wind'] ?? 10,
                    'confidence' => $day['confidence'] ?? 85
                ];
            }
            
            return $transformedForecast;
            
        } catch (Exception $e) {
            error_log("Error getting weather forecast from API: " . $e->getMessage());
            
            // Fallback: Try database forecasting API
            try {
                $forecastResult = $this->forecasting->getWeatherRecordsForecast($days, 'straight_line');
                
                if ($forecastResult['success'] && isset($forecastResult['data']['forecast']['forecast_data'])) {
                    return $forecastResult['data']['forecast']['forecast_data'];
                }
            } catch (Exception $e2) {
                error_log("Database forecasting also failed: " . $e2->getMessage());
            }
            
            // Final fallback: Get recent weather data and extrapolate
            return $this->getRecentWeatherData($days);
        }
    }
    
    /**
     * Get recent weather data as fallback
     */
    private function getRecentWeatherData($days) {
        try {
            $query = "SELECT 
                        DATE(recorded_at) as date,
                        AVG(temperature) as avg_temp,
                        AVG(humidity) as avg_humidity,
                        SUM(rainfall) as total_rainfall,
                        MAX(wind_speed) as max_wind
                     FROM weather_data 
                     WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY DATE(recorded_at)
                     ORDER BY date DESC
                     LIMIT 7";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $recentData = $stmt->fetchAll();
            
            // Generate forecast based on recent patterns
            $forecast = [];
            $baseDate = new DateTime();
            
            for ($i = 1; $i <= $days; $i++) {
                $forecastDate = clone $baseDate;
                $forecastDate->add(new DateInterval('P' . $i . 'D'));
                
                // Use average of recent data with some variation
                $avgRainfall = !empty($recentData) ? array_sum(array_column($recentData, 'total_rainfall')) / count($recentData) : 5;
                $avgWind = !empty($recentData) ? array_sum(array_column($recentData, 'max_wind')) / count($recentData) : 10;
                
                $forecast[] = [
                    'date' => $forecastDate->format('Y-m-d'),
                    'predicted_temperature' => 28, // Default
                    'predicted_rainfall' => max(0, $avgRainfall + rand(-5, 10)),
                    'predicted_wind' => max(0, $avgWind + rand(-3, 5)),
                    'confidence' => 60
                ];
            }
            
            return $forecast;
            
        } catch (Exception $e) {
            // Return default forecast
            return $this->getDefaultForecast($days);
        }
    }
    
    /**
     * Get default forecast
     */
    private function getDefaultForecast($days) {
        $forecast = [];
        $baseDate = new DateTime();
        
        for ($i = 1; $i <= $days; $i++) {
            $forecastDate = clone $baseDate;
            $forecastDate->add(new DateInterval('P' . $i . 'D'));
            
            $forecast[] = [
                'date' => $forecastDate->format('Y-m-d'),
                'predicted_temperature' => 28,
                'predicted_rainfall' => 5,
                'predicted_wind' => 10,
                'confidence' => 50
            ];
        }
        
        return $forecast;
    }
    
    /**
     * Analyze weather risks from forecast
     */
    private function analyzeWeatherRisks($forecast, $cropData) {
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
            
            // Heavy rain risk
            if ($rainfall > $this->riskThresholds['heavy_rain']) {
                $risks['heavy_rain'][] = [
                    'date' => $date,
                    'rainfall' => $rainfall,
                    'severity' => $rainfall > 50 ? 'high' : ($rainfall > 40 ? 'medium' : 'low')
                ];
                if ($rainfall > 50) {
                    $maxRiskSeverity = 'high';
                    $maxRiskDate = $date;
                } elseif ($maxRiskSeverity !== 'high' && $rainfall > 40) {
                    $maxRiskSeverity = 'medium';
                    if (!$maxRiskDate) $maxRiskDate = $date;
                }
            }
            
            // Wind damage risk
            $windMax = $cropData['optimal_wind_max'] ?? 15;
            if ($wind > $windMax) {
                $risks['wind_damage'][] = [
                    'date' => $date,
                    'wind_speed' => $wind,
                    'severity' => $wind > ($windMax * 1.5) ? 'high' : 'medium'
                ];
                if ($wind > ($windMax * 1.5) && $maxRiskSeverity !== 'high') {
                    $maxRiskSeverity = 'high';
                    $maxRiskDate = $date;
                }
            }
            
            // Flood risk
            if ($rainfall > $this->riskThresholds['flood_risk']) {
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
            if ($rainfall > $this->riskThresholds['lodging_risk'] && $wind > 20) {
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
     * Get crop price with error handling
     */
    private function getCropPrice($cropName) {
        try {
            $priceData = $this->cropPrices->getCurrentPrice($cropName);
            if ($priceData && isset($priceData['price_per_kg'])) {
                return floatval($priceData['price_per_kg']);
            }
            // Try alternative key name
            if ($priceData && isset($priceData['price'])) {
                return floatval($priceData['price']);
            }
        } catch (Exception $e) {
            error_log("Error getting crop price for {$cropName}: " . $e->getMessage());
        }
        
        // Use default prices with fallback
        try {
            $reflection = new ReflectionClass('CropPricesAPI');
            $property = $reflection->getProperty('defaultPrices');
            $property->setAccessible(true);
            $defaultPrices = $property->getValue($this->cropPrices);
            
            $basePrice = $defaultPrices[$cropName] ?? 25.00;
            
            // Apply seasonal adjustment if possible
            $adjustMethod = new ReflectionMethod('CropPricesAPI', 'applySeasonalAdjustment');
            $adjustMethod->setAccessible(true);
            return floatval($adjustMethod->invoke($this->cropPrices, $cropName, $basePrice));
        } catch (Exception $e) {
            error_log("Error accessing default prices: " . $e->getMessage());
            return 25.00; // Final fallback: Default PHP 25/kg
        }
    }
    
    /**
     * Calculate yield scenarios
     */
    private function calculateScenarios($crop, $cropData, $maturityPercent, $daysToFullMaturity, $weatherRisks, $cropPrice) {
        $areaHectares = floatval($crop['area_hectares']);
        
        // Base yield (tons per hectare) - estimated based on crop type
        $baseYieldPerHa = $this->getBaseYield($cropData);
        
        // Scenario 1: Harvest Now (at current maturity)
        $harvestNowMaturity = round($maturityPercent);
        $harvestNowYieldFactor = $this->getMaturityYieldFactor($harvestNowMaturity);
        $harvestNowYield = $baseYieldPerHa * $harvestNowYieldFactor;
        $harvestNowTotalYield = $harvestNowYield * $areaHectares;
        $harvestNowValue = $harvestNowTotalYield * 1000 * $cropPrice; // Convert tons to kg
        
        // Scenario 2: Wait to Full Maturity (no weather risk)
        $waitYield = $baseYieldPerHa * $areaHectares;
        $waitValue = $waitYield * 1000 * $cropPrice;
        
        // Scenario 3: Wait but Risk Damage
        $damageFactor = $this->calculateDamageFactor($weatherRisks);
        $riskYield = $waitYield * (1 - $damageFactor);
        $riskValue = $riskYield * 1000 * $cropPrice;
        
        // Calculate optimal harvest date (if early harvest recommended)
        $optimalHarvestDate = null;
        $optimalHarvestMaturity = null;
        $optimalHarvestValue = null;
        
        if ($weatherRisks['overall_risk_level'] !== 'low' && $weatherRisks['highest_risk_date']) {
            $riskDate = new DateTime($weatherRisks['highest_risk_date']);
            $currentDate = new DateTime();
            $daysToRisk = $currentDate->diff($riskDate)->days;
            
            // Recommend harvesting 2-3 days before risk
            $recommendedHarvestDays = max(1, $daysToRisk - 2);
            $recommendedHarvestDate = clone $currentDate;
            $recommendedHarvestDate->add(new DateInterval('P' . $recommendedHarvestDays . 'D'));
            
            // Calculate maturity at recommended date
            $plantingDate = new DateTime($crop['planting_date']);
            $daysAtHarvest = $plantingDate->diff($recommendedHarvestDate)->days;
            $growthDays = $cropData['growth_days'] ?? 120;
            $maturityAtHarvest = min(100, ($daysAtHarvest / $growthDays) * 100);
            
            $optimalHarvestMaturity = round($maturityAtHarvest);
            $optimalYieldFactor = $this->getMaturityYieldFactor($optimalHarvestMaturity);
            $optimalYield = $baseYieldPerHa * $optimalYieldFactor * $areaHectares;
            $optimalHarvestValue = $optimalYield * 1000 * $cropPrice;
            $optimalHarvestDate = $recommendedHarvestDate->format('Y-m-d');
        }
        
        return [
            'harvest_now' => [
                'maturity_percent' => $harvestNowMaturity,
                'yield_tons' => round($harvestNowTotalYield, 2),
                'value_php' => round($harvestNowValue, 2),
                'description' => 'Harvest immediately at current maturity'
            ],
            'wait_full_maturity' => [
                'maturity_percent' => 100,
                'yield_tons' => round($waitYield, 2),
                'value_php' => round($waitValue, 2),
                'days_to_maturity' => $daysToFullMaturity,
                'description' => 'Wait until full maturity (no weather risk)'
            ],
            'wait_risk_damage' => [
                'maturity_percent' => 100,
                'yield_tons' => round($riskYield, 2),
                'value_php' => round($riskValue, 2),
                'damage_percent' => round($damageFactor * 100, 1),
                'description' => 'Wait but risk weather damage'
            ],
            'optimal_harvest' => $optimalHarvestDate ? [
                'date' => $optimalHarvestDate,
                'maturity_percent' => $optimalHarvestMaturity,
                'yield_tons' => round($optimalYield, 2),
                'value_php' => round($optimalHarvestValue, 2),
                'description' => 'Optimal harvest timing (before weather risk)'
            ] : null
        ];
    }
    
    /**
     * Get base yield per hectare (tons)
     */
    private function getBaseYield($cropData) {
        $yieldPotential = $cropData['yield_potential'] ?? 'medium';
        
        $yieldMap = [
            'very_high' => 6.0,
            'high' => 4.5,
            'medium' => 3.0,
            'low' => 1.5
        ];
        
        return $yieldMap[$yieldPotential] ?? 3.0;
    }
    
    /**
     * Get yield factor based on maturity percentage
     */
    private function getMaturityYieldFactor($maturityPercent) {
        $maturity = round($maturityPercent);
        
        // Find closest maturity level
        $closestMaturity = 70;
        foreach ($this->maturityYieldFactors as $maturityLevel => $factor) {
            if ($maturity >= $maturityLevel) {
                $closestMaturity = $maturityLevel;
            } else {
                break;
            }
        }
        
        // Interpolate if needed
        if ($maturity < 70) {
            return 0.70 + (($maturity / 70) * 0.15); // Linear interpolation from 70% to 85%
        }
        
        return $this->maturityYieldFactors[$closestMaturity];
    }
    
    /**
     * Calculate damage factor based on weather risks
     */
    private function calculateDamageFactor($weatherRisks) {
        $damageFactor = 0;
        
        // Check for combined severe events
        $hasHeavyRain = !empty($weatherRisks['heavy_rain']);
        $hasWind = !empty($weatherRisks['wind_damage']);
        $hasFlood = !empty($weatherRisks['flood_risk']);
        $hasLodging = !empty($weatherRisks['lodging_risk']);
        
        if ($hasLodging || ($hasHeavyRain && $hasWind)) {
            return $this->damageFactors['combined_severe'];
        }
        
        if ($hasFlood) {
            $damageFactor = max($damageFactor, $this->damageFactors['flood']);
        }
        
        if ($hasLodging) {
            $damageFactor = max($damageFactor, $this->damageFactors['lodging']);
        }
        
        if ($hasHeavyRain) {
            $damageFactor = max($damageFactor, $this->damageFactors['heavy_rain']);
        }
        
        if ($hasWind) {
            $damageFactor = max($damageFactor, $this->damageFactors['wind_damage']);
        }
        
        return $damageFactor;
    }
    
    /**
     * Generate prescription recommendation
     */
    private function generatePrescription($crop, $cropData, $maturityPercent, $daysToFullMaturity, $weatherRisks, $scenarios, $cropPrice) {
        $recommendation = 'wait';
        $priority = 'low';
        $action = 'Continue monitoring. No immediate action required.';
        $rationale = [];
        $financialAnalysis = [];
        
        // Determine recommendation based on scenarios
        if ($weatherRisks['overall_risk_level'] === 'high' && $scenarios['optimal_harvest']) {
            $optimalValue = $scenarios['optimal_harvest']['value_php'];
            $riskValue = $scenarios['wait_risk_damage']['value_php'];
            $harvestNowValue = $scenarios['harvest_now']['value_php'];
            
            if ($optimalValue > $riskValue && $optimalValue > $harvestNowValue) {
                $recommendation = 'harvest_early_optimal';
                $priority = 'high';
                $action = "URGENT: Harvest on {$scenarios['optimal_harvest']['date']} (before weather risk on {$weatherRisks['highest_risk_date']})";
                $rationale[] = "Weather risk detected: {$weatherRisks['highest_risk_severity']} risk on {$weatherRisks['highest_risk_date']}";
                $rationale[] = "Optimal harvest timing: {$scenarios['optimal_harvest']['maturity_percent']}% maturity";
                $rationale[] = "Expected yield: {$scenarios['optimal_harvest']['yield_tons']} tons";
                
                $financialAnalysis['recommended_value'] = $optimalValue;
                $financialAnalysis['risk_scenario_value'] = $riskValue;
                $financialAnalysis['net_benefit'] = $optimalValue - $riskValue;
                $financialAnalysis['benefit_percentage'] = round((($optimalValue - $riskValue) / $riskValue) * 100, 1);
            } elseif ($harvestNowValue > $riskValue) {
                $recommendation = 'harvest_now';
                $priority = 'high';
                $action = "URGENT: Harvest immediately to avoid weather damage";
                $rationale[] = "Current maturity: {$scenarios['harvest_now']['maturity_percent']}%";
                $rationale[] = "Weather risk: {$weatherRisks['highest_risk_severity']} risk expected";
                
                $financialAnalysis['recommended_value'] = $harvestNowValue;
                $financialAnalysis['risk_scenario_value'] = $riskValue;
                $financialAnalysis['net_benefit'] = $harvestNowValue - $riskValue;
            }
        } elseif ($weatherRisks['overall_risk_level'] === 'medium') {
            $recommendation = 'monitor_closely';
            $priority = 'medium';
            $action = "Monitor weather closely. Consider early harvest if conditions worsen.";
            $rationale[] = "Moderate weather risk detected";
            $rationale[] = "Current maturity: " . round($maturityPercent) . "%";
        } else {
            if ($maturityPercent >= 95) {
                $recommendation = 'harvest_soon';
                $priority = 'medium';
                $action = "Crop is nearly mature. Plan harvest within next few days.";
                $rationale[] = "Crop maturity: " . round($maturityPercent) . "%";
                $rationale[] = "Optimal harvest window: Now to full maturity";
            } else {
                $recommendation = 'wait';
                $action = "Continue normal care. Crop is developing well.";
                $rationale[] = "Current maturity: " . round($maturityPercent) . "%";
                $rationale[] = "Days to full maturity: {$daysToFullMaturity}";
                $rationale[] = "No significant weather risks detected";
            }
        }
        
        return [
            'crop_id' => $crop['id'],
            'crop_name' => $crop['crop_name'],
            'area_hectares' => floatval($crop['area_hectares']),
            'current_status' => [
                'maturity_percent' => round($maturityPercent, 1),
                'days_since_planting' => (new DateTime($crop['planting_date']))->diff(new DateTime())->days,
                'days_to_full_maturity' => $daysToFullMaturity,
                'health_status' => $crop['health_status'],
                'status' => $crop['status']
            ],
            'weather_risks' => $weatherRisks,
            'scenarios' => $scenarios,
            'recommendation' => [
                'action' => $recommendation,
                'priority' => $priority,
                'action_text' => $action,
                'rationale' => $rationale,
                'deadline' => $scenarios['optimal_harvest']['date'] ?? null
            ],
            'financial_analysis' => $financialAnalysis,
            'crop_price_per_kg' => $cropPrice,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
}

// Handle the request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $prescription = new HarvestDecisionPrescription();
    
    if ($requestMethod === 'GET') {
        $cropId = $_GET['crop_id'] ?? null;
        $userId = $_GET['user_id'] ?? null;
        $result = $prescription->getHarvestPrescription($cropId, $userId);
    } elseif ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $cropId = $input['crop_id'] ?? null;
        $userId = $input['user_id'] ?? null;
        $result = $prescription->getHarvestPrescription($cropId, $userId);
    } else {
        http_response_code(405);
        $result = [
            'success' => false,
            'message' => 'Method not allowed'
        ];
    }
    
    // Clean output buffer and end buffering
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Encode JSON (compact format to reduce size)
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($json === false) {
        $error = json_last_error_msg();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'JSON encoding error: ' . $error
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Check JSON size (warn if very large, but still output)
    $jsonSize = strlen($json);
    if ($jsonSize > 1000000) { // 1MB
        error_log("Warning: Large JSON response: " . $jsonSize . " bytes");
    }
    
    // Output JSON
    echo $json;
    
} catch (Exception $e) {
    // Clean output buffer and end buffering
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}