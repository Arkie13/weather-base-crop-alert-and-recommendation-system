<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

class UserCropsAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function getUserCrops() {
        try {
            // Check if user is logged in
            session_start();
            if (!isset($_SESSION['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'User not authenticated'
                ];
            }
            
            $userId = $_SESSION['user_id'];
            
            // Get user crops
            $query = "SELECT * FROM user_crops WHERE user_id = :user_id ORDER BY planting_date DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            
            $crops = $stmt->fetchAll();
            
            // Format crop data
            $formattedCrops = array_map(function($crop) {
                $plantingDate = new DateTime($crop['planting_date']);
                $daysPlanted = $plantingDate->diff(new DateTime())->days;
                
                $expectedHarvest = null;
                $daysToHarvest = null;
                if ($crop['expected_harvest_date']) {
                    $expectedHarvest = new DateTime($crop['expected_harvest_date']);
                    $daysToHarvest = $expectedHarvest->diff(new DateTime())->days;
                }
                
                return [
                    'id' => $crop['id'],
                    'crop_name' => $crop['crop_name'],
                    'variety' => $crop['variety'],
                    'planting_date' => $crop['planting_date'],
                    'expected_harvest_date' => $crop['expected_harvest_date'],
                    'area_hectares' => $crop['area_hectares'],
                    'status' => $crop['status'],
                    'health_status' => $crop['health_status'],
                    'notes' => $crop['notes'],
                    'days_planted' => $daysPlanted,
                    'days_to_harvest' => $daysToHarvest,
                    'created_at' => $crop['created_at'],
                    'updated_at' => $crop['updated_at']
                ];
            }, $crops);
            
            return [
                'success' => true,
                'data' => $formattedCrops,
                'count' => count($formattedCrops)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch crops: ' . $e->getMessage()
            ];
        }
    }
    
    public function getCropById($cropId) {
        try {
            // Check if user is logged in
            session_start();
            if (!isset($_SESSION['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'User not authenticated'
                ];
            }
            
            $userId = $_SESSION['user_id'];
            
            // Get specific crop
            $query = "SELECT * FROM user_crops WHERE id = :crop_id AND user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':crop_id', $cropId);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            
            $crop = $stmt->fetch();
            
            if (!$crop) {
                return [
                    'success' => false,
                    'message' => 'Crop not found or access denied'
                ];
            }
            
            // Format crop data
            $plantingDate = new DateTime($crop['planting_date']);
            $daysPlanted = $plantingDate->diff(new DateTime())->days;
            
            $expectedHarvest = null;
            $daysToHarvest = null;
            if ($crop['expected_harvest_date']) {
                $expectedHarvest = new DateTime($crop['expected_harvest_date']);
                $daysToHarvest = $expectedHarvest->diff(new DateTime())->days;
            }
            
            $formattedCrop = [
                'id' => $crop['id'],
                'crop_name' => $crop['crop_name'],
                'variety' => $crop['variety'],
                'planting_date' => $crop['planting_date'],
                'expected_harvest_date' => $crop['expected_harvest_date'],
                'area_hectares' => $crop['area_hectares'],
                'status' => $crop['status'],
                'health_status' => $crop['health_status'],
                'notes' => $crop['notes'],
                'days_planted' => $daysPlanted,
                'days_to_harvest' => $daysToHarvest,
                'created_at' => $crop['created_at'],
                'updated_at' => $crop['updated_at']
            ];
            
            return [
                'success' => true,
                'data' => $formattedCrop
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch crop: ' . $e->getMessage()
            ];
        }
    }
    
    public function addCrop() {
        try {
            // Check if user is logged in
            session_start();
            if (!isset($_SESSION['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'User not authenticated'
                ];
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $_SESSION['user_id'];
            
            $cropName = $input['crop_name'] ?? $input['name'] ?? '';
            $variety = $input['variety'] ?? '';
            $plantingDate = $input['planting_date'] ?? '';
            $expectedHarvestDate = $input['expected_harvest_date'] ?? '';
            $areaHectares = $input['area_hectares'] ?? $input['area'] ?? 0;
            $notes = $input['notes'] ?? '';
            
            if (empty($cropName) || empty($plantingDate) || empty($areaHectares)) {
                return [
                    'success' => false,
                    'message' => 'Crop name, planting date, and area are required'
                ];
            }
            
            // Insert new crop
            $query = "INSERT INTO user_crops (user_id, crop_name, variety, planting_date, expected_harvest_date, area_hectares, notes) 
                     VALUES (:user_id, :crop_name, :variety, :planting_date, :expected_harvest_date, :area_hectares, :notes)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':crop_name', $cropName);
            $stmt->bindParam(':variety', $variety);
            $stmt->bindParam(':planting_date', $plantingDate);
            $stmt->bindParam(':expected_harvest_date', $expectedHarvestDate);
            $stmt->bindParam(':area_hectares', $areaHectares);
            $stmt->bindParam(':notes', $notes);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Crop added successfully',
                    'crop_id' => $this->conn->lastInsertId()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to add crop'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to add crop: ' . $e->getMessage()
            ];
        }
    }
    
    public function updateCrop() {
        try {
            // Check if user is logged in
            session_start();
            if (!isset($_SESSION['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'User not authenticated'
                ];
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $_SESSION['user_id'];
            $cropId = $input['crop_id'] ?? 0;
            
            if (!$cropId) {
                return [
                    'success' => false,
                    'message' => 'Crop ID is required'
                ];
            }
            
            // Verify crop belongs to user
            $checkQuery = "SELECT id FROM user_crops WHERE id = :crop_id AND user_id = :user_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':crop_id', $cropId);
            $checkStmt->bindParam(':user_id', $userId);
            $checkStmt->execute();
            
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'message' => 'Crop not found or access denied'
                ];
            }
            
            // Update crop
            $updateFields = [];
            $params = [':crop_id' => $cropId];
            
            if (isset($input['crop_name'])) {
                $updateFields[] = "crop_name = :crop_name";
                $params[':crop_name'] = $input['crop_name'];
            }
            if (isset($input['variety'])) {
                $updateFields[] = "variety = :variety";
                $params[':variety'] = $input['variety'];
            }
            if (isset($input['planting_date'])) {
                $updateFields[] = "planting_date = :planting_date";
                $params[':planting_date'] = $input['planting_date'];
            }
            if (isset($input['expected_harvest_date'])) {
                $updateFields[] = "expected_harvest_date = :expected_harvest_date";
                $params[':expected_harvest_date'] = $input['expected_harvest_date'];
            }
            if (isset($input['area_hectares'])) {
                $updateFields[] = "area_hectares = :area_hectares";
                $params[':area_hectares'] = $input['area_hectares'];
            }
            if (isset($input['status'])) {
                $updateFields[] = "status = :status";
                $params[':status'] = $input['status'];
            }
            if (isset($input['health_status'])) {
                $updateFields[] = "health_status = :health_status";
                $params[':health_status'] = $input['health_status'];
            }
            if (isset($input['notes'])) {
                $updateFields[] = "notes = :notes";
                $params[':notes'] = $input['notes'];
            }
            
            if (empty($updateFields)) {
                return [
                    'success' => false,
                    'message' => 'No fields to update'
                ];
            }
            
            $updateFields[] = "updated_at = NOW()";
            
            $query = "UPDATE user_crops SET " . implode(', ', $updateFields) . " WHERE id = :crop_id";
            $stmt = $this->conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Crop updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update crop'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update crop: ' . $e->getMessage()
            ];
        }
    }
    
    public function deleteCrop() {
        try {
            // Check if user is logged in
            session_start();
            if (!isset($_SESSION['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'User not authenticated'
                ];
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $userId = $_SESSION['user_id'];
            $cropId = $input['crop_id'] ?? 0;
            
            if (!$cropId) {
                return [
                    'success' => false,
                    'message' => 'Crop ID is required'
                ];
            }
            
            // Delete crop
            $query = "DELETE FROM user_crops WHERE id = :crop_id AND user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':crop_id', $cropId);
            $stmt->bindParam(':user_id', $userId);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return [
                    'success' => true,
                    'message' => 'Crop deleted successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Crop not found or access denied'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete crop: ' . $e->getMessage()
            ];
        }
    }
}

// Handle the request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$cropsAPI = new UserCropsAPI();

switch ($requestMethod) {
    case 'GET':
        // Check if requesting a specific crop by ID
        if (isset($_GET['crop_id'])) {
            $result = $cropsAPI->getCropById($_GET['crop_id']);
        } else {
            $result = $cropsAPI->getUserCrops();
        }
        break;
    case 'POST':
        $result = $cropsAPI->addCrop();
        break;
    case 'PUT':
        $result = $cropsAPI->updateCrop();
        break;
    case 'DELETE':
        $result = $cropsAPI->deleteCrop();
        break;
    default:
        http_response_code(405);
        $result = ['success' => false, 'message' => 'Method not allowed'];
        break;
}

echo json_encode($result);
?>
