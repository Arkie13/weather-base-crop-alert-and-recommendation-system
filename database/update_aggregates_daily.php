<?php
/**
 * Daily Aggregate Tables Update Script
 * 
 * This script should be run daily (via cron) to update aggregate tables
 * Run this script: php database/update_aggregates_daily.php
 * 
 * Recommended cron schedule: Run daily at 1:00 AM
 * Example: 0 1 * * * /usr/bin/php /path/to/project/database/update_aggregates_daily.php
 */

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Starting aggregate tables update...\n";
    echo "Date: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Update all daily aggregates for today
    echo "Updating daily aggregates for " . date('Y-m-d') . "...\n";
    $stmt = $conn->prepare("CALL update_all_daily_aggregates()");
    $stmt->execute();
    echo "✓ Daily aggregates updated\n";
    
    // Update yesterday's aggregates (in case of late data)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    echo "\nUpdating aggregates for yesterday ($yesterday)...\n";
    
    $stmt = $conn->prepare("CALL update_daily_weather_aggregates(?)");
    $stmt->execute([$yesterday]);
    echo "✓ Weather aggregates for yesterday updated\n";
    
    $stmt = $conn->prepare("CALL update_alert_statistics_aggregates(?)");
    $stmt->execute([$yesterday]);
    echo "✓ Alert aggregates for yesterday updated\n";
    
    $stmt = $conn->prepare("CALL update_farmer_statistics_aggregates(?)");
    $stmt->execute([$yesterday]);
    echo "✓ Farmer aggregates for yesterday updated\n";
    
    $stmt = $conn->prepare("CALL update_crop_statistics_aggregates(?)");
    $stmt->execute([date('Y-m-d')]); // Crop stats are current, use today
    echo "✓ Crop aggregates updated\n";
    
    $stmt = $conn->prepare("CALL update_daily_user_statistics(?)");
    $stmt->execute([$yesterday]);
    echo "✓ User statistics for yesterday updated\n";
    
    // Update monthly aggregates for current month
    $currentYear = date('Y');
    $currentMonth = date('n');
    echo "\nUpdating monthly weather aggregates for $currentYear-$currentMonth...\n";
    $stmt = $conn->prepare("CALL update_monthly_weather_aggregates(?, ?)");
    $stmt->execute([$currentYear, $currentMonth]);
    echo "✓ Monthly weather aggregates updated\n";
    
    echo "\n========================================\n";
    echo "Aggregate tables update completed successfully!\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "ERROR: Failed to update aggregate tables\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>

