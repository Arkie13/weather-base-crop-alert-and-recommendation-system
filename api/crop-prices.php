<?php
/**
 * Crop Prices API Integration - Multi-Source with Fallback
 * Fetches daily crop prices from multiple external APIs for Market Timing Optimization
 * 
 * Supported APIs (with automatic fallback):
 * 1. FinanceFlowAPI (Primary - 20 requests/min free tier)
 * 2. Twelve Data (Secondary - free tier available)
 * 3. Commodities-API (Tertiary - 100 requests/month free tier)
 * 4. USDA My Market News (Historical data - free)
 * 
 * Usage:
 * GET api/crop-prices.php?action=current&crop=rice
 * GET api/crop-prices.php?action=historical&crop=rice&days=30
 * GET api/crop-prices.php?action=update_all
 */

// Only set headers and start session if this file is accessed directly (not included)
if (basename($_SERVER['PHP_SELF']) === 'crop-prices.php') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Start session for user authentication (if needed)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

require_once __DIR__ . '/../config/database.php';

class CropPricesAPI {
    private $conn;
    
    // API Configuration - Multiple Sources (Truly Free APIs)
    private $commoditiesApiKey = '';
    private $commoditiesApiUrl = 'https://api.commodities-api.com/';
    
    private $alphaVantageApiKey = '';
    private $alphaVantageApiUrl = 'https://www.alphavantage.co/query';
    
    private $twelveDataApiKey = '';
    private $twelveDataApiUrl = 'https://api.twelvedata.com/';
    
    private $usdaApiKey = '';
    private $usdaApiUrl = 'https://mymarketnews.ams.usda.gov/api/v1/';
    
    // API Ninjas - FREE (No API key required for basic usage, or very generous free tier)
    private $apiNinjasUrl = 'https://api.api-ninjas.com/v1/commodityprice';
    private $apiNinjasKey = ''; // Optional - can work without key for basic usage
    
    // Crop name mapping for different APIs
    private $cropMapping = [
        // Commodities-API mapping
        'commodities' => [
            'Rice' => 'RICE',
            'Corn' => 'CORN',
            'Wheat' => 'WHEAT',
            'Soybeans' => 'SOYBEAN',
            'Tomato' => null,
            'Eggplant' => null,
            'Okra' => null,
            'Squash' => null,
            'Pepper' => null,
            'Cabbage' => null
        ],
        // Alpha Vantage mapping (uses commodity symbols)
        'alphavantage' => [
            'Rice' => 'RICE',
            'Corn' => 'CORN',
            'Wheat' => 'WHEAT',
            'Soybeans' => 'SOYBEAN',
            'Tomato' => null,
            'Eggplant' => null,
            'Okra' => null,
            'Squash' => null,
            'Pepper' => null,
            'Cabbage' => null
        ],
        // Twelve Data mapping
        'twelvedata' => [
            'Rice' => 'RICE',
            'Corn' => 'CORN',
            'Wheat' => 'WHEAT',
            'Soybeans' => 'SOYBEAN',
            'Tomato' => null,
            'Eggplant' => null,
            'Okra' => null,
            'Squash' => null,
            'Pepper' => null,
            'Cabbage' => null
        ]
    ];
    
    // Default prices (PHP per kg) - realistic Philippine market prices
    private $defaultPrices = [
        'Rice' => 25.50,
        'Corn' => 18.50,
        'Tomato' => 35.00,
        'Eggplant' => 28.00,
        'Okra' => 30.00,
        'Squash' => 25.00,
        'Pepper' => 40.00,
        'Cabbage' => 22.00,
        'Wheat' => 20.00,
        'Soybeans' => 30.00,
        // Additional crops with realistic prices
        'Onion' => 45.00,
        'Garlic' => 120.00,
        'Potato' => 35.00,
        'Carrot' => 40.00,
        'Cucumber' => 30.00,
        'String Beans' => 35.00,
        'Ampalaya' => 40.00,
        'Pechay' => 25.00,
        'Kangkong' => 20.00,
        'Lettuce' => 50.00
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        // Load API keys from config if available
        if (defined('CROP_PRICE_API_KEY')) {
            $this->commoditiesApiKey = CROP_PRICE_API_KEY;
        }
        if (defined('ALPHAVANTAGE_API_KEY')) {
            $this->alphaVantageApiKey = ALPHAVANTAGE_API_KEY;
        }
        if (defined('TWELVEDATA_API_KEY')) {
            $this->twelveDataApiKey = TWELVEDATA_API_KEY;
        }
        if (defined('USDA_API_KEY')) {
            $this->usdaApiKey = USDA_API_KEY;
        }
        if (defined('API_NINJAS_KEY')) {
            $this->apiNinjasKey = API_NINJAS_KEY;
        }
    }
    
    /**
     * Normalize crop name to standard format (capitalize first letter, rest lowercase)
     * This ensures consistent matching across the system
     */
    private function normalizeCropName($cropName) {
        if (empty($cropName)) {
            return $cropName;
        }
        // Capitalize first letter, rest lowercase
        return ucfirst(strtolower(trim($cropName)));
    }
    
    /**
     * Get current price for a crop
     */
    public function getCurrentPrice($cropName, $location = 'Manila') {
        try {
            // Normalize crop name to ensure consistent matching
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            // First, try to get from database (most recent)
            $dbPrice = $this->getPriceFromDatabase($cropName, $location);
            if ($dbPrice && $this->isPriceRecent($dbPrice['date'])) {
                error_log("Crop price from database (recent): {$cropName} = {$dbPrice['price_per_kg']} PHP/kg (date: {$dbPrice['date']})");
                return [
                    'success' => true,
                    'crop' => $cropName,
                    'location' => $location,
                    'price_per_kg' => (float)$dbPrice['price_per_kg'],
                    'date' => $dbPrice['date'],
                    'source' => $dbPrice['source'] ?? 'database',
                    'demand_level' => $dbPrice['demand_level'] ?? 'medium',
                    'quality_grade' => $dbPrice['quality_grade'] ?? 'standard',
                    'accuracy' => $this->getPriceAccuracy($dbPrice['source'] ?? 'database', $dbPrice['date'] ?? date('Y-m-d'))
                ];
            }
            
            // If database price is old, try APIs with fallback
            error_log("Fetching fresh price from APIs for crop: {$cropName}, location: {$location}");
            $apiPrice = $this->fetchPriceFromAPIs($cropName, $location);
            if ($apiPrice) {
                // Apply location-based adjustment to API price
                $locationAdjustedPrice = $this->applyLocationAdjustment($cropName, $apiPrice['price'], $location);
                // Save to database with location-adjusted price
                $this->savePriceToDatabase($cropName, $location, $locationAdjustedPrice, $apiPrice['date'], $apiPrice['source']);
                error_log("Crop price from API ({$apiPrice['source']}): {$cropName} = {$locationAdjustedPrice} PHP/kg (base: {$apiPrice['price']}, location: {$location}, date: {$apiPrice['date']})");
                return [
                    'success' => true,
                    'crop' => $cropName,
                    'location' => $location,
                    'price_per_kg' => $locationAdjustedPrice,
                    'date' => $apiPrice['date'],
                    'source' => $apiPrice['source'],
                    'demand_level' => 'medium',
                    'accuracy' => $this->getPriceAccuracy($apiPrice['source'], $apiPrice['date'])
                ];
            }
            
            error_log("No API price found for crop: {$cropName}, trying calculated price");
            
            // Try to calculate dynamic price from historical trends
            $calculatedPrice = $this->calculateDynamicPrice($cropName, $location);
            if ($calculatedPrice && $calculatedPrice['price'] > 0) {
                // Save calculated price to database
                $this->savePriceToDatabase($cropName, $location, $calculatedPrice['price'], $calculatedPrice['date'], $calculatedPrice['source']);
                error_log("Crop price calculated ({$calculatedPrice['source']}): {$cropName} = {$calculatedPrice['price']} PHP/kg");
                return [
                    'success' => true,
                    'crop' => $cropName,
                    'location' => $location,
                    'price_per_kg' => $calculatedPrice['price'],
                    'date' => $calculatedPrice['date'],
                    'source' => $calculatedPrice['source'],
                    'demand_level' => 'medium',
                    'note' => 'Calculated from historical trends',
                    'accuracy' => $this->getPriceAccuracy($calculatedPrice['source'], $calculatedPrice['date'])
                ];
            }
            
            // Last resort: Use default price with seasonal and location adjustment
            $basePrice = $this->defaultPrices[$cropName] ?? $this->getRealisticDefaultPrice($cropName);
            $seasonalAdjustedPrice = $this->applySeasonalAdjustment($cropName, $basePrice);
            $adjustedPrice = $this->applyLocationAdjustment($cropName, $seasonalAdjustedPrice, $location);
            error_log("Crop price using default/seasonal/location: {$cropName} = {$adjustedPrice} PHP/kg (base: {$basePrice}, seasonal: {$seasonalAdjustedPrice}, location: {$location})");
            return [
                'success' => true,
                'crop' => $cropName,
                'location' => $location,
                'price_per_kg' => $adjustedPrice,
                'date' => date('Y-m-d'),
                'source' => 'calculated',
                'demand_level' => 'medium',
                'note' => 'Calculated with seasonal and location adjustment',
                'accuracy' => $this->getPriceAccuracy('calculated', date('Y-m-d'))
            ];
            
        } catch (Exception $e) {
            // Even on error, try to return calculated price
            $calculatedPrice = $this->calculateDynamicPrice($cropName, $location);
            if ($calculatedPrice && $calculatedPrice['price'] > 0) {
                return [
                    'success' => true,
                    'crop' => $cropName,
                    'location' => $location,
                    'price_per_kg' => $calculatedPrice['price'],
                    'date' => $calculatedPrice['date'],
                    'source' => $calculatedPrice['source'],
                    'error_note' => $e->getMessage()
                ];
            }
            
            $basePrice = $this->defaultPrices[$cropName] ?? $this->getRealisticDefaultPrice($cropName);
            $seasonalAdjustedPrice = $this->applySeasonalAdjustment($cropName, $basePrice);
            $locationAdjustedPrice = $this->applyLocationAdjustment($cropName, $seasonalAdjustedPrice, $location);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'crop' => $cropName,
                'price_per_kg' => $locationAdjustedPrice,
                'source' => 'calculated_fallback'
            ];
        }
    }
    
    /**
     * Get historical prices
     * Uses case-insensitive matching for crop names and locations
     */
    public function getHistoricalPrices($cropName, $location = 'Manila', $days = 30) {
        try {
            // Normalize crop name and location
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            // Use case-insensitive matching
            $query = "SELECT date, price_per_kg, demand_level 
                     FROM market_prices 
                     WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) 
                     AND LOWER(TRIM(location)) = LOWER(:location) 
                     AND date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                     ORDER BY date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':crop', $cropName);
            $stmt->bindParam(':location', $location);
            $stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
            $stmt->execute();
            
            $prices = $stmt->fetchAll();
            
            return [
                'success' => true,
                'crop' => $cropName,
                'location' => $location,
                'days' => $days,
                'prices' => $prices,
                'count' => count($prices)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update prices for all crops
     */
    public function updateAllPrices($location = 'Manila') {
        $results = [];
        $allCrops = ['Rice', 'Corn', 'Wheat', 'Soybeans', 'Tomato', 'Eggplant', 'Okra', 'Squash', 'Pepper', 'Cabbage'];
        
        // Normalize location
        $location = ucfirst(strtolower(trim($location)));
        
        foreach ($allCrops as $crop) {
            // Normalize crop name and get price from any available API
            // Note: getCurrentPrice already normalizes, but we normalize here for consistency
            $result = $this->getCurrentPrice($crop, $location);
            $results[$crop] = $result;
            
            // Add small delay to respect API rate limits
            usleep(200000); // 0.2 seconds between requests
        }
        
        return [
            'success' => true,
            'location' => $location,
            'updated_at' => date('Y-m-d H:i:s'),
            'results' => $results,
            'apis_used' => $this->getAvailableAPIs()
        ];
    }
    
    /**
     * Get list of available APIs
     */
    private function getAvailableAPIs() {
        $apis = [];
        $apis[] = 'API Ninjas'; // Always available (free, no key required)
        if ($this->commoditiesApiKey) $apis[] = 'Commodities-API';
        if ($this->alphaVantageApiKey) $apis[] = 'Alpha Vantage';
        if ($this->twelveDataApiKey) $apis[] = 'Twelve Data';
        if ($this->usdaApiKey) $apis[] = 'USDA MMN';
        return $apis;
    }
    
    /**
     * Fetch price from multiple APIs with fallback
     * Tries APIs in order: Bantay Presyo (Philippine DA) -> API Ninjas -> Commodities-API -> Alpha Vantage -> Twelve Data
     * Then tries dynamic price calculation based on historical data
     */
    private function fetchPriceFromAPIs($cropName, $location = 'Manila') {
        // Normalize crop name before fetching from APIs
        $cropName = $this->normalizeCropName($cropName);
        $location = ucfirst(strtolower(trim($location)));
        
        // Try Bantay Presyo first (Philippine Department of Agriculture - most accurate for PH prices)
        error_log("Trying Bantay Presyo (DA) for crop: {$cropName}, location: {$location}");
        $price = $this->fetchFromBantayPresyo($cropName, $location);
        if ($price) {
            error_log("Bantay Presyo succeeded for crop: {$cropName}");
            return $price;
        }
        error_log("Bantay Presyo failed for crop: {$cropName}");
        
        // Try API Ninjas second (FREE - no key required or very generous free tier)
        error_log("Trying API Ninjas for crop: {$cropName}");
        $price = $this->fetchFromApiNinjas($cropName);
        if ($price) {
            error_log("API Ninjas succeeded for crop: {$cropName}");
            return $price;
        }
        error_log("API Ninjas failed for crop: {$cropName}");
        
        // Try Commodities-API second (100 requests/month, truly free)
        if ($this->commoditiesApiKey) {
            error_log("Trying Commodities-API for crop: {$cropName}");
            $price = $this->fetchFromCommoditiesAPI($cropName);
            if ($price) {
                error_log("Commodities-API succeeded for crop: {$cropName}");
                return $price;
            }
            error_log("Commodities-API failed for crop: {$cropName}");
        } else {
            error_log("Commodities-API key not configured");
        }
        
        // Try Alpha Vantage third (5 requests/min, truly free)
        if ($this->alphaVantageApiKey) {
            error_log("Trying Alpha Vantage for crop: {$cropName}");
            $price = $this->fetchFromAlphaVantage($cropName);
            if ($price) {
                error_log("Alpha Vantage succeeded for crop: {$cropName}");
                return $price;
            }
            error_log("Alpha Vantage failed for crop: {$cropName}");
        } else {
            error_log("Alpha Vantage API key not configured");
        }
        
        // Try Twelve Data fourth (if available)
        if ($this->twelveDataApiKey) {
            error_log("Trying Twelve Data for crop: {$cropName}");
            $price = $this->fetchFromTwelveData($cropName);
            if ($price) {
                error_log("Twelve Data succeeded for crop: {$cropName}");
                return $price;
            }
            error_log("Twelve Data failed for crop: {$cropName}");
        } else {
            error_log("Twelve Data API key not configured");
        }
        
        // If no API works, try to calculate dynamic price from historical data
        error_log("All APIs failed, trying calculated price for crop: {$cropName}");
        $calculatedPrice = $this->calculateDynamicPrice($cropName);
        if ($calculatedPrice) {
            error_log("Calculated price succeeded for crop: {$cropName}");
            return $calculatedPrice;
        }
        
        error_log("All price sources failed for crop: {$cropName}");
        return null;
    }
    
    /**
     * Fetch price from Bantay Presyo (Philippine Department of Agriculture)
     * This is the most accurate source for Philippine market prices
     */
    private function fetchFromBantayPresyo($cropName, $location) {
        try {
            // Map location to Bantay Presyo region
            $regionMap = [
                'Manila' => 'NCR',
                'Quezon City' => 'NCR',
                'Makati' => 'NCR',
                'Pasig' => 'NCR',
                'Taguig' => 'NCR',
                'Cebu' => 'Region VII',
                'Davao' => 'Region XI',
                'Baguio' => 'CAR',
                'Iloilo' => 'Region VI',
                'Cagayan de Oro' => 'Region X',
            ];
            
            $region = $regionMap[$location] ?? 'NCR'; // Default to NCR if not mapped
            
            // Try to get price from database (Bantay Presyo scraper should populate this)
            $query = "SELECT price_per_kg, date, source 
                     FROM market_prices 
                     WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) 
                     AND LOWER(TRIM(location)) = LOWER(:location)
                     AND source = 'bantay-presyo'
                     ORDER BY date DESC 
                     LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':crop', $cropName);
            $stmt->bindParam(':location', $location);
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result && $this->isPriceRecent($result['date'])) {
                return [
                    'price' => (float)$result['price_per_kg'],
                    'date' => $result['date'],
                    'source' => 'bantay-presyo'
                ];
            }
            
            // If no recent Bantay Presyo data, return null to try other sources
            return null;
            
        } catch (Exception $e) {
            error_log("Bantay Presyo fetch error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fetch price from API Ninjas (FREE - Secondary)
     * This is a truly free API that doesn't require an API key for basic usage
     */
    private function fetchFromApiNinjas($cropName) {
        try {
            // Map crop names to API Ninjas commodity names
            $commodityMap = [
                'Rice' => 'rice',
                'Corn' => 'corn',
                'Wheat' => 'wheat',
                'Soybeans' => 'soybeans',
                'Tomato' => null, // Not available in API Ninjas
                'Eggplant' => null,
                'Okra' => null,
                'Squash' => null,
                'Pepper' => null,
                'Cabbage' => null
            ];
            
            $commodityName = $commodityMap[$cropName] ?? null;
            if (!$commodityName) {
                return null; // Crop not supported by API Ninjas
            }
            
            $url = $this->apiNinjasUrl . '?name=' . urlencode($commodityName);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            // Add API key header if available (optional)
            if ($this->apiNinjasKey) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'X-Api-Key: ' . $this->apiNinjasKey
                ]);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            // API Ninjas returns array of commodities
            if (is_array($data) && !empty($data)) {
                $commodity = $data[0] ?? null;
                if ($commodity && isset($commodity['price']) && is_numeric($commodity['price'])) {
                    // API Ninjas returns price in USD per unit (usually per bushel or metric ton)
                    // Convert to PHP per kg
                    // Note: API Ninjas prices are typically per bushel (for grains) or per metric ton
                    // We'll convert assuming per metric ton and convert to per kg
                    $usdPerTon = (float)$commodity['price'];
                    $phpPerKg = $this->convertToPhpPerKg($usdPerTon / 1000, 'USD'); // Convert ton to kg
                    
                    return [
                        'price' => round($phpPerKg, 2),
                        'date' => date('Y-m-d'),
                        'source' => 'api-ninjas',
                        'raw_data' => $data
                    ];
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("API Ninjas error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fetch price from Alpha Vantage (Secondary - Truly Free)
     */
    private function fetchFromAlphaVantage($cropName) {
        if (!$this->alphaVantageApiKey) {
            return null;
        }
        
        $apiSymbol = $this->cropMapping['alphavantage'][$cropName] ?? null;
        if (!$apiSymbol) {
            return null;
        }
        
        try {
            // Alpha Vantage uses commodity endpoint
            $url = $this->alphaVantageApiUrl;
            $params = [
                'function' => 'COMMODITY_PRICES',
                'symbol' => $apiSymbol,
                'apikey' => $this->alphaVantageApiKey
            ];
            
            $fullUrl = $url . '?' . http_build_query($params);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            // Alpha Vantage response format
            if (isset($data['data']) && is_array($data['data']) && !empty($data['data'])) {
                // Get latest price from data array
                $latestPrice = $data['data'][0];
                if (isset($latestPrice['value']) && is_numeric($latestPrice['value'])) {
                    // Alpha Vantage returns prices in USD per unit, convert to PHP per kg
                    $phpPerKg = $this->convertToPhpPerKg($latestPrice['value'], 'USD');
                    
                    return [
                        'price' => round($phpPerKg, 2),
                        'date' => $latestPrice['date'] ?? date('Y-m-d'),
                        'source' => 'alphavantage',
                        'raw_data' => $data
                    ];
                }
            }
            
            // Alternative: Try CURRENCY_EXCHANGE_RATE if commodity endpoint doesn't work
            // This is a fallback for Alpha Vantage
            return null;
            
        } catch (Exception $e) {
            error_log("Alpha Vantage API error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fetch price from Twelve Data (Tertiary)
     */
    private function fetchFromTwelveData($cropName) {
        if (!$this->twelveDataApiKey) {
            return null;
        }
        
        $apiSymbol = $this->cropMapping['twelvedata'][$cropName] ?? null;
        if (!$apiSymbol) {
            return null;
        }
        
        try {
            $url = $this->twelveDataApiUrl . 'price';
            $params = [
                'symbol' => $apiSymbol,
                'apikey' => $this->twelveDataApiKey,
                'format' => 'json'
            ];
            
            $fullUrl = $url . '?' . http_build_query($params);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['price']) && is_numeric($data['price'])) {
                $phpPerKg = $this->convertToPhpPerKg($data['price'], 'USD');
                
                return [
                    'price' => round($phpPerKg, 2),
                    'date' => date('Y-m-d'),
                    'source' => 'twelvedata',
                    'raw_data' => $data
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Twelve Data API error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fetch price from Commodities-API (Primary - Truly Free)
     */
    private function fetchFromCommoditiesAPI($cropName) {
        if (!$this->commoditiesApiKey) {
            return null;
        }
        
        $apiSymbol = $this->cropMapping['commodities'][$cropName] ?? null;
        if (!$apiSymbol) {
            return null;
        }
        
        try {
            $url = $this->commoditiesApiUrl . 'latest';
            $params = [
                'access_key' => $this->commoditiesApiKey,
                'base' => 'USD',
                'symbols' => $apiSymbol
            ];
            
            $fullUrl = $url . '?' . http_build_query($params);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['success']) && $data['success'] && isset($data['data']['rates'][$apiSymbol])) {
                // Convert USD per metric ton to PHP per kg
                $usdPerTon = $data['data']['rates'][$apiSymbol];
                $phpPerKg = $this->convertToPhpPerKg($usdPerTon / 1000, 'USD'); // Convert ton to kg first
                
                return [
                    'price' => round($phpPerKg, 2),
                    'date' => date('Y-m-d'),
                    'source' => 'commodities-api',
                    'raw_data' => $data
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Commodities-API error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Convert price to PHP per kg
     */
    private function convertToPhpPerKg($price, $currency = 'USD') {
        if ($currency === 'USD') {
            // Get real-time USD to PHP exchange rate
            $usdToPhp = $this->getUsdToPhpRate();
            return $price * $usdToPhp;
        }
        
        return $price; // Already in PHP or other handling needed
    }
    
    /**
     * Get USD to PHP exchange rate (with caching)
     */
    private function getUsdToPhpRate() {
        // Try to get from cache/database first
        try {
            // Ensure system_settings table exists
            $this->ensureSystemSettingsTable();
            
            $query = "SELECT value, updated_at FROM system_settings WHERE setting_key = 'usd_to_php_rate' LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result && isset($result['value'])) {
                $cachedRate = (float)$result['value'];
                
                // If cached rate is less than 24 hours old, use it
                if (isset($result['updated_at']) && $result['updated_at']) {
                    $cacheTime = new DateTime($result['updated_at']);
                    $now = new DateTime();
                    $diff = $now->diff($cacheTime);
                    if ($diff->days < 1) {
                        return $cachedRate;
                    }
                }
            }
        } catch (Exception $e) {
            // Continue to fetch new rate
            error_log("Error reading cached exchange rate: " . $e->getMessage());
        }
        
        // Try to fetch real-time rate from free API
        try {
            // Use exchangerate-api.com (free tier: 1,500 requests/month)
            $url = 'https://api.exchangerate-api.com/v4/latest/USD';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (isset($data['rates']['PHP'])) {
                    $rate = (float)$data['rates']['PHP'];
                    
                    // Cache the rate
                    try {
                        $this->ensureSystemSettingsTable();
                        $query = "INSERT INTO system_settings (setting_key, value, updated_at) 
                                 VALUES ('usd_to_php_rate', :rate, NOW())
                                 ON DUPLICATE KEY UPDATE value = :rate, updated_at = NOW()";
                        $stmt = $this->conn->prepare($query);
                        $stmt->bindParam(':rate', $rate);
                        $stmt->execute();
                    } catch (Exception $e) {
                        // Ignore cache errors
                        error_log("Error caching exchange rate: " . $e->getMessage());
                    }
                    
                    return $rate;
                }
            }
        } catch (Exception $e) {
            error_log("Exchange rate API error: " . $e->getMessage());
        }
        
        // Fallback to approximate rate (update periodically)
        return 55.0; // Approximate USD to PHP rate
    }
    
    /**
     * Get price from database
     * Uses case-insensitive matching to handle variations in crop names
     * Returns location-specific prices only
     */
    private function getPriceFromDatabase($cropName, $location) {
        try {
            // Normalize crop name and location for consistent matching
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            // Use case-insensitive matching (LOWER() function)
            // IMPORTANT: Only get prices for the specific location
            $query = "SELECT price_per_kg, date, demand_level, quality_grade, source
                     FROM market_prices 
                     WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) 
                     AND LOWER(TRIM(location)) = LOWER(:location) 
                     ORDER BY date DESC 
                     LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':crop', $cropName);
            $stmt->bindParam(':location', $location);
            $stmt->execute();
            
            $result = $stmt->fetch();
            
            // If no price found for this specific location, return null so system can fetch/create location-specific price
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in getPriceFromDatabase: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if price is recent (within 24 hours)
     */
    private function isPriceRecent($date) {
        $priceDate = new DateTime($date);
        $now = new DateTime();
        $diff = $now->diff($priceDate);
        
        return $diff->days < 1; // Less than 1 day old
    }
    
    /**
     * Save price to database
     * Normalizes crop names and locations before saving for consistency
     */
    private function savePriceToDatabase($cropName, $location, $price, $date, $source = 'api') {
        try {
            // Normalize crop name and location before saving
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            // First check if price for this crop/location/date already exists (case-insensitive)
            $checkQuery = "SELECT id FROM market_prices 
                          WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) 
                          AND LOWER(TRIM(location)) = LOWER(:location) 
                          AND date = :date 
                          LIMIT 1";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':crop', $cropName);
            $checkStmt->bindParam(':location', $location);
            $checkStmt->bindParam(':date', $date);
            $checkStmt->execute();
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update existing record (use normalized names)
                $query = "UPDATE market_prices 
                         SET price_per_kg = :price, 
                             source = :source,
                             demand_level = 'medium',
                             crop_name = :crop,
                             location = :location
                         WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $existing['id']);
            } else {
                // Insert new record with normalized names
                $query = "INSERT INTO market_prices 
                         (crop_name, price_per_kg, location, date, demand_level, source) 
                         VALUES (:crop, :price, :location, :date, 'medium', :source)";
                $stmt = $this->conn->prepare($query);
            }
            
            $stmt->bindParam(':crop', $cropName);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':source', $source);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to save price to database: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calculate dynamic price based on historical data and trends
     * This ensures all crops get real-time calculated prices even without API data
     */
    private function calculateDynamicPrice($cropName, $location = 'Manila') {
        try {
            // Normalize crop name before processing
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            // Get historical prices from database
            $historical = $this->getHistoricalPrices($cropName, $location, 90); // Last 90 days
            
            if ($historical['success'] && !empty($historical['prices']) && count($historical['prices']) >= 3) {
                $prices = $historical['prices'];
                
                // Get most recent price
                $latestPrice = (float)$prices[0]['price_per_kg'];
                $latestDate = $prices[0]['date'];
                
                // Calculate trend (average of last 7 days vs previous 7 days)
                $recentPrices = array_slice($prices, 0, min(7, count($prices)));
                $olderPrices = array_slice($prices, 7, min(7, count($prices) - 7));
                
                if (!empty($recentPrices) && !empty($olderPrices)) {
                    $recentAvg = array_sum(array_column($recentPrices, 'price_per_kg')) / count($recentPrices);
                    $olderAvg = array_sum(array_column($olderPrices, 'price_per_kg')) / count($olderPrices);
                    
                    // Calculate trend percentage
                    $trendPercent = $olderAvg > 0 ? (($recentAvg - $olderAvg) / $olderAvg) * 100 : 0;
                    
                    // Project current price based on trend (assuming linear continuation)
                    $daysSinceLatest = $this->daysBetween($latestDate, date('Y-m-d'));
                    if ($daysSinceLatest > 0 && $daysSinceLatest <= 7) {
                        // Apply trend projection
                        $dailyTrend = $trendPercent / 7; // Daily trend percentage
                        $projectedPrice = $latestPrice * (1 + ($dailyTrend * $daysSinceLatest / 100));
                        
                        // Apply seasonal and location adjustment
                        $projectedPrice = $this->applySeasonalAdjustment($cropName, $projectedPrice);
                        $projectedPrice = $this->applyLocationAdjustment($cropName, $projectedPrice, $location);
                        
                        return [
                            'price' => round(max($projectedPrice, $latestPrice * 0.7), 2), // Don't drop below 70% of latest
                            'date' => date('Y-m-d'),
                            'source' => 'calculated-trend'
                        ];
                    }
                }
                
                // If we have recent data but no strong trend, use latest with seasonal and location adjustment
                $daysSinceLatest = $this->daysBetween($latestDate, date('Y-m-d'));
                if ($daysSinceLatest <= 14) {
                    $adjustedPrice = $this->applySeasonalAdjustment($cropName, $latestPrice);
                    $adjustedPrice = $this->applyLocationAdjustment($cropName, $adjustedPrice, $location);
                    return [
                        'price' => round($adjustedPrice, 2),
                        'date' => date('Y-m-d'),
                        'source' => 'calculated-seasonal'
                    ];
                }
            }
            
            // If no historical data, seed database with initial prices based on defaults
            // This ensures we have data to work with next time
            $basePrice = $this->defaultPrices[$cropName] ?? $this->getRealisticDefaultPrice($cropName);
            $seasonalAdjustedPrice = $this->applySeasonalAdjustment($cropName, $basePrice);
            $adjustedPrice = $this->applyLocationAdjustment($cropName, $seasonalAdjustedPrice, $location);
            
            // Seed the database with this price and some historical variation
            $this->seedInitialPrices($cropName, $location, $adjustedPrice);
            
            return [
                'price' => round($adjustedPrice, 2),
                'date' => date('Y-m-d'),
                'source' => 'calculated-seasonal-default'
            ];
            
        } catch (Exception $e) {
            error_log("Dynamic price calculation error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Apply location-based adjustment to prices
     * Different locations in Philippines have different market prices
     * Metro areas (Manila) typically have higher prices, rural areas lower
     */
    private function applyLocationAdjustment($cropName, $basePrice, $location) {
        // Normalize location
        $location = ucfirst(strtolower(trim($location)));
        
        // Location multipliers based on market characteristics
        // Metro areas: Higher prices due to demand and logistics
        // Provincial capitals: Moderate prices
        // Rural areas: Lower prices
        $locationMultipliers = [
            'Manila' => 1.15,        // Metro Manila - highest prices (15% premium)
            'Quezon City' => 1.12,   // Part of Metro Manila
            'Makati' => 1.18,        // Business district - premium prices
            'Pasig' => 1.14,         // Metro Manila
            'Taguig' => 1.16,        // Metro Manila
            'Cebu' => 1.08,          // Major city - slightly above average
            'Davao' => 1.05,         // Major city - moderate premium
            'Baguio' => 1.10,        // Tourist area - higher prices
            'Iloilo' => 1.03,        // Provincial capital - slight premium
            'Cagayan de Oro' => 1.02, // Provincial capital - near base
            'Bacolod' => 1.04,       // Provincial capital
            'Cagayan' => 0.95,       // Rural area - lower prices
            'Zamboanga' => 1.00,     // Base price
            'General Santos' => 0.98, // Slightly below base
            'Butuan' => 0.97,       // Rural area
            'Laoag' => 0.96,        // Rural area
            'Legazpi' => 0.99,      // Near base
            'Naga' => 0.98,         // Slightly below base
            'Tacloban' => 0.97,     // Rural area
            'Puerto Princesa' => 1.06, // Tourist area - slight premium
        ];
        
        // Get multiplier for location (default to 1.0 if not found)
        $multiplier = $locationMultipliers[$location] ?? 1.0;
        
        // For locations not in the list, estimate based on common patterns
        if (!isset($locationMultipliers[$location])) {
            // Check if it's a metro/manila area
            if (stripos($location, 'manila') !== false || 
                stripos($location, 'metro') !== false ||
                stripos($location, 'quezon') !== false ||
                stripos($location, 'makati') !== false ||
                stripos($location, 'pasig') !== false ||
                stripos($location, 'taguig') !== false) {
                $multiplier = 1.12; // Metro area average
            }
            // Check if it's a major city
            elseif (stripos($location, 'city') !== false) {
                $multiplier = 1.05; // City average
            }
            // Otherwise, assume rural/provincial (slightly below base)
            else {
                $multiplier = 0.97; // Rural average
            }
        }
        
        return $basePrice * $multiplier;
    }
    
    /**
     * Apply seasonal adjustment to prices based on month
     * Philippine agricultural seasons affect prices
     */
    private function applySeasonalAdjustment($cropName, $basePrice) {
        // Normalize crop name to ensure proper matching
        $cropName = $this->normalizeCropName($cropName);
        $month = (int)date('n');
        
        // Seasonal multipliers for different crops in Philippines
        $seasonalFactors = [
            'Rice' => [
                1 => 1.05, 2 => 1.08, 3 => 1.10, 4 => 1.12, // Jan-Apr: Dry season, higher prices
                5 => 1.00, 6 => 0.95, 7 => 0.92, 8 => 0.90, // May-Aug: Wet season, harvest period
                9 => 0.95, 10 => 1.00, 11 => 1.03, 12 => 1.05  // Sep-Dec: Transition
            ],
            'Corn' => [
                1 => 1.05, 2 => 1.08, 3 => 1.10, 4 => 1.05,
                5 => 0.95, 6 => 0.90, 7 => 0.88, 8 => 0.90,
                9 => 0.95, 10 => 1.00, 11 => 1.03, 12 => 1.05
            ],
            'Tomato' => [
                1 => 1.15, 2 => 1.20, 3 => 1.10, 4 => 0.95, // High demand in early year
                5 => 0.85, 6 => 0.80, 7 => 0.75, 8 => 0.80, // Lower in rainy season
                9 => 0.90, 10 => 1.00, 11 => 1.10, 12 => 1.15 // Higher in dry months
            ],
            'Eggplant' => [
                1 => 1.10, 2 => 1.15, 3 => 1.10, 4 => 1.00,
                5 => 0.90, 6 => 0.85, 7 => 0.80, 8 => 0.85,
                9 => 0.95, 10 => 1.05, 11 => 1.10, 12 => 1.12
            ],
            'Okra' => [
                1 => 1.10, 2 => 1.12, 3 => 1.08, 4 => 1.00,
                5 => 0.90, 6 => 0.85, 7 => 0.80, 8 => 0.85,
                9 => 0.95, 10 => 1.05, 11 => 1.10, 12 => 1.08
            ],
            'Squash' => [
                1 => 1.08, 2 => 1.10, 3 => 1.05, 4 => 1.00,
                5 => 0.90, 6 => 0.85, 7 => 0.80, 8 => 0.85,
                9 => 0.95, 10 => 1.05, 11 => 1.08, 12 => 1.10
            ],
            'Pepper' => [
                1 => 1.12, 2 => 1.15, 3 => 1.10, 4 => 1.05,
                5 => 0.95, 6 => 0.90, 7 => 0.85, 8 => 0.90,
                9 => 1.00, 10 => 1.08, 11 => 1.12, 12 => 1.15
            ],
            'Cabbage' => [
                1 => 1.10, 2 => 1.12, 3 => 1.08, 4 => 1.00,
                5 => 0.90, 6 => 0.85, 7 => 0.80, 8 => 0.85,
                9 => 0.95, 10 => 1.05, 11 => 1.10, 12 => 1.12
            ]
        ];
        
        $factor = $seasonalFactors[$cropName][$month] ?? 1.0;
        return $basePrice * $factor;
    }
    
    /**
     * Calculate days between two dates
     */
    private function daysBetween($date1, $date2) {
        $d1 = new DateTime($date1);
        $d2 = new DateTime($date2);
        $diff = $d2->diff($d1);
        return $diff->days;
    }
    
    /**
     * Ensure system_settings table exists
     */
    private function ensureSystemSettingsTable() {
        try {
            $query = "CREATE TABLE IF NOT EXISTS system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            
            $this->conn->exec($query);
        } catch (Exception $e) {
            error_log("Failed to create system_settings table: " . $e->getMessage());
        }
    }
    
    /**
     * Get realistic default price for crops not in defaultPrices array
     */
    private function getRealisticDefaultPrice($cropName) {
        // Normalize crop name to ensure proper matching
        $cropName = $this->normalizeCropName($cropName);
        
        // Realistic Philippine market prices per kg (PHP)
        $realisticPrices = [
            'Rice' => 25.50,
            'Corn' => 18.50,
            'Tomato' => 35.00,
            'Eggplant' => 28.00,
            'Okra' => 30.00,
            'Squash' => 25.00,
            'Pepper' => 40.00,
            'Cabbage' => 22.00,
            'Wheat' => 20.00,
            'Soybeans' => 30.00,
            // Add more crops with realistic prices
            'Onion' => 45.00,
            'Garlic' => 120.00,
            'Potato' => 35.00,
            'Carrot' => 40.00,
            'Cucumber' => 30.00,
            'String Beans' => 35.00,
            'Ampalaya' => 40.00,
            'Pechay' => 25.00,
            'Kangkong' => 20.00,
            'Lettuce' => 50.00
        ];
        
        return $realisticPrices[$cropName] ?? 30.00; // Default to 30 instead of 25
    }
    
    /**
     * Seed initial prices in database to create historical data
     */
    private function seedInitialPrices($cropName, $location, $basePrice) {
        try {
            // Normalize crop name and location before seeding
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            // Check if we already have data for this crop (case-insensitive)
            $checkQuery = "SELECT COUNT(*) as count FROM market_prices 
                          WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) AND LOWER(TRIM(location)) = LOWER(:location)";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':crop', $cropName);
            $checkStmt->bindParam(':location', $location);
            $checkStmt->execute();
            $result = $checkStmt->fetch();
            
            // Only seed if we have no data
            if ($result && $result['count'] == 0) {
                // Create 30 days of historical data with realistic variation
                $variation = 0.15; // 15% variation
                $dates = [];
                $prices = [];
                
                for ($i = 0; $i < 30; $i++) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    // Add realistic daily variation (-10% to +10%)
                    $dailyVariation = 1 + (rand(-100, 100) / 1000); // -10% to +10%
                    $price = $basePrice * $dailyVariation;
                    // Apply location adjustment to each historical price
                    $price = $this->applyLocationAdjustment($cropName, $price, $location);
                    $prices[] = round($price, 2);
                    $dates[] = $date;
                }
                
                // Insert prices in reverse order (oldest first)
                $prices = array_reverse($prices);
                $dates = array_reverse($dates);
                
                $insertQuery = "INSERT INTO market_prices 
                               (crop_name, price_per_kg, location, date, demand_level, source) 
                               VALUES (:crop, :price, :location, :date, 'medium', 'seeded')";
                $insertStmt = $this->conn->prepare($insertQuery);
                
                for ($i = 0; $i < count($prices); $i++) {
                    $insertStmt->bindParam(':crop', $cropName);
                    $insertStmt->bindParam(':price', $prices[$i]);
                    $insertStmt->bindParam(':location', $location);
                    $insertStmt->bindParam(':date', $dates[$i]);
                    $insertStmt->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Failed to seed initial prices: " . $e->getMessage());
        }
    }
    
    /**
     * Get price trend analysis
     */
    public function getPriceTrend($cropName, $location = 'Manila', $days = 30) {
        $historical = $this->getHistoricalPrices($cropName, $location, $days);
        
        if (!$historical['success'] || empty($historical['prices'])) {
            return [
                'success' => false,
                'error' => 'No historical data available'
            ];
        }
        
        $prices = $historical['prices'];
        $currentPrice = $prices[0]['price_per_kg'];
        $oldestPrice = end($prices)['price_per_kg'];
        
        $priceChange = $currentPrice - $oldestPrice;
        $priceChangePercent = $oldestPrice > 0 ? (($priceChange / $oldestPrice) * 100) : 0;
        
        // Calculate average
        $sum = array_sum(array_column($prices, 'price_per_kg'));
        $average = $sum / count($prices);
        
        // Determine trend
        $trend = 'stable';
        if ($priceChangePercent > 5) {
            $trend = 'increasing';
        } elseif ($priceChangePercent < -5) {
            $trend = 'decreasing';
        }
        
        return [
            'success' => true,
            'crop' => $cropName,
            'location' => $location,
            'current_price' => round($currentPrice, 2),
            'oldest_price' => round($oldestPrice, 2),
            'average_price' => round($average, 2),
            'price_change' => round($priceChange, 2),
            'price_change_percent' => round($priceChangePercent, 2),
            'trend' => $trend,
            'days_analyzed' => count($prices)
        ];
    }
    
    /**
     * Get all market prices for all available crops
     * Returns prices for all crops in the defaultPrices array
     */
    public function getAllMarketPrices($location = 'Manila') {
        try {
            // Normalize location
            $location = ucfirst(strtolower(trim($location)));
            
            // Get all crops from defaultPrices (includes all available crops)
            $allCrops = array_keys($this->defaultPrices);
            
            // Also include crops from realisticPrices if they're not in defaultPrices
            $realisticPrices = [
                'Onion' => 45.00,
                'Garlic' => 120.00,
                'Potato' => 35.00,
                'Carrot' => 40.00,
                'Cucumber' => 30.00,
                'String Beans' => 35.00,
                'Ampalaya' => 40.00,
                'Pechay' => 25.00,
                'Kangkong' => 20.00,
                'Lettuce' => 50.00
            ];
            
            // Merge all crops
            $allCrops = array_unique(array_merge($allCrops, array_keys($realisticPrices)));
            sort($allCrops);
            
            $marketPrices = [];
            $errors = [];
            
            foreach ($allCrops as $crop) {
                try {
                    $priceData = $this->getCurrentPrice($crop, $location);
                    
                    if ($priceData && isset($priceData['success']) && $priceData['success']) {
                        // Ensure price is location-specific (it should already be adjusted, but double-check)
                        $finalPrice = (float)$priceData['price_per_kg'];
                        
                        // Get price trend for additional info (using location-specific prices)
                        $trendData = $this->getPriceTrend($crop, $location, 7); // Last 7 days
                        
                        // Get price ID from database
                        $priceId = $this->getPriceIdFromDatabase($crop, $location, $priceData['date'] ?? date('Y-m-d'));
                        
                        $marketPrices[] = [
                            'id' => $priceId,
                            'crop_name' => $crop,
                            'location' => $location,
                            'price_per_kg' => $finalPrice,
                            'date' => $priceData['date'] ?? date('Y-m-d'),
                            'source' => $priceData['source'] ?? 'calculated',
                            'demand_level' => $priceData['demand_level'] ?? 'medium',
                            'quality_grade' => $priceData['quality_grade'] ?? 'standard',
                            'trend' => $trendData['trend'] ?? 'stable',
                            'price_change_percent' => isset($trendData['price_change_percent']) ? round($trendData['price_change_percent'], 2) : 0,
                            'accuracy' => $priceData['accuracy'] ?? 'estimated'
                        ];
                    } else {
                        $errors[] = "Failed to get price for {$crop}";
                    }
                    
                    // Small delay to respect API rate limits
                    usleep(100000); // 0.1 seconds between requests
                    
                } catch (Exception $e) {
                    error_log("Error getting price for {$crop}: " . $e->getMessage());
                    $errors[] = "Error for {$crop}: " . $e->getMessage();
                }
            }
            
            return [
                'success' => true,
                'location' => $location,
                'updated_at' => date('Y-m-d H:i:s'),
                'prices' => $marketPrices,
                'total_crops' => count($marketPrices),
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Admin: Add or update crop price manually
     */
    public function addOrUpdatePrice($cropName, $location, $pricePerKg, $date = null, $demandLevel = 'medium', $qualityGrade = 'standard', $source = 'admin') {
        try {
            // Normalize inputs
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            $pricePerKg = (float)$pricePerKg;
            $date = $date ? $date : date('Y-m-d');
            
            // Validate inputs
            if (empty($cropName) || empty($location) || $pricePerKg <= 0) {
                return [
                    'success' => false,
                    'error' => 'Invalid input: crop name, location, and price (must be > 0) are required'
                ];
            }
            
            // Check if price already exists for this crop/location/date
            $checkQuery = "SELECT id FROM market_prices 
                          WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) 
                          AND LOWER(TRIM(location)) = LOWER(:location) 
                          AND date = :date 
                          LIMIT 1";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':crop', $cropName);
            $checkStmt->bindParam(':location', $location);
            $checkStmt->bindParam(':date', $date);
            $checkStmt->execute();
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update existing record
                $query = "UPDATE market_prices 
                         SET price_per_kg = :price,
                             demand_level = :demand_level,
                             quality_grade = :quality_grade,
                             source = :source,
                             updated_at = NOW()
                         WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $existing['id']);
            } else {
                // Insert new record
                $query = "INSERT INTO market_prices 
                         (crop_name, price_per_kg, location, date, demand_level, quality_grade, source) 
                         VALUES (:crop, :price, :location, :date, :demand_level, :quality_grade, :source)";
                $stmt = $this->conn->prepare($query);
            }
            
            $stmt->bindParam(':crop', $cropName);
            $stmt->bindParam(':price', $pricePerKg);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':demand_level', $demandLevel);
            $stmt->bindParam(':quality_grade', $qualityGrade);
            $stmt->bindParam(':source', $source);
            $stmt->execute();
            
            return [
                'success' => true,
                'message' => $existing ? 'Price updated successfully' : 'Price added successfully',
                'crop_name' => $cropName,
                'location' => $location,
                'price_per_kg' => $pricePerKg,
                'date' => $date
            ];
            
        } catch (Exception $e) {
            error_log("Error adding/updating price: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Admin: Delete crop price
     */
    public function deletePrice($priceId) {
        try {
            $query = "DELETE FROM market_prices WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $priceId);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return [
                    'success' => true,
                    'message' => 'Price deleted successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Price not found'
                ];
            }
        } catch (Exception $e) {
            error_log("Error deleting price: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Admin: Get price by ID
     */
    public function getPriceById($priceId) {
        try {
            $query = "SELECT * FROM market_prices WHERE id = :id LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $priceId);
            $stmt->execute();
            $price = $stmt->fetch();
            
            if ($price) {
                return [
                    'success' => true,
                    'price' => $price
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Price not found'
                ];
            }
        } catch (Exception $e) {
            error_log("Error getting price by ID: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get price accuracy level based on source and date
     * Returns: 'high', 'medium', 'low', 'estimated'
     */
    private function getPriceAccuracy($source, $date) {
        // Check how recent the price is
        $priceDate = new DateTime($date);
        $now = new DateTime();
        $daysOld = $now->diff($priceDate)->days;
        
        // Bantay Presyo (DA official data) - highest accuracy
        if ($source === 'bantay-presyo') {
            if ($daysOld <= 1) return 'high';
            if ($daysOld <= 3) return 'medium';
            return 'low';
        }
        
        // Admin manually entered prices - high accuracy
        if ($source === 'admin') {
            if ($daysOld <= 7) return 'high';
            if ($daysOld <= 14) return 'medium';
            return 'low';
        }
        
        // API sources (international commodities) - medium accuracy
        if (in_array($source, ['api-ninjas', 'commodities-api', 'alphavantage', 'twelvedata', 'api'])) {
            if ($daysOld <= 1) return 'medium';
            if ($daysOld <= 3) return 'low';
            return 'estimated';
        }
        
        // Database cached prices - depends on age
        if ($source === 'database') {
            if ($daysOld <= 1) return 'medium';
            if ($daysOld <= 7) return 'low';
            return 'estimated';
        }
        
        // Calculated prices - lower accuracy
        if (strpos($source, 'calculated') !== false) {
            return 'estimated';
        }
        
        // Default fallback
        return 'estimated';
    }
    
    /**
     * Get price ID from database by crop, location, and date
     * Made public for API access
     */
    public function getPriceIdFromDatabase($cropName, $location, $date) {
        try {
            $cropName = $this->normalizeCropName($cropName);
            $location = ucfirst(strtolower(trim($location)));
            
            $query = "SELECT id FROM market_prices 
                     WHERE LOWER(TRIM(crop_name)) = LOWER(:crop) 
                     AND LOWER(TRIM(location)) = LOWER(:location) 
                     AND date = :date 
                     LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':crop', $cropName);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result ? $result['id'] : null;
        } catch (Exception $e) {
            error_log("Error getting price ID: " . $e->getMessage());
            return null;
        }
    }
}

// Only handle HTTP requests if this file is accessed directly (not included)
// Check if this script is being run directly by comparing the script name
if (basename($_SERVER['PHP_SELF']) === 'crop-prices.php') {
    // Handle the request
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($requestMethod === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $api = new CropPricesAPI();
    $action = $_GET['action'] ?? 'current';

    try {
        switch ($action) {
            case 'current':
                $crop = $_GET['crop'] ?? 'Rice';
                $location = $_GET['location'] ?? 'Manila';
                $result = $api->getCurrentPrice($crop, $location);
                break;
                
            case 'historical':
                $crop = $_GET['crop'] ?? 'Rice';
                $location = $_GET['location'] ?? 'Manila';
                $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
                $result = $api->getHistoricalPrices($crop, $location, $days);
                break;
                
            case 'trend':
                $crop = $_GET['crop'] ?? 'Rice';
                $location = $_GET['location'] ?? 'Manila';
                $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
                $result = $api->getPriceTrend($crop, $location, $days);
                break;
                
            case 'update_all':
                $location = $_GET['location'] ?? 'Manila';
                $result = $api->updateAllPrices($location);
                break;
                
            case 'all_market_prices':
                $location = $_GET['location'] ?? 'Manila';
                $result = $api->getAllMarketPrices($location);
                break;
                
            case 'add_price':
            case 'update_price':
                // Check if user is admin
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Admin access required'
                    ]);
                    exit;
                }
                
                $cropName = $_POST['crop_name'] ?? $_GET['crop_name'] ?? '';
                $location = $_POST['location'] ?? $_GET['location'] ?? 'Manila';
                $pricePerKg = $_POST['price_per_kg'] ?? $_GET['price_per_kg'] ?? 0;
                $date = $_POST['date'] ?? $_GET['date'] ?? null;
                $demandLevel = $_POST['demand_level'] ?? $_GET['demand_level'] ?? 'medium';
                $qualityGrade = $_POST['quality_grade'] ?? $_GET['quality_grade'] ?? 'standard';
                
                $result = $api->addOrUpdatePrice($cropName, $location, $pricePerKg, $date, $demandLevel, $qualityGrade, 'admin');
                break;
                
            case 'delete_price':
                // Check if user is admin
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Admin access required'
                    ]);
                    exit;
                }
                
                $priceId = $_POST['price_id'] ?? $_GET['price_id'] ?? 0;
                $result = $api->deletePrice($priceId);
                break;
                
            case 'get_price':
                // Support both ID and crop/location/date lookup
                if (isset($_GET['price_id']) && $_GET['price_id']) {
                    $priceId = $_GET['price_id'];
                    $result = $api->getPriceById($priceId);
                } elseif (isset($_GET['crop_name']) && isset($_GET['location']) && isset($_GET['date'])) {
                    $cropName = $_GET['crop_name'];
                    $location = $_GET['location'];
                    $date = $_GET['date'];
                    $priceId = $api->getPriceIdFromDatabase($cropName, $location, $date);
                    if ($priceId) {
                        $result = $api->getPriceById($priceId);
                    } else {
                        $result = [
                            'success' => false,
                            'error' => 'Price not found'
                        ];
                    }
                } else {
                    $result = [
                        'success' => false,
                        'error' => 'Either price_id or crop_name/location/date required'
                    ];
                }
                break;
                
            default:
                $result = [
                    'success' => false,
                    'error' => 'Invalid action. Use: current, historical, trend, update_all, all_market_prices, add_price, update_price, delete_price, or get_price'
                ];
        }
        
        echo json_encode($result, JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}