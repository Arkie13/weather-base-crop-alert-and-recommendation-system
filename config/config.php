<?php
/**
 * Weather-Based Crop Alert System - Configuration File
 * Update these settings according to your environment
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'crop_alert_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// Weather API Configuration
define('WEATHER_API_KEY', '3G6B8WCJSMLKY53J4HUY6DNSN'); // Visual Crossing API key
define('WEATHER_API_URL', 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/');
define('DEFAULT_LOCATION', 'Manila, Philippines'); // Default location for weather data
define('DEFAULT_CITY', 'Manila'); // Default city for weather data

// Crop Price API Configuration (for Market Timing Optimization)
// Multiple truly free APIs with automatic fallback for better reliability

// Commodities-API (Primary - 100 requests/month, truly free, no subscription)
// Sign up at: https://commodities-api.com/
define('CROP_PRICE_API_KEY', ''); // Get free API key (no credit card required)

// Alpha Vantage (Secondary - 5 requests/min, truly free, no subscription)
// Sign up at: https://www.alphavantage.co/support/#api-key
define('ALPHAVANTAGE_API_KEY', 'LPU38OLD0Z2AME0A'); // ✅ Configured

// Twelve Data (Tertiary - Free tier available, verify current terms)
// Sign up at: https://twelvedata.com/commodities
define('TWELVEDATA_API_KEY', ''); // Optional

// USDA My Market News (Historical data - Free, requires registration)
// Sign up at: https://mymarketnews.ams.usda.gov/
define('USDA_API_KEY', ''); // Optional

// API Ninjas (FREE - Primary crop price API, no subscription required)
// Sign up at: https://api-ninjas.com/
define('API_NINJAS_KEY', 'IjBb7N7Djp56p58cjPvjJA==wFVm8Xx1aUPrde5m'); // ✅ Configured

// API Configuration
define('CROP_PRICE_API_URL', 'https://api.commodities-api.com/');
define('CROP_PRICE_UPDATE_INTERVAL', 24); // Hours between price updates
define('CROP_PRICE_CACHE_DURATION', 24); // Hours to cache prices before fetching new data
define('USD_TO_PHP_RATE', 55.0); // USD to PHP conversion rate (update periodically)

// Application Configuration
define('APP_NAME', 'Weather-Based Crop Alert System');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'Asia/Manila');

// Alert Configuration
define('DROUGHT_THRESHOLD_RAINFALL', 5); // mm per day average
define('DROUGHT_THRESHOLD_DAYS', 7); // days of low rainfall
define('STORM_THRESHOLD_WIND', 50); // km/h
define('STORM_THRESHOLD_RAIN', 20); // mm
define('HEAVY_RAIN_THRESHOLD', 30); // mm
define('EXTREME_HEAT_THRESHOLD', 35); // Celsius
define('EXTREME_COLD_THRESHOLD', 10); // Celsius

// System Configuration
define('WEATHER_UPDATE_INTERVAL', 30); // minutes
define('MAX_ALERTS_PER_PAGE', 50);
define('MAX_FARMERS_PER_PAGE', 100);

// Security Configuration
define('ENABLE_CORS', true);
define('ALLOWED_ORIGINS', '*'); // For production, specify actual domains

// Logging Configuration
define('ENABLE_LOGGING', true);
define('LOG_FILE', 'logs/system.log');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', ''); // Gmail address
define('SMTP_PASSWORD', ''); // Gmail app password
define('SMTP_FROM_EMAIL', ''); // Should match SMTP_USERNAME
define('SMTP_FROM_NAME', 'Crop Alert System');
define('SMTP_ENCRYPTION', 'tls'); // TLS encryption for Gmail

// Email Verification Configuration
define('VERIFICATION_CODE_EXPIRY_MINUTES', 15); // Verification code expires in 15 minutes
define('VERIFICATION_CODE_LENGTH', 6); // 6-digit verification code
define('MAX_VERIFICATION_ATTEMPTS', 5); // Max attempts per email per hour
define('MAX_RESEND_ATTEMPTS', 3); // Max resend attempts per email per hour

// Email Template Paths
define('EMAIL_TEMPLATE_PATH', __DIR__ . '/../templates/');
define('EMAIL_VERIFICATION_TEMPLATE', EMAIL_TEMPLATE_PATH . 'email-verification.html');
define('EMAIL_ALERT_TEMPLATE', EMAIL_TEMPLATE_PATH . 'email-alert.html');
define('EMAIL_WELCOME_TEMPLATE', EMAIL_TEMPLATE_PATH . 'email-welcome.html');

// Email Notification Configuration
define('EMAIL_BATCH_SIZE', 10); // Number of emails to send per batch
define('EMAIL_BATCH_DELAY_SECONDS', 2); // Delay between batches in seconds

// SMS Configuration (for future notifications)
define('SMS_API_KEY', '');
define('SMS_API_URL', '');
define('SMS_SENDER_ID', 'CROPALERT');

// File Upload Configuration
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

// Cache Configuration
define('ENABLE_CACHE', true);
define('CACHE_DURATION', 300); // 5 minutes

// Backup Configuration
define('BACKUP_ENABLED', true);
define('BACKUP_RETENTION_DAYS', 30); // Keep backups for 30 days
define('BACKUP_STORAGE_PATH', __DIR__ . '/../backups/');
define('BACKUP_MAX_SIZE_MB', 1000); // Max backup size in MB
define('AUTO_BACKUP_SCHEDULE', 'daily'); // daily, weekly, monthly
define('BACKUP_COMPRESSION', true); // Use gzip compression
define('BACKUP_DATABASE_PATH', BACKUP_STORAGE_PATH . 'database/');
define('BACKUP_FILES_PATH', BACKUP_STORAGE_PATH . 'files/');
define('BACKUP_LOGS_PATH', BACKUP_STORAGE_PATH . 'logs/');
define('BACKUP_LOG_FILE', BACKUP_LOGS_PATH . 'backup.log');

// Development Configuration
define('DEBUG_MODE', true);
define('SHOW_ERRORS', true);

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Error reporting
// For API endpoints, always suppress error display to prevent HTML output in JSON responses
if (DEBUG_MODE && SHOW_ERRORS && !defined('API_MODE')) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL); // Still report errors to logs
    ini_set('display_errors', 0); // But don't display them (prevents HTML in JSON)
}

// Create logs directory if it doesn't exist
if (ENABLE_LOGGING && !is_dir('logs')) {
    mkdir('logs', 0755, true);
}

/**
 * Get configuration value
 */
function getConfig($key, $default = null) {
    return defined($key) ? constant($key) : $default;
}

/**
 * Log message to file
 */
function logMessage($message, $level = 'INFO') {
    if (!ENABLE_LOGGING) return;
    
    $logFile = LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Get database connection
 */
function getDatabaseConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        logMessage("Database connection failed: " . $e->getMessage(), 'ERROR');
        throw new Exception("Database connection failed");
    }
}

/**
 * Send CORS headers
 */
function sendCorsHeaders() {
    if (ENABLE_CORS) {
        header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}

/**
 * Validate API key (for future use)
 */
function validateApiKey($key) {
    // Implement API key validation logic here
    return true; // For now, always return true
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate unique ID
 */
function generateUniqueId($prefix = '') {
    return $prefix . uniqid() . '_' . time();
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'Y-m-d H:i:s') {
    if (is_string($date)) {
        $date = new DateTime($date);
    }
    return $date->format($format);
}

/**
 * Get current season
 */
function getCurrentSeason() {
    $month = (int)date('n');
    if ($month >= 3 && $month <= 5) return 'spring';
    if ($month >= 6 && $month <= 8) return 'summer';
    if ($month >= 9 && $month <= 11) return 'autumn';
    return 'winter';
}

/**
 * Check if system is in maintenance mode
 */
function isMaintenanceMode() {
    return file_exists('maintenance.flag');
}

/**
 * Get system status
 */
function getSystemStatus() {
    $status = [
        'database' => false,
        'weather_api' => false,
        'disk_space' => false,
        'memory_usage' => false
    ];
    
    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->query("SELECT 1");
        $status['database'] = true;
    } catch (Exception $e) {
        $status['database'] = false;
    }
    
    // Check weather API
    if (!empty(WEATHER_API_KEY) && WEATHER_API_KEY !== 'your_openweathermap_api_key') {
        $status['weather_api'] = true;
    }
    
    // Check disk space
    $freeBytes = disk_free_space('.');
    $totalBytes = disk_total_space('.');
    $freePercent = ($freeBytes / $totalBytes) * 100;
    $status['disk_space'] = $freePercent > 10; // At least 10% free
    
    // Check memory usage
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $status['memory_usage'] = $memoryUsage < (int)$memoryLimit * 0.8; // Less than 80% of limit
    
    return $status;
}
?>
