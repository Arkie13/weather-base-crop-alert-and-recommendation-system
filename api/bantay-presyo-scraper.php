<?php
/**
 * Bantay Presyo Scraper
 * 
 * This script scrapes daily price data from DA's Bantay Presyo website
 * and stores it in the local database for use in the crop pricing system.
 * 
 * Usage:
 * - Run manually: php api/bantay-presyo-scraper.php
 * - Run via cron: 0 6 * * * /usr/bin/php /path/to/project/api/bantay-presyo-scraper.php
 */

require_once __DIR__ . '/../config/database.php';

class BantayPresyoScraper {
    private $baseUrl = 'http://www.bantaypresyo.da.gov.ph/';
    private $conn;
    
    // Commodity mapping (Bantay Presyo ID => Crop Name)
    private $commodityMap = [
        '1' => 'Rice',
        '2' => 'Corn',
        '3' => 'Fish',
        '4' => 'Fruits',
        '5' => 'Highland Vegetables',
        '6' => 'Lowland Vegetables',
        '7' => 'Meat and Poultry',
        '8' => 'Spices',
        '9' => 'Other Commodities'
    ];
    
    // Region mapping (Region Name => Bantay Presyo ID)
    private $regionMap = [
        'NCR' => '130000000',
        'Manila' => '130000000',
        'CAR' => '140000000',
        'Region I' => '100000000',
        'Region II' => '200000000',
        'Region III' => '300000000',
        'Region IV-A' => '400000000',
        'Region IV-B' => '400000000',
        'Region V' => '500000000',
        'Region VI' => '600000000',
        'Region VII' => '700000000',
        'Cebu' => '700000000',
        'Region VIII' => '800000000',
        'Region IX' => '900000000',
        'Region X' => '1000000000',
        'Region XI' => '1100000000',
        'Davao' => '1100000000',
        'Region XII' => '1200000000',
        'BARMM' => '1500000000',
        'Region XIII' => '1600000000'
    ];
    
    public function __construct() {
        // Use Database class for consistent connection
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Scrape prices for a specific commodity and region
     */
    public function scrapePrices($commodityId, $regionId, $commodityName, $regionName) {
        try {
            // Get market headers first
            $markets = $this->getMarketHeaders($commodityId, $regionId);
            
            if (empty($markets)) {
                error_log("No markets found for commodity: {$commodityName}, region: {$regionName}");
                return false;
            }
            
            // Get price data
            $priceData = $this->getPriceData($commodityId, $regionId, count($markets));
            
            if (empty($priceData)) {
                error_log("No price data found for commodity: {$commodityName}, region: {$regionName}");
                return false;
            }
            
            // Get date
            $date = $this->getPriceDate($commodityId, $regionId);
            
            // Store prices in database
            return $this->storePrices($commodityName, $regionName, $markets, $priceData, $date);
            
        } catch (Exception $e) {
            error_log("Error scraping Bantay Presyo: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get market headers (market names)
     */
    private function getMarketHeaders($commodityId, $regionId) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . 'tbl_price_get_comm_header.php');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'commodity' => $commodityId,
            'region' => $regionId
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CropAlert/1.0)');
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($html)) {
            return [];
        }
        
        // Parse HTML to extract market names
        return $this->parseMarketHeaders($html);
    }
    
    /**
     * Parse market headers from HTML
     */
    private function parseMarketHeaders($html) {
        $markets = [];
        
        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Find all td elements with market names
        $tds = $xpath->query('//td[@class="text-wrap"]');
        
        foreach ($tds as $td) {
            $marketName = trim($td->textContent);
            if (!empty($marketName)) {
                $markets[] = $marketName;
            }
        }
        
        return $markets;
    }
    
    /**
     * Get price data
     */
    private function getPriceData($commodityId, $regionId, $marketCount) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . 'tbl_price_get_comm_price.php');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'commodity' => $commodityId,
            'region' => $regionId,
            'count' => $marketCount
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CropAlert/1.0)');
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($html)) {
            return [];
        }
        
        // Parse HTML to extract prices
        return $this->parsePriceData($html);
    }
    
    /**
     * Parse price data from HTML table
     */
    private function parsePriceData($html) {
        $priceData = [];
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Find all table rows
        $rows = $xpath->query('//tbody/tr');
        
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            $rowData = [];
            
            foreach ($cells as $index => $cell) {
                $text = trim($cell->textContent);
                
                // First two columns are commodity and specifications
                if ($index < 2) {
                    $rowData[] = $text;
                } else {
                    // Remaining columns are prices for each market
                    // Extract numeric price value
                    $price = $this->extractPrice($text);
                    $rowData[] = $price;
                }
            }
            
            if (!empty($rowData)) {
                $priceData[] = $rowData;
            }
        }
        
        return $priceData;
    }
    
    /**
     * Extract price value from text
     */
    private function extractPrice($text) {
        // Remove currency symbols and extract numeric value
        $text = preg_replace('/[^\d.,]/', '', $text);
        $text = str_replace(',', '', $text);
        
        return !empty($text) ? (float)$text : null;
    }
    
    /**
     * Get price date
     */
    private function getPriceDate($commodityId, $regionId) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . 'tbl_price_get_date_rice.php');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'commodity' => $commodityId,
            'region' => $regionId
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CropAlert/1.0)');
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        // Parse date from response
        $date = $this->parseDate($response);
        
        return $date ?: date('Y-m-d');
    }
    
    /**
     * Parse date from response
     */
    private function parseDate($response) {
        // Response format: "November 25, 2025"
        $response = trim($response);
        
        if (empty($response)) {
            return date('Y-m-d');
        }
        
        // Try to parse the date
        $timestamp = strtotime($response);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return date('Y-m-d');
    }
    
    /**
     * Store prices in database
     */
    private function storePrices($commodityName, $regionName, $markets, $priceData, $date) {
        $stored = 0;
        
        foreach ($priceData as $row) {
            if (count($row) < 3) continue; // Need at least commodity, spec, and one price
            
            $cropName = trim($row[0]);
            $specification = trim($row[1]);
            
            // Skip if no crop name
            if (empty($cropName)) continue;
            
            // Normalize crop name
            $cropName = $this->normalizeCropName($cropName);
            
            // Store price for each market
            for ($i = 2; $i < count($row) && ($i - 2) < count($markets); $i++) {
                $price = $row[$i];
                $marketName = $markets[$i - 2];
                
                if ($price === null || $price <= 0) continue;
                
                // Store in database
                $query = "INSERT INTO market_prices 
                         (crop_name, location, market_name, price_per_kg, date, source, specification, demand_level) 
                         VALUES (:crop_name, :location, :market_name, :price_per_kg, :date, 'bantay-presyo', :specification, 'medium')
                         ON DUPLICATE KEY UPDATE 
                         price_per_kg = VALUES(price_per_kg),
                         date = VALUES(date),
                         source = VALUES(source)";
                
                $stmt = $this->conn->prepare($query);
                $stmt->execute([
                    ':crop_name' => $cropName,
                    ':location' => $regionName,
                    ':market_name' => $marketName,
                    ':price_per_kg' => $price,
                    ':date' => $date,
                    ':specification' => $specification
                ]);
                
                $stored++;
            }
        }
        
        return $stored > 0;
    }
    
    /**
     * Normalize crop name to match your database
     */
    private function normalizeCropName($name) {
        $name = trim($name);
        
        // Map common variations
        $mappings = [
            'Rice (Regular Milled)' => 'Rice',
            'Rice (Well Milled)' => 'Rice',
            'Rice (Premium)' => 'Rice',
            'Corn (White)' => 'Corn',
            'Corn (Yellow)' => 'Corn',
            // Add more mappings as needed
        ];
        
        return $mappings[$name] ?? $name;
    }
    
    /**
     * Scrape all commodities for a region
     */
    public function scrapeAllCommodities($regionName) {
        $regionId = $this->regionMap[$regionName] ?? null;
        
        if (!$regionId) {
            error_log("Unknown region: {$regionName}");
            return false;
        }
        
        $results = [];
        
        foreach ($this->commodityMap as $commodityId => $commodityName) {
            echo "Scraping {$commodityName} for {$regionName}...\n";
            
            $success = $this->scrapePrices($commodityId, $regionId, $commodityName, $regionName);
            $results[$commodityName] = $success;
            
            // Be respectful - add delay between requests
            sleep(2);
        }
        
        return $results;
    }
}

// Run scraper if executed directly
if (php_sapi_name() === 'cli') {
    $scraper = new BantayPresyoScraper();
    
    // Default: scrape NCR (Manila) for all commodities
    $region = $argv[1] ?? 'Manila';
    
    echo "Starting Bantay Presyo scraper for region: {$region}\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    $results = $scraper->scrapeAllCommodities($region);
    
    echo "\nScraping completed!\n";
    echo "Results:\n";
    foreach ($results as $commodity => $success) {
        echo "  {$commodity}: " . ($success ? "✓ Success" : "✗ Failed") . "\n";
    }
}

