<?php
/**
 * Email Service for Crop Alert System
 * Handles sending verification codes, alert notifications, and welcome emails
 * Uses PHPMailer if available, falls back to native PHP mail
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Try to load PHPMailer if available
$phpmailerAvailable = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $phpmailerAvailable = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

class EmailService {
    private $conn;
    private $usePHPMailer;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->usePHPMailer = $phpmailerAvailable;
    }
    
    /**
     * Send verification code email
     */
    public function sendVerificationCode($email, $code, $name) {
        $subject = 'Email Verification - Crop Alert System';
        $template = EMAIL_VERIFICATION_TEMPLATE;
        
        // Replace template variables
        $htmlBody = $this->loadTemplate($template, [
            'name' => htmlspecialchars($name),
            'code' => $code,
            'expiry_minutes' => VERIFICATION_CODE_EXPIRY_MINUTES
        ]);
        
        return $this->sendEmail($email, $subject, $htmlBody, $name);
    }
    
    /**
     * Send alert notification email
     */
    public function sendAlertNotification($email, $alert, $userName) {
        $subject = 'Alert: ' . $alert['type'] . ' - Crop Alert System';
        $template = EMAIL_ALERT_TEMPLATE;
        
        // Determine severity color and icon
        $severityColors = [
            'low' => '#28a745',
            'medium' => '#ffc107',
            'high' => '#dc3545'
        ];
        
        $alertIcons = [
            'drought' => '☀️',
            'storm' => '🌪️',
            'flood' => '🌊',
            'heat' => '🔥',
            'frost' => '❄️',
            'cold' => '🧊',
            'wind' => '💨',
            'rain' => '🌧️'
        ];
        
        $severityColor = $severityColors[strtolower($alert['severity'])] ?? '#667eea';
        $alertIcon = '🚨';
        
        foreach ($alertIcons as $key => $icon) {
            if (stripos($alert['type'], $key) !== false) {
                $alertIcon = $icon;
                break;
            }
        }
        
        // Get dashboard URL (adjust based on your setup)
        $dashboardUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                       '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/farmer-dashboard.html';
        
        // Replace template variables
        $htmlBody = $this->loadTemplate($template, [
            'name' => htmlspecialchars($userName),
            'alert_type' => htmlspecialchars($alert['type']),
            'severity' => htmlspecialchars($alert['severity']),
            'severity_color' => $severityColor,
            'description' => nl2br(htmlspecialchars($alert['description'])),
            'alert_icon' => $alertIcon,
            'created_at' => date('F j, Y g:i A', strtotime($alert['created_at'])),
            'dashboard_url' => $dashboardUrl
        ]);
        
        return $this->sendEmail($email, $subject, $htmlBody, $userName);
    }
    
    /**
     * Send welcome email after verification
     */
    public function sendWelcomeEmail($email, $name) {
        $subject = 'Welcome to Crop Alert System!';
        $template = EMAIL_WELCOME_TEMPLATE;
        
        // Get dashboard URL
        $dashboardUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                       '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/farmer-dashboard.html';
        
        // Replace template variables
        $htmlBody = $this->loadTemplate($template, [
            'name' => htmlspecialchars($name),
            'dashboard_url' => $dashboardUrl
        ]);
        
        return $this->sendEmail($email, $subject, $htmlBody, $name);
    }
    
    /**
     * Load and process email template
     */
    private function loadTemplate($templatePath, $variables) {
        if (!file_exists($templatePath)) {
            error_log("Email template not found: $templatePath");
            return $this->getPlainTextFallback($variables);
        }
        
        $template = file_get_contents($templatePath);
        
        // Replace variables in template
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        
        return $template;
    }
    
    /**
     * Get plain text fallback if template is missing
     */
    private function getPlainTextFallback($variables) {
        $name = $variables['name'] ?? 'User';
        $code = $variables['code'] ?? '';
        
        if (isset($variables['code'])) {
            return "Hello $name,\n\nYour verification code is: $code\n\nThis code expires in " . VERIFICATION_CODE_EXPIRY_MINUTES . " minutes.\n\nBest regards,\nCrop Alert System";
        }
        
        return "Hello $name,\n\nWelcome to Crop Alert System!\n\nBest regards,\nCrop Alert System";
    }
    
    /**
     * Send email using PHPMailer or native PHP mail
     */
    private function sendEmail($to, $subject, $htmlBody, $recipientName = '') {
        // Check if SMTP is configured
        if (empty(SMTP_USERNAME) || empty(SMTP_PASSWORD) || empty(SMTP_FROM_EMAIL)) {
            $missing = [];
            if (empty(SMTP_USERNAME)) $missing[] = 'SMTP_USERNAME';
            if (empty(SMTP_PASSWORD)) $missing[] = 'SMTP_PASSWORD';
            if (empty(SMTP_FROM_EMAIL)) $missing[] = 'SMTP_FROM_EMAIL';
            
            error_log("SMTP credentials not configured. Missing: " . implode(', ', $missing) . ". Email not sent to: $to");
            return [
                'success' => false,
                'message' => 'Email service not configured. Please configure SMTP settings (SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL) in config/config.php',
                'error_type' => 'smtp_not_configured',
                'missing_settings' => $missing
            ];
        }
        
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address: $to");
            return [
                'success' => false,
                'message' => 'Invalid email address format',
                'error_type' => 'invalid_email'
            ];
        }
        
        try {
            if ($this->usePHPMailer) {
                return $this->sendEmailWithPHPMailer($to, $subject, $htmlBody, $recipientName);
            } else {
                return $this->sendEmailWithNativeMail($to, $subject, $htmlBody, $recipientName);
            }
        } catch (Exception $e) {
            error_log("Email sending exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
                'error_type' => 'exception'
            ];
        }
    }
    
    /**
     * Send email using PHPMailer
     */
    private function sendEmailWithPHPMailer($to, $subject, $htmlBody, $recipientName) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Enable verbose debug output (only log, don't output)
            // Set to 2 for full debugging (can be changed to 0 in production)
            $mail->SMTPDebug = 2; // 0 = off, 1 = client messages, 2 = client and server messages
            $mail->Debugoutput = function($str, $level) {
                $logMessage = "PHPMailer Debug [$level]: $str";
                error_log($logMessage);
                // Also log to a specific email log file if possible
                $logFile = __DIR__ . '/../logs/email.log';
                if (is_writable(dirname($logFile))) {
                    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $logMessage\n", FILE_APPEND);
                }
            };
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 30; // 30 second timeout
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to, $recipientName);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            
            $mail->send();
            
            error_log("Email sent successfully via PHPMailer to: $to");
            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];
            
        } catch (Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log("PHPMailer Error sending to $to: $errorInfo");
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $errorInfo,
                'error_type' => 'phpmailer_error',
                'error_details' => $errorInfo
            ];
        }
    }
    
    /**
     * Send email using native PHP mail (fallback)
     */
    private function sendEmailWithNativeMail($to, $subject, $htmlBody, $recipientName) {
        // Create plain text version
        $plainText = strip_tags($htmlBody);
        
        // Headers
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // Check if mail function is available
        if (!function_exists('mail')) {
            error_log("PHP mail() function is not available on this server");
            return [
                'success' => false,
                'message' => 'PHP mail() function is not available on this server. Please install PHPMailer (run: composer install) or configure a mail server.',
                'error_type' => 'mail_function_unavailable'
            ];
        }
        
        // Send email
        $result = @mail($to, $subject, $htmlBody, $headers);
        
        if ($result) {
            error_log("Email sent successfully via native mail() to: $to");
            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];
        } else {
            $lastError = error_get_last();
            $errorMsg = $lastError ? $lastError['message'] : 'Unknown error';
            error_log("Failed to send email using native mail() to: $to. Error: $errorMsg");
            
            return [
                'success' => false,
                'message' => 'Failed to send email using native mail(). The server may not be configured for email. Please configure SMTP with PHPMailer (run: composer install) for better reliability.',
                'error_type' => 'native_mail_failed',
                'error_details' => $errorMsg
            ];
        }
    }
    
    /**
     * Record email notification in database
     */
    public function recordNotification($userId, $alertId, $notificationType, $emailAddress, $subject, $status, $errorMessage = null) {
        try {
            $query = "INSERT INTO email_notifications 
                     (user_id, alert_id, notification_type, email_address, subject, status, error_message, sent_at) 
                     VALUES (:user_id, :alert_id, :notification_type, :email_address, :subject, :status, :error_message, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':alert_id', $alertId);
            $stmt->bindParam(':notification_type', $notificationType);
            $stmt->bindParam(':email_address', $emailAddress);
            $stmt->bindParam(':subject', $subject);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':error_message', $errorMessage);
            
            $stmt->execute();
            
            return $this->conn->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Failed to record email notification: " . $e->getMessage());
            return false;
        }
    }
}

