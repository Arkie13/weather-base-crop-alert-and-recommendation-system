<?php
/**
 * Daily Crop Prices Update Cron Job
 * 
 * This script should be run daily (e.g., at 6 AM) to update crop prices
 * 
 * Setup cron job:
 * 0 6 * * * /usr/bin/php /path/to/project_v1/cron/update-crop-prices.php
 * 
 * Or for Windows Task Scheduler:
 * php.exe C:\xampp\htdocs\project_v1\cron\update-crop-prices.php
 */

// Set execution time limit
set_time_limit(300); // 5 minutes

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/crop-prices.php';

// Log file
$logFile = __DIR__ . '/../logs/crop-prices-update.log';

/**
 * Log message to file
 */
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    echo $logEntry; // Also output to console
}

// Start logging
logMessage("=== Crop Prices Update Started ===");

try {
    // First, try to scrape Bantay Presyo data (most accurate source)
    logMessage("Step 1: Scraping Bantay Presyo data...");
    try {
        require_once __DIR__ . '/bantay-presyo-scraper.php';
        $scraper = new BantayPresyoScraper();
        
        // Scrape priority regions
        $priorityRegions = ['Manila', 'Cebu', 'Davao'];
        foreach ($priorityRegions as $region) {
            logMessage("Scraping Bantay Presyo for: $region");
            $scraper->scrapeAllCommodities($region);
            sleep(2); // Be respectful
        }
        logMessage("Bantay Presyo scraping completed");
    } catch (Exception $e) {
        logMessage("Bantay Presyo scraping failed (non-critical): " . $e->getMessage());
        // Continue with other price sources
    }
    
    // Then update prices from other sources
    logMessage("Step 2: Updating prices from other sources...");
    $api = new CropPricesAPI();
    
    // Get all locations from database (or use default)
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get unique locations from users table
    $query = "SELECT DISTINCT location FROM users WHERE location IS NOT NULL AND location != ''";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If no locations found, use default
    if (empty($locations)) {
        $locations = ['Manila'];
    }
    
    logMessage("Found " . count($locations) . " location(s) to update");
    
    $totalUpdated = 0;
    $totalFailed = 0;
    
    // Update prices for each location
    foreach ($locations as $location) {
        logMessage("Updating prices for location: $location");
        
        $result = $api->updateAllPrices($location);
        
        if ($result['success']) {
            $updated = 0;
            $failed = 0;
            
            foreach ($result['results'] as $crop => $cropResult) {
                if (isset($cropResult['success']) && $cropResult['success']) {
                    $updated++;
                    logMessage("  ✓ $crop: " . ($cropResult['price_per_kg'] ?? 'N/A') . " PHP/kg");
                } else {
                    $failed++;
                    logMessage("  ✗ $crop: Failed - " . ($cropResult['error'] ?? 'Unknown error'));
                }
            }
            
            $totalUpdated += $updated;
            $totalFailed += $failed;
            
            logMessage("Location $location: $updated updated, $failed failed");
        } else {
            logMessage("Failed to update prices for location: $location");
            $totalFailed++;
        }
    }
    
    logMessage("=== Update Complete ===");
    logMessage("Total updated: $totalUpdated");
    logMessage("Total failed: $totalFailed");
    
    // Exit with success code
    exit(0);
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
?>

