<?php
/**
 * Bantay Presyo Automatic Scraper Cron Job
 * 
 * This script scrapes daily price data from DA's Bantay Presyo website
 * and stores it in the local database for accurate Philippine market prices.
 * 
 * Setup cron job (Linux/Unix):
 * 0 6 * * * /usr/bin/php /path/to/project_v1/cron/bantay-presyo-scraper.php
 * 
 * Or for Windows Task Scheduler:
 * php.exe C:\xampp\htdocs\project_v1\cron\bantay-presyo-scraper.php
 * 
 * Recommended: Run daily at 6 AM (after DA updates their data)
 */

// Set execution time limit
set_time_limit(600); // 10 minutes (scraping can take time)

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/bantay-presyo-scraper.php';

// Log file
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/bantay-presyo-scraper.log';

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
logMessage("=== Bantay Presyo Scraper Started ===");

try {
    $scraper = new BantayPresyoScraper();
    
    // Priority regions to scrape (most important locations)
    $priorityRegions = [
        'Manila' => 'NCR',
        'Cebu' => 'Region VII',
        'Davao' => 'Region XI',
        'Baguio' => 'CAR',
        'Iloilo' => 'Region VI',
        'Cagayan de Oro' => 'Region X',
    ];
    
    $totalSuccess = 0;
    $totalFailed = 0;
    
    logMessage("Scraping prices for " . count($priorityRegions) . " priority regions");
    
    foreach ($priorityRegions as $regionName => $regionCode) {
        logMessage("--- Scraping region: $regionName ($regionCode) ---");
        
        try {
            $results = $scraper->scrapeAllCommodities($regionName);
            
            $successCount = 0;
            $failCount = 0;
            
            foreach ($results as $commodity => $success) {
                if ($success) {
                    $successCount++;
                    logMessage("  ✓ $commodity: Success");
                } else {
                    $failCount++;
                    logMessage("  ✗ $commodity: Failed");
                }
            }
            
            if ($successCount > 0) {
                $totalSuccess += $successCount;
                logMessage("Region $regionName: $successCount commodities scraped successfully");
            }
            
            if ($failCount > 0) {
                $totalFailed += $failCount;
                logMessage("Region $regionName: $failCount commodities failed");
            }
            
            // Be respectful - add delay between regions
            sleep(3);
            
        } catch (Exception $e) {
            logMessage("ERROR scraping region $regionName: " . $e->getMessage());
            $totalFailed++;
        }
    }
    
    logMessage("=== Scraping Complete ===");
    logMessage("Total successful: $totalSuccess");
    logMessage("Total failed: $totalFailed");
    
    // Also trigger price update to refresh cached prices
    logMessage("Triggering price cache refresh...");
    require_once __DIR__ . '/../api/crop-prices.php';
    $priceAPI = new CropPricesAPI();
    
    foreach (array_keys($priorityRegions) as $location) {
        try {
            $result = $priceAPI->updateAllPrices($location);
            if ($result['success']) {
                logMessage("Price cache refreshed for: $location");
            }
        } catch (Exception $e) {
            logMessage("Failed to refresh cache for $location: " . $e->getMessage());
        }
    }
    
    logMessage("=== All Tasks Complete ===");
    
    // Exit with success code
    exit(0);
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
?>
