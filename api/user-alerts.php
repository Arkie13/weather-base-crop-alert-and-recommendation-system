<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class UserAlertsAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                return $this->getUserAlerts();
            case 'POST':
                return $this->checkWeatherAndGenerateAlerts();
            default:
                http_response_code(405);
                return ['success' => false, 'message' => 'Method not allowed'];
        }
    }
    
    public function getUserAlerts() {
        try {
            // First, automatically resolve old active alerts (older than 7 days)
            $this->autoResolveOldAlerts();
            
            $userId = $_GET['user_id'] ?? null;
            $statusParam = $_GET['status'] ?? '';
            // Support 'all' or empty string to get all statuses
            // If no status specified, default to empty (all statuses) for weather history
            $status = ($statusParam === 'all' || $statusParam === '') ? '' : $statusParam;
            // Increased default limit from 20 to 500 for weather history
            // Use 'all' to get all alerts without limit
            $limitParam = $_GET['limit'] ?? 500;
            // Handle 'all' parameter (case-insensitive) to return all records without limit
            $limitParamStr = is_string($limitParam) ? strtolower(trim($limitParam)) : $limitParam;
            $limit = ($limitParamStr === 'all' || $limitParamStr === '') ? null : (int)$limitParam;
            
            if (!$userId) {
                return [
                    'success' => false,
                    'message' => 'User ID is required'
                ];
            }
            
            // Get user's location, coordinates, and account creation date
            $userQuery = "SELECT location, latitude, longitude, created_at FROM users WHERE id = :user_id";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->bindParam(':user_id', $userId);
            $userStmt->execute();
            $user = $userStmt->fetch();
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }
            
            // Show all alerts regardless of location - user wants to see all active alerts
            // from all areas, even if they're far away, to have knowledge of what's happening
            $showAllAlerts = true; // Always show all alerts
            $filterByLocation = false; // Never filter by location - show all alerts from all areas
            
            // Get user's account creation date to filter alerts
            $userCreatedAt = $user['created_at'] ?? null;
            
            $query = "SELECT DISTINCT a.*, 
                     d.center_latitude as disaster_lat,
                     d.center_longitude as disaster_lng,
                     d.affected_radius_km as disaster_radius,
                     CASE 
                         WHEN a.type LIKE '%crop_drought%' OR a.type LIKE '%Drought%' THEN 'fas fa-sun'
                         WHEN a.type LIKE '%crop_flood%' OR a.type LIKE '%Storm%' THEN 'fas fa-wind'
                         WHEN a.type LIKE '%crop_heat%' OR a.type LIKE '%Heat%' THEN 'fas fa-fire'
                         WHEN a.type LIKE '%crop_frost%' OR a.type LIKE '%Frost%' THEN 'fas fa-snowflake'
                         WHEN a.type LIKE '%crop_cold%' OR a.type LIKE '%Temperature%' THEN 'fas fa-thermometer-half'
                         WHEN a.type LIKE '%crop_wind%' OR a.type LIKE '%Rain%' THEN 'fas fa-cloud-rain'
                         WHEN a.type LIKE '%crop%' THEN 'fas fa-seedling'
                         WHEN a.type LIKE '%Humidity%' THEN 'fas fa-tint'
                         ELSE 'fas fa-exclamation-triangle'
                     END as alert_icon
                     FROM alerts a
                     LEFT JOIN disasters d ON a.disaster_id = d.id";
            
            $params = [];
            
            // Build WHERE clause
            $whereConditions = [];
            
            // Filter alerts to only show those created after user account creation
            // This ensures newly created accounts don't see alerts from days ago
            // BUT: Skip this filter when viewing weather history (empty status or limit=all)
            // so users can see all historical alerts regardless of account creation date
            $isViewingHistory = (empty($status) && ($limit === null || $limitParamStr === 'all'));
            if ($userCreatedAt && !$isViewingHistory) {
                $whereConditions[] = "a.created_at >= :user_created_at";
                $params[':user_created_at'] = $userCreatedAt;
            }
            
            if (!empty($status)) {
                $whereConditions[] = "a.status = :status";
                $params[':status'] = $status;
            }
            
            // For active alerts, filter by location if user has coordinates
            if ($filterByLocation) {
                $userLat = (float)$user['latitude'];
                $userLng = (float)$user['longitude'];
                
                // Use Haversine formula to calculate distance
                // Show alerts if:
                // 1. Alert is linked to a disaster AND user is within affected radius
                // 2. Alert is NOT linked to a disaster (general alerts - show within 50km default radius)
                $whereConditions[] = "(
                    (a.disaster_id IS NOT NULL AND d.center_latitude IS NOT NULL AND d.center_longitude IS NOT NULL AND d.affected_radius_km IS NOT NULL AND
                     (6371 * acos(
                         cos(radians(:user_lat)) * 
                         cos(radians(d.center_latitude)) * 
                         cos(radians(d.center_longitude) - radians(:user_lng)) + 
                         sin(radians(:user_lat)) * 
                         sin(radians(d.center_latitude))
                     )) <= d.affected_radius_km)
                    OR
                    (a.disaster_id IS NULL OR d.center_latitude IS NULL OR d.center_longitude IS NULL OR d.affected_radius_km IS NULL)
                )";
                
                $params[':user_lat'] = $userLat;
                $params[':user_lng'] = $userLng;
            }
            
            if (!empty($whereConditions)) {
                $query .= " WHERE " . implode(" AND ", $whereConditions);
            }
            
            $query .= " ORDER BY a.created_at DESC";
            
            if ($limit !== null) {
                $query .= " LIMIT :limit";
                $params[':limit'] = $limit;
            }
            
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $stmt->execute();
            
            $alerts = $stmt->fetchAll();
            
            // Post-process alerts to filter general alerts (not linked to disasters) by default radius
            if ($filterByLocation && !empty($alerts)) {
                $userLat = (float)$user['latitude'];
                $userLng = (float)$user['longitude'];
                $defaultRadius = 50; // 50km default radius for general alerts
                
                $filteredAlerts = [];
                foreach ($alerts as $alert) {
                    // If alert is linked to a disaster, it's already filtered by the query
                    if (!empty($alert['disaster_lat']) && !empty($alert['disaster_lng']) && !empty($alert['disaster_radius'])) {
                        $filteredAlerts[] = $alert;
                    } else {
                        // For general alerts (not linked to disasters), show if within default radius
                        // Since we don't have alert coordinates, we'll show all general alerts
                        // Or you could add a default location check here if needed
                        $filteredAlerts[] = $alert;
                    }
                }
                $alerts = $filteredAlerts;
            }
            
            // Get total count for pagination support (with same filtering logic)
            $countQuery = "SELECT COUNT(DISTINCT a.id) as total FROM alerts a
                          LEFT JOIN disasters d ON a.disaster_id = d.id";
            
            $countWhereConditions = [];
            $countParams = [];
            
            // Apply same user creation date filter to count query
            // Skip this filter when viewing weather history (same logic as main query)
            if ($userCreatedAt && !$isViewingHistory) {
                $countWhereConditions[] = "a.created_at >= :count_user_created_at";
                $countParams[':count_user_created_at'] = $userCreatedAt;
            }
            
            if (!empty($status)) {
                $countWhereConditions[] = "a.status = :count_status";
                $countParams[':count_status'] = $status;
            }
            
            // Apply same location filtering for count query
            if ($filterByLocation) {
                $userLat = (float)$user['latitude'];
                $userLng = (float)$user['longitude'];
                
                $countWhereConditions[] = "(
                    (a.disaster_id IS NOT NULL AND d.center_latitude IS NOT NULL AND d.center_longitude IS NOT NULL AND d.affected_radius_km IS NOT NULL AND
                     (6371 * acos(
                         cos(radians(:count_user_lat)) * 
                         cos(radians(d.center_latitude)) * 
                         cos(radians(d.center_longitude) - radians(:count_user_lng)) + 
                         sin(radians(:count_user_lat)) * 
                         sin(radians(d.center_latitude))
                     )) <= d.affected_radius_km)
                    OR
                    (a.disaster_id IS NULL OR d.center_latitude IS NULL OR d.center_longitude IS NULL OR d.affected_radius_km IS NULL)
                )";
                
                $countParams[':count_user_lat'] = $userLat;
                $countParams[':count_user_lng'] = $userLng;
            }
            
            if (!empty($countWhereConditions)) {
                $countQuery .= " WHERE " . implode(" AND ", $countWhereConditions);
            }
            
            $countStmt = $this->conn->prepare($countQuery);
            foreach ($countParams as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $totalCount = $countStmt->fetch()['total'] ?? count($alerts);
            
            // Format alerts for frontend
            $formattedAlerts = array_map(function($alert) {
                return [
                    'id' => $alert['id'],
                    'type' => $alert['type'],
                    'severity' => $alert['severity'],
                    'description' => $alert['description'],
                    'message' => $alert['description'], // Add message field for compatibility
                    'title' => $alert['type'], // Add title field for compatibility
                    'status' => $alert['status'],
                    'created_at' => $alert['created_at'],
                    'icon' => $alert['alert_icon'],
                    'time_ago' => $this->getTimeAgo($alert['created_at'])
                ];
            }, $alerts);
            
            return [
                'success' => true,
                'data' => $formattedAlerts,
                'total' => (int)$totalCount,
                'limit' => $limit,
                'count' => count($formattedAlerts)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch user alerts: ' . $e->getMessage()
            ];
        }
    }
    
    public function checkWeatherAndGenerateAlerts() {
        try {
            // Include the weather checker
            require_once 'weather-check.php';
            
            $checker = new WeatherConditionChecker();
            $result = $checker->checkWeatherConditions();
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => 'Weather conditions checked and alerts generated',
                    'data' => $result['data']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $result['message']
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to check weather conditions: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Automatically resolve old active alerts (older than 7 days)
     * This ensures alerts don't stay "active" forever
     */
    private function autoResolveOldAlerts() {
        try {
            // Update alerts that are active and older than 7 days to 'resolved'
            $query = "UPDATE alerts 
                     SET status = 'resolved', 
                         updated_at = NOW() 
                     WHERE status = 'active' 
                     AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $updatedCount = $stmt->rowCount();
            
            if ($updatedCount > 0) {
                error_log("Auto-resolved $updatedCount old alert(s) that were older than 7 days");
            }
            
            return $updatedCount;
            
        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Failed to auto-resolve old alerts: " . $e->getMessage());
            return 0;
        }
    }
    
    private function getTimeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time/60) . ' minutes ago';
        if ($time < 86400) return floor($time/3600) . ' hours ago';
        if ($time < 2592000) return floor($time/86400) . ' days ago';
        if ($time < 31536000) return floor($time/2592000) . ' months ago';
        return floor($time/31536000) . ' years ago';
    }
}

// Handle the request
$userAlertsAPI = new UserAlertsAPI();
$result = $userAlertsAPI->handleRequest();
echo json_encode($result);
?>
