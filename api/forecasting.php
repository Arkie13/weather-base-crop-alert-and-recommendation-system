<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class ForecastingAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function getWeatherRecordsForecast($days = 30, $method = 'exponential_smoothing') {
        try {
            // Check if database connection is available
            if (!$this->conn) {
                // Generate sample data if database is not available
                return $this->generateSampleForecast($days, $method);
            }
            
            // Choose data source based on method
            if ($method === 'straight_line') {
                // For Straight Line Method: Use Average Temperature
                $query = "SELECT 
                            DATE(recorded_at) as date, 
                            AVG(temperature) as avg_temperature 
                          FROM weather_data 
                          WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                          GROUP BY DATE(recorded_at) 
                          ORDER BY date";
            } else {
                // For Exponential Smoothing Method: Use Daily Records Count
                $query = "SELECT 
                            DATE(recorded_at) as date, 
                            COUNT(*) as daily_records 
                          FROM weather_data 
                          WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                          GROUP BY DATE(recorded_at) 
                          ORDER BY date";
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $historicalData = $stmt->fetchAll();
            
            // If no data, generate sample data
            if (empty($historicalData)) {
                return $this->generateSampleForecast($days, $method);
            }
            
            // Choose forecasting method
            if ($method === 'straight_line') {
                $forecast = $this->calculateStraightLineForecast($historicalData, $days);
            } else {
                // Default to exponential smoothing
                $forecast = $this->calculateExponentialSmoothingForecast($historicalData, $days);
            }
            
            return [
                'success' => true,
                'data' => [
                    'historical' => $historicalData,
                    'forecast' => $forecast,
                    'forecast_period' => $days,
                    'method_used' => $method,
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            // Fallback to sample data if database fails
            return $this->generateSampleForecast($days, $method);
        }
    }
    
    private function generateSampleForecast($days, $method = 'exponential_smoothing') {
        // Generate sample historical data for demonstration
        $historicalData = [];
        $baseDate = new DateTime();
        $baseDate->sub(new DateInterval('P30D'));
        
        // Generate 30 days of sample data with some variation
        for ($i = 0; $i < 30; $i++) {
            $date = clone $baseDate;
            $date->add(new DateInterval('P' . $i . 'D'));
            
            if ($method === 'straight_line') {
                // For Straight Line Method: Generate average temperature data
                // Simulate realistic temperature patterns (20-35°C range)
                $baseTemp = 27; // Base temperature
                $seasonalVariation = sin(($i / 30) * 2 * pi()) * 3; // Seasonal variation
                $dailyVariation = rand(-2, 2); // Daily random variation
                $avgTemperature = round($baseTemp + $seasonalVariation + $dailyVariation, 1);
                
                $historicalData[] = [
                    'date' => $date->format('Y-m-d'),
                    'avg_temperature' => $avgTemperature
                ];
            } else {
                // For Exponential Smoothing Method: Generate daily records count
                // Simulate realistic weather data collection patterns
                $baseRecords = 12; // Base records per day
                $variation = rand(-3, 5); // Random variation
                $weekendFactor = (in_array($date->format('N'), [6, 7])) ? 0.7 : 1.0; // Less on weekends
                
                $dailyRecords = max(1, round(($baseRecords + $variation) * $weekendFactor));
                
                $historicalData[] = [
                    'date' => $date->format('Y-m-d'),
                    'daily_records' => $dailyRecords
                ];
            }
        }
        
        // Choose forecasting method
        if ($method === 'straight_line') {
            $forecast = $this->calculateStraightLineForecast($historicalData, $days);
        } else {
            $forecast = $this->calculateExponentialSmoothingForecast($historicalData, $days);
        }
        
        return [
            'success' => true,
            'data' => [
                'historical' => $historicalData,
                'forecast' => $forecast,
                'forecast_period' => $days,
                'method_used' => $method,
                'last_updated' => date('Y-m-d H:i:s'),
                'note' => 'Using sample data - database connection not available'
            ]
        ];
    }
    
    /**
     * Calculate Exponential Smoothing Forecast
     * Formula: (0.40 x actual value) + (0.60 x forecasted value) previous period
     * 
     * First time: (0.40 x actual value) + (0.60 x actual value)
     * Second: (0.40 x actual value of the second period) + (0.60 x actual value of the first period)
     * Third: (0.40 x actual value of the 3rd period) + (0.60 x forecasted value of the 2nd period)
     * And so on...
     */
    private function calculateExponentialSmoothingForecast($historicalData, $forecastDays) {
        if (count($historicalData) < 2) {
            // Fallback to simple average if insufficient data
            return $this->calculateSimpleAverageForecast($historicalData, $forecastDays);
        }

        // Extract values
        $values = array_column($historicalData, 'daily_records');
        $n = count($values);
        
        // Exponential smoothing parameters
        $alpha = 0.40; // Weight for actual value
        $beta = 0.60;  // Weight for forecasted value (1 - alpha)
        
        // Initialize smoothed values array
        $smoothed = [];
        $forecasted = [];
        
        // First period: (0.40 x actual value) + (0.60 x actual value)
        $firstForecast = ($alpha * $values[0]) + ($beta * $values[0]);
        $smoothed[0] = $values[0];
        $forecasted[0] = $firstForecast;
        
        // Second period: (0.40 x actual value of the second period) + (0.60 x actual value of the first period)
        if ($n > 1) {
            $secondForecast = ($alpha * $values[1]) + ($beta * $values[0]);
            $smoothed[1] = $values[1];
            $forecasted[1] = $secondForecast;
        }
        
        // Third period onwards: (0.40 x actual value) + (0.60 x forecasted value of previous period)
        for ($i = 2; $i < $n; $i++) {
            $forecastValue = ($alpha * $values[$i]) + ($beta * $forecasted[$i - 1]);
            $smoothed[$i] = $values[$i];
            $forecasted[$i] = $forecastValue;
        }
        
        // Get the last forecasted value for future predictions
        $lastForecasted = $forecasted[$n - 1];
        
        // Generate forecast for future periods
        $forecast = [];
        $lastHistoricalDate = new DateTime($historicalData[$n - 1]['date']);
        
        for ($i = 1; $i <= $forecastDays; $i++) {
            $forecastDate = clone $lastHistoricalDate;
            $forecastDate->add(new DateInterval('P' . $i . 'D'));
            
            // For future periods, use the last forecasted value as the "actual" value
            // Formula: (0.40 x last forecasted) + (0.60 x previous forecasted)
            if ($i == 1) {
                // First future period uses last historical forecasted value
                $predictedValue = ($alpha * $lastForecasted) + ($beta * $lastForecasted);
            } else {
                // Subsequent periods use previous forecasted value
                $prevForecast = $forecast[$i - 2]['predicted_records'];
                $predictedValue = ($alpha * $prevForecast) + ($beta * $prevForecast);
            }
            
            $predictedValue = max(0, round($predictedValue, 2)); // Ensure non-negative
            
            $forecast[] = [
                'date' => $forecastDate->format('Y-m-d'),
                'predicted_records' => $predictedValue,
                'confidence' => $this->calculateConfidence($historicalData, null, null)
            ];
        }
        
        // Determine trend direction based on last few values
        $recentValues = array_slice($values, -7);
        $trend = ($recentValues[count($recentValues) - 1] - $recentValues[0]) / max(1, count($recentValues) - 1);
        $trend_direction = $trend > 0.1 ? 'increasing' : ($trend < -0.1 ? 'decreasing' : 'stable');

        return [
            'forecast_data' => $forecast,
            'method' => 'exponential_smoothing',
            'parameters' => [
                'alpha' => $alpha,
                'beta' => $beta,
                'formula' => '(0.40 x actual) + (0.60 x forecasted_previous)'
            ],
            'trend_direction' => $trend_direction,
            'average_historical' => round(array_sum($values) / $n, 2),
            'last_smoothed_value' => round($lastForecasted, 2)
        ];
    }
    
    /**
     * Calculate Straight Line Forecast with 3 modes
     * Mode 1: Optimistic (upper bound)
     * Mode 2: Realistic (best fit line)
     * Mode 3: Pessimistic (lower bound)
     * Uses Average Temperature as the variable
     */
    private function calculateStraightLineForecast($historicalData, $forecastDays) {
        if (count($historicalData) < 2) {
            return [];
        }
        
        // Calculate trend using simple linear regression
        $n = count($historicalData);
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumXX = 0;
        
        // Convert dates to numeric values (days from first date)
        $firstDate = new DateTime($historicalData[0]['date']);
        
        foreach ($historicalData as $index => $dataPoint) {
            $currentDate = new DateTime($dataPoint['date']);
            $x = $currentDate->diff($firstDate)->days;
            // Use avg_temperature if available, otherwise fallback to daily_records for backward compatibility
            $y = (float)($dataPoint['avg_temperature'] ?? $dataPoint['daily_records'] ?? 0);
            
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }
        
        // Calculate slope (m) and intercept (b) for y = mx + b (Mode 2: Realistic)
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Calculate standard deviation for confidence intervals
        $values = array_column($historicalData, 'avg_temperature');
        // Fallback to daily_records if avg_temperature not available
        if (empty(array_filter($values))) {
            $values = array_column($historicalData, 'daily_records');
        }
        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $stdDev = sqrt($variance / count($values));
        
        // Mode 1: Optimistic (upper bound) - add one standard deviation
        $optimisticSlope = $slope + ($stdDev / $n);
        $optimisticIntercept = $intercept + ($stdDev * 0.5);
        
        // Mode 3: Pessimistic (lower bound) - subtract one standard deviation
        $pessimisticSlope = $slope - ($stdDev / $n);
        $pessimisticIntercept = $intercept - ($stdDev * 0.5);
        
        // Generate forecast for all 3 modes
        $forecast = [];
        $lastHistoricalDate = new DateTime($historicalData[count($historicalData) - 1]['date']);
        
        for ($i = 1; $i <= $forecastDays; $i++) {
            $forecastDate = clone $lastHistoricalDate;
            $forecastDate->add(new DateInterval('P' . $i . 'D'));
            
            $daysFromFirst = $forecastDate->diff($firstDate)->days;
            
            // Mode 2: Realistic (best fit line)
            $realisticValue = round($slope * $daysFromFirst + $intercept, 1);
            
            // Mode 1: Optimistic
            $optimisticValue = round($optimisticSlope * $daysFromFirst + $optimisticIntercept, 1);
            
            // Mode 3: Pessimistic
            $pessimisticValue = round($pessimisticSlope * $daysFromFirst + $pessimisticIntercept, 1);
            
            $forecast[] = [
                'date' => $forecastDate->format('Y-m-d'),
                'predicted_temperature' => $realisticValue, // Temperature forecast
                'predicted_records' => $realisticValue, // For backward compatibility
                'optimistic' => $optimisticValue,
                'realistic' => $realisticValue,
                'pessimistic' => $pessimisticValue,
                'confidence' => $this->calculateConfidence($historicalData, $slope, $intercept)
            ];
        }
        
        return [
            'forecast_data' => $forecast,
            'method' => 'straight_line_3modes',
            'variable' => 'average_temperature',
            'modes' => [
                'mode_1_optimistic' => 'Upper bound forecast',
                'mode_2_realistic' => 'Best fit line forecast',
                'mode_3_pessimistic' => 'Lower bound forecast'
            ],
            'trend_slope' => round($slope, 4),
            'trend_intercept' => round($intercept, 2),
            'trend_direction' => $slope > 0 ? 'increasing' : ($slope < 0 ? 'decreasing' : 'stable'),
            'average_historical' => round($sumY / $n, 2),
            'standard_deviation' => round($stdDev, 2)
        ];
    }

    private function calculateSimpleAverageForecast($historicalData, $forecastDays) {
        $values = array_column($historicalData, 'daily_records');
        $average = array_sum($values) / count($values);

        $forecast = [];
        $lastHistoricalDate = new DateTime($historicalData[count($historicalData) - 1]['date']);

        for ($i = 1; $i <= $forecastDays; $i++) {
            $forecastDate = clone $lastHistoricalDate;
            $forecastDate->add(new DateInterval('P' . $i . 'D'));

            $forecast[] = [
                'date' => $forecastDate->format('Y-m-d'),
                'predicted_records' => round($average),
                'confidence' => 50.0 // Low confidence for simple average
            ];
        }

        return [
            'forecast_data' => $forecast,
            'method' => 'simple_average',
            'trend_direction' => 'stable',
            'average_historical' => round($average, 2)
        ];
    }
    
    private function calculateConfidence($historicalData, $slope, $intercept) {
        // Simple confidence calculation based on data consistency
        // Try avg_temperature first, fallback to daily_records
        $values = array_column($historicalData, 'avg_temperature');
        if (empty(array_filter($values))) {
            $values = array_column($historicalData, 'daily_records');
        }
        
        $mean = array_sum($values) / count($values);
        
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance /= count($values);
        $stdDev = sqrt($variance);
        
        // Confidence decreases with higher standard deviation
        $coefficientOfVariation = $mean > 0 ? ($stdDev / $mean) : 0;
        $confidence = max(0.1, min(0.95, 1 - ($coefficientOfVariation * 0.5)));
        
        return round($confidence * 100, 1);
    }
    
    public function getForecastSummary() {
        try {
            $forecast = $this->getWeatherRecordsForecast(7); // 7-day forecast
            
            if (!$forecast['success']) {
                return $forecast;
            }
            
            $forecastData = $forecast['data']['forecast']['forecast_data'];
            $totalPredicted = array_sum(array_column($forecastData, 'predicted_records'));
            $avgDaily = $totalPredicted / 7;
            
            return [
                'success' => true,
                'summary' => [
                    'next_7_days_total' => $totalPredicted,
                    'average_daily' => round($avgDaily, 1),
                    'trend_direction' => $forecast['data']['forecast']['trend_direction'],
                    'confidence' => $forecastData[0]['confidence'] ?? 0,
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to generate forecast summary: ' . $e->getMessage()
            ];
        }
    }
}

// Handle the request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$forecasting = new ForecastingAPI();

if ($requestMethod === 'GET') {
    $action = $_GET['action'] ?? 'forecast';
    $days = (int)($_GET['days'] ?? 30);
    $method = $_GET['method'] ?? 'exponential_smoothing'; // 'exponential_smoothing' or 'straight_line'
    
    switch ($action) {
        case 'summary':
            $result = $forecasting->getForecastSummary();
            break;
        case 'forecast':
        default:
            $result = $forecasting->getWeatherRecordsForecast($days, $method);
            break;
    }
    
    echo json_encode($result);
}