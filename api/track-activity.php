<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class ActivityTracker {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->createActivityTable();
    }
    
    public function trackActivity() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                return [
                    'success' => false,
                    'message' => 'Invalid input data'
                ];
            }
            
            $userId = $input['user_id'] ?? null;
            $activityType = $input['activity_type'] ?? 'dashboard_view';
            
            if (!$userId) {
                return [
                    'success' => false,
                    'message' => 'User ID is required'
                ];
            }
            
            // Insert activity record
            $query = "INSERT INTO user_activity_log (user_id, activity_type, ip_address, user_agent, created_at) 
                     VALUES (:user_id, :activity_type, :ip_address, :user_agent, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':activity_type', $activityType);
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? '');
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Activity tracked successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to track activity'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to track activity: ' . $e->getMessage()
            ];
        }
    }
    
    public function getRecentActivities() {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $action = $_GET['action'] ?? 'recent';
            $t = isset($_GET['t']) ? (int)$_GET['t'] : null;
            
            // If 't' parameter is provided, use it as limit
            if ($t !== null && $t > 0) {
                $limit = $t;
            }
            
            // Ensure limit is reasonable
            $limit = min(max(1, $limit), 100);
            
            $query = "SELECT ual.*, u.username, u.full_name 
                     FROM user_activity_log ual 
                     LEFT JOIN users u ON ual.user_id = u.id 
                     ORDER BY ual.created_at DESC 
                     LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format activities for display
            $formattedActivities = array_map(function($activity) {
                return [
                    'id' => $activity['id'],
                    'user_id' => $activity['user_id'],
                    'username' => $activity['username'] ?? 'Unknown',
                    'full_name' => $activity['full_name'] ?? 'Unknown User',
                    'activity_type' => $activity['activity_type'],
                    'created_at' => $activity['created_at'],
                    'ip_address' => $activity['ip_address']
                ];
            }, $activities);
            
            return [
                'success' => true,
                'data' => $formattedActivities,
                'count' => count($formattedActivities)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch activities: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    private function createActivityTable() {
        try {
            $query = "CREATE TABLE IF NOT EXISTS user_activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                activity_type ENUM('login', 'dashboard_view', 'crop_add', 'alert_view', 'profile_update') NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            
            $this->conn->exec($query);
            
            // Create indexes
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_user_activity_user_id ON user_activity_log(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_activity_created_at ON user_activity_log(created_at)",
                "CREATE INDEX IF NOT EXISTS idx_user_activity_type ON user_activity_log(activity_type)"
            ];
            
            foreach ($indexes as $indexQuery) {
                $this->conn->exec($indexQuery);
            }
            
        } catch (Exception $e) {
            error_log("Failed to create user_activity_log table: " . $e->getMessage());
        }
    }
}

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Handle the request
$tracker = new ActivityTracker();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $tracker->trackActivity();
    echo json_encode($result);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $tracker->getRecentActivities();
    echo json_encode($result);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
