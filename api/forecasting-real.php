<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
$host = 'localhost';
$db_name = 'crop_alert_system';
$username = 'root';
$password = '';

class ForecastingAPIReal {
    private $conn;
    
    public function __construct() {
        // Try PDO first, fallback to MySQLi
        try {
            $this->conn = new PDO(
                "mysql:host=localhost;dbname=crop_alert_system",
                'root',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback to MySQLi
            $this->conn = new mysqli('localhost', 'root', '', 'crop_alert_system');
            if ($this->conn->connect_error) {
                $this->conn = null;
            }
        }
    }
    
    public function getWeatherRecordsForecast($days = 30) {
        try {
            // Check if database connection is available
            if (!$this->conn) {
                return $this->generateSampleForecast($days);
            }
            
            // Get method parameter (default to straight_line for this API)
            $method = $_GET['method'] ?? 'straight_line';
            
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
            
            if ($this->conn instanceof PDO) {
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                $historicalData = $stmt->fetchAll();
            } else {
                // MySQLi version
                $result = $this->conn->query($query);
                $historicalData = [];
                while ($row = $result->fetch_assoc()) {
                    $historicalData[] = $row;
                }
            }
            
            // If no data, generate sample data
            if (empty($historicalData)) {
                return $this->generateSampleForecast($days);
            }
            
            // Choose forecasting method
            if ($method === 'exponential_smoothing') {
                $forecast = $this->calculateExponentialSmoothingForecast($historicalData, $days);
            } else {
                // Default to straight line
                $forecast = $this->calculateStraightLineForecast($historicalData, $days);
            }
            
            return [
                'success' => true,
                'data' => [
                    'historical' => $historicalData,
                    'forecast' => $forecast,
                    'forecast_period' => $days,
                    'last_updated' => date('Y-m-d H:i:s'),
                    'data_source' => 'real_database'
                ]
            ];
            
        } catch (Exception $e) {
            // Fallback to sample data if database fails
            return $this->generateSampleForecast($days);
        }
    }
    
    private function generateSampleForecast($days) {
        // Generate sample historical data for demonstration
        $historicalData = [];
        $baseDate = new DateTime();
        $baseDate->sub(new DateInterval('P30D'));
        
        // Get method parameter (default to straight_line for this API)
        $method = $_GET['method'] ?? 'straight_line';
        
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
        if ($method === 'exponential_smoothing') {
            $forecast = $this->calculateExponentialSmoothingForecast($historicalData, $days);
        } else {
            $forecast = $this->calculateStraightLineForecast($historicalData, $days);
        }
        
        return [
            'success' => true,
            'data' => [
                'historical' => $historicalData,
                'forecast' => $forecast,
                'forecast_period' => $days,
                'last_updated' => date('Y-m-d H:i:s'),
                'data_source' => 'sample_data',
                'note' => 'Using sample data - database connection not available'
            ]
        ];
    }
    
    /**
     * Calculate Exponential Smoothing Forecast
     * Formula: (0.40 x actual value) + (0.60 x forecasted value) previous period
     */
    private function calculateExponentialSmoothingForecast($historicalData, $forecastDays) {
        if (count($historicalData) < 2) {
            return [];
        }

        // Extract values
        $values = array_column($historicalData, 'daily_records');
        $n = count($values);
        
        // Exponential smoothing parameters
        $alpha = 0.40; // Weight for actual value
        $beta = 0.60;  // Weight for forecasted value (1 - alpha)
        
        // Initialize smoothed values array
        $forecasted = [];
        
        // First period: (0.40 x actual value) + (0.60 x actual value)
        $forecasted[0] = ($alpha * $values[0]) + ($beta * $values[0]);
        
        // Second period: (0.40 x actual value of the second period) + (0.60 x actual value of the first period)
        if ($n > 1) {
            $forecasted[1] = ($alpha * $values[1]) + ($beta * $values[0]);
        }
        
        // Third period onwards: (0.40 x actual value) + (0.60 x forecasted value of previous period)
        for ($i = 2; $i < $n; $i++) {
            $forecasted[$i] = ($alpha * $values[$i]) + ($beta * $forecasted[$i - 1]);
        }
        
        // Get the last forecasted value for future predictions
        $lastForecasted = $forecasted[$n - 1];
        
        // Generate forecast for future periods
        $forecast = [];
        $lastHistoricalDate = new DateTime($historicalData[$n - 1]['date']);
        
        for ($i = 1; $i <= $forecastDays; $i++) {
            $forecastDate = clone $lastHistoricalDate;
            $forecastDate->add(new DateInterval('P' . $i . 'D'));
            
            // For future periods, use the last forecasted value
            if ($i == 1) {
                $predictedValue = ($alpha * $lastForecasted) + ($beta * $lastForecasted);
            } else {
                $prevForecast = $forecast[$i - 2]['predicted_records'];
                $predictedValue = ($alpha * $prevForecast) + ($beta * $prevForecast);
            }
            
            $predictedValue = max(0, round($predictedValue, 2));
            
            $forecast[] = [
                'date' => $forecastDate->format('Y-m-d'),
                'predicted_records' => $predictedValue,
                'confidence' => $this->calculateConfidence($historicalData, null, null)
            ];
        }
        
        // Determine trend direction
        $recentValues = array_slice($values, -7);
        $trend = count($recentValues) > 1 ? ($recentValues[count($recentValues) - 1] - $recentValues[0]) / (count($recentValues) - 1) : 0;
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
            'average_historical' => round(array_sum($values) / $n, 2)
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
        
        // Calculate slope (m) and intercept (b) for y = mx + b
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
    
    public function addSampleWeatherData() {
        try {
            if (!$this->conn) {
                return ['success' => false, 'message' => 'No database connection'];
            }
            
            // Add sample weather data for the last 30 days
            $baseDate = new DateTime();
            $baseDate->sub(new DateInterval('P30D'));
            
            $added = 0;
            for ($i = 0; $i < 30; $i++) {
                $date = clone $baseDate;
                $date->add(new DateInterval('P' . $i . 'D'));
                
                // Generate 8-15 records per day
                $recordsPerDay = rand(8, 15);
                
                for ($j = 0; $j < $recordsPerDay; $j++) {
                    $recordTime = clone $date;
                    $recordTime->add(new DateInterval('PT' . rand(0, 23) . 'H' . rand(0, 59) . 'M'));
                    
                    $temperature = rand(20, 35) + (rand(0, 99) / 100);
                    $humidity = rand(40, 90);
                    $rainfall = rand(0, 50) / 10;
                    $windSpeed = rand(5, 25) + (rand(0, 99) / 100);
                    $conditions = ['Sunny', 'Partly Cloudy', 'Cloudy', 'Rainy', 'Stormy'][rand(0, 4)];
                    
                    if ($this->conn instanceof PDO) {
                        $query = "INSERT INTO weather_data (temperature, humidity, rainfall, wind_speed, `condition`, recorded_at) 
                                  VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $this->conn->prepare($query);
                        $stmt->execute([$temperature, $humidity, $rainfall, $windSpeed, $conditions, $recordTime->format('Y-m-d H:i:s')]);
                    } else {
                        $query = "INSERT INTO weather_data (temperature, humidity, rainfall, wind_speed, `condition`, recorded_at) 
                                  VALUES ('$temperature', '$humidity', '$rainfall', '$windSpeed', '$conditions', '{$recordTime->format('Y-m-d H:i:s')}')";
                        $this->conn->query($query);
                    }
                    $added++;
                }
            }
            
            return [
                'success' => true,
                'message' => "Added $added weather records for the last 30 days",
                'records_added' => $added
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to add sample data: ' . $e->getMessage()
            ];
        }
    }
}

// Handle the request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$forecasting = new ForecastingAPIReal();

if ($requestMethod === 'GET') {
    $action = $_GET['action'] ?? 'forecast';
    $days = (int)($_GET['days'] ?? 30);
    
    switch ($action) {
        case 'add_sample_data':
            $result = $forecasting->addSampleWeatherData();
            break;
        case 'forecast':
        default:
            $result = $forecasting->getWeatherRecordsForecast($days);
            break;
    }
    
    echo json_encode($result);
}
?>
