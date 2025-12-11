<?php
/**
 * Email Notification Service for Crop Alert System
 * Handles sending alert notifications to verified users
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/email-service.php';

class EmailNotificationService {
    private $conn;
    private $emailService;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->emailService = new EmailService();
    }
    
    /**
     * Send alert emails to affected users
     */
    public function sendAlertEmails($alertId) {
        try {
            // Get alert details
            $alertQuery = "SELECT * FROM alerts WHERE id = :alert_id";
            $alertStmt = $this->conn->prepare($alertQuery);
            $alertStmt->bindParam(':alert_id', $alertId);
            $alertStmt->execute();
            $alert = $alertStmt->fetch();
            
            if (!$alert) {
                return [
                    'success' => false,
                    'message' => 'Alert not found'
                ];
            }
            
            // Get affected farmers/users (email verification removed - will be added back later)
            $usersQuery = "SELECT DISTINCT u.id, u.email, u.full_name 
                          FROM users u
                          LEFT JOIN alert_farmers af ON u.id = af.farmer_id
                          WHERE u.email IS NOT NULL 
                          AND u.email != ''
                          AND (af.alert_id = :alert_id OR af.alert_id IS NULL)";
            
            // If alert has specific affected farmers, only send to them
            // Otherwise, send to all users with email
            $checkAffectedQuery = "SELECT COUNT(*) as count FROM alert_farmers WHERE alert_id = :alert_id";
            $checkStmt = $this->conn->prepare($checkAffectedQuery);
            $checkStmt->bindParam(':alert_id', $alertId);
            $checkStmt->execute();
            $hasAffectedFarmers = $checkStmt->fetch()['count'] > 0;
            
            if ($hasAffectedFarmers) {
                // Only send to affected farmers
                $usersQuery = "SELECT DISTINCT u.id, u.email, u.full_name 
                              FROM users u
                              INNER JOIN alert_farmers af ON u.id = af.farmer_id
                              WHERE u.email IS NOT NULL 
                              AND u.email != ''
                              AND af.alert_id = :alert_id";
            }
            
            $usersStmt = $this->conn->prepare($usersQuery);
            $usersStmt->bindParam(':alert_id', $alertId);
            $usersStmt->execute();
            $users = $usersStmt->fetchAll();
            
            if (empty($users)) {
                return [
                    'success' => true,
                    'message' => 'No users to notify',
                    'emails_sent' => 0
                ];
            }
            
            $emailsSent = 0;
            $emailsFailed = 0;
            $batchCount = 0;
            
            foreach ($users as $user) {
                // Check if email already sent (prevent duplicates)
                $checkQuery = "SELECT id FROM email_notifications 
                              WHERE user_id = :user_id AND alert_id = :alert_id AND status = 'sent'";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user['id']);
                $checkStmt->bindParam(':alert_id', $alertId);
                $checkStmt->execute();
                
                if ($checkStmt->fetch()) {
                    continue; // Already sent, skip
                }
                
                // Send email
                $subject = 'Alert: ' . $alert['type'] . ' - Crop Alert System';
                $emailResult = $this->emailService->sendAlertNotification(
                    $user['email'],
                    $alert,
                    $user['full_name']
                );
                
                // Record notification
                $status = $emailResult['success'] ? 'sent' : 'failed';
                $errorMessage = $emailResult['success'] ? null : $emailResult['message'];
                
                $this->emailService->recordNotification(
                    $user['id'],
                    $alertId,
                    'alert',
                    $user['email'],
                    $subject,
                    $status,
                    $errorMessage
                );
                
                if ($emailResult['success']) {
                    $emailsSent++;
                } else {
                    $emailsFailed++;
                    error_log("Failed to send alert email to {$user['email']}: {$errorMessage}");
                }
                
                // Batch processing - add delay between batches
                $batchCount++;
                if ($batchCount >= EMAIL_BATCH_SIZE) {
                    sleep(EMAIL_BATCH_DELAY_SECONDS);
                    $batchCount = 0;
                } else {
                    // Small delay between individual emails
                    usleep(200000); // 0.2 seconds
                }
            }
            
            return [
                'success' => true,
                'message' => "Alert notifications sent: {$emailsSent} successful, {$emailsFailed} failed",
                'emails_sent' => $emailsSent,
                'emails_failed' => $emailsFailed,
                'total_users' => count($users)
            ];
            
        } catch (Exception $e) {
            error_log("Failed to send alert emails: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send alert emails: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send alert emails to specific users
     */
    public function sendAlertToUsers($alertId, $userIds) {
        try {
            // Get alert details
            $alertQuery = "SELECT * FROM alerts WHERE id = :alert_id";
            $alertStmt = $this->conn->prepare($alertQuery);
            $alertStmt->bindParam(':alert_id', $alertId);
            $alertStmt->execute();
            $alert = $alertStmt->fetch();
            
            if (!$alert) {
                return [
                    'success' => false,
                    'message' => 'Alert not found'
                ];
            }
            
            if (empty($userIds)) {
                return [
                    'success' => false,
                    'message' => 'No users specified'
                ];
            }
            
            // Get users (email verification removed - will be added back later)
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $usersQuery = "SELECT id, email, full_name 
                          FROM users 
                          WHERE id IN ($placeholders) 
                          AND email IS NOT NULL 
                          AND email != ''";
            
            $usersStmt = $this->conn->prepare($usersQuery);
            $usersStmt->execute($userIds);
            $users = $usersStmt->fetchAll();
            
            if (empty($users)) {
                return [
                    'success' => true,
                    'message' => 'No users found',
                    'emails_sent' => 0
                ];
            }
            
            $emailsSent = 0;
            $emailsFailed = 0;
            
            foreach ($users as $user) {
                // Check if already sent
                $checkQuery = "SELECT id FROM email_notifications 
                              WHERE user_id = :user_id AND alert_id = :alert_id AND status = 'sent'";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user['id']);
                $checkStmt->bindParam(':alert_id', $alertId);
                $checkStmt->execute();
                
                if ($checkStmt->fetch()) {
                    continue;
                }
                
                // Send email
                $subject = 'Alert: ' . $alert['type'] . ' - Crop Alert System';
                $emailResult = $this->emailService->sendAlertNotification(
                    $user['email'],
                    $alert,
                    $user['full_name']
                );
                
                // Record notification
                $status = $emailResult['success'] ? 'sent' : 'failed';
                $errorMessage = $emailResult['success'] ? null : $emailResult['message'];
                
                $this->emailService->recordNotification(
                    $user['id'],
                    $alertId,
                    'alert',
                    $user['email'],
                    $subject,
                    $status,
                    $errorMessage
                );
                
                if ($emailResult['success']) {
                    $emailsSent++;
                } else {
                    $emailsFailed++;
                }
                
                // Small delay between emails
                usleep(200000);
            }
            
            return [
                'success' => true,
                'message' => "Alert notifications sent: {$emailsSent} successful, {$emailsFailed} failed",
                'emails_sent' => $emailsSent,
                'emails_failed' => $emailsFailed
            ];
            
        } catch (Exception $e) {
            error_log("Failed to send alert emails to users: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send alert emails: ' . $e->getMessage()
            ];
        }
    }
}

