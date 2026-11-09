<?php
declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Debug;
use CMS\Security;
use PDO;

/**
 * System Service
 * Handles system status, diagnostics, and troubleshooting
 * 
 * @package CMS\Services
 */
class SystemService {
    private static ?SystemService $instance = null;
    private Database $db;
    
    /**
     * Get singleton instance
     */
    public static function instance(): SystemService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->db = Database::instance();
    }

    public function getConfiguredLogDirectory(): string {
        $logDir = defined('LOG_PATH') ? (string) LOG_PATH : (ABSPATH . 'logs' . DIRECTORY_SEPARATOR);

        return rtrim($logDir, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR;
    }

    public function getConfiguredErrorLogFile(): string {
        if (defined('CMS_ERROR_LOG')) {
            return (string) CMS_ERROR_LOG;
        }

        return $this->getConfiguredLogDirectory() . 'error.log';
    }
    
    /**
     * Get system information
     */
    public function getSystemInfo(): array {
        $pdo = $this->db->getConnection();
        
        // Get MySQL version
        $mysql_version = 'Unknown';
        try {
            $result = $pdo->query("SELECT VERSION() as version");
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $mysql_version = $row['version'];
            }
        } catch (\Exception $e) {
            $mysql_version = 'Error: ' . $e->getMessage();
        }
        
        return [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'mysql_version' => $mysql_version,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_input_vars' => ini_get('max_input_vars'),
            'timezone' => date_default_timezone_get(),
            'display_errors' => ini_get('display_errors') ? 'On' : 'Off',
            'error_reporting' => error_reporting(),
            'session_save_path' => session_save_path(),
            'temp_dir' => sys_get_temp_dir(),
            'os' => PHP_OS,
            'architecture' => php_uname('m'),
            'hostname' => gethostname()
        ];
    }
    
    /**
     * Get database status
     */
    public function getDatabaseStatus(): array {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();
        
        $status = [
            'connected' => false,
            'database_name' => DB_NAME,
            'database_size' => 0,
            'total_tables' => 0,
            'cms_tables' => 0,
            'connection_error' => null
        ];
        
        try {
            // Test connection
            $result = $pdo->query("SELECT 1");
            if ($result) {
                $status['connected'] = true;
            }
            
            // Get database size
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(data_length + index_length) as size
                FROM information_schema.TABLES 
                WHERE table_schema = ?
            ");
            $stmt->execute([(string) DB_NAME]);
            $result = $stmt;
            
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $status['database_size'] = (int)($row['size'] ?? 0);
            }
            
            // Count total tables
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM information_schema.TABLES 
                WHERE table_schema = ?
            ");
            $stmt->execute([(string) DB_NAME]);
            $result = $stmt;
            
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $status['total_tables'] = (int)($row['total'] ?? 0);
            }
            
            // Count CMS tables
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM information_schema.TABLES 
                WHERE table_schema = ?
                AND table_name LIKE ?
            ");
            $stmt->execute([(string) DB_NAME, $prefix . '%']);
            $result = $stmt;
            
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $status['cms_tables'] = (int)($row['total'] ?? 0);
            }
            
        } catch (\Exception $e) {
            $status['connection_error'] = $e->getMessage();
        }
        
        return $status;
    }
    
    /**
     * Check all CMS database tables
     */
    public function checkDatabaseTables(): array {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();
        
        $tables = [
            'users' => 'Benutzer',
            'user_meta' => 'Benutzer-Metadaten',
            'roles' => 'Rollen',
            'sessions' => 'Sitzungen',
            'settings' => 'Einstellungen',
            'pages' => 'Seiten',
            'page_revisions' => 'Seiten-Revisionen',
            'landing_sections' => 'Landing Sections',
            'activity_log' => 'Aktivitätslog',
            'cache' => 'Cache',
            'login_attempts' => 'Login-Versuche',
            'blocked_ips' => 'Blockierte IPs',
            'media' => 'Media-Bibliothek',
            'plugins' => 'Plugins',
            'plugin_meta' => 'Plugin-Metadaten',
            'theme_customizations' => 'Theme-Anpassungen',
            
            // Content
            'post_categories' => 'Blog-Kategorien',
            'posts' => 'Blog-Beiträge',
            'comments' => 'Kommentare',
            'page_views' => 'Seitenaufrufe',
            'orders' => 'Bestellungen',
            'messages' => 'Nachrichten',

            // Subscription System
            'subscription_plans' => 'Abo-Pakete',
            'user_subscriptions' => 'Benutzer-Abos',
            'user_groups' => 'Benutzer-Gruppen',
            'user_group_members' => 'Gruppen-Mitglieder',
            'subscription_usage' => 'Abo-Nutzung',

            // Security & Audit
            'failed_logins' => 'Fehlgeschlagene Logins',
            'audit_log' => 'Audit-Log',

            // Custom Fonts
            'custom_fonts' => 'Eigene Schriften',
        ];
        
        $table_status = [];
        
        foreach ($tables as $table => $label) {
            $full_table = $prefix . $table;
            $status = [
                'name' => $table,
                'label' => $label,
                'exists' => false,
                'rows' => 0,
                'size' => 0,
                'status' => 'OK',
                'error' => null
            ];
            
            try {
                // Check if table exists
                if ($this->db->tableExists($full_table)) {
                    $status['exists'] = true;
                    $quotedFullTable = $this->quoteIdentifier($full_table);
                    
                    // Count rows
                    $result = $pdo->query("SELECT COUNT(*) as total FROM {$quotedFullTable}");
                    if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                        $status['rows'] = (int)($row['total'] ?? 0);
                    }
                    
                    // Get table size
                    $stmt = $pdo->prepare("
                        SELECT 
                            data_length + index_length as size
                        FROM information_schema.TABLES 
                        WHERE table_schema = ?
                        AND table_name = ?
                    ");
                    $stmt->execute([(string) DB_NAME, $full_table]);
                    $result = $stmt;
                    
                    if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                        $status['size'] = (int)($row['size'] ?? 0);
                    }
                    
                    // Check table status
                    $result = $pdo->query("CHECK TABLE {$quotedFullTable}");
                    if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                        $status['status'] = $row['Msg_text'] ?? 'OK';
                    }
                } else {
                    $status['status'] = 'Missing';
                    $status['error'] = 'Tabelle existiert nicht';
                }
                
            } catch (\Exception $e) {
                $status['error'] = $e->getMessage();
                $status['status'] = 'Error';
            }
            
            $table_status[] = $status;
        }
        
        return $table_status;
    }
    
    /**
     * Check file and directory permissions
     */
    public function checkFilePermissions(): array {
        $base_path = dirname(dirname(__DIR__));
        $logPath = rtrim($this->getConfiguredLogDirectory(), DIRECTORY_SEPARATOR);
        
        $paths = [
            'uploads' => $base_path . '/uploads',
            'cache' => $base_path . '/cache',
            'logs' => $logPath,
            'config' => $base_path . '/config',
            'assets/css' => $base_path . '/assets/css',
            'assets/js' => $base_path . '/assets/js',
            'assets/images' => $base_path . '/assets/images'
        ];
        
        $permissions = [];
        
        foreach ($paths as $name => $path) {
            $status = [
                'path' => $name,
                'full_path' => $path,
                'exists' => false,
                'readable' => false,
                'writable' => false,
                'permissions' => null,
                'status' => 'Error'
            ];
            
            if (file_exists($path)) {
                $status['exists'] = true;
                $status['readable'] = is_readable($path);
                $status['writable'] = is_writable($path);
                $status['permissions'] = substr(sprintf('%o', fileperms($path)), -4);
                
                if ($status['readable'] && $status['writable']) {
                    $status['status'] = 'OK';
                } elseif ($status['readable']) {
                    $status['status'] = 'Read-Only';
                } else {
                    $status['status'] = 'No Access';
                }
            } else {
                $status['status'] = 'Missing';
            }
            
            $permissions[] = $status;
        }
        
        return $permissions;
    }
    
    /**
     * Get directory sizes
     */
    public function getDirectorySizes(): array {
        $base_path = dirname(dirname(__DIR__));
        $logPath = rtrim($this->getConfiguredLogDirectory(), DIRECTORY_SEPARATOR);
        
        $directories = [
            'uploads' => $base_path . '/uploads',
            'cache' => $base_path . '/cache',
            'logs' => $logPath,
            'assets' => $base_path . '/assets'
        ];
        
        $sizes = [];
        
        foreach ($directories as $name => $path) {
            $size = 0;
            
            if (is_dir($path)) {
                $size = $this->getDirectorySize($path);
            }
            
            $sizes[$name] = [
                'path' => $name,
                'size' => $size,
                'formatted' => $this->formatBytes($size)
            ];
        }
        
        return $sizes;
    }
    
    /**
     * Calculate directory size recursively
     */
    private function getDirectorySize(string $path): int {
        $size = 0;
        
        try {
            if (is_dir($path)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore errors (permission denied, etc.)
        }
        
        return $size;
    }
    
    /**
     * Format bytes to human readable
     */
    public function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Get CMS statistics
     */
    public function getCMSStatistics(): array {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();
        
        $stats = [
            'total_users' => 0,
            'active_users' => 0,
            'total_pages' => 0,
            'total_sessions' => 0,
            'cache_entries' => 0,
            'failed_logins_today' => 0
        ];
        
        try {
            // Users
            $result = $pdo->prepare("SELECT COUNT(*) as total FROM {$this->quoteIdentifier($prefix . 'users')}");
            $result->execute();
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $stats['total_users'] = (int)($row['total'] ?? 0);
            }
            
            $result = $pdo->prepare("SELECT COUNT(*) as total FROM {$this->quoteIdentifier($prefix . 'users')} WHERE status = ?");
            $result->execute(['active']);
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $stats['active_users'] = (int)($row['total'] ?? 0);
            }
            
            // Pages
            $result = $pdo->prepare("SELECT COUNT(*) as total FROM {$this->quoteIdentifier($prefix . 'pages')}");
            $result->execute();
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $stats['total_pages'] = (int)($row['total'] ?? 0);
            }
            
            // Sessions
            $result = $pdo->prepare("SELECT COUNT(*) as total FROM {$this->quoteIdentifier($prefix . 'sessions')} WHERE expires_at > NOW()");
            $result->execute();
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $stats['total_sessions'] = (int)($row['total'] ?? 0);
            }
            
            // Cache
            $result = $pdo->prepare("SELECT COUNT(*) as total FROM {$this->quoteIdentifier($prefix . 'cache')} WHERE expires_at > NOW()");
            $result->execute();
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $stats['cache_entries'] = (int)($row['total'] ?? 0);
            }
            
            // Fehlgeschlagene Logins heute; login_attempts enthält auch andere Rate-Limit-Ereignisse.
            $failedLoginActions = Security::FAILED_LOGIN_ACTIONS;
            $result = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM {$this->quoteIdentifier($prefix . 'login_attempts')} 
                WHERE attempted_at >= CURDATE()
                  AND action IN (" . implode(', ', array_fill(0, count($failedLoginActions), '?')) . ")
            ");
            $result->execute($failedLoginActions);
            if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
                $stats['failed_logins_today'] = (int)($row['total'] ?? 0);
            }
        } catch (\Exception $e) {
            // Log error but return partial stats
        }
        
        return $stats;
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): bool {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();
        
        try {
            $stmt = $pdo->prepare("DELETE FROM {$this->quoteIdentifier($prefix . 'cache')}");
            $stmt->execute();
            
            // Also clear cache directory
            $cache_dir = dirname(dirname(__DIR__)) . '/cache';
            if (is_dir($cache_dir)) {
                $cacheRoot = realpath($cache_dir);
                $files = glob($cache_dir . '/*') ?: [];
                foreach ($files as $file) {
                    $resolved = realpath($file);
                    if ($cacheRoot !== false && $resolved !== false && str_starts_with(str_replace('\\', '/', $resolved), rtrim(str_replace('\\', '/', $cacheRoot), '/') . '/') && is_file($file)) {
                        unlink($file); // M-03: kein @, is_file vorher geprüft
                    }
                }
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Clear old sessions
     */
    public function clearOldSessions(): bool {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();
        
        try {
            $stmt = $pdo->prepare("DELETE FROM {$this->quoteIdentifier($prefix . 'sessions')} WHERE expires_at < NOW()");
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Clear failed login attempts older than 24h
     */
    public function clearOldFailedLogins(): bool {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();
        
        try {
            $stmt = $pdo->prepare("
                DELETE FROM {$this->quoteIdentifier($prefix . 'login_attempts')} 
                WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Repair database tables
     */
    public function repairTables(): array {
        $prefix = $this->db->getPrefix();
        $supportedEngines = ['MYISAM', 'ARCHIVE', 'CSV'];
        $results = [];

        foreach ($this->getCmsTableMaintenanceTargets() as $target) {
            $tableName = (string)($target['table_name'] ?? '');
            $engine = strtoupper((string)($target['engine'] ?? ''));
            $key = str_starts_with($tableName, $prefix) ? substr($tableName, strlen($prefix)) : $tableName;

            if (!in_array($engine, $supportedEngines, true)) {
                $results[$key] = [
                    'success' => false,
                    'skipped' => true,
                    'message' => 'Übersprungen: REPAIR TABLE ist für Engine ' . ($engine !== '' ? $engine : 'unbekannt') . ' nicht vorgesehen.'
                ];
                continue;
            }

            $results[$key] = $this->runTableMaintenanceStatement('REPAIR', $tableName);
        }

        return $results;
    }
    
    /**
     * Optimize database tables
     */
    public function optimizeTables(): array {
        $prefix = $this->db->getPrefix();
        $supportedEngines = ['INNODB', 'MYISAM', 'ARCHIVE'];
        $results = [];

        foreach ($this->getCmsTableMaintenanceTargets() as $target) {
            $tableName = (string)($target['table_name'] ?? '');
            $engine = strtoupper((string)($target['engine'] ?? ''));
            $key = str_starts_with($tableName, $prefix) ? substr($tableName, strlen($prefix)) : $tableName;

            if (!in_array($engine, $supportedEngines, true)) {
                $results[$key] = [
                    'success' => false,
                    'skipped' => true,
                    'message' => 'Übersprungen: OPTIMIZE TABLE ist für Engine ' . ($engine !== '' ? $engine : 'unbekannt') . ' nicht vorgesehen.'
                ];
                continue;
            }

            $results[$key] = $this->runTableMaintenanceStatement('OPTIMIZE', $tableName);
        }

        return $results;
    }

    /**
     * @return list<array{table_name:string,engine:?string}>
     */
    private function getCmsTableMaintenanceTargets(): array {
        $pdo = $this->db->getConnection();
        $prefix = $this->db->getPrefix();

        $stmt = $pdo->prepare(
            "SELECT table_name, engine
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = 'BASE TABLE'
               AND table_name LIKE ?
             ORDER BY table_name ASC"
        );
        $stmt->execute([$prefix . '%']);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{success:bool,message:string,details?:list<array<string,mixed>>}
     */
    private function runTableMaintenanceStatement(string $operation, string $tableName): array {
        $pdo = $this->db->getConnection();
        $operation = strtoupper($operation) === 'REPAIR' ? 'REPAIR' : 'OPTIMIZE';

        try {
            $result = $pdo->query($operation . ' TABLE ' . $this->quoteIdentifier($tableName));
            $rows = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];

            if ($rows === []) {
                return [
                    'success' => false,
                    'message' => $operation === 'REPAIR' ? 'Repair fehlgeschlagen' : 'Optimierung fehlgeschlagen'
                ];
            }

            $messages = [];
            $hasError = false;
            foreach ($rows as $row) {
                $msgType = strtolower((string)($row['Msg_type'] ?? ''));
                $msgText = trim((string)($row['Msg_text'] ?? ''));
                if ($msgType === 'error') {
                    $hasError = true;
                }
                if ($msgText !== '') {
                    $messages[] = $msgText;
                }
            }

            return [
                'success' => !$hasError,
                'message' => $messages !== [] ? implode(' | ', $messages) : 'OK',
                'details' => $rows,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function quoteIdentifier(string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    
    /**
     * Get recent error logs
     */
    public function getErrorLogs(int $limit = 100): array {
        $log_file = $this->getConfiguredErrorLogFile();
        $logs = [];
        
        if (!file_exists($log_file)) {
            return $logs;
        }
        
        try {
            $lines = $this->readLastLogLines($log_file, $limit);
            
            foreach ($lines as $line) {
                if (preg_match('/^\[(.*?)\]\s+PHP\s+([^:]+):\s+(.*)$/i', $line, $matches)) {
                    $logs[] = [
                        'timestamp' => $matches[1],
                        'type' => strtoupper(trim($matches[2])),
                        'message' => $matches[3]
                    ];
                    continue;
                }

                // Parse log line (format: [YYYY-MM-DD HH:MM:SS] TYPE: Message)
                if (preg_match('/^\[(.*?)\]\s+(\w+):\s+(.*)$/', $line, $matches)) {
                    $logs[] = [
                        'timestamp' => $matches[1],
                        'type' => $matches[2],
                        'message' => $matches[3]
                    ];
                } else {
                    $logs[] = [
                        'timestamp' => '',
                        'type' => 'UNKNOWN',
                        'message' => $line
                    ];
                }
            }
            
        } catch (\Exception $e) {
            // Ignore errors
        }
        
        return array_reverse($logs);
    }
    
    /**
     * Clear error logs
     */
    public function clearErrorLogs(): bool {
        $log_file = $this->getConfiguredErrorLogFile();

        return $this->clearLogPath($log_file, false);
    }

    /**
     * Get available CMS log files from the configured LOG_PATH.
     *
     * @return array<int, array{filename:string,path:string,size:int,formatted_size:string,modified_at:string,channel:string}>
     */
    public function getCmsLogFiles(): array
    {
        $logDir = $this->getConfiguredLogDirectory();
        $files  = [];

        if (!is_dir($logDir)) {
            return $files;
        }

        $globResult = glob($logDir . '*.log');
        if ($globResult === false) {
            return $files;
        }

        foreach ($globResult as $path) {
            if (!is_file($path)) {
                continue;
            }

            $filename = basename($path);
            $size = filesize($path);
            if ($size === false || (int) $size <= 0) {
                continue;
            }

            $modifiedAt = filemtime($path);
            $channel = preg_replace('/-\d{4}-\d{2}-\d{2}\.log$/', '', $filename) ?? $filename;

            $files[] = [
                'filename' => $filename,
                'path' => $path,
                'size' => $size === false ? 0 : (int) $size,
                'formatted_size' => $this->formatBytes($size === false ? 0 : (int) $size),
                'modified_at' => $modifiedAt !== false ? date('Y-m-d H:i:s', $modifiedAt) : '',
                'channel' => $channel,
            ];
        }

        usort($files, static function (array $left, array $right): int {
            return strcmp((string) ($right['modified_at'] ?? ''), (string) ($left['modified_at'] ?? ''));
        });

        return $files;
    }

    /**
     * Read CMS log entries from a specific log file.
     *
     * @param string $filename Log filename (only basename, no traversal)
     * @param int    $limit    Max number of entries
     * @return array<int, array{timestamp: string, level: string, channel: string, message: string}>
     */
    public function getCmsLogEntries(string $filename, int $limit = 200): array
    {
        if (!$this->isSafeLogFilename($filename)) {
            return [];
        }

        $logDir  = $this->getConfiguredLogDirectory();
        $logFile = $logDir . $filename;

        if (!file_exists($logFile)) {
            return [];
        }

        $entries = [];
        $lines = $this->readLastLogLines($logFile, $limit);

        foreach ($lines as $line) {
            // Logger format: [2026-02-22 14:30:00] [WARNING] [cms] Message {context}
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\[([A-Z]+)\]\s+\[([A-Za-z0-9_.-]+)\]\s+(.*)$/', $line, $m)) {
                $entries[] = [
                    'timestamp' => $m[1],
                    'level'     => $m[2],
                    'channel'   => $m[3],
                    'message'   => $m[4],
                ];
            } else {
                $entries[] = [
                    'timestamp' => '',
                    'level'     => 'RAW',
                    'channel'   => '',
                    'message'   => $line,
                ];
            }
        }

        return array_reverse($entries);
    }

    /**
     * @return array<int, array{timestamp: string, level: string, channel: string, message: string, file: string}>
     */
    public function getRecentLogEntriesByChannel(string $channel, int $limit = 50): array
    {
        $channel = trim($channel);
        if ($channel === '') {
            return [];
        }

        $entries = [];
        foreach ($this->getCmsLogFiles() as $fileInfo) {
            $filename = (string) ($fileInfo['filename'] ?? '');
            foreach ($this->getCmsLogEntries($filename, $limit) as $entry) {
                if (($entry['channel'] ?? '') !== $channel) {
                    continue;
                }

                $entry['file'] = $filename;
                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    /**
     * Delete a CMS log file.
     */
    public function clearCmsLogFile(string $filename): bool
    {
        if (!$this->isSafeLogFilename($filename)) {
            return false;
        }

        $logDir  = $this->getConfiguredLogDirectory();
        $logFile = $logDir . $filename;

        if (file_exists($logFile)) {
            return $this->clearLogPath($logFile, true);
        }

        return true;
    }

    public function clearAllCmsLogFiles(): int
    {
        return (int) $this->clearAllCmsLogFilesDetailed()['cleared'];
    }

    /**
     * @return array{cleared:int, failed:list<string>}
     */
    public function clearAllCmsLogFilesDetailed(): array
    {
        $deleted = 0;
        $failed = [];

        foreach ($this->getCmsLogFiles() as $fileInfo) {
            $filename = (string) ($fileInfo['filename'] ?? '');
            if ($filename === '') {
                continue;
            }

            if ($this->clearCmsLogFile($filename)) {
                $deleted++;
                continue;
            }

            $failed[] = $filename;
        }

        return [
            'cleared' => $deleted,
            'failed' => $failed,
        ];
    }

    private function clearLogPath(string $path, bool $removeFile): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        $directory = dirname($path);
        if (!$this->ensureWritableLogDirectory($directory)) {
            return false;
        }

        if (!file_exists($path)) {
            return true;
        }

        if (!is_file($path)) {
            return false;
        }

        if ($removeFile && @unlink($path)) {
            clearstatcache(true, $path);
            return true;
        }

        return $this->truncateLogPath($path);
    }

    private function truncateLogPath(string $path): bool
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            return false;
        }

        try {
            $locked = @flock($handle, LOCK_EX);
            $truncated = @ftruncate($handle, 0);
            @fflush($handle);
            if ($locked) {
                @flock($handle, LOCK_UN);
            }

            clearstatcache(true, $path);
            return $truncated === true;
        } finally {
            @fclose($handle);
        }
    }

    private function ensureWritableLogDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0750, true) || is_dir($directory);
    }

    private function isSafeLogFilename(string $filename): bool {
        return preg_match('/^[A-Za-z0-9._-]+\.log$/', $filename) === 1
            && basename($filename) === $filename
            && !str_contains($filename, '..');
    }

    /** @return array<int,string> */
    private function readLastLogLines(string $path, int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $maxBytes = 2 * 1024 * 1024;
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $offset = max(0, $size - $maxBytes);
            fseek($handle, $offset);
            $content = stream_get_contents($handle);
            if ($content === false) {
                return [];
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));
        $lines = array_slice($lines, -$limit);

        return array_map(static function (string $line): string {
            return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $line) ?? '');
        }, $lines);
    }
    
    /**
     * Get security status
     */
    public function getSecurityStatus(): array {
        $headerProfile = Security::instance()->getSecurityHeaderProfile();

        return [
            'debug_mode' => defined('CMS_DEBUG') && CMS_DEBUG ? 'Aktiv ⚠️' : 'Deaktiviert ✓',
            'display_errors' => ini_get('display_errors') ? 'Aktiv ⚠️' : 'Deaktiviert ✓',
            'session_secure' => ini_get('session.cookie_secure') ? 'Aktiv ✓' : 'Deaktiviert ⚠️',
            'session_httponly' => ini_get('session.cookie_httponly') ? 'Aktiv ✓' : 'Deaktiviert ⚠️',
            'session_samesite' => ini_get('session.cookie_samesite') ?: 'Nicht gesetzt ⚠️',
            'max_upload' => ini_get('upload_max_filesize'),
            'memory_limit' => ini_get('memory_limit'),
            'https_enabled' => $headerProfile['https'] ? 'Ja ✓' : 'Nein ⚠️',
            'https_redirect' => (string)($headerProfile['https_redirect_managed_by'] ?? 'Reverse-Proxy / Webserver'),
            'csp_mode' => $headerProfile['csp_mode'] === 'enforced' ? 'Erzwungen ✓' : 'Report-Only ⚠️',
            'csp_nonce' => $headerProfile['csp_uses_nonce'] ? 'Aktiv ✓' : 'Nicht aktiv ⚠️',
            'trusted_types' => $headerProfile['trusted_types_enforced']
                ? 'Erzwungen ✓'
                : ($headerProfile['trusted_types_report_only'] ? 'Report-Only ⚠️' : 'Nicht aktiv ⚠️'),
            'hsts' => $headerProfile['hsts_enabled'] ? 'Aktiv ✓' : ($headerProfile['https'] ? 'Nicht aktiv ⚠️' : 'Nur mit HTTPS ℹ️'),
            'hsts_include_subdomains' => $headerProfile['hsts_include_subdomains'] ? 'Aktiv ✓' : ($headerProfile['https'] ? 'Nicht aktiv ⚠️' : 'Nur mit HTTPS ℹ️'),
            'hsts_preload' => $headerProfile['hsts_preload'] ? 'Aktiv ✓' : ($headerProfile['https'] ? 'Nicht aktiv ⚠️' : 'Nur mit HTTPS ℹ️'),
        ];
    }

    /**
     * Debug-only Runtime-/Query-Telemetrie für Diagnoseansichten.
     */
    public function getRuntimeTelemetry(): array
    {
        if (!defined('CMS_DEBUG') || !CMS_DEBUG) {
            return [
                'enabled' => false,
                'message' => 'Runtime-Telemetrie ist nur im Debug-Modus aktiv.',
            ];
        }

        return Debug::getRuntimeTelemetry();
    }
    
    /**
     * Run all system checks
     */
    public function runSystemCheck(): array {
        return [
            'system_info' => $this->getSystemInfo(),
            'database_status' => $this->getDatabaseStatus(),
            'table_status' => $this->checkDatabaseTables(),
            'file_permissions' => $this->checkFilePermissions(),
            'directory_sizes' => $this->getDirectorySizes(),
            'cms_statistics' => $this->getCMSStatistics(),
            'security_status' => $this->getSecurityStatus(),
            'runtime_telemetry' => $this->getRuntimeTelemetry()
        ];
    }
}
