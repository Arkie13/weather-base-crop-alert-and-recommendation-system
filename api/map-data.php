<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class MapDataAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';
        
        switch ($method) {
            case 'GET':
                switch ($action) {
                    case 'locations':
                        return $this->getAllLocations();
                    case 'farmers':
                        return $this->getFarmerLocations();
                    case 'disasters':
                        return $this->getDisasters();
                    case 'affected-areas':
                        return $this->getAffectedAreas();
                    case 'alerts':
                        return $this->getAlertsWithLocations();
                    default:
                        return $this->getAllMapData();
                }
            case 'POST':
                return $this->createDisaster();
            case 'PUT':
                return $this->updateLocation();
            default:
                http_response_code(405);
                return ['success' => false, 'message' => 'Method not allowed'];
        }
    }
    
    public function getAllLocations() {
        try {
            // Get all users with locations
            // Users and farmers are the same - farmers are users with role='farmer'
            $query = "SELECT id, full_name as name, location, latitude, longitude, role, 
                     email, phone, created_at 
                     FROM users 
                     WHERE (latitude IS NOT NULL AND longitude IS NOT NULL) 
                     OR location IS NOT NULL
                     ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            // Separate users by role for frontend compatibility
            $farmers = array_filter($users, function($user) {
                return $user['role'] === 'farmer';
            });
            $admins = array_filter($users, function($user) {
                return $user['role'] === 'admin';
            });
            
            return [
                'success' => true,
                'data' => [
                    'users' => array_values($users),
                    'farmers' => array_values($farmers),
                    'admins' => array_values($admins)
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch locations: ' . $e->getMessage()
            ];
        }
    }
    
    public function getFarmerLocations() {
        try {
            // Users and farmers are the same - farmers are users with role='farmer'
            $query = "SELECT id, full_name as name, location, latitude, longitude, 
                     email, phone, created_at 
                     FROM users 
                     WHERE role = 'farmer' 
                     AND ((latitude IS NOT NULL AND longitude IS NOT NULL) OR location IS NOT NULL)
                     ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $farmers = $stmt->fetchAll();
            
            return [
                'success' => true,
                'data' => $farmers
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch farmer locations: ' . $e->getMessage()
            ];
        }
    }
    
    public function getDisasters() {
        try {
            $status = $_GET['status'] ?? '';
            
            // Get ALL disasters across the Philippines - NO location filtering
            // Users should be able to see all disasters regardless of their location
            $query = "SELECT d.*, COUNT(DISTINCT a.id) as alert_count 
                     FROM disasters d 
                     LEFT JOIN alerts a ON a.disaster_id = d.id 
                     WHERE 1=1";
            
            $params = [];
            if (!empty($status)) {
                if ($status === 'active') {
                    // For active status, include both active and warning status disasters
                    $query .= " AND d.status IN ('active', 'warning')";
                } else {
                    $query .= " AND d.status = :status";
                    $params[':status'] = $status;
                }
            }
            
            // Prioritize typhoons and active disasters
            // Show ALL disasters across Philippines, not filtered by user location
            $query .= " GROUP BY d.id ORDER BY 
                      CASE WHEN d.type = 'typhoon' THEN 1 ELSE 2 END,
                      CASE WHEN d.status = 'active' THEN 1 WHEN d.status = 'warning' THEN 2 ELSE 3 END,
                      d.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $disasters = $stmt->fetchAll();
            
            // Get affected areas for each disaster
            foreach ($disasters as &$disaster) {
                $areaQuery = "SELECT latitude, longitude, sequence_order 
                             FROM disaster_affected_areas 
                             WHERE disaster_id = :disaster_id 
                             ORDER BY sequence_order ASC";
                $areaStmt = $this->conn->prepare($areaQuery);
                $areaStmt->bindParam(':disaster_id', $disaster['id']);
                $areaStmt->execute();
                $disaster['affected_area_coordinates'] = $areaStmt->fetchAll();
            }
            
            return [
                'success' => true,
                'data' => $disasters
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch disasters: ' . $e->getMessage()
            ];
        }
    }
    
    public function getAffectedAreas() {
        try {
            $disasterId = $_GET['disaster_id'] ?? null;
            
            if ($disasterId) {
                $query = "SELECT latitude, longitude, sequence_order 
                         FROM disaster_affected_areas 
                         WHERE disaster_id = :disaster_id 
                         ORDER BY sequence_order ASC";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':disaster_id', $disasterId);
                $stmt->execute();
                
                return [
                    'success' => true,
                    'data' => $stmt->fetchAll()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Disaster ID is required'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch affected areas: ' . $e->getMessage()
            ];
        }
    }
    
    public function getAlertsWithLocations() {
        try {
            // Get ALL active alerts with their disaster locations
            // Show alerts from disasters across the entire Philippines, not filtered by user location
            $query = "SELECT a.*, d.name as disaster_name, d.type as disaster_type, 
                     d.center_latitude, d.center_longitude, d.affected_radius_km,
                     COUNT(af.farmer_id) as affected_farmers 
                     FROM alerts a 
                     LEFT JOIN disasters d ON a.disaster_id = d.id 
                     LEFT JOIN alert_farmers af ON a.id = af.alert_id 
                     WHERE a.status = 'active'
                     GROUP BY a.id 
                     ORDER BY 
                     CASE WHEN d.type = 'typhoon' THEN 1 ELSE 2 END,
                     CASE WHEN d.status = 'active' THEN 1 WHEN d.status = 'warning' THEN 2 ELSE 3 END,
                     a.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $alerts = $stmt->fetchAll();
            
            // Extract coordinates from alert description if no disaster coordinates exist
            // Some alerts might have location info in the description
            foreach ($alerts as &$alert) {
                // If alert has disaster coordinates, use those
                if ($alert['center_latitude'] && $alert['center_longitude']) {
                    continue;
                }
                
                // Try to extract coordinates from description if it contains location info
                // Format: "Lat: X, Lng: Y" or similar
                if (preg_match('/Lat[itude]*:\s*([0-9.]+).*Lng[itude]*:\s*([0-9.]+)/i', $alert['description'], $matches)) {
                    $alert['center_latitude'] = (float)$matches[1];
                    $alert['center_longitude'] = (float)$matches[2];
                }
            }
            
            return [
                'success' => true,
                'data' => $alerts
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch alerts: ' . $e->getMessage()
            ];
        }
    }
    
    public function getAllMapData() {
        try {
            $locations = $this->getAllLocations();
            $disasters = $this->getDisasters();
            $alerts = $this->getAlertsWithLocations();
            
            return [
                'success' => true,
                'data' => [
                    'locations' => $locations['data'] ?? [],
                    'disasters' => $disasters['data'] ?? [],
                    'alerts' => $alerts['data'] ?? []
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch map data: ' . $e->getMessage()
            ];
        }
    }
    
    public function createDisaster() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $name = $input['name'] ?? '';
            $type = $input['type'] ?? 'typhoon';
            $severity = $input['severity'] ?? 'medium';
            $description = $input['description'] ?? '';
            $centerLat = $input['center_latitude'] ?? null;
            $centerLng = $input['center_longitude'] ?? null;
            $radius = $input['affected_radius_km'] ?? null;
            $affectedAreas = $input['affected_areas'] ?? [];
            
            if (empty($name)) {
                return [
                    'success' => false,
                    'message' => 'Disaster name is required'
                ];
            }
            
            $this->conn->beginTransaction();
            
            $query = "INSERT INTO disasters (name, type, severity, status, description, 
                     center_latitude, center_longitude, affected_radius_km, start_date) 
                     VALUES (:name, :type, :severity, 'active', :description, 
                     :center_lat, :center_lng, :radius, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':severity', $severity);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':center_lat', $centerLat);
            $stmt->bindParam(':center_lng', $centerLng);
            $stmt->bindParam(':radius', $radius);
            
            if ($stmt->execute()) {
                $disasterId = $this->conn->lastInsertId();
                
                // Insert affected area coordinates
                if (!empty($affectedAreas) && is_array($affectedAreas)) {
                    $areaQuery = "INSERT INTO disaster_affected_areas 
                                 (disaster_id, latitude, longitude, sequence_order) 
                                 VALUES (:disaster_id, :lat, :lng, :order)";
                    $areaStmt = $this->conn->prepare($areaQuery);
                    
                    foreach ($affectedAreas as $index => $area) {
                        $areaStmt->bindParam(':disaster_id', $disasterId);
                        $areaStmt->bindParam(':lat', $area['latitude']);
                        $areaStmt->bindParam(':lng', $area['longitude']);
                        $areaStmt->bindParam(':order', $index);
                        $areaStmt->execute();
                    }
                }
                
                $this->conn->commit();
                
                return [
                    'success' => true,
                    'message' => 'Disaster created successfully',
                    'data' => ['id' => $disasterId]
                ];
            } else {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Failed to create disaster'
                ];
            }
        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => 'Failed to create disaster: ' . $e->getMessage()
            ];
        }
    }
    
    public function updateLocation() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $userId = $input['user_id'] ?? null;
            $latitude = $input['latitude'] ?? null;
            $longitude = $input['longitude'] ?? null;
            
            if (!$userId || $latitude === null || $longitude === null) {
                return [
                    'success' => false,
                    'message' => 'User ID, latitude, and longitude are required'
                ];
            }
            
            // Users and farmers are the same - update in users table
            $query = "UPDATE users SET latitude = :lat, longitude = :lng WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':lat', $latitude);
            $stmt->bindParam(':lng', $longitude);
            $stmt->bindParam(':id', $userId);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Location updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update location'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update location: ' . $e->getMessage()
            ];
        }
    }
}

// Handle the request
$mapAPI = new MapDataAPI();
$result = $mapAPI->handleRequest();
echo json_encode($result);
?>

