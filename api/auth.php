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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Define API_MODE to suppress error display in config.php
define('API_MODE', true);

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/email-service.php';
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

class AuthAPI {
    private $conn;
    private $emailService;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->emailService = new EmailService();
    }
    
    private function getClientIp() {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    }
    
    private function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    }
    
    private function recordLogin($userId, $username, $ipAddress, $userAgent, $loginStatus, $failureReason = null, $sessionId = null) {
        try {
            // Try to use stored procedure first
            $stmt = $this->conn->prepare("CALL sp_record_login(?, ?, ?, ?, ?, ?, ?, @login_id, @status)");
            $stmt->execute([
                $userId,
                $username,
                $ipAddress,
                $userAgent,
                $loginStatus,
                $failureReason,
                $sessionId
            ]);
            
            // Get the output parameters
            $result = $this->conn->query("SELECT @login_id as login_id, @status as status")->fetch();
            return $result;
        } catch (Exception $e) {
            // Fallback: Insert directly into login_history table if stored procedure fails
            // Use transaction to ensure data consistency (same as stored procedure)
            try {
                error_log("Stored procedure failed, using direct insert with transaction: " . $e->getMessage());
                
                // Start transaction to ensure atomicity (all or nothing)
                $this->conn->beginTransaction();
                
                // Step 1: Insert into login_history
                $query = "INSERT INTO login_history (user_id, username, ip_address, user_agent, login_status, failure_reason, session_id) 
                         VALUES (:user_id, :username, :ip_address, :user_agent, :login_status, :failure_reason, :session_id)";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':username' => $username,
                    ':ip_address' => $ipAddress,
                    ':user_agent' => $userAgent,
                    ':login_status' => $loginStatus,
                    ':failure_reason' => $failureReason,
                    ':session_id' => $sessionId
                ]);
                
                // Step 2: Also insert into user_activity_log for successful logins
                if ($loginStatus === 'success' && $userId) {
                    $activityQuery = "INSERT INTO user_activity_log (user_id, activity_type, ip_address, user_agent, created_at) 
                                     VALUES (:user_id, 'login', :ip_address, :user_agent, NOW())";
                    $activityStmt = $this->conn->prepare($activityQuery);
                    $activityStmt->execute([
                        ':user_id' => $userId,
                        ':ip_address' => $ipAddress,
                        ':user_agent' => $userAgent
                    ]);
                }
                
                // Commit transaction (saves all changes)
                $this->conn->commit();
                return ['status' => 'SUCCESS'];
            } catch (Exception $fallbackException) {
                // Rollback transaction on error (undoes all changes)
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                // Log error but don't fail the login
                error_log("Failed to record login history (both methods): " . $fallbackException->getMessage());
                return null;
            }
        }
    }
    
    public function login($username, $password) {
        $ipAddress = $this->getClientIp();
        $userAgent = $this->getUserAgent();
        
        try {
            $query = "SELECT * FROM users WHERE username = :username AND is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Start session
                session_start();
                $sessionId = session_id();
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['location'] = $user['location'];
                
                // Record successful login
                $this->recordLogin(
                    $user['id'],
                    $username,
                    $ipAddress,
                    $userAgent,
                    'success',
                    null,
                    $sessionId
                );
                
                return [
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role'],
                        'full_name' => $user['full_name'],
                        'location' => $user['location']
                    ]
                ];
            } else {
                // Record failed login attempt
                $userId = $user ? $user['id'] : null;
                $this->recordLogin(
                    $userId,
                    $username,
                    $ipAddress,
                    $userAgent,
                    'failed',
                    'Invalid username or password',
                    null
                );
                
                return [
                    'success' => false,
                    'message' => 'Invalid username or password'
                ];
            }
        } catch (Exception $e) {
            // Record failed login attempt due to error
            $this->recordLogin(
                null,
                $username,
                $ipAddress,
                $userAgent,
                'failed',
                'Login error: ' . $e->getMessage(),
                null
            );
            
            return [
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate a 6-digit verification code
     */
    private function generateVerificationCode() {
        return str_pad(rand(0, 999999), VERIFICATION_CODE_LENGTH, '0', STR_PAD_LEFT);
    }
    
    /**
     * Check rate limiting for verification attempts
     */
    private function checkRateLimit($email, $maxAttempts, $timeWindowMinutes = 60) {
        try {
            $query = "SELECT email_verification_attempts, email_verification_expires_at 
                     FROM users 
                     WHERE email = :email 
                     AND email_verification_expires_at > DATE_SUB(NOW(), INTERVAL :time_window MINUTE)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->bindValue(':time_window', $timeWindowMinutes, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            
            if ($result && $result['email_verification_attempts'] >= $maxAttempts) {
                return false; // Rate limit exceeded
            }
            
            return true; // Within rate limit
        } catch (Exception $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            return true; // Allow on error to avoid blocking legitimate users
        }
    }
    
    /**
     * Send verification code to email
     */
    public function sendVerificationCode($email, $fullName) {
        try {
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email format'
                ];
            }
            
            // Check if email is already registered
            $query = "SELECT id, email_verified FROM users WHERE email = :email";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $existingUser = $stmt->fetch();
            
            if ($existingUser && $existingUser['email_verified']) {
                return [
                    'success' => false,
                    'message' => 'This email is already registered and verified'
                ];
            }
            
            // Check rate limiting (max 3 resends per hour)
            if (!$this->checkRateLimit($email, MAX_RESEND_ATTEMPTS, 60)) {
                return [
                    'success' => false,
                    'message' => 'Too many verification code requests. Please try again later.'
                ];
            }
            
            // Generate verification code
            $code = $this->generateVerificationCode();
            $hashedCode = password_hash($code, PASSWORD_DEFAULT);
            $expiresAt = date('Y-m-d H:i:s', time() + (VERIFICATION_CODE_EXPIRY_MINUTES * 60));
            
            // Update or insert verification code
            if ($existingUser) {
                // Update existing user's verification code
                $query = "UPDATE users 
                         SET email_verification_code = :code, 
                             email_verification_expires_at = :expires_at,
                             email_verification_attempts = 0
                         WHERE email = :email";
            } else {
                // Create temporary record (user will be created on registration)
                // We'll store this in a temporary way - actually, we need to check if user exists first
                // For now, we'll require the user to exist or create a placeholder
                return [
                    'success' => false,
                    'message' => 'Please complete registration form first'
                ];
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':code', $hashedCode);
            $stmt->bindParam(':expires_at', $expiresAt);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            // Send email
            $emailResult = $this->emailService->sendVerificationCode($email, $code, $fullName);
            
            if ($emailResult['success']) {
                return [
                    'success' => true,
                    'message' => 'Verification code sent to your email',
                    'expires_in_minutes' => VERIFICATION_CODE_EXPIRY_MINUTES
                ];
            } else {
                // Provide more helpful error message
                $errorMsg = $emailResult['message'];
                if (isset($emailResult['error_type']) && $emailResult['error_type'] === 'smtp_not_configured') {
                    $errorMsg .= ' See EMAIL_SETUP_GUIDE.md for setup instructions.';
                }
                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'error_type' => $emailResult['error_type'] ?? 'unknown',
                    'error_details' => $emailResult['error_details'] ?? null
                ];
            }
            
        } catch (Exception $e) {
            error_log("Send verification code error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send verification code: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify email with code
     */
    public function verifyEmail($email, $code) {
        try {
            // Get user with verification code
            $query = "SELECT id, email_verification_code, email_verification_expires_at, 
                            email_verification_attempts, full_name 
                     FROM users 
                     WHERE email = :email";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $user = $stmt->fetch();
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Email not found'
                ];
            }
            
            // Check if already verified
            if ($user['email_verified']) {
                return [
                    'success' => true,
                    'message' => 'Email already verified'
                ];
            }
            
            // Check if code exists
            if (empty($user['email_verification_code'])) {
                return [
                    'success' => false,
                    'message' => 'No verification code found. Please request a new one.'
                ];
            }
            
            // Check if code expired
            if (strtotime($user['email_verification_expires_at']) < time()) {
                return [
                    'success' => false,
                    'message' => 'Verification code has expired. Please request a new one.'
                ];
            }
            
            // Check rate limiting
            if ($user['email_verification_attempts'] >= MAX_VERIFICATION_ATTEMPTS) {
                return [
                    'success' => false,
                    'message' => 'Too many verification attempts. Please request a new code.'
                ];
            }
            
            // Verify code
            if (!password_verify($code, $user['email_verification_code'])) {
                // Increment attempts
                $query = "UPDATE users 
                         SET email_verification_attempts = email_verification_attempts + 1 
                         WHERE email = :email";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                
                $remainingAttempts = MAX_VERIFICATION_ATTEMPTS - ($user['email_verification_attempts'] + 1);
                
                return [
                    'success' => false,
                    'message' => 'Invalid verification code. ' . ($remainingAttempts > 0 ? $remainingAttempts . ' attempts remaining.' : 'Please request a new code.')
                ];
            }
            
            // Code is valid - mark email as verified
            $query = "UPDATE users 
                     SET email_verified = TRUE, 
                         email_verification_code = NULL,
                         email_verification_expires_at = NULL,
                         email_verification_attempts = 0
                     WHERE email = :email";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            // Send welcome email
            $this->emailService->sendWelcomeEmail($email, $user['full_name']);
            
            return [
                'success' => true,
                'message' => 'Email verified successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Verify email error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Resend verification code
     */
    public function resendVerificationCode($email, $fullName) {
        // Check rate limiting (max 3 resends per hour)
        if (!$this->checkRateLimit($email, MAX_RESEND_ATTEMPTS, 60)) {
            return [
                'success' => false,
                'message' => 'Too many resend requests. Please try again later.'
            ];
        }
        
        return $this->sendVerificationCode($email, $fullName);
    }
    
    public function register($username, $password, $fullName, $email, $phone, $location, $verificationCode = null) {
        try {
            // Validate required fields
            if (empty($email)) {
                return [
                    'success' => false,
                    'message' => 'Email is required'
                ];
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email format'
                ];
            }
            
            // Check if username already exists
            $query = "SELECT id FROM users WHERE username = :username";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                return [
                    'success' => false,
                    'message' => 'Username already exists'
                ];
            }
            
            // Check if email already exists
            $query = "SELECT id FROM users WHERE email = :email";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                return [
                    'success' => false,
                    'message' => 'This email is already registered'
                ];
            }
            
            // Create new user account (email verification removed - will be added back later)
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $query = "INSERT INTO users (username, password, role, full_name, email, phone, location, email_verified) 
                     VALUES (:username, :password, 'farmer', :full_name, :email, :phone, :location, TRUE)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':location', $location);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Registration successful'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Registration failed'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ];
        }
    }
    
    public function logout() {
        session_start();
        session_destroy();
        return [
            'success' => true,
            'message' => 'Logged out successfully'
        ];
    }
    
    public function checkAuth() {
        session_start();
        
        if (isset($_SESSION['user_id'])) {
            return [
                'success' => true,
                'authenticated' => true,
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'],
                    'role' => $_SESSION['role'],
                    'full_name' => $_SESSION['full_name'],
                    'location' => $_SESSION['location']
                ]
            ];
        } else {
            return [
                'success' => true,
                'authenticated' => false
            ];
        }
    }
}

// Handle the request
try {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $result = null;
    
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }
        
        $auth = new AuthAPI();
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'login':
                $result = $auth->login($input['username'] ?? '', $input['password'] ?? '');
                break;
            case 'register':
                $result = $auth->register(
                    $input['username'] ?? '',
                    $input['password'] ?? '',
                    $input['full_name'] ?? '',
                    $input['email'] ?? '',
                    $input['phone'] ?? '',
                    $input['location'] ?? '',
                    null // verification_code parameter kept for compatibility but not used
                );
                break;
            case 'send_verification_code':
                $result = $auth->sendVerificationCode(
                    $input['email'] ?? '',
                    $input['full_name'] ?? ''
                );
                break;
            case 'verify_email':
                $result = $auth->verifyEmail(
                    $input['email'] ?? '',
                    $input['code'] ?? ''
                );
                break;
            case 'resend_verification_code':
                $result = $auth->resendVerificationCode(
                    $input['email'] ?? '',
                    $input['full_name'] ?? ''
                );
                break;
            case 'logout':
                $result = $auth->logout();
                break;
            default:
                $result = [
                    'success' => false,
                    'message' => 'Invalid action'
                ];
        }
    } elseif ($requestMethod === 'GET') {
        $auth = new AuthAPI();
        $result = $auth->checkAuth();
    } else {
        $result = [
            'success' => false,
            'message' => 'Method not allowed'
        ];
    }
    
    // Clean any output before sending JSON
    ob_clean();
    
    // Ensure result is always an array
    if (!is_array($result)) {
        $result = [
            'success' => false,
            'message' => 'Invalid response format'
        ];
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    // Clean any output
    ob_clean();
    
    // Log the error
    error_log("Auth API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Return JSON error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage(),
        'error_type' => 'exception'
    ]);
} catch (Error $e) {
    // Clean any output
    ob_clean();
    
    // Log the error
    error_log("Auth API Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Return JSON error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again later.',
        'error_type' => 'fatal_error'
    ]);
}
?>
