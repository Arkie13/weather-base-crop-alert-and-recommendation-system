<?php
/**
 * Weather Check Cron Job
 * 
 * This script should be run every 30 minutes via cron job
 * Example cron entry: */30 * * * * /usr/bin/php /path/to/your/project/cron/weather-check-cron.php
 */

// Set the working directory to the project root
chdir(dirname(__DIR__));

// Include the weather scheduler
require_once 'api/weather-scheduler.php';

// Create a simple logger
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    file_put_contents('logs/weather-cron.log', $logMessage, FILE_APPEND | LOCK_EX);
}

try {
    logMessage("Starting weather check cron job");
    
    $scheduler = new WeatherScheduler();
    $result = $scheduler->runWeatherCheck();
    
    if ($result['success']) {
        logMessage("Weather check completed successfully: " . json_encode($result['data']));
    } else {
        logMessage("Weather check failed: " . $result['message']);
    }
    
} catch (Exception $e) {
    logMessage("Weather check cron job failed with exception: " . $e->getMessage());
}

logMessage("Weather check cron job finished");
?>
