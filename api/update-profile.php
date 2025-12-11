<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

class ProfileUpdateAPI {
    private $conn;
    private $authAPI;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->authAPI = new AuthAPI();
    }
    
    public function updateProfile() {
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
            
            $fullName = $input['full_name'] ?? '';
            $email = $input['email'] ?? '';
            $phone = $input['phone'] ?? '';
            $location = $input['location'] ?? '';
            
            if (empty($fullName) || empty($location)) {
                return [
                    'success' => false,
                    'message' => 'Full name and location are required'
                ];
            }
            
            // Get current user data
            $currentQuery = "SELECT email FROM users WHERE id = :user_id";
            $currentStmt = $this->conn->prepare($currentQuery);
            $currentStmt->bindParam(':user_id', $userId);
            $currentStmt->execute();
            $currentUser = $currentStmt->fetch();
            
            if (!$currentUser) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }
            
            $emailChanged = !empty($email) && $email !== $currentUser['email'];
            
            // If email is being changed, validate it
            if ($emailChanged) {
                // Validate email format
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return [
                        'success' => false,
                        'message' => 'Invalid email format'
                    ];
                }
                
                // Check if new email is already registered
                $checkEmailQuery = "SELECT id FROM users WHERE email = :email AND id != :user_id";
                $checkEmailStmt = $this->conn->prepare($checkEmailQuery);
                $checkEmailStmt->bindParam(':email', $email);
                $checkEmailStmt->bindParam(':user_id', $userId);
                $checkEmailStmt->execute();
                
                if ($checkEmailStmt->fetch()) {
                    return [
                        'success' => false,
                        'message' => 'This email is already registered'
                    ];
                }
            }
            
            // Update user profile (email verification removed - will be added back later)
            $query = "UPDATE users SET full_name = :full_name, email = :email, 
                     phone = :phone, location = :location, updated_at = NOW() 
                     WHERE id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':user_id', $userId);
            
            if ($stmt->execute()) {
                // Update session data
                $_SESSION['full_name'] = $fullName;
                $_SESSION['location'] = $location;
                
                return [
                    'success' => true,
                    'message' => 'Profile updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update profile'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage()
            ];
        }
    }
}

// Handle the request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    $profileAPI = new ProfileUpdateAPI();
    $result = $profileAPI->updateProfile();
    echo json_encode($result);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
