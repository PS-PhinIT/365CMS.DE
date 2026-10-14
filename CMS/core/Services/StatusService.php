<?php
declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\WP_Error;

/**
 * Status Service - System Health & Maintenance
 * 
 * @package CMS\Services
 */
class StatusService {
    
    private Database $db;
    private string $prefix;
    private static ?StatusService $instance = null;
    
    private function __construct() {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }
    
    public static function getInstance(): StatusService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Vollständiger System-Status
     */
    public function getFullStatus(): array {
        return [
            'database' => $this->checkDatabase(),
            'filesystem' => $this->checkFilesystem(),
            'php' => $this->checkPHP(),
            'security' => $this->checkSecurity(),
            'performance' => $this->checkPerformance()
        ];
    }
    
    /**
     * Datenbank-Prüfung
     */
    public function checkDatabase(): array {
        $checks = [];
        
        // Verbindung
        try {
            $this->db->get_var("SELECT 1");
            $checks['connection'] = ['status' => 'ok', 'message' => 'Datenbankverbindung aktiv'];
        } catch (\Exception $e) {
            $checks['connection'] = ['status' => 'error', 'message' => $e->getMessage()];
        }
        
        // Tabellen
        $prefix = $this->prefix;
        $required_tables = [
            $prefix . 'users', $prefix . 'user_meta', $prefix . 'sessions', $prefix . 'login_attempts',
            $prefix . 'settings', $prefix . 'pages', $prefix . 'activity_log'
        ];
        
        $existing_tables = $this->db->get_results("SHOW TABLES");
        $table_names = array_map(function($row) {
            return array_values((array)$row)[0];
        }, $existing_tables);
        
        $missing_tables = array_diff($required_tables, $table_names);
        
        if (empty($missing_tables)) {
            $checks['tables'] = ['status' => 'ok', 'message' => 'Alle Tabellen vorhanden (' . count($required_tables) . ')'];
        } else {
            $checks['tables'] = [
                'status' => 'error',
                'message' => 'Fehlende Tabellen: ' . implode(', ', $missing_tables)
            ];
        }
        
        // Zeichenkodierung
        $charset = $this->db->get_var("SELECT @@character_set_database");
        $collation = $this->db->get_var("SELECT @@collation_database");
        
        if ($charset === 'utf8mb4') {
            $checks['charset'] = ['status' => 'ok', 'message' => "utf8mb4 ({$collation})"];
        } else {
            $checks['charset'] = ['status' => 'warning', 'message' => "Empfohlen: utf8mb4, aktuell: {$charset}"];
        }
        
        // Overhead
        $overhead_query = "SELECT SUM(data_free) as overhead 
                          FROM information_schema.TABLES 
                          WHERE table_schema = DATABASE()";
        $overhead = (int)$this->db->get_var($overhead_query);
        
        if ($overhead > 10 * 1024 * 1024) { // > 10 MB
            $checks['overhead'] = [
                'status' => 'warning',
                'message' => $this->formatBytes($overhead) . ' Overhead (sollte optimiert werden)',
                'action' => 'cleanup_overhead'
            ];
        } else {
            $checks['overhead'] = ['status' => 'ok', 'message' => $this->formatBytes($overhead) . ' Overhead'];
        }
        
        // Größe
        $size_query = "SELECT SUM(data_length + index_length) as size 
                      FROM information_schema.TABLES 
                      WHERE table_schema = DATABASE()";
        $size = (int)$this->db->get_var($size_query);
        
        $checks['size'] = [
            'status' => 'info',
            'message' => $this->formatBytes($size),
            'raw' => $size
        ];
        
        return $checks;
    }
    
    /**
     * Dateisystem-Prüfung
     */
    public function checkFilesystem(): array {
        $checks = [];
        $base_path = ABSPATH;
        
        // Wichtige Verzeichnisse
        $directories = [
            'uploads' => $base_path . '/uploads',
            'cache' => $base_path . '/cache',
            'logs' => $base_path . '/logs',
            'themes' => $base_path . '/themes',
            'plugins' => $base_path . '/plugins'
        ];
        
        foreach ($directories as $name => $path) {
            if (!is_dir($path)) {
                $checks[$name] = [
                    'status' => 'error',
                    'message' => "Verzeichnis existiert nicht: {$path}",
                    'action' => 'create_directory'
                ];
            } elseif (!is_writable($path)) {
                $perms = substr(sprintf('%o', fileperms($path)), -4);
                $checks[$name] = [
                    'status' => 'error',
                    'message' => "Nicht beschreibbar ({$perms})",
                    'action' => 'fix_permissions',
                    'path' => $path
                ];
            } else {
                $perms = substr(sprintf('%o', fileperms($path)), -4);
                $checks[$name] = ['status' => 'ok', 'message' => "Beschreibbar ({$perms})"];
            }
        }
        
        // config.php Permissions
        $config_file = $base_path . '/config.php';
        if (file_exists($config_file)) {
            $perms = substr(sprintf('%o', fileperms($config_file)), -4);
            if ($perms !== '0600' && $perms !== '600') {
                $checks['config'] = [
                    'status' => 'critical',
                    'message' => "config.php ist world-readable ({$perms})! Sicherheitsrisiko!",
                    'action' => 'fix_config_permissions',
                    'path' => $config_file
                ];
            } else {
                $checks['config'] = ['status' => 'ok', 'message' => "Korrekte Permissions ({$perms})"];
            }
        }
        
        // Disk Space
        $free = @disk_free_space($base_path);
        $total = @disk_total_space($base_path);
        if ($free === false || $total === false || $total <= 0) {
            $checks['disk_space'] = ['status' => 'warning', 'message' => 'Speicherplatz konnte nicht ermittelt werden'];
            return $checks;
        }
        $percent_used = (($total - $free) / $total) * 100;
        
        if ($percent_used > 90) {
            $checks['disk_space'] = [
                'status' => 'critical',
                'message' => round($percent_used, 2) . '% belegt - Kritisch wenig Speicherplatz!'
            ];
        } elseif ($percent_used > 80) {
            $checks['disk_space'] = [
                'status' => 'warning',
                'message' => round($percent_used, 2) . '% belegt'
            ];
        } else {
            $checks['disk_space'] = [
                'status' => 'ok',
                'message' => $this->formatBytes((int) $free) . ' frei von ' . $this->formatBytes((int) $total)
            ];
        }
        
        return $checks;
    }
    
    /**
     * PHP-Umgebung prüfen
     */
    public function checkPHP(): array {
        $checks = [];
        $requiredPhpVersion = \CMS\Version::minimumPhp();
        
        // PHP-Version
        if (version_compare(PHP_VERSION, $requiredPhpVersion, '>=')) {
            $checks['version'] = ['status' => 'ok', 'message' => 'PHP ' . PHP_VERSION];
        } else {
            $checks['version'] = ['status' => 'critical', 'message' => 'PHP ' . PHP_VERSION . ' (mindestens ' . $requiredPhpVersion . ' erforderlich)'];
        }
        
        // Extensions
        $required = [
            'pdo_mysql' => 'PDO MySQL (Pflicht)',
            'mbstring' => 'Multibyte String (Pflicht)',
            'openssl' => 'OpenSSL (Pflicht)',
            'curl' => 'cURL (Pflicht)',
            'gd' => 'GD Library (Empfohlen)',
            'zip' => 'ZIP (Empfohlen)',
            'json' => 'JSON (Pflicht)',
            'xml' => 'XML (Empfohlen)'
        ];
        
        $missing = [];
        $loaded = [];
        
        foreach ($required as $ext => $label) {
            if (extension_loaded($ext)) {
                $loaded[] = $ext;
            } else {
                $missing[] = $label;
            }
        }
        
        if (empty($missing)) {
            $checks['extensions'] = ['status' => 'ok', 'message' => 'Alle Extensions geladen (' . count($loaded) . ')'];
        } else {
            $checks['extensions'] = [
                'status' => 'warning',
                'message' => 'Fehlend: ' . implode(', ', $missing)
            ];
        }
        
        // Memory Limit
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->returnBytes($memory_limit);
        
        if ($memory_bytes >= 256 * 1024 * 1024) {
            $checks['memory_limit'] = ['status' => 'ok', 'message' => $memory_limit];
        } else {
            $checks['memory_limit'] = [
                'status' => 'warning',
                'message' => $memory_limit . ' (empfohlen: mindestens 256M)'
            ];
        }
        
        // Max Execution Time
        $max_exec = ini_get('max_execution_time');
        $checks['max_execution_time'] = [
            'status' => 'info',
            'message' => $max_exec . ' Sekunden'
        ];
        
        // Upload Limits
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        
        $checks['upload_limits'] = [
            'status' => 'info',
            'message' => "Upload: {$upload_max}, POST: {$post_max}"
        ];
        
        return $checks;
    }
    
    /**
     * Sicherheits-Checks
     */
    public function checkSecurity(): array {
        $checks = [];
        
        // HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $checks['https'] = ['status' => 'ok', 'message' => 'HTTPS aktiv'];
        } else {
            $checks['https'] = [
                'status' => 'critical',
                'message' => 'HTTPS nicht aktiv - Unsichere Verbindung!'
            ];
        }
        
        // Debug-Modus
        if (defined('CMS_DEBUG') && CMS_DEBUG) {
            $checks['debug_mode'] = [
                'status' => 'warning',
                'message' => 'Debug-Modus aktiv (nicht für Produktion!)'
            ];
        } else {
            $checks['debug_mode'] = ['status' => 'ok', 'message' => 'Debug-Modus deaktiviert'];
        }
        
        // Display Errors
        if (ini_get('display_errors')) {
            $checks['display_errors'] = [
                'status' => 'warning',
                'message' => 'display_errors aktiv (Sicherheitsrisiko)'
            ];
        } else {
            $checks['display_errors'] = ['status' => 'ok', 'message' => 'Errors werden nicht angezeigt'];
        }
        
        // Standard-Admin-User
        $admin_user = $this->db->get_row("SELECT * FROM {$this->prefix}users WHERE username = 'admin' AND role = 'admin'");
        if ($admin_user) {
            $checks['default_admin'] = [
                'status' => 'warning',
                'message' => 'Standard-Admin-Account existiert (Sicherheitsrisiko)',
                'action' => 'rename_admin'
            ];
        } else {
            $checks['default_admin'] = ['status' => 'ok', 'message' => 'Kein Standard-Admin vorhanden'];
        }
        
        return $checks;
    }
    
    /**
     * Performance-Checks
     */
    public function checkPerformance(): array {
        $checks = [];
        
        // OPcache
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status();
            if ($status && $status['opcache_enabled']) {
                $hit_rate = $status['opcache_statistics']['opcache_hit_rate'] ?? 0;
                if ($hit_rate > 90) {
                    $checks['opcache'] = ['status' => 'ok', 'message' => 'OPcache aktiv (' . round($hit_rate, 2) . '% Hit-Rate)'];
                } else {
                    $checks['opcache'] = ['status' => 'warning', 'message' => 'OPcache Hit-Rate niedrig (' . round($hit_rate, 2) . '%)'];
                }
            } else {
                $checks['opcache'] = ['status' => 'warning', 'message' => 'OPcache installiert aber nicht aktiv'];
            }
        } else {
            $checks['opcache'] = [
                'status' => 'warning',
                'message' => 'OPcache nicht installiert (empfohlen für Performance)'
            ];
        }
        
        // Object Caching
        if (extension_loaded('redis')) {
            $checks['object_cache'] = ['status' => 'ok', 'message' => 'Redis verfügbar'];
        } elseif (extension_loaded('memcached')) {
            $checks['object_cache'] = ['status' => 'ok', 'message' => 'Memcached verfügbar'];
        } else {
            $checks['object_cache'] = [
                'status' => 'info',
                'message' => 'Kein Object-Cache (Redis/Memcached empfohlen)'
            ];
        }
        
        return $checks;
    }
    
    /**
     * Datenbank reparieren
     */
    public function repairDatabase(): array|WP_Error {
        $repaired = [];
        $errors = [];
        
        try {
            $tables = $this->db->get_results("SHOW TABLES");
            
            foreach ($tables as $table) {
                $table_name = array_values((array)$table)[0];
                
                // CHECK TABLE
                $check_result = $this->db->get_row("CHECK TABLE `{$table_name}`");
                
                if ($check_result && stripos($check_result->Msg_text, 'OK') === false) {
                    // REPAIR TABLE
                    $repair_result = $this->db->get_row("REPAIR TABLE `{$table_name}`");
                    
                    if ($repair_result && stripos($repair_result->Msg_text, 'OK') !== false) {
                        $repaired[] = $table_name;
                    } else {
                        $errors[] = "Fehler bei {$table_name}: " . ($repair_result->Msg_text ?? 'Unbekannter Fehler');
                    }
                }
            }
            
            return [
                'success' => true,
                'repaired' => $repaired,
                'errors' => $errors,
                'message' => count($repaired) > 0 
                    ? count($repaired) . ' Tabelle(n) repariert' 
                    : 'Keine Reparaturen notwendig'
            ];
            
        } catch (\Exception $e) {
            return new WP_Error('repair_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Datenbank-Overhead bereinigen
     */
    public function cleanupOverhead(): array|WP_Error {
        try {
            $optimized = [];
            $freed_space = 0;
            
            $overhead_query = "SELECT table_name, data_free 
                              FROM information_schema.TABLES 
                              WHERE table_schema = DATABASE() 
                              AND data_free > 0";
            
            $tables = $this->db->get_results($overhead_query);
            
            foreach ($tables as $table) {
                $this->db->query("OPTIMIZE TABLE `{$table->table_name}`");
                $optimized[] = $table->table_name;
                $freed_space += (int)$table->data_free;
            }
            
            return [
                'success' => true,
                'optimized' => $optimized,
                'count' => count($optimized),
                'freed_space' => $this->formatBytes($freed_space),
                'message' => count($optimized) . ' Tabelle(n) optimiert, ' . $this->formatBytes($freed_space) . ' freigegeben'
            ];
            
        } catch (\Exception $e) {
            return new WP_Error('cleanup_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Verwaiste Einträge bereinigen
     */
    public function cleanupOrphans(): array {
        $deleted = 0;
        
        // Verwaiste User-Meta
        $result = $this->db->query(
            "DELETE FROM {$this->prefix}user_meta 
             WHERE user_id NOT IN (SELECT id FROM {$this->prefix}users)"
        );
        $deleted += $this->db->affected_rows();
        
        // Verwaiste Sessions
        $result = $this->db->query(
            "DELETE FROM {$this->prefix}sessions 
             WHERE user_id NOT IN (SELECT id FROM {$this->prefix}users)"
        );
        $deleted += $this->db->affected_rows();
        
        // Alte Login-Attempts (> 90 Tage)
        $result = $this->db->query(
            "DELETE FROM {$this->prefix}login_attempts 
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $deleted += $this->db->affected_rows();
        
        return [
            'success' => true,
            'deleted' => $deleted,
            'message' => $deleted . ' verwaiste Einträge gelöscht'
        ];
    }
    
    /**
     * Temp-Dateien bereinigen
     */
    public function cleanupTempFiles(): array {
        $deleted_files = 0;
        $freed_space = 0;
        
        $cache_dir = ABSPATH . '/cache';
        $tmp_dir = sys_get_temp_dir();
        
        // Cache bereinigen
        if (is_dir($cache_dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $freed_space += $file->getSize();
                    unlink($file->getRealPath());
                    $deleted_files++;
                }
            }
        }
        
        return [
            'success' => true,
            'deleted_files' => $deleted_files,
            'freed_space' => $this->formatBytes($freed_space),
            'message' => $deleted_files . ' Dateien gelöscht, ' . $this->formatBytes($freed_space) . ' freigegeben'
        ];
    }
    
    /**
     * Permissions korrigieren
     */
    public function fixPermissions(): array|WP_Error {
        $fixed = [];
        $base_path = ABSPATH;
        
        $directories = [
            'uploads' => ['path' => $base_path . '/uploads', 'perms' => 0755],
            'cache' => ['path' => $base_path . '/cache', 'perms' => 0755],
            'logs' => ['path' => $base_path . '/logs', 'perms' => 0755]
        ];
        
        foreach ($directories as $name => $config) {
            if (is_dir($config['path'])) {
                if (@chmod($config['path'], $config['perms'])) {
                    $fixed[] = $name;
                }
            }
        }
        
        // config.php
        $config_file = $base_path . '/config.php';
        if (file_exists($config_file)) {
            if (@chmod($config_file, 0600)) {
                $fixed[] = 'config.php';
            }
        }
        
        return [
            'success' => true,
            'fixed' => $fixed,
            'message' => count($fixed) . ' Permissions korrigiert'
        ];
    }
    
    /**
     * Helper: Bytes formatieren
     */
    private function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Helper: String zu Bytes
     */
    private function returnBytes(string $size_str): int {
        $size_str = trim($size_str);
        $last = strtolower($size_str[strlen($size_str)-1]);
        $size = (int)$size_str;
        
        switch($last) {
            case 'g': $size *= 1024;
            case 'm': $size *= 1024;
            case 'k': $size *= 1024;
        }
        
        return $size;
    }
}
