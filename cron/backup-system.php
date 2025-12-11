<?php
/**
 * Backup System Script
 * Creates full system backups (database + files)
 * Can be run manually or via scheduled task
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class BackupSystem {
    private $dbConfig;
    private $backupPath;
    private $dbBackupPath;
    private $filesBackupPath;
    private $logFile;
    private $startTime;
    
    public function __construct() {
        $this->dbConfig = [
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASS
        ];
        
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
    
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also output for CLI execution
        if (php_sapi_name() === 'cli') {
            echo $logEntry;
        }
    }
    
    public function createBackup($backupType = 'full') {
        $this->startTime = microtime(true);
        $timestamp = date('Y-m-d_H-i-s');
        
        $this->log("=== Starting backup process (Type: $backupType) ===");
        
        $results = [
            'success' => true,
            'database' => null,
            'files' => null,
            'errors' => []
        ];
        
        // Backup database
        try {
            $dbBackup = $this->backupDatabase($timestamp);
            $results['database'] = $dbBackup;
            $this->log("Database backup completed: {$dbBackup['file']}");
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = "Database backup failed: " . $e->getMessage();
            $this->log("Database backup failed: " . $e->getMessage(), 'ERROR');
        }
        
        // Backup files (optional - don't fail entire backup if this fails)
        try {
            $filesBackup = $this->backupFiles($timestamp);
            $results['files'] = $filesBackup;
            $this->log("Files backup completed: {$filesBackup['file']}");
        } catch (Exception $e) {
            // File backup is optional - log warning but don't mark entire backup as failed
            $results['errors'][] = "Files backup failed (optional): " . $e->getMessage();
            $this->log("Files backup failed (optional): " . $e->getMessage(), 'WARNING');
            // Only mark as failed if database backup also failed
            if ($results['database'] === null) {
                $results['success'] = false;
            }
        }
        
        // Cleanup old backups
        $this->cleanupOldBackups();
        
        $duration = round(microtime(true) - $this->startTime, 2);
        $this->log("=== Backup process completed in {$duration} seconds ===");
        
        return $results;
    }
    
    private function backupDatabase($timestamp) {
        $dbFile = $this->dbBackupPath . "crop_alert_system_{$timestamp}.sql";
        $compressedFile = $dbFile . '.gz';
        
        // Find mysqldump executable
        $mysqldump = $this->findMysqldump();
        
        if (!$mysqldump) {
            throw new Exception("mysqldump not found. Please ensure MySQL is installed and in PATH.");
        }
        
        // Build mysqldump command
        $command = sprintf(
            '"%s" --host=%s --user=%s --password=%s --single-transaction --routines --triggers %s > "%s"',
            $mysqldump,
            escapeshellarg($this->dbConfig['host']),
            escapeshellarg($this->dbConfig['user']),
            escapeshellarg($this->dbConfig['pass']),
            escapeshellarg($this->dbConfig['name']),
            $dbFile
        );
        
        // Execute mysqldump
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0 || !file_exists($dbFile) || filesize($dbFile) === 0) {
            if (file_exists($dbFile)) {
                unlink($dbFile);
            }
            throw new Exception("mysqldump failed: " . implode("\n", $output));
        }
        
        // Compress if enabled
        if (BACKUP_COMPRESSION) {
            $this->compressFile($dbFile, $compressedFile);
            $finalFile = $compressedFile;
        } else {
            $finalFile = $dbFile;
        }
        
        $fileSize = filesize($finalFile);
        
        return [
            'file' => basename($finalFile),
            'path' => $finalFile,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'timestamp' => $timestamp,
            'compressed' => BACKUP_COMPRESSION
        ];
    }
    
    private function backupFiles($timestamp) {
        $projectRoot = dirname(__DIR__);
        $filesArchive = $this->filesBackupPath . "files_{$timestamp}.tar";
        $compressedArchive = $filesArchive . '.gz';
        
        // Files and directories to backup
        $itemsToBackup = [
            'api',
            'assets',
            'config',
            '*.html',
            '*.php'
        ];
        
        // Files and directories to exclude
        $excludes = [
            'backups',
            'logs',
            'node_modules',
            '.git',
            '*.log',
            '*.tmp',
            '*.cache'
        ];
        
        // Build tar command
        $tar = $this->findTar();
        
        if (!$tar) {
            // Fallback: Use PHP ZipArchive for Windows
            return $this->backupFilesZip($timestamp, $projectRoot);
        }
        
        $command = sprintf(
            'cd "%s" && "%s" -cf "%s" %s %s',
            $projectRoot,
            $tar,
            $filesArchive,
            implode(' ', array_map('escapeshellarg', $itemsToBackup)),
            implode(' ', array_map(function($exclude) {
                return '--exclude=' . escapeshellarg($exclude);
            }, $excludes))
        );
        
        // Execute tar
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0 || !file_exists($filesArchive)) {
            if (file_exists($filesArchive)) {
                unlink($filesArchive);
            }
            throw new Exception("tar failed: " . implode("\n", $output));
        }
        
        // Compress if enabled
        if (BACKUP_COMPRESSION) {
            $this->compressFile($filesArchive, $compressedArchive);
            $finalFile = $compressedArchive;
        } else {
            $finalFile = $filesArchive;
        }
        
        $fileSize = filesize($finalFile);
        
        return [
            'file' => basename($finalFile),
            'path' => $finalFile,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'timestamp' => $timestamp,
            'compressed' => BACKUP_COMPRESSION
        ];
    }
    
    private function backupFilesZip($timestamp, $projectRoot) {
        // Try ZipArchive first
        if (class_exists('ZipArchive')) {
            return $this->backupFilesZipArchive($timestamp, $projectRoot);
        }
        
        // Fallback: Create a simple tar-like archive using PHP
        return $this->backupFilesSimple($timestamp, $projectRoot);
    }
    
    private function backupFilesZipArchive($timestamp, $projectRoot) {
        $zipFile = $this->filesBackupPath . "files_{$timestamp}.zip";
        
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Cannot create zip file: $zipFile");
        }
        
        // Files and directories to backup
        $itemsToBackup = ['api', 'assets', 'config'];
        $excludes = ['backups', 'logs', 'node_modules', '.git'];
        
        foreach ($itemsToBackup as $item) {
            $itemPath = $projectRoot . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->addDirectoryToZip($zip, $itemPath, $item, $excludes);
            } elseif (is_file($itemPath)) {
                $zip->addFile($itemPath, $item);
            }
        }
        
        // Add HTML and PHP files from root
        $rootFiles = glob($projectRoot . DIRECTORY_SEPARATOR . '*.{html,php}', GLOB_BRACE);
        foreach ($rootFiles as $file) {
            $zip->addFile($file, basename($file));
        }
        
        $zip->close();
        
        $fileSize = filesize($zipFile);
        
        return [
            'file' => basename($zipFile),
            'path' => $zipFile,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'timestamp' => $timestamp,
            'compressed' => true,
            'format' => 'zip'
        ];
    }
    
    private function backupFilesSimple($timestamp, $projectRoot) {
        // Create a simple text-based archive listing files and their contents
        $archiveFile = $this->filesBackupPath . "files_{$timestamp}.txt";
        $fp = fopen($archiveFile, 'wb');
        
        if (!$fp) {
            throw new Exception("Cannot create archive file: $archiveFile");
        }
        
        // Write header
        fwrite($fp, "=== FILE BACKUP ARCHIVE ===\n");
        fwrite($fp, "Created: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "Project Root: $projectRoot\n");
        fwrite($fp, str_repeat("=", 50) . "\n\n");
        
        // Files and directories to backup
        $itemsToBackup = ['api', 'assets', 'config'];
        $excludes = ['backups', 'logs', 'node_modules', '.git'];
        
        $fileCount = 0;
        foreach ($itemsToBackup as $item) {
            $itemPath = $projectRoot . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $fileCount += $this->addDirectoryToArchive($fp, $itemPath, $item, $excludes);
            } elseif (is_file($itemPath)) {
                $this->addFileToArchive($fp, $itemPath, $item);
                $fileCount++;
            }
        }
        
        // Add HTML and PHP files from root
        $rootFiles = glob($projectRoot . DIRECTORY_SEPARATOR . '*.{html,php}', GLOB_BRACE);
        foreach ($rootFiles as $file) {
            $this->addFileToArchive($fp, $file, basename($file));
            $fileCount++;
        }
        
        fwrite($fp, "\n=== END OF ARCHIVE ===\n");
        fwrite($fp, "Total files: $fileCount\n");
        fclose($fp);
        
        // Compress if enabled
        $compressedFile = $archiveFile . '.gz';
        if (BACKUP_COMPRESSION && function_exists('gzencode')) {
            $this->compressFile($archiveFile, $compressedFile);
            $finalFile = $compressedFile;
        } else {
            $finalFile = $archiveFile;
        }
        
        $fileSize = filesize($finalFile);
        
        return [
            'file' => basename($finalFile),
            'path' => $finalFile,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'timestamp' => $timestamp,
            'compressed' => BACKUP_COMPRESSION && function_exists('gzencode'),
            'format' => BACKUP_COMPRESSION && function_exists('gzencode') ? 'txt.gz' : 'txt'
        ];
    }
    
    private function addDirectoryToArchive($fp, $dir, $basePath, $excludes) {
        $fileCount = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $basePath . '/' . str_replace('\\', '/', substr($filePath, strlen($dir) + 1));
                
                // Check if file should be excluded
                $shouldExclude = false;
                foreach ($excludes as $exclude) {
                    if (strpos($relativePath, $exclude) !== false) {
                        $shouldExclude = true;
                        break;
                    }
                }
                
                if (!$shouldExclude) {
                    $this->addFileToArchive($fp, $filePath, $relativePath);
                    $fileCount++;
                }
            }
        }
        
        return $fileCount;
    }
    
    private function addFileToArchive($fp, $filePath, $relativePath) {
        fwrite($fp, "\n--- FILE: $relativePath ---\n");
        fwrite($fp, "Size: " . filesize($filePath) . " bytes\n");
        fwrite($fp, "Modified: " . date('Y-m-d H:i:s', filemtime($filePath)) . "\n");
        fwrite($fp, "Content:\n");
        
        // Read and write file content (limit to 1MB per file to avoid memory issues)
        if (filesize($filePath) < 1048576) {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                fwrite($fp, base64_encode($content) . "\n");
            }
        } else {
            fwrite($fp, "[FILE TOO LARGE - SKIPPED CONTENT]\n");
        }
    }
    
    private function addDirectoryToZip($zip, $dir, $basePath, $excludes) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $basePath . '/' . substr($filePath, strlen($dir) + 1);
                
                // Check if file should be excluded
                $shouldExclude = false;
                foreach ($excludes as $exclude) {
                    if (strpos($relativePath, $exclude) !== false) {
                        $shouldExclude = true;
                        break;
                    }
                }
                
                if (!$shouldExclude) {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
    }
    
    private function compressFile($source, $destination) {
        $fp_in = fopen($source, 'rb');
        if (!$fp_in) {
            throw new Exception("Cannot open source file: $source");
        }
        
        $fp_out = gzopen($destination, 'wb9');
        if (!$fp_out) {
            fclose($fp_in);
            throw new Exception("Cannot create compressed file: $destination");
        }
        
        while (!feof($fp_in)) {
            gzwrite($fp_out, fread($fp_in, 8192));
        }
        
        fclose($fp_in);
        gzclose($fp_out);
        
        // Delete original file
        unlink($source);
    }
    
    private function cleanupOldBackups() {
        $retentionDays = BACKUP_RETENTION_DAYS;
        $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
        
        $deletedCount = 0;
        
        // Clean database backups
        $dbFiles = glob($this->dbBackupPath . '*');
        foreach ($dbFiles as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
                $this->log("Deleted old backup: " . basename($file));
            }
        }
        
        // Clean file backups
        $fileBackups = glob($this->filesBackupPath . '*');
        foreach ($fileBackups as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
                $this->log("Deleted old backup: " . basename($file));
            }
        }
        
        if ($deletedCount > 0) {
            $this->log("Cleaned up $deletedCount old backup(s)");
        }
    }
    
    private function findMysqldump() {
        $possiblePaths = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump'
        ];
        
        foreach ($possiblePaths as $path) {
            // Check if file exists (works on Windows)
            if (file_exists($path)) {
                return $path;
            }
            // On Unix systems, try which command
            if (PHP_OS_FAMILY !== 'Windows') {
                $whichResult = shell_exec("which " . escapeshellarg($path) . " 2>/dev/null");
                if ($whichResult && trim($whichResult)) {
                    return trim($whichResult);
                }
            }
        }
        
        return null;
    }
    
    private function findTar() {
        $possiblePaths = [
            'C:\\Program Files\\Git\\usr\\bin\\tar.exe',
            'tar',
            '/usr/bin/tar',
            '/bin/tar'
        ];
        
        foreach ($possiblePaths as $path) {
            // Check if file exists (works on Windows)
            if (file_exists($path)) {
                return $path;
            }
            // On Unix systems, try which command
            if (PHP_OS_FAMILY !== 'Windows') {
                $whichResult = shell_exec("which " . escapeshellarg($path) . " 2>/dev/null");
                if ($whichResult && trim($whichResult)) {
                    return trim($whichResult);
                }
            }
        }
        
        return null;
    }
    
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// Execute backup if run from command line
if (php_sapi_name() === 'cli') {
    $backup = new BackupSystem();
    $result = $backup->createBackup();
    
    if ($result['success']) {
        echo "Backup completed successfully!\n";
        exit(0);
    } else {
        echo "Backup completed with errors:\n";
        foreach ($result['errors'] as $error) {
            echo "  - $error\n";
        }
        exit(1);
    }
}

