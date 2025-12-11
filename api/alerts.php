<?php
// Start output buffering to catch any stray output
if (!ob_get_level()) {
    ob_start();
}

// Set error handler to prevent HTML error output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Suppress default error output
}, E_ALL);

// Set exception handler
set_exception_handler(function($exception) {
    ob_clean(); // Clear any output
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $exception->getMessage(),
        'error_type' => 'server_error'
    ]);
    exit;
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/email-notifications.php';
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load required files: ' . $e->getMessage(),
        'error_type' => 'initialization_error'
    ]);
    exit;
}

class AlertsAPI {
    private $conn;
    private $emailNotificationService;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->emailNotificationService = new EmailNotificationService();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                return $this->getAlerts();
            case 'POST':
                return $this->createAlert();
            case 'PUT':
                return $this->updateAlert();
            default:
                http_response_code(405);
                return ['success' => false, 'message' => 'Method not allowed'];
        }
    }
    
    public function getAlerts() {
        try {
            // First, automatically resolve old active alerts (older than 7 days)
            $this->autoResolveOldAlerts();
            
            $status = $_GET['status'] ?? '';
            // Increased default limit from 50 to 500 to show more historical alerts
            // Use 'all' to get all alerts without limit
            $limitParam = $_GET['limit'] ?? 500;
            $limit = ($limitParam === 'all' || $limitParam === '') ? null : (int)$limitParam;
            
            $query = "SELECT a.*, COUNT(af.farmer_id) as affected_farmers 
                     FROM alerts a 
                     LEFT JOIN alert_farmers af ON a.id = af.alert_id 
                     WHERE 1=1";
            
            $params = [];
            
            if (!empty($status)) {
                $query .= " AND a.status = :status";
                $params[':status'] = $status;
            }
            
            $query .= " GROUP BY a.id ORDER BY a.created_at DESC";
            
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
            
            // Also get total count for pagination support
            $countQuery = "SELECT COUNT(DISTINCT a.id) as total 
                          FROM alerts a 
                          LEFT JOIN alert_farmers af ON a.id = af.alert_id 
                          WHERE 1=1";
            if (!empty($status)) {
                $countQuery .= " AND a.status = :status";
            }
            $countStmt = $this->conn->prepare($countQuery);
            if (!empty($status)) {
                $countStmt->bindValue(':status', $status);
            }
            $countStmt->execute();
            $totalCount = $countStmt->fetch()['total'] ?? count($alerts);
            
            return [
                'success' => true,
                'data' => $alerts,
                'total' => (int)$totalCount,
                'limit' => $limit,
                'count' => count($alerts)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch alerts: ' . $e->getMessage()
            ];
        }
    }
    
    public function createAlert() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $type = $input['type'] ?? '';
            $severity = $input['severity'] ?? 'medium';
            $description = $input['description'] ?? '';
            $affected_farmers = $input['affected_farmers'] ?? [];
            
            if (empty($type) || empty($description)) {
                return [
                    'success' => false,
                    'message' => 'Alert type and description are required'
                ];
            }
            
            // Start transaction
            $this->conn->beginTransaction();
            
            // Insert alert
            $query = "INSERT INTO alerts (type, severity, description, status, created_at) 
                     VALUES (:type, :severity, :description, 'active', NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':severity', $severity);
            $stmt->bindParam(':description', $description);
            
            if ($stmt->execute()) {
                $alertId = $this->conn->lastInsertId();
                
                // Insert affected farmers
                if (!empty($affected_farmers)) {
                    $farmerQuery = "INSERT INTO alert_farmers (alert_id, farmer_id) VALUES (:alert_id, :farmer_id)";
                    $farmerStmt = $this->conn->prepare($farmerQuery);
                    
                    foreach ($affected_farmers as $farmerId) {
                        $farmerStmt->bindParam(':alert_id', $alertId);
                        $farmerStmt->bindParam(':farmer_id', $farmerId);
                        $farmerStmt->execute();
                    }
                }
                
                $this->conn->commit();
                
                // Send email notifications to users (async/non-blocking)
                // Don't fail alert creation if email sending fails
                try {
                    $emailResult = $this->emailNotificationService->sendAlertEmails($alertId);
                    if ($emailResult['success']) {
                        error_log("Alert emails sent: {$emailResult['emails_sent']} successful, {$emailResult['emails_failed']} failed");
                    }
                } catch (Exception $e) {
                    // Log error but don't fail alert creation
                    error_log("Failed to send alert emails: " . $e->getMessage());
                }
                
                return [
                    'success' => true,
                    'message' => 'Alert created successfully',
                    'data' => ['id' => $alertId]
                ];
            } else {
                $this->conn->rollback();
                return [
                    'success' => false,
                    'message' => 'Failed to create alert'
                ];
            }
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => 'Failed to create alert: ' . $e->getMessage()
            ];
        }
    }
    
    public function updateAlert() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $id = $input['id'] ?? '';
            $status = $input['status'] ?? '';
            $description = $input['description'] ?? '';
            
            if (empty($id)) {
                return [
                    'success' => false,
                    'message' => 'Alert ID is required'
                ];
            }
            
            $query = "UPDATE alerts SET ";
            $params = [];
            
            if (!empty($status)) {
                $query .= "status = :status, ";
                $params[':status'] = $status;
            }
            
            if (!empty($description)) {
                $query .= "description = :description, ";
                $params[':description'] = $description;
            }
            
            $query .= "updated_at = NOW() WHERE id = :id";
            $params[':id'] = $id;
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Alert updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update alert'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update alert: ' . $e->getMessage()
            ];
        }
    }
    
    public function getAlertById($id) {
        try {
            $query = "SELECT a.*, COUNT(af.farmer_id) as affected_farmers 
                     FROM alerts a 
                     LEFT JOIN alert_farmers af ON a.id = af.alert_id 
                     WHERE a.id = :id 
                     GROUP BY a.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            $alert = $stmt->fetch();
            
            if ($alert) {
                return [
                    'success' => true,
                    'data' => $alert
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Alert not found'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch alert: ' . $e->getMessage()
            ];
        }
    }
    
    public function getAffectedFarmers($alertId) {
        try {
            $query = "SELECT f.* FROM farmers f 
                     INNER JOIN alert_farmers af ON f.id = af.farmer_id 
                     WHERE af.alert_id = :alert_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':alert_id', $alertId);
            $stmt->execute();
            
            $farmers = $stmt->fetchAll();
            
            return [
                'success' => true,
                'data' => $farmers
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch affected farmers: ' . $e->getMessage()
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
}

// Handle the request
try {
    $alertsAPI = new AlertsAPI();
    $result = $alertsAPI->handleRequest();
    
    // Clean any output before sending JSON
    ob_clean();
    
    // Ensure result is always an array
    if (!is_array($result)) {
        $result = [
            'success' => false,
            'message' => 'Invalid response format'
        ];
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Clean any output
    ob_clean();
    
    // Log the error
    error_log("Alerts API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Return JSON error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage(),
        'error_type' => 'exception'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Error $e) {
    // Clean any output
    ob_clean();
    
    // Log the error
    error_log("Alerts API Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Return JSON error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again later.',
        'error_type' => 'fatal_error'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// End output buffering if it was started
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>
