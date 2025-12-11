<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class CropRecommendationML {
    private $conn;
    private $useMLModel = true; // Enable ML model by default
    private $mlModelPath;
    private $pythonCommand;
    private $mlError = null; // Store ML errors for debugging
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        // Set ML model path
        $this->mlModelPath = realpath(__DIR__ . '/../ml/api_bridge.py');
        if (!$this->mlModelPath) {
            // Try alternative path
            $this->mlModelPath = __DIR__ . '/../ml/api_bridge.py';
        }
        
        // Detect Python command (Windows compatibility)
        $this->pythonCommand = $this->detectPythonCommand();
    }
    
    private function detectPythonCommand() {
        // Try common Python commands
        $commands = ['python', 'py', 'python3'];
        
        foreach ($commands as $cmd) {
            $test = shell_exec("$cmd --version 2>&1");
            if ($test && strpos($test, 'Python') !== false) {
                return $cmd;
            }
        }
        
        // Default fallback
        return 'python';
    }
    
    // Crop database with ML features
    private $cropDatabase = [
        'rice' => [
            'name' => 'Rice',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 70,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2500,
            'optimal_wind_max' => 15,
            'season' => ['wet', 'dry'],
            'soil_type' => ['clay', 'loam'],
            'ph_min' => 5.5,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 120,
            'yield_potential' => 'high',
            'market_demand' => 'very_high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'high'
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
            'season' => ['dry', 'wet'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.5,
            'sunlight_hours' => 10,
            'growth_days' => 90,
            'yield_potential' => 'high',
            'market_demand' => 'high',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
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
            'season' => ['dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 6.8,
            'sunlight_hours' => 8,
            'growth_days' => 75,
            'yield_potential' => 'medium',
            'market_demand' => 'high',
            'disease_resistance' => 'low',
            'water_requirement' => 'medium'
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
            'season' => ['dry', 'wet'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 5.5,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 100,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'medium',
            'water_requirement' => 'medium'
        ],
        'okra' => [
            'name' => 'Okra',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 85,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1200,
            'optimal_wind_max' => 20,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.5,
            'sunlight_hours' => 8,
            'growth_days' => 60,
            'yield_potential' => 'high',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'squash' => [
            'name' => 'Squash',
            'optimal_temp_min' => 18,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 1000,
            'optimal_wind_max' => 15,
            'season' => ['dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 90,
            'yield_potential' => 'high',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'pepper' => [
            'name' => 'Pepper',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 40,
            'optimal_humidity_max' => 70,
            'optimal_rainfall_min' => 300,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 15,
            'season' => ['dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 80,
            'yield_potential' => 'medium',
            'market_demand' => 'high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'low'
        ],
        'cabbage' => [
            'name' => 'Cabbage',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 85,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'season' => ['cool'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 90,
            'yield_potential' => 'high',
            'market_demand' => 'high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'high'
        ],
        'sweet_potato' => [
            'name' => 'Sweet Potato',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 600,
            'optimal_rainfall_max' => 1200,
            'optimal_wind_max' => 15,
            'season' => ['wet', 'dry'],
            'soil_type' => ['sandy_loam', 'loam'],
            'ph_min' => 5.5,
            'ph_max' => 6.5,
            'sunlight_hours' => 8,
            'growth_days' => 120,
            'yield_potential' => 'high',
            'market_demand' => 'high',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'cassava' => [
            'name' => 'Cassava',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1500,
            'optimal_wind_max' => 20,
            'season' => ['wet', 'dry'],
            'soil_type' => ['sandy_loam', 'loam'],
            'ph_min' => 5.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 300,
            'yield_potential' => 'very_high',
            'market_demand' => 'medium',
            'disease_resistance' => 'very_high',
            'water_requirement' => 'low'
        ],
        'mango' => [
            'name' => 'Mango',
            'optimal_temp_min' => 24,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2000,
            'optimal_wind_max' => 15,
            'season' => ['dry', 'wet'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.5,
            'sunlight_hours' => 8,
            'growth_days' => 365,
            'yield_potential' => 'high',
            'market_demand' => 'very_high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'medium'
        ],
        'banana' => [
            'name' => 'Banana',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1200,
            'optimal_rainfall_max' => 2500,
            'optimal_wind_max' => 10,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 5.5,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 365,
            'yield_potential' => 'very_high',
            'market_demand' => 'very_high',
            'disease_resistance' => 'low',
            'water_requirement' => 'high'
        ],
        'coconut' => [
            'name' => 'Coconut',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 32,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 3000,
            'optimal_wind_max' => 25,
            'season' => ['wet', 'dry'],
            'soil_type' => ['sandy_loam', 'loam'],
            'ph_min' => 5.0,
            'ph_max' => 8.0,
            'sunlight_hours' => 8,
            'growth_days' => 2555,
            'yield_potential' => 'high',
            'market_demand' => 'very_high',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'pineapple' => [
            'name' => 'Pineapple',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 600,
            'optimal_rainfall_max' => 1500,
            'optimal_wind_max' => 15,
            'season' => ['dry', 'wet'],
            'soil_type' => ['sandy_loam', 'loam'],
            'ph_min' => 4.5,
            'ph_max' => 6.5,
            'sunlight_hours' => 8,
            'growth_days' => 540,
            'yield_potential' => 'high',
            'market_demand' => 'high',
            'disease_resistance' => 'high',
            'water_requirement' => 'low'
        ],
        'papaya' => [
            'name' => 'Papaya',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2000,
            'optimal_wind_max' => 15,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 365,
            'yield_potential' => 'high',
            'market_demand' => 'high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'medium'
        ],
        'watermelon' => [
            'name' => 'Watermelon',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 70,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 15,
            'season' => ['dry'],
            'soil_type' => ['sandy_loam', 'loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 90,
            'yield_potential' => 'high',
            'market_demand' => 'high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'high'
        ],
        'cucumber' => [
            'name' => 'Cucumber',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'season' => ['dry', 'wet'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 60,
            'yield_potential' => 'high',
            'market_demand' => 'medium',
            'disease_resistance' => 'medium',
            'water_requirement' => 'high'
        ],
        'string_beans' => [
            'name' => 'String Beans',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1000,
            'optimal_wind_max' => 15,
            'season' => ['dry', 'wet'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 60,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'bitter_gourd' => [
            'name' => 'Bitter Gourd',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 85,
            'optimal_rainfall_min' => 600,
            'optimal_rainfall_max' => 1200,
            'optimal_wind_max' => 15,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 90,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'ampalaya' => [
            'name' => 'Ampalaya',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 85,
            'optimal_rainfall_min' => 600,
            'optimal_rainfall_max' => 1200,
            'optimal_wind_max' => 15,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 90,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'radish' => [
            'name' => 'Radish',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'season' => ['cool', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 30,
            'yield_potential' => 'high',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'carrot' => [
            'name' => 'Carrot',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 500,
            'optimal_rainfall_max' => 1000,
            'optimal_wind_max' => 10,
            'season' => ['cool', 'dry'],
            'soil_type' => ['sandy_loam', 'loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 75,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'medium',
            'water_requirement' => 'medium'
        ],
        'lettuce' => [
            'name' => 'Lettuce',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'season' => ['cool', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 45,
            'yield_potential' => 'medium',
            'market_demand' => 'high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'high'
        ],
        'spinach' => [
            'name' => 'Spinach',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 10,
            'season' => ['cool', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 40,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'medium',
            'water_requirement' => 'high'
        ],
        'onion' => [
            'name' => 'Onion',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 70,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 15,
            'season' => ['cool', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 120,
            'yield_potential' => 'medium',
            'market_demand' => 'very_high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'medium'
        ],
        'garlic' => [
            'name' => 'Garlic',
            'optimal_temp_min' => 15,
            'optimal_temp_max' => 25,
            'optimal_humidity_min' => 50,
            'optimal_humidity_max' => 70,
            'optimal_rainfall_min' => 400,
            'optimal_rainfall_max' => 800,
            'optimal_wind_max' => 15,
            'season' => ['cool', 'dry'],
            'soil_type' => ['loam', 'sandy_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 8,
            'growth_days' => 150,
            'yield_potential' => 'medium',
            'market_demand' => 'very_high',
            'disease_resistance' => 'high',
            'water_requirement' => 'medium'
        ],
        'ginger' => [
            'name' => 'Ginger',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 70,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2000,
            'optimal_wind_max' => 10,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 5.5,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 240,
            'yield_potential' => 'medium',
            'market_demand' => 'high',
            'disease_resistance' => 'high',
            'water_requirement' => 'high'
        ],
        'turmeric' => [
            'name' => 'Turmeric',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 70,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2000,
            'optimal_wind_max' => 10,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 5.5,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 270,
            'yield_potential' => 'medium',
            'market_demand' => 'medium',
            'disease_resistance' => 'high',
            'water_requirement' => 'high'
        ],
        'coffee' => [
            'name' => 'Coffee',
            'optimal_temp_min' => 18,
            'optimal_temp_max' => 24,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 1200,
            'optimal_rainfall_max' => 2000,
            'optimal_wind_max' => 10,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 6.0,
            'ph_max' => 6.5,
            'sunlight_hours' => 6,
            'growth_days' => 1095,
            'yield_potential' => 'medium',
            'market_demand' => 'very_high',
            'disease_resistance' => 'medium',
            'water_requirement' => 'medium'
        ],
        'cacao' => [
            'name' => 'Cacao',
            'optimal_temp_min' => 20,
            'optimal_temp_max' => 30,
            'optimal_humidity_min' => 70,
            'optimal_humidity_max' => 90,
            'optimal_rainfall_min' => 1200,
            'optimal_rainfall_max' => 2500,
            'optimal_wind_max' => 10,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.0,
            'sunlight_hours' => 6,
            'growth_days' => 1095,
            'yield_potential' => 'medium',
            'market_demand' => 'high',
            'disease_resistance' => 'low',
            'water_requirement' => 'high'
        ],
        'sugarcane' => [
            'name' => 'Sugarcane',
            'optimal_temp_min' => 25,
            'optimal_temp_max' => 35,
            'optimal_humidity_min' => 60,
            'optimal_humidity_max' => 80,
            'optimal_rainfall_min' => 1000,
            'optimal_rainfall_max' => 2000,
            'optimal_wind_max' => 20,
            'season' => ['wet', 'dry'],
            'soil_type' => ['loam', 'clay_loam'],
            'ph_min' => 6.0,
            'ph_max' => 7.5,
            'sunlight_hours' => 8,
            'growth_days' => 365,
            'yield_potential' => 'very_high',
            'market_demand' => 'high',
            'disease_resistance' => 'high',
            'water_requirement' => 'high'
        ]
    ];
    
    
    public function getRecommendations($weatherData = null, $soilData = null, $season = null, $useML = true) {
        try {
            // Check database connection
            if (!$this->conn) {
                throw new Exception('Database connection not available');
            }
            
            // Get current weather data if not provided
            if (!$weatherData) {
                $weatherData = $this->getCurrentWeatherData();
            }
            
            // Get current season if not provided
            if (!$season) {
                $season = $this->getCurrentSeason();
            }
            
            // Default soil data if not provided
            if (!$soilData) {
                $soilData = $this->getDefaultSoilData();
            }
            
            // Try to use ML model first if enabled
            $mlPredictions = null;
            $mlError = null;
            if ($useML && $this->useMLModel) {
                $mlPredictions = $this->getMLPredictions($weatherData, $soilData);
                $mlError = $this->mlError; // Get any error that occurred
            }
            
            // Calculate scores for each crop
            $recommendations = [];
            foreach ($this->cropDatabase as $cropId => $crop) {
                // Get rule-based score
                $ruleScore = $this->calculateMLScore($crop, $weatherData, $soilData, $season);
                
                // Get ML confidence if available
                $mlConfidence = null;
                if ($mlPredictions) {
                    $mlConfidence = $this->getMLConfidenceForCrop($cropId, $mlPredictions);
                }
                
                // Combine scores: 70% ML, 30% rule-based (if ML available)
                if ($mlConfidence !== null) {
                    $score = ($mlConfidence * 0.7) + ($ruleScore * 0.3);
                    $prediction_method = 'ml_enhanced';
                } else {
                    $score = $ruleScore;
                    $prediction_method = 'rule_based';
                }
                
                $recommendations[] = [
                    'crop_id' => $cropId,
                    'crop_name' => $crop['name'],
                    'score' => round($score, 2),
                    'ml_confidence' => $mlConfidence,
                    'rule_score' => round($ruleScore, 2),
                    'prediction_method' => $prediction_method,
                    'suitability' => $this->getSuitabilityLevel($score),
                    'reasons' => $this->getRecommendationReasons($crop, $weatherData, $soilData, $season),
                    'growth_info' => [
                        'growth_days' => $crop['growth_days'],
                        'yield_potential' => $crop['yield_potential'],
                        'market_demand' => $crop['market_demand'],
                        'water_requirement' => $crop['water_requirement']
                    ]
                ];
            }
            
            // Sort by score (highest first)
            usort($recommendations, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            
            return [
                'success' => true,
                'recommendations' => $recommendations,
                'weather_conditions' => $weatherData,
                'season' => $season,
                'soil_data' => $soilData,
                'ml_enabled' => $mlPredictions !== null,
                'ml_error' => $mlError, // Include ML error for debugging
                'python_command' => $this->pythonCommand, // Show which Python command is used
                'top_ml_predictions' => $mlPredictions ? array_slice($mlPredictions, 0, 5) : null,
                'explanation' => $this->getExplanation($recommendations, $mlPredictions)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to generate recommendations: ' . $e->getMessage()
            ];
        }
    }
    
    private function getMLPredictions($weatherData, $soilData) {
        try {
            // Check if Python script exists
            if (!file_exists($this->mlModelPath)) {
                error_log("ML model script not found: " . $this->mlModelPath);
                return null;
            }
            
            // Prepare input data for ML model
            // Estimate N, P, K from soil data if not provided
            $N = $soilData['N'] ?? $this->estimateNutrient('N', $soilData);
            $P = $soilData['P'] ?? $this->estimateNutrient('P', $soilData);
            $K = $soilData['K'] ?? $this->estimateNutrient('K', $soilData);
            
            $mlInput = [
                'N' => $N,
                'P' => $P,
                'K' => $K,
                'temperature' => $weatherData['temperature'],
                'humidity' => $weatherData['humidity'],
                'ph' => $soilData['ph'],
                'rainfall' => $weatherData['rainfall'],
                'include_importance' => false
            ];
            
            // Call Python ML model
            $jsonInput = json_encode($mlInput);
            $command = $this->pythonCommand . ' "' . $this->mlModelPath . '" 2>&1';
            
            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w']   // stderr
            ];
            
            $process = proc_open($command, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                // Write input
                fwrite($pipes[0], $jsonInput);
                fclose($pipes[0]);
                
                // Read output
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                $returnValue = proc_close($process);
                
                if ($returnValue === 0 && !empty($output)) {
                    $mlResult = json_decode($output, true);
                    if ($mlResult && isset($mlResult['success'])) {
                        if ($mlResult['success']) {
                            return $mlResult['predictions'];
                        } else {
                            $errorMsg = $mlResult['error'] ?? 'Unknown error';
                            $this->mlError = $errorMsg;
                            error_log("ML model error: " . $errorMsg);
                        }
                    } else {
                        $errorMsg = "Invalid JSON response from ML model";
                        $this->mlError = $errorMsg . (empty($output) ? " (empty output)" : " (output: " . substr($output, 0, 100) . ")");
                        error_log("ML model error: " . $this->mlError);
                    }
                } else {
                    $errorMsg = "Python execution failed (return code: $returnValue)";
                    if (!empty($errors)) {
                        $errorMsg .= " - " . trim($errors);
                    }
                    $this->mlError = $errorMsg;
                    error_log("ML model execution error: " . $errorMsg);
                }
            } else {
                $this->mlError = "Failed to open Python process";
                error_log("ML model error: Failed to open process");
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->mlError = "Exception: " . $e->getMessage();
            error_log("Error calling ML model: " . $e->getMessage());
            return null;
        }
    }
    
    private function getMLConfidenceForCrop($cropId, $mlPredictions) {
        // Normalize database crop ID
        $dbCrop = strtolower(str_replace([' ', '_', '-'], '', $cropId));
        
        foreach ($mlPredictions as $prediction) {
            // Normalize ML crop name (handle variations)
            $mlCrop = strtolower(str_replace([' ', '_', '-'], '', $prediction['crop']));
            
            // Exact match
            if ($mlCrop === $dbCrop) {
                return $prediction['confidence'];
            }
            
            // Partial match (check if one contains the other)
            if (strpos($mlCrop, $dbCrop) !== false || strpos($dbCrop, $mlCrop) !== false) {
                return $prediction['confidence'];
            }
            
            // Handle common variations
            $variations = [
                'sweet_potato' => ['sweetpotato', 'sweet-potato'],
                'string_beans' => ['stringbeans', 'string-beans', 'beans'],
                'bitter_gourd' => ['bittergourd', 'bitter-gourd'],
                'sweet_potato' => ['sweetpotato']
            ];
            
            if (isset($variations[$cropId])) {
                foreach ($variations[$cropId] as $variant) {
                    if ($mlCrop === strtolower(str_replace([' ', '_', '-'], '', $variant))) {
                        return $prediction['confidence'];
                    }
                }
            }
        }
        return null;
    }
    
    private function estimateNutrient($nutrient, $soilData) {
        // Estimate nutrient levels based on soil type and pH
        // These are rough estimates - in production, use actual soil test data
        $baseValues = [
            'N' => 70,
            'P' => 40,
            'K' => 50
        ];
        
        $ph = $soilData['ph'] ?? 6.5;
        $nutrients = $soilData['nutrients'] ?? 'medium';
        
        // Adjust based on pH
        if ($ph < 6.0) {
            $baseValues['P'] *= 0.8; // Lower P availability in acidic soil
        } elseif ($ph > 7.5) {
            $baseValues['P'] *= 0.7; // Lower P availability in alkaline soil
        }
        
        // Adjust based on nutrient level
        $multipliers = [
            'low' => 0.6,
            'medium' => 1.0,
            'high' => 1.4
        ];
        
        $multiplier = $multipliers[$nutrients] ?? 1.0;
        
        return $baseValues[$nutrient] * $multiplier;
    }
    
    private function calculateMLScore($crop, $weather, $soil, $season) {
        $score = 0;
        $maxScore = 100;
        
        // Temperature score (25 points)
        $tempScore = $this->calculateTemperatureScore($crop, $weather['temperature']);
        $score += $tempScore * 0.25;
        
        // Humidity score (20 points)
        $humidityScore = $this->calculateHumidityScore($crop, $weather['humidity']);
        $score += $humidityScore * 0.20;
        
        // Rainfall score (20 points)
        $rainfallScore = $this->calculateRainfallScore($crop, $weather['rainfall']);
        $score += $rainfallScore * 0.20;
        
        // Wind score (10 points)
        $windScore = $this->calculateWindScore($crop, $weather['wind_speed']);
        $score += $windScore * 0.10;
        
        // Season score (15 points)
        $seasonScore = $this->calculateSeasonScore($crop, $season);
        $score += $seasonScore * 0.15;
        
        // Soil score (10 points)
        $soilScore = $this->calculateSoilScore($crop, $soil);
        $score += $soilScore * 0.10;
        
        return min(100, max(0, $score));
    }
    
    private function calculateTemperatureScore($crop, $temperature) {
        $min = $crop['optimal_temp_min'];
        $max = $crop['optimal_temp_max'];
        
        if ($temperature >= $min && $temperature <= $max) {
            return 100; // Perfect temperature
        } elseif ($temperature < $min) {
            $diff = $min - $temperature;
            return max(0, 100 - ($diff * 5)); // Penalty for being too cold
        } else {
            $diff = $temperature - $max;
            return max(0, 100 - ($diff * 5)); // Penalty for being too hot
        }
    }
    
    private function calculateHumidityScore($crop, $humidity) {
        $min = $crop['optimal_humidity_min'];
        $max = $crop['optimal_humidity_max'];
        
        if ($humidity >= $min && $humidity <= $max) {
            return 100;
        } elseif ($humidity < $min) {
            $diff = $min - $humidity;
            return max(0, 100 - ($diff * 2));
        } else {
            $diff = $humidity - $max;
            return max(0, 100 - ($diff * 2));
        }
    }
    
    private function calculateRainfallScore($crop, $rainfall) {
        $min = $crop['optimal_rainfall_min'];
        $max = $crop['optimal_rainfall_max'];
        
        if ($rainfall >= $min && $rainfall <= $max) {
            return 100;
        } elseif ($rainfall < $min) {
            $diff = $min - $rainfall;
            return max(0, 100 - ($diff / 10));
        } else {
            $diff = $rainfall - $max;
            return max(0, 100 - ($diff / 20));
        }
    }
    
    private function calculateWindScore($crop, $windSpeed) {
        $max = $crop['optimal_wind_max'];
        
        if ($windSpeed <= $max) {
            return 100;
        } else {
            $diff = $windSpeed - $max;
            return max(0, 100 - ($diff * 3));
        }
    }
    
    private function calculateSeasonScore($crop, $season) {
        if (in_array($season, $crop['season'])) {
            return 100;
        } else {
            return 50; // Partial score for non-optimal seasons
        }
    }
    
    private function calculateSoilScore($crop, $soil) {
        if (in_array($soil['type'], $crop['soil_type'])) {
            $phScore = 100;
            if ($soil['ph'] >= $crop['ph_min'] && $soil['ph'] <= $crop['ph_max']) {
                $phScore = 100;
            } else {
                $phScore = 70; // Partial score for non-optimal pH
            }
            return $phScore;
        } else {
            return 30; // Low score for unsuitable soil type
        }
    }
    
    private function getSuitabilityLevel($score) {
        if ($score >= 80) return 'Excellent';
        if ($score >= 60) return 'Good';
        if ($score >= 40) return 'Fair';
        if ($score >= 20) return 'Poor';
        return 'Not Recommended';
    }
    
    private function getRecommendationReasons($crop, $weather, $soil, $season) {
        $reasons = [];
        
        // Temperature reason
        if ($weather['temperature'] >= $crop['optimal_temp_min'] && $weather['temperature'] <= $crop['optimal_temp_max']) {
            $reasons[] = "Temperature is optimal for " . $crop['name'];
        } elseif ($weather['temperature'] < $crop['optimal_temp_min']) {
            $reasons[] = "Temperature is too low for " . $crop['name'] . " (needs " . $crop['optimal_temp_min'] . "°C+)";
        } else {
            $reasons[] = "Temperature is too high for " . $crop['name'] . " (max " . $crop['optimal_temp_max'] . "°C)";
        }
        
        // Season reason
        if (in_array($season, $crop['season'])) {
            $reasons[] = "Current season is ideal for " . $crop['name'];
        } else {
            $reasons[] = "Not the optimal season for " . $crop['name'];
        }
        
        // Market demand
        if ($crop['market_demand'] === 'very_high') {
            $reasons[] = "High market demand and good profitability";
        } elseif ($crop['market_demand'] === 'high') {
            $reasons[] = "Good market demand";
        }
        
        return $reasons;
    }
    
    private function getCurrentWeatherData() {
        try {
            if (!$this->conn) {
                throw new Exception('Database connection not available');
            }
            
            $query = "SELECT * FROM weather_data ORDER BY recorded_at DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $weather = $stmt->fetch();
            
            if ($weather) {
                return [
                    'temperature' => $weather['temperature'],
                    'humidity' => $weather['humidity'],
                    'rainfall' => $weather['rainfall'],
                    'wind_speed' => $weather['wind_speed']
                ];
            }
        } catch (Exception $e) {
            error_log("Error getting weather data: " . $e->getMessage());
        }
        
        // Default weather data (Manila, Philippines typical values)
        return [
            'temperature' => 28,
            'humidity' => 75,
            'rainfall' => 100,
            'wind_speed' => 10
        ];
    }
    
    private function getCurrentSeason() {
        $month = date('n');
        
        // Philippine seasons
        if ($month >= 3 && $month <= 5) {
            return 'dry'; // Summer
        } elseif ($month >= 6 && $month <= 11) {
            return 'wet'; // Rainy season
        } else {
            return 'cool'; // Cool dry season
        }
    }
    
    private function getDefaultSoilData() {
        return [
            'type' => 'loam',
            'ph' => 6.5,
            'moisture' => 'medium',
            'nutrients' => 'medium'
        ];
    }
    
    private function getExplanation($recommendations, $mlPredictions) {
        $explanation = [
            'method' => $mlPredictions ? 'Machine Learning Enhanced' : 'Rule-Based Scoring',
            'top_recommendation' => null,
            'key_factors' => []
        ];
        
        if (!empty($recommendations)) {
            $top = $recommendations[0];
            $explanation['top_recommendation'] = [
                'crop' => $top['crop_name'],
                'score' => $top['score'],
                'method' => $top['prediction_method']
            ];
            
            // Extract key factors
            if ($top['ml_confidence'] !== null) {
                $explanation['key_factors'][] = "ML Model Confidence: " . round($top['ml_confidence'], 2) . "%";
            }
            if ($top['rule_score'] !== null) {
                $explanation['key_factors'][] = "Rule-Based Score: " . round($top['rule_score'], 2) . "%";
            }
            
            // Add top reasons
            if (!empty($top['reasons'])) {
                $explanation['key_factors'] = array_merge($explanation['key_factors'], array_slice($top['reasons'], 0, 3));
            }
        }
        
        return $explanation;
    }
}

// Handle the request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'GET') {
    $cropML = new CropRecommendationML();
    $result = $cropML->getRecommendations();
    echo json_encode($result);
} elseif ($requestMethod === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $cropML = new CropRecommendationML();
    
    $weatherData = $input['weather'] ?? null;
    $soilData = $input['soil'] ?? null;
    $season = $input['season'] ?? null;
    
    $result = $cropML->getRecommendations($weatherData, $soilData, $season);
    echo json_encode($result);
}