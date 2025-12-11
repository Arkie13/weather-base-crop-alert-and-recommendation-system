<?php
/**
 * Backup and Restore API
 * Handles backup creation, listing, restoration, deletion, and download
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../cron/backup-system.php';

class BackupRestoreAPI {
    private $backupPath;
    private $dbBackupPath;
    private $filesBackupPath;
    private $logFile;
    
    public function __construct() {
        $this->backupPath = BACKUP_STORAGE_PATH;
        $this->dbBackupPath = BACKUP_DATABASE_PATH;
        $this->filesBackupPath = BACKUP_FILES_PATH;
        $this->logFile = BACKUP_LOG_FILE;
        
        // Ensure directories exist
        $this->ensureDirectories();
    }
    
    private function ensureDirectories() {
        $dirs = [
            $this->backupPath,
            $this->dbBackupPath,
            $this->filesBackupPath,
            BACKUP_LOGS_PATH
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    private function checkAdminAuth() {
        session_start();
        
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
            http_response_code(403);
            return [
                'success' => false,
                'message' => 'Admin authentication required'
            ];
        }
        
        return null;
    }
    
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    public function listBackups() {
        $authError = $this->checkAdminAuth();
        if ($authError) {
            return $authError;
        }
        
        $backups = [
            'database' => [],
            'files' => []
        ];
        
        // List database backups
        $dbFiles = glob($this->dbBackupPath . '*');
        foreach ($dbFiles as $file) {
            if (is_file($file)) {
                $backups['database'][] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'size_formatted' => $this->formatBytes(filesize($file)),
                    'created' => date('Y-m-d H:i:s', filemtime($file)),
                    'timestamp' => filemtime($file),
                    'type' => 'database'
                ];
            }
        }
        
        // List file backups
        $fileBackups = glob($this->filesBackupPath . '*');
        foreach ($fileBackups as $file) {
            if (is_file($file)) {
                $backups['files'][] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'size_formatted' => $this->formatBytes(filesize($file)),
                    'created' => date('Y-m-d H:i:s', filemtime($file)),
                    'timestamp' => filemtime($file),
                    'type' => 'files'
                ];
            }
        }
        
        // Sort by timestamp (newest first)
        usort($backups['database'], function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        usort($backups['files'], function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return [
            'success' => true,
            'backups' => $backups,
            'total_database' => count($backups['database']),
            'total_files' => count($backups['files'])
        ];
    }
    
    public function createBackup() {
        $authError = $this->checkAdminAuth();
        if ($authError) {
            return $authError;
        }
        
        if (!BACKUP_ENABLED) {
            return [
                'success' => false,
                'message' => 'Backup system is disabled'
            ];
        }
        
        try {
            $backup = new BackupSystem();
            $result = $backup->createBackup();
            
            $this->log("Manual backup created by admin: " . $_SESSION['username']);
            
            // Consider backup successful if database backup succeeded (file backup is optional)
            $isSuccessful = $result['success'] && $result['database'] !== null;
            
            return [
                'success' => $isSuccessful,
                'database' => $result['database'],
                'files' => $result['files'],
                'errors' => $result['errors'] ?? [],
                'message' => $isSuccessful 
                    ? 'Backup created successfully' . (empty($result['errors']) ? '' : ' (with warnings)')
                    : 'Backup failed: ' . (implode('; ', $result['errors'] ?? ['Unknown error']))
            ];
        } catch (Exception $e) {
            $this->log("Backup creation failed: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'message' => 'Backup creation failed: ' . $e->getMessage()
            ];
        }
    }
    
    public function restoreBackup($filename, $type = 'database') {
        $authError = $this->checkAdminAuth();
        if ($authError) {
            return $authError;
        }
        
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        
        if ($type === 'database') {
            $backupFile = $this->dbBackupPath . $filename;
        } else {
            $backupFile = $this->filesBackupPath . $filename;
        }
        
        if (!file_exists($backupFile) || !is_readable($backupFile)) {
            return [
                'success' => false,
                'message' => 'Backup file not found or not readable'
            ];
        }
        
        // Only database restore is supported for now
        if ($type !== 'database') {
            return [
                'success' => false,
                'message' => 'File restore not yet implemented'
            ];
        }
        
        try {
            // Create safety backup before restore
            $this->log("Creating safety backup before restore");
            $safetyBackup = new BackupSystem();
            $safetyResult = $safetyBackup->createBackup();
            
            if (!$safetyResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to create safety backup. Restore aborted for safety.'
                ];
            }
            
            // Restore database
            $this->log("Starting database restore from: $filename");
            $result = $this->restoreDatabase($backupFile);
            
            if ($result['success']) {
                $this->log("Database restore completed successfully by admin: " . $_SESSION['username']);
            } else {
                $this->log("Database restore failed: " . $result['message'], 'ERROR');
            }
            
            return $result;
        } catch (Exception $e) {
            $this->log("Restore failed: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'message' => 'Restore failed: ' . $e->getMessage()
            ];
        }
    }
    
    private function restoreDatabase($backupFile) {
        require_once __DIR__ . '/../config/database.php';
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Determine if file is compressed
        $isCompressed = substr($backupFile, -3) === '.gz';
        $tempSqlFile = null;
        
        if ($isCompressed) {
            // Decompress file
            $tempSqlFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_' . uniqid() . '.sql';
            $fp_in = gzopen($backupFile, 'rb');
            $fp_out = fopen($tempSqlFile, 'wb');
            
            if (!$fp_in || !$fp_out) {
                return [
                    'success' => false,
                    'message' => 'Failed to decompress backup file'
                ];
            }
            
            while (!gzeof($fp_in)) {
                fwrite($fp_out, gzread($fp_in, 8192));
            }
            
            gzclose($fp_in);
            fclose($fp_out);
            
            $sqlFile = $tempSqlFile;
        } else {
            $sqlFile = $backupFile;
        }
        
        // Read SQL file
        $sql = file_get_contents($sqlFile);
        
        if ($sql === false) {
            if ($tempSqlFile) {
                unlink($tempSqlFile);
            }
            return [
                'success' => false,
                'message' => 'Failed to read SQL file'
            ];
        }
        
        // Execute SQL
        try {
            // Disable foreign key checks temporarily
            $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
            
            // Split SQL into individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^--/', $stmt);
                }
            );
            
            $executed = 0;
            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    try {
                        $conn->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        // Log but continue (some statements may fail if tables don't exist)
                        error_log("SQL execution warning: " . $e->getMessage());
                    }
                }
            }
            
            // Re-enable foreign key checks
            $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
            
            // Clean up temp file
            if ($tempSqlFile && file_exists($tempSqlFile)) {
                unlink($tempSqlFile);
            }
            
            return [
                'success' => true,
                'message' => "Database restored successfully. $executed statements executed."
            ];
        } catch (Exception $e) {
            // Re-enable foreign key checks on error
            try {
                $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Exception $e2) {
                // Ignore
            }
            
            if ($tempSqlFile && file_exists($tempSqlFile)) {
                unlink($tempSqlFile);
            }
            
            return [
                'success' => false,
                'message' => 'Database restore failed: ' . $e->getMessage()
            ];
        }
    }
    
    public function deleteBackup($filename, $type = 'database') {
        $authError = $this->checkAdminAuth();
        if ($authError) {
            return $authError;
        }
        
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        
        if ($type === 'database') {
            $backupFile = $this->dbBackupPath . $filename;
        } else {
            $backupFile = $this->filesBackupPath . $filename;
        }
        
        if (!file_exists($backupFile)) {
            return [
                'success' => false,
                'message' => 'Backup file not found'
            ];
        }
        
        if (unlink($backupFile)) {
            $this->log("Backup deleted by admin: $filename");
            return [
                'success' => true,
                'message' => 'Backup deleted successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to delete backup file'
            ];
        }
    }
    
    public function downloadBackup($filename, $type = 'database') {
        $authError = $this->checkAdminAuth();
        if ($authError) {
            http_response_code(403);
            echo json_encode($authError);
            exit;
        }
        
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        
        if ($type === 'database') {
            $backupFile = $this->dbBackupPath . $filename;
        } else {
            $backupFile = $this->filesBackupPath . $filename;
        }
        
        if (!file_exists($backupFile) || !is_readable($backupFile)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Backup file not found'
            ]);
            exit;
        }
        
        // Set headers for file download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($backupFile));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Stream file
        readfile($backupFile);
        exit;
    }
    
    public function getStatus() {
        $authError = $this->checkAdminAuth();
        if ($authError) {
            return $authError;
        }
        
        // Get last backup time
        $lastBackup = null;
        $dbFiles = glob($this->dbBackupPath . '*');
        $fileBackups = glob($this->filesBackupPath . '*');
        
        $allBackups = array_merge($dbFiles, $fileBackups);
        if (!empty($allBackups)) {
            $latestFile = null;
            $latestTime = 0;
            foreach ($allBackups as $file) {
                if (is_file($file) && filemtime($file) > $latestTime) {
                    $latestTime = filemtime($file);
                    $latestFile = $file;
                }
            }
            if ($latestFile) {
                $lastBackup = [
                    'file' => basename($latestFile),
                    'time' => date('Y-m-d H:i:s', $latestTime),
                    'timestamp' => $latestTime
                ];
            }
        }
        
        // Calculate disk usage
        $totalSize = 0;
        foreach ($allBackups as $file) {
            if (is_file($file)) {
                $totalSize += filesize($file);
            }
        }
        
        // Get disk space info
        $freeBytes = disk_free_space($this->backupPath);
        $totalBytes = disk_total_space($this->backupPath);
        
        return [
            'success' => true,
            'enabled' => BACKUP_ENABLED,
            'last_backup' => $lastBackup,
            'backup_count' => [
                'database' => count(array_filter($dbFiles, 'is_file')),
                'files' => count(array_filter($fileBackups, 'is_file'))
            ],
            'disk_usage' => [
                'total_size' => $totalSize,
                'total_size_formatted' => $this->formatBytes($totalSize),
                'free_space' => $freeBytes,
                'free_space_formatted' => $this->formatBytes($freeBytes),
                'total_space' => $totalBytes,
                'total_space_formatted' => $this->formatBytes($totalBytes),
                'used_percent' => $totalBytes > 0 ? round(($totalSize / $totalBytes) * 100, 2) : 0
            ],
            'retention_days' => BACKUP_RETENTION_DAYS,
            'compression_enabled' => BACKUP_COMPRESSION
        ];
    }
}

// Handle request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$api = new BackupRestoreAPI();

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

switch ($requestMethod) {
    case 'GET':
        if ($action === 'download') {
            $filename = $_GET['file'] ?? '';
            $type = $_GET['type'] ?? 'database';
            $api->downloadBackup($filename, $type);
        } elseif ($action === 'status') {
            echo json_encode($api->getStatus());
        } else {
            echo json_encode($api->listBackups());
        }
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $action;
        
        if ($action === 'create') {
            echo json_encode($api->createBackup());
        } elseif ($action === 'restore') {
            $filename = $input['file'] ?? '';
            $type = $input['type'] ?? 'database';
            echo json_encode($api->restoreBackup($filename, $type));
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
        }
        break;
        
    case 'DELETE':
        $filename = $_GET['file'] ?? '';
        $type = $_GET['type'] ?? 'database';
        echo json_encode($api->deleteBackup($filename, $type));
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
}

