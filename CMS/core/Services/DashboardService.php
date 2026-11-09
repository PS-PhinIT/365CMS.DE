<?php
declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Json;
use CMS\Logger;
use CMS\Version;

/**
 * Dashboard Service - Business Logic für Dashboard-Statistiken
 * 
 * @package CMS\Services
 */
class DashboardService {
    
    private Database $db;
    private string $prefix;
    private static ?DashboardService $instance = null;
    private ?array $cachedAllStats = null;
    /** @var array<string, true> */
    private array $statsFailures = [];
    
    private function __construct() {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }
    
    public static function getInstance(): DashboardService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Alle Dashboard-Statistiken abrufen
     * 
     * @return array Alle Stats
     */
    public function getAllStats(): array {
        if ($this->cachedAllStats !== null) {
            return $this->cachedAllStats;
        }

        $this->statsFailures = [];
        $this->cachedAllStats = [
            'users' => $this->loadStatsSegment('users', fn (): array => $this->getUserStats()),
            'pages' => $this->loadStatsSegment('pages', fn (): array => $this->getPageStats()),
            'posts' => $this->loadStatsSegment('posts', fn (): array => $this->getPostStats()),
            'media' => $this->loadStatsSegment('media', fn (): array => $this->getMediaStats()),
            'sessions' => $this->loadStatsSegment('sessions', fn (): array => $this->getSessionStats()),
            'security' => $this->loadStatsSegment('security', fn (): array => $this->getSecurityStats()),
            'performance' => $this->loadStatsSegment('performance', fn (): array => $this->getPerformanceStats()),
            'system' => $this->loadStatsSegment('system', fn (): array => $this->getSystemInfo()),
            'orders' => $this->loadStatsSegment('orders', fn (): array => $this->getOrderStats()),
            'meta' => [
                'degraded_sections' => array_keys($this->statsFailures),
            ],
        ];

        return $this->cachedAllStats;
    }

    /**
     * Lädt einen Statistikblock fail-soft mit segmentweisem Fallback.
     *
     * @param callable():array $loader
     */
    private function loadStatsSegment(string $segment, callable $loader): array
    {
        try {
            $stats = $loader();

            return is_array($stats)
                ? $stats
                : $this->getDefaultStatsForSegment($segment);
        } catch (\Throwable $e) {
            $this->statsFailures[$segment] = true;

            Logger::instance()->withChannel('dashboard')->warning('Dashboard statistics segment fell back to defaults.', [
                'segment' => $segment,
                'exception' => $e,
            ]);

            return $this->getDefaultStatsForSegment($segment);
        }
    }

    private function getDefaultStatsForSegment(string $segment): array
    {
        return match ($segment) {
            'users' => [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'active_today' => 0,
                'new_today' => 0,
                'new_this_week' => 0,
                'new_this_month' => 0,
                'roles' => [],
                'growth_rate' => 0,
            ],
            'pages' => [
                'total' => 0,
                'published' => 0,
                'drafts' => 0,
                'private' => 0,
                'scheduled' => 0,
            ],
            'posts' => [
                'total' => 0,
                'published' => 0,
                'drafts' => 0,
                'private' => 0,
                'scheduled' => 0,
            ],
            'media' => [
                'total' => 0,
                'total_files' => 0,
                'total_size' => 0,
                'total_size_mb' => 0,
                'total_size_formatted' => $this->formatBytes(0),
                'types' => [
                    'images' => 0,
                    'videos' => 0,
                    'documents' => 0,
                    'archives' => 0,
                    'other' => 0,
                ],
            ],
            'sessions' => [
                'active' => 0,
                'active_now' => 0,
                'today' => 0,
                'total' => 0,
                'avg_duration' => 0,
                'browsers' => [],
            ],
            'security' => [
                'failed_logins_24h' => 0,
                'successful_logins_24h' => 0,
                'blocked_ips' => 0,
                'https_enabled' => false,
                'security_score' => 0,
                'status' => 'warning',
            ],
            'performance' => [
                'memory_limit' => (string) (ini_get('memory_limit') ?: '0'),
                'memory_usage' => 0,
                'memory_usage_formatted' => $this->formatBytes(0),
                'memory_peak' => 0,
                'memory_peak_formatted' => $this->formatBytes(0),
                'memory_percent' => 0,
                'disk_free' => 0,
                'disk_free_formatted' => $this->formatBytes(0),
                'disk_total' => 0,
                'disk_total_formatted' => $this->formatBytes(0),
                'disk_percent' => 0,
                'opcache' => [],
                'performance_score' => 0,
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'mysql_version' => 'Unbekannt',
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'os' => PHP_OS,
                'cms_version' => defined('CMS_VERSION') ? CMS_VERSION : Version::CURRENT,
                'server_time' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
                'max_execution_time' => (string) (ini_get('max_execution_time') ?: '0'),
                'upload_max_filesize' => (string) (ini_get('upload_max_filesize') ?: '0'),
                'post_max_size' => (string) (ini_get('post_max_size') ?: '0'),
            ],
            'orders' => [
                'total' => 0,
                'pending' => 0,
                'month_revenue' => 0.0,
                'month_revenue_formatted' => '0,00 EUR',
            ],
            default => [],
        };
    }

    /**
     * Bestell-Statistiken.
     */
    public function getOrderStats(): array {
        if (!CoreModuleService::getInstance()->isModuleEnabled('subscription_admin_orders')) {
            return [
                'total' => 0,
                'pending' => 0,
                'month_revenue' => 0.0,
                'month_revenue_formatted' => '0,00 EUR',
            ];
        }

        try {
            $orderTable = $this->prefix . 'orders';
            $amountExpression = $this->buildOrderAmountExpression();
            $total = (int)$this->db->get_var("SELECT COUNT(*) FROM {$orderTable}") ?: 0;
            $pending = (int)$this->db->get_var("SELECT COUNT(*) FROM {$orderTable} WHERE LOWER(TRIM(status)) = 'pending'") ?: 0;
            $monthRevenue = (float)$this->db->get_var(
                "SELECT COALESCE(SUM({$amountExpression}), 0) FROM {$orderTable} WHERE LOWER(TRIM(status)) IN ('paid', 'confirmed', 'completed') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );

            return [
                'total' => $total,
                'pending' => $pending,
                'month_revenue' => $monthRevenue,
                'month_revenue_formatted' => number_format($monthRevenue, 2, ',', '.') . ' EUR',
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('dashboard')->warning('Order statistics could not be loaded.', [
                'exception' => $e,
            ]);
            return [
                'total' => 0,
                'pending' => 0,
                'month_revenue' => 0.0,
                'month_revenue_formatted' => '0,00 EUR',
            ];
        }
    }
    
    /**
     * Benutzer-Statistiken
     */
    public function getUserStats(): array {
        $total = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users");
        $active = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users WHERE status = 'active'");
        $inactive = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users WHERE status = 'inactive'");
        
        // Benutzer, die heute eingeloggt waren
        $active_today = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}users WHERE DATE(last_login) = CURDATE()"
        );
        
        $new_today = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}users WHERE DATE(created_at) = CURDATE()"
        );
        $new_this_week = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $new_this_month = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        // Rollen-Verteilung
        $roles = $this->db->get_results(
            "SELECT role, COUNT(*) as count FROM {$this->prefix}users GROUP BY role"
        );
        
        $role_distribution = [];
        foreach ($roles as $role) {
            $role_distribution[$role->role] = (int)$role->count;
        }
        
        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'active_today' => $active_today,
            'new_today' => $new_today,
            'new_this_week' => $new_this_week,
            'new_this_month' => $new_this_month,
            'roles' => $role_distribution,
            'growth_rate' => $total > 0 ? round(($new_this_month / $total) * 100, 2) : 0
        ];
    }
    
    /**
     * Seiten-Statistiken
     */
    public function getPageStats(): array {
        // Dynamic table via prefix
        $total = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}pages") ?: 0;
        $published = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}pages WHERE status = 'published'") ?: 0;
        $drafts = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}pages WHERE status = 'draft'") ?: 0;
        $private = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}pages WHERE status = 'private'") ?: 0;

        // Seiten haben derzeit keinen Scheduling-Workflow.
        $scheduled = 0;
        
        return [
            'total' => $total,
            'published' => $published,
            'drafts' => $drafts,
            'private' => $private,
            'scheduled' => $scheduled
        ];
    }

    /**
     * Beitrags-Statistiken.
     */
    public function getPostStats(): array {
        $currentDateTime = date('Y-m-d H:i:s');
        $total = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}posts") ?: 0;
        $published = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts WHERE status = 'published' AND (published_at IS NULL OR published_at <= ?)",
            [$currentDateTime]
        ) ?: 0;
        $drafts = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}posts WHERE status = 'draft'") ?: 0;
        $private = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}posts WHERE status = 'private'") ?: 0;
        $scheduled = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}posts WHERE status = 'published' AND published_at IS NOT NULL AND published_at > ?",
            [$currentDateTime]
        ) ?: 0;

        return [
            'total' => $total,
            'published' => $published,
            'drafts' => $drafts,
            'private' => $private,
            'scheduled' => $scheduled,
        ];
    }
    
    /**
     * Medien-Statistiken
     */
    public function getMediaStats(): array {
        // Disk usage
        $upload_dir = ABSPATH . '/uploads';
        $total_size = 0;
        $file_count = 0;
        
        if (is_dir($upload_dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($upload_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $total_size += $file->getSize();
                    $file_count++;
                }
            }
        }
        
        // Dateitypen-Verteilung
        $types = [
            'images' => 0,
            'videos' => 0,
            'documents' => 0,
            'archives' => 0,
            'other' => 0
        ];
        
        if (is_dir($upload_dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($upload_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($files as $file) {
                if (!$file->isFile()) continue;
                
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                    $types['images']++;
                } elseif (in_array($ext, ['mp4', 'avi', 'mov', 'wmv', 'webm'])) {
                    $types['videos']++;
                } elseif (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
                    $types['documents']++;
                } elseif (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) {
                    $types['archives']++;
                } else {
                    $types['other']++;
                }
            }
        }
        
        return [
            'total' => $file_count,
            'total_files' => $file_count,
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1048576, 2),
            'total_size_formatted' => $this->formatBytes($total_size),
            'types' => $types
        ];
    }
    
    /**
     * Session-Statistiken
     */
    public function getSessionStats(): array {
        try {
            $sessionsTable = $this->prefix . 'sessions';
            $active_sessions = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$sessionsTable} WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            ) ?: 0;
            
            $total_sessions_today = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$sessionsTable} WHERE DATE(last_activity) = CURDATE()"
            ) ?: 0;

            $avg_session_duration = 0;
            if ($this->hasColumn($sessionsTable, 'created_at')) {
                $avg_session_duration = $this->db->get_var(
                    "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, last_activity)) 
                     FROM {$sessionsTable} 
                     WHERE DATE(created_at) = CURDATE()
                     AND last_activity > created_at"
                ) ?: 0;
            }
            
            // Browser-Statistik
            $browsers = $this->db->get_results(
                "SELECT 
                    CASE 
                        WHEN user_agent LIKE '%Chrome%' THEN 'Chrome'
                        WHEN user_agent LIKE '%Firefox%' THEN 'Firefox'
                        WHEN user_agent LIKE '%Safari%' AND user_agent NOT LIKE '%Chrome%' THEN 'Safari'
                        WHEN user_agent LIKE '%Edge%' THEN 'Edge'
                        ELSE 'Other'
                    END as browser,
                    COUNT(*) as count
                 FROM {$sessionsTable} 
                 WHERE DATE(last_activity) = CURDATE()
                 GROUP BY browser"
            ) ?: [];
            
            $browser_stats = [];
            foreach ($browsers as $browser) {
                $browser_stats[$browser->browser] = (int)$browser->count;
            }
            
            $total_sessions = (int)$this->db->get_var("SELECT COUNT(*) FROM {$sessionsTable}") ?: 0;
            
            return [
                'active' => $active_sessions,
                'active_now' => $active_sessions,
                'today' => $total_sessions_today,
                'total' => $total_sessions,
                'avg_duration' => round((float)$avg_session_duration / 60, 2), // in Minuten
                'browsers' => $browser_stats
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('dashboard')->warning('Session statistics could not be loaded.', [
                'exception' => $e,
            ]);
            return [
                'active' => 0,
                'active_now' => 0,
                'today' => 0,
                'total' => 0,
                'avg_duration' => 0,
                'browsers' => []
            ];
        }
    }
    
    /**
     * Sicherheits-Statistiken
     */
    public function getSecurityStats(): array {
        // login_attempts speichert auch andere Rate-Limit-Ereignisse (Suche, API, Registrierung …).
        $failedLoginActions = \CMS\Security::FAILED_LOGIN_ACTIONS;
        $failed_logins_24h = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND action IN (" . implode(', ', array_fill(0, count($failedLoginActions), '?')) . ")",
            $failedLoginActions
        ) ?: 0;

        // Erfolgreiche Logins werden nicht in login_attempts gespeichert
        $successful_logins_24h = 0;
        
        // Note: cms_blocked_ips table doesn't exist yet
        // This functionality can be implemented by a security plugin
        $blocked_ips = 0;
        
        // Security Score berechnen
        $score = 100;
        
        // PHP-Version
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $score -= 10;
        }
        
        // HTTPS – dieselbe Erkennung wie Sicherheits-Audit und System-Info (Port 443, X-Forwarded-Proto/-SSL, Front-End-Https)
        $https_enabled = (bool) (\CMS\Security::instance()->getSecurityHeaderProfile()['https'] ?? false);
        
        if (!$https_enabled) {
            $score -= 20;
        }
        
        // Debug-Modus
        if (defined('CMS_DEBUG') && CMS_DEBUG) {
            $score -= 10;
        }
        
        // Zu viele Failed Logins
        if ($failed_logins_24h > 10) {
            $score -= 15;
        }
        
        return [
            'failed_logins_24h' => $failed_logins_24h,
            'successful_logins_24h' => $successful_logins_24h,
            'blocked_ips' => $blocked_ips,
            'https_enabled' => $https_enabled,
            'security_score' => max(0, $score),
            'status' => $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'critical')
        ];
    }
    
    /**
     * Performance-Statistiken
     */
    public function getPerformanceStats(): array {
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        
        $disk_free = disk_free_space(ABSPATH);
        $disk_total = disk_total_space(ABSPATH);
        
        // OPcache
        $opcache_enabled = function_exists('opcache_get_status');
        $opcache_stats = [];
        if ($opcache_enabled) {
            $status = @opcache_get_status();
            if ($status) {
                $opcache_stats = [
                    'enabled' => true,
                    'hits' => $status['opcache_statistics']['hits'] ?? 0,
                    'misses' => $status['opcache_statistics']['misses'] ?? 0,
                    'hit_rate' => $status['opcache_statistics']['opcache_hit_rate'] ?? 0,
                    'memory_used' => $status['memory_usage']['used_memory'] ?? 0,
                    'memory_free' => $status['memory_usage']['free_memory'] ?? 0
                ];
            }
        }
        
        // Performance Score
        $score = 0;
        
        // PHP Version (10 Punkte)
        if (version_compare(PHP_VERSION, '8.0.0', '>=')) $score += 10;
        
        // Memory (10 Punkte)
        $memory_limit_bytes = $this->returnBytes($memory_limit);
        $memory_percent = ($memory_usage / $memory_limit_bytes) * 100;
        if ($memory_percent < 70) $score += 10;
        elseif ($memory_percent < 85) $score += 5;
        
        // Disk Space (10 Punkte)
        $disk_percent = (($disk_total - $disk_free) / $disk_total) * 100;
        if ($disk_percent < 70) $score += 10;
        elseif ($disk_percent < 85) $score += 5;
        
        // OPcache (10 Punkte)
        if (!empty($opcache_stats['enabled'])) $score += 10;
        
        // Extensions (10 Punkte)
        $required_extensions = ['pdo_mysql', 'mbstring', 'curl', 'gd', 'zip', 'json'];
        $loaded = 0;
        foreach ($required_extensions as $ext) {
            if (extension_loaded($ext)) $loaded++;
        }
        $score += round(($loaded / count($required_extensions)) * 10);
        
        return [
            'memory_limit' => $memory_limit,
            'memory_usage' => $memory_usage,
            'memory_usage_formatted' => $this->formatBytes($memory_usage),
            'memory_peak' => $memory_peak,
            'memory_peak_formatted' => $this->formatBytes($memory_peak),
            'memory_percent' => round($memory_percent, 2),
            'disk_free' => $disk_free,
            'disk_free_formatted' => $this->formatBytes($disk_free),
            'disk_total' => $disk_total,
            'disk_total_formatted' => $this->formatBytes($disk_total),
            'disk_percent' => round($disk_percent, 2),
            'opcache' => $opcache_stats,
            'performance_score' => $score
        ];
    }
    
    /**
     * System-Informationen
     */
    public function getSystemInfo(): array {
        return [
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->db->get_var("SELECT VERSION()"),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'os' => PHP_OS,
            'cms_version' => defined('CMS_VERSION') ? CMS_VERSION : Version::CURRENT,
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size')
        ];
    }
    
    /**
     * Aktivitäts-Feed abrufen
     * 
     * @param int $limit Anzahl Einträge
     * @return array
     */
    public function getActivityFeed(int $limit = 50): array {
        $activities = $this->db->get_results(
            "SELECT al.*, u.username 
             FROM {$this->prefix}activity_log al
             LEFT JOIN {$this->prefix}users u ON al.user_id = u.id
             ORDER BY al.created_at DESC
             LIMIT ?",
            [$limit]
        ) ?: [];
        
        foreach ($activities as $activity) {
            $activity->details = Json::decodeObject($activity->details ?? null);
        }
        
        return $activities;
    }

    /**
     * Neueste Bestellungen für das Dashboard.
     *
     * @return array<int, object>
     */
    public function getRecentOrders(int $limit = 5): array {
        if (!CoreModuleService::getInstance()->isModuleEnabled('subscription_admin_orders')) {
            return [];
        }

        try {
            $rows = $this->db->get_results(
                "SELECT o.*, u.display_name, u.username
                 FROM {$this->prefix}orders o
                 LEFT JOIN {$this->prefix}users u ON o.user_id = u.id
                 ORDER BY o.created_at DESC
                 LIMIT ?",
                [$limit]
            ) ?: [];

            return array_map(fn ($row) => $this->normalizeRecentOrderRow($row), $rows);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('dashboard')->warning('Recent orders could not be loaded.', [
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Kompakte Aufgabenliste für das Dashboard.
     */
    public function getAttentionItems(?array $stats = null): array {
        $stats ??= $this->getAllStats();
        $items = [];

        if (CoreModuleService::getInstance()->isModuleEnabled('subscription_admin_orders')
            && ($stats['orders']['pending'] ?? 0) > 0) {
            $items[] = [
                'type' => 'warning',
                'icon' => '🧾',
                'label' => 'Offene Bestellungen',
                'value' => (string)$stats['orders']['pending'],
                'hint' => 'Warten auf Prüfung oder Bestätigung',
                'url' => '/admin/orders',
            ];
        }

        if (($stats['security']['failed_logins_24h'] ?? 0) > 0) {
            $items[] = [
                'type' => (($stats['security']['failed_logins_24h'] ?? 0) >= 10) ? 'danger' : 'warning',
                'icon' => '🔐',
                'label' => 'Fehlgeschlagene Logins (24h)',
                'value' => (string)$stats['security']['failed_logins_24h'],
                'hint' => 'Sicherheitslage im Blick behalten',
                'url' => '/admin/security-audit',
            ];
        }

        if (($stats['security']['https_enabled'] ?? false) === false) {
            $items[] = [
                'type' => 'danger',
                'icon' => '⚠️',
                'label' => 'HTTPS nicht aktiv',
                'value' => 'Check',
                'hint' => 'Produktivbetrieb ohne TLS ist riskant',
                'url' => '/admin/security-audit',
            ];
        }

        return $items;
    }
    
    /**
     * Bytes formatieren
     */
    private function formatBytes(int|float $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = (float)$bytes; // Konvertiere zu float für Division
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * String zu Bytes konvertieren (z.B. "128M" -> 134217728)
     */
    private function returnBytes(string $size_str): int|float {
        $size_str = trim($size_str);
        
        if (empty($size_str)) {
            return 0;
        }
        
        $last = strtolower($size_str[strlen($size_str)-1]);
        $size = (float)$size_str; // Use float to handle large numbers
        
        switch($last) {
            case 'g':
                $size *= 1024;
                // fall through
            case 'm':
                $size *= 1024;
                // fall through
            case 'k':
                $size *= 1024;
                break;
        }
        
        return $size;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                return false;
            }

            return $this->db->columnExists($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildOrderAmountExpression(string $alias = ''): string
    {
        $table = $this->prefix . 'orders';
        $qualifier = $alias !== '' ? $alias . '.' : '';
        $hasTotalAmount = $this->hasColumn($table, 'total_amount');
        $hasAmount = $this->hasColumn($table, 'amount');

        return match (true) {
            $hasTotalAmount && $hasAmount => "COALESCE(NULLIF({$qualifier}total_amount, 0), {$qualifier}amount, 0)",
            $hasTotalAmount => "COALESCE({$qualifier}total_amount, 0)",
            $hasAmount => "COALESCE({$qualifier}amount, 0)",
            default => '0',
        };
    }

    private function normalizeRecentOrderRow(object $row): object
    {
        $data = (array) $row;
        $contactData = $this->decodeOrderContactData((string) ($data['contact_data'] ?? ''));
        $customerEmail = $this->firstNonEmpty([
            (string) ($data['customer_email'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($contactData['email'] ?? ''),
        ]);
        $contactName = trim(((string) ($contactData['first_name'] ?? '')) . ' ' . ((string) ($contactData['last_name'] ?? '')));
        $legacyName = trim(((string) ($data['forename'] ?? '')) . ' ' . ((string) ($data['lastname'] ?? '')));
        $customerName = $this->firstNonEmpty([
            (string) ($data['customer_name'] ?? ''),
            $contactName,
            $legacyName,
            (string) ($data['display_name'] ?? ''),
            (string) ($data['username'] ?? ''),
            $customerEmail,
            'Gast',
        ]);
        $status = strtolower(trim((string) ($data['status'] ?? 'pending')));
        if (in_array($status, ['confirmed', 'completed'], true)) {
            $status = 'paid';
        }

        $amount = isset($data['total_amount']) ? (float) $data['total_amount'] : 0.0;
        if ($amount <= 0.0 && isset($data['amount'])) {
            $amount = (float) $data['amount'];
        }

        return (object) [
            'order_number' => (string) ($data['order_number'] ?? ''),
            'total_amount' => $amount,
            'currency' => (string) ($data['currency'] ?? 'EUR'),
            'status' => $status,
            'created_at' => (string) ($data['created_at'] ?? ''),
            'customer_name' => $customerName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOrderContactData(string $contactData): array
    {
        if ($contactData === '') {
            return [];
        }

        $decoded = json_decode($contactData, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, string> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
