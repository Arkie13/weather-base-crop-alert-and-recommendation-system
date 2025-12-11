<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

class FarmersAPI {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                $id = $_GET['id'] ?? null;
                if ($id) {
                    return $this->getFarmerById($id);
                }
                return $this->getFarmers();
            case 'POST':
                return $this->addFarmer();
            case 'PUT':
                return $this->updateFarmer();
            case 'DELETE':
                return $this->deleteFarmer();
            default:
                http_response_code(405);
                return ['success' => false, 'message' => 'Method not allowed'];
        }
    }
    
    public function getFarmers() {
        try {
            // Get farmers from users table (role = 'farmer')
            // Users and farmers are the same - farmers are users with role='farmer'
            $query = "SELECT id, username, full_name as name, location, email, phone, latitude, longitude, created_at, is_active 
                     FROM users 
                     WHERE role = 'farmer' 
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
                'message' => 'Failed to fetch farmers: ' . $e->getMessage()
            ];
        }
    }
    
    public function addFarmer() {
        try {
            $name = $_POST['name'] ?? '';
            $location = $_POST['location'] ?? '';
            $contact = $_POST['contact'] ?? '';
            $crops = $_POST['crops'] ?? '';
            
            if (empty($name) || empty($location)) {
                return [
                    'success' => false,
                    'message' => 'Name and location are required'
                ];
            }
            
            // Generate a username from the name
            $username = strtolower(str_replace(' ', '_', $name)) . '_' . rand(100, 999);
            
            // Generate a default password (in real app, you'd send this to the farmer)
            $defaultPassword = 'farmer123';
            $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);
            
            // Add to users table as a farmer
            $query = "INSERT INTO users (username, password, role, full_name, location, phone, created_at) 
                     VALUES (:username, :password, 'farmer', :name, :location, :contact, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':contact', $contact);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Farmer added successfully. Username: ' . $username . ', Password: ' . $defaultPassword,
                    'data' => ['id' => $this->conn->lastInsertId()]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to add farmer'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to add farmer: ' . $e->getMessage()
            ];
        }
    }
    
    public function updateFarmer() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? '';
            $name = $input['name'] ?? '';
            $location = $input['location'] ?? '';
            $email = $input['email'] ?? '';
            $phone = $input['phone'] ?? '';
            $username = $input['username'] ?? '';
            
            if (empty($id) || empty($name) || empty($location)) {
                return [
                    'success' => false,
                    'message' => 'ID, name and location are required'
                ];
            }
            
            // Update in users table (users and farmers are the same)
            $query = "UPDATE users SET full_name = :name, location = :location, 
                     email = :email, phone = :phone, username = :username, updated_at = NOW() 
                     WHERE id = :id AND role = 'farmer'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':username', $username);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Farmer updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update farmer'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update farmer: ' . $e->getMessage()
            ];
        }
    }
    
    public function deleteFarmer() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? '';
            
            if (empty($id)) {
                return [
                    'success' => false,
                    'message' => 'Farmer ID is required'
                ];
            }
            
            // Delete from users table (users and farmers are the same)
            $query = "DELETE FROM users WHERE id = :id AND role = 'farmer'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Farmer deleted successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to delete farmer'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete farmer: ' . $e->getMessage()
            ];
        }
    }
    
    public function getFarmerById($id) {
        try {
            // Get farmer from users table (users and farmers are the same)
            $query = "SELECT id, username, full_name as name, location, email, phone, latitude, longitude, created_at, is_active 
                     FROM users 
                     WHERE id = :id AND role = 'farmer'";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            $farmer = $stmt->fetch();
            
            if ($farmer) {
                return [
                    'success' => true,
                    'data' => $farmer
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Farmer not found'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch farmer: ' . $e->getMessage()
            ];
        }
    }
}

// Handle the request
$farmersAPI = new FarmersAPI();
$result = $farmersAPI->handleRequest();
echo json_encode($result);
?>
