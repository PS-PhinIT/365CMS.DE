<?php
/**
 * Analytics Service
 * 
 * Provides real statistics and monitoring data from database
 * 
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class AnalyticsService
{
    private static ?self $instance = null;
    private Database $db;
    private TrackingService $tracking;
    private FeatureUsageService $featureUsage;
    private CoreWebVitalsService $coreWebVitals;
    private ?bool $sessionsTableHasCreatedAt = null;
    
    /**
     * Singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->db = Database::instance();
        $this->tracking = TrackingService::getInstance();
        $this->featureUsage = FeatureUsageService::getInstance();
        $this->coreWebVitals = CoreWebVitalsService::getInstance();
    }
    
    /**
     * Get page views statistics (REAL DATA)
     */
    public function getPageViews(int $days = 30): array
    {
        return $this->tracking->getPageViewsByDate($days);
    }
    
    /**
     * Get visitor statistics (REAL DATA)
     */
    public function getVisitorStats(int $days = 30): array
    {
        try {
            // Total page views
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM {$this->db->getPrefix()}page_views
                WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            if (!$stmt) {
                return $this->getEmptyStats();
            }
            
            $stmt->execute([$days]);
            $total = (int)$stmt->fetch(\PDO::FETCH_OBJ)->total;
            
            // Unique visitors
            $unique = $this->tracking->getUniqueVisitors($days);
            
            // Active sessions (last 30 minutes)
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT id) as count
                FROM {$this->db->getPrefix()}sessions
                WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            
            $stmt->execute();
            $activeSessions = (int)$stmt->fetch(\PDO::FETCH_OBJ)->count;
            
            // Bounce rate calculation (single page visits)
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(DISTINCT session_id) as single_page,
                    (SELECT COUNT(DISTINCT session_id) FROM {$this->db->getPrefix()}page_views 
                     WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) as total_sessions
                FROM {$this->db->getPrefix()}page_views
                WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY session_id
                HAVING COUNT(*) = 1
            ");
            
            $stmt->execute([$days, $days]);
            $bounceData = $stmt->fetch(\PDO::FETCH_OBJ);
            
            $bounceRate = 0;
            if ($bounceData && $bounceData->total_sessions > 0) {
                $bounceRate = round(($bounceData->single_page / $bounceData->total_sessions) * 100, 1);
            }
            
            // Average session duration (from sessions table)
            $avgDuration = $this->getAverageSessionDurationSeconds($days);
            
            $minutes = floor($avgDuration / 60);
            $seconds = $avgDuration % 60;
            
            return [
                'total' => $total,
                'unique' => $unique,
                'active_now' => $activeSessions,
                'bounce_rate' => $bounceRate . '%',
                'avg_session_duration' => $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s",
            ];
        } catch (\Exception $e) {
            Logger::instance()->withChannel('analytics')->warning('Visitor statistics could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return $this->getEmptyStats();
        }
    }
    
    /**
     * Get empty stats fallback
     */
    private function getEmptyStats(): array
    {
        return [
            'total' => 0,
            'unique' => 0,
            'active_now' => 0,
            'bounce_rate' => '0%',
            'avg_session_duration' => '0s',
        ];
    }

    private function sessionsTableHasCreatedAt(): bool
    {
        if ($this->sessionsTableHasCreatedAt !== null) {
            return $this->sessionsTableHasCreatedAt;
        }

        try {
            $table = $this->db->getPrefix() . 'sessions';
            $this->sessionsTableHasCreatedAt = $this->db->get_var("SHOW COLUMNS FROM {$table} LIKE 'created_at'") !== null;
        } catch (\Throwable) {
            $this->sessionsTableHasCreatedAt = false;
        }

        return $this->sessionsTableHasCreatedAt;
    }

    private function getAverageSessionDurationSeconds(int $days): int
    {
        if (!$this->sessionsTableHasCreatedAt()) {
            return 0;
        }

        $stmt = $this->db->prepare("\n            SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, last_activity)) as avg_duration\n            FROM {$this->db->getPrefix()}sessions\n            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)\n            AND last_activity > created_at\n        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->execute([$days]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return (int) round((float) ($row->avg_duration ?? 0));
    }
    
    /**
     * Get top pages (REAL DATA)
     */
    public function getTopPages(int $limit = 10): array
    {
        return $this->tracking->getTopPages(30, $limit);
    }

    /**
     * Get Core Web Vitals field data (REAL DATA)
     */
    public function getCoreWebVitals(int $days = 30): array
    {
        return $this->coreWebVitals->getDashboardSummary($days);
    }

    /**
     * Get feature usage statistics (REAL DATA)
     */
    public function getFeatureUsageSummary(int $days = 30): array
    {
        $totals = $this->featureUsage->getUsageTotals($days);

        return [
            'totals' => $totals,
            'top_features' => $this->featureUsage->getTopFeatures($days, 10),
            'area_breakdown' => $this->featureUsage->getAreaBreakdown($days),
            'daily_usage' => $this->featureUsage->getDailyUsage($days),
            'note' => 'Feature-Nutzung wird datensparsam pro Session und Funktion aggregiert (Throttle: 15 Minuten).',
        ];
    }
    
    /**
     * Get user activity (REAL DATA)
     */
    public function getUserActivity(int $days = 7): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count, DATE(created_at) as date
                FROM {$this->db->getPrefix()}users
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            
            if (!$stmt) {
                return [];
            }
            
            $stmt->execute([$days]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            Logger::instance()->withChannel('analytics')->warning('User activity could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return [];
        }
    }
    
    /**
     * Get system health metrics (REAL DATA)
     */
    public function getSystemHealth(): array
    {
        return [
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'database_size' => $this->getDatabaseSize(),
            'uptime' => $this->getSystemUptime(),
        ];
    }
    
    /**
     * Get real CPU usage (Linux only, otherwise approximation)
     */
    private function getCpuUsage(): float
    {
        if (stristr(PHP_OS, 'WIN')) {
            // Windows: Use WMI or return 0
            return 0.0;
        }
        
        // Linux: Read from /proc/stat
        if (is_readable('/proc/stat')) {
            $stat1 = @file('/proc/stat');
            // Kurzes Messfenster statt sleep(1): blockierte den Admin-Request eine volle Sekunde.
            usleep(200000);
            $stat2 = @file('/proc/stat');
            if (!is_array($stat1) || !is_array($stat2) || !isset($stat1[0], $stat2[0])) {
                return 0.0;
            }
            
            $info1 = array_map('intval', explode(' ', (string) preg_replace('!cpu +!', '', $stat1[0])));
            $info2 = array_map('intval', explode(' ', (string) preg_replace('!cpu +!', '', $stat2[0])));
            if (count($info1) < 4 || count($info2) < 4) {
                return 0.0;
            }
            
            $dif = [];
            $dif['user'] = $info2[0] - $info1[0];
            $dif['nice'] = $info2[1] - $info1[1];
            $dif['sys'] = $info2[2] - $info1[2];
            $dif['idle'] = $info2[3] - $info1[3];
            
            $total = array_sum($dif);
            if ($total <= 0) {
                return 0.0;
            }
            $cpu = 100 - ($dif['idle'] * 100 / $total);
            
            return round($cpu, 1);
        }
        
        return 0.0;
    }
    
    /**
     * Get real memory usage
     */
    private function getMemoryUsage(): float
    {
        if (stristr(PHP_OS, 'WIN')) {
            // Windows
            return round(memory_get_usage(true) / 1024 / 1024, 1);
        }
        
        // Linux
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
            
            if (isset($total[1]) && isset($available[1])) {
                $used = ($total[1] - $available[1]) / $total[1] * 100;
                return round($used, 1);
            }
        }
        
        return round(memory_get_usage(true) / 1024 / 1024, 1);
    }
    
    /**
     * Get real disk usage
     */
    private function getDiskUsage(): float
    {
        $total = disk_total_space(ABSPATH);
        $free = disk_free_space(ABSPATH);
        
        if ($total > 0) {
            $used = ($total - $free) / $total * 100;
            return round($used, 1);
        }
        
        return 0.0;
    }
    
    /**
     * Get database size in MB (REAL DATA)
     */
    private function getDatabaseSize(): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size
                FROM information_schema.TABLES
                WHERE table_schema = DATABASE()
            ");
            
            if (!$stmt) {
                return 0.0;
            }
            
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            return $result ? (float)$result->size : 0.0;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('analytics')->warning('Database size could not be determined.', [
                'exception' => $e,
            ]);
            return 0.0;
        }
    }
    
    /**
     * Get system uptime (REAL DATA)
     */
    private function getSystemUptime(): string
    {
        if (stristr(PHP_OS, 'WIN')) {
            return 'N/A (Windows)';
        }
        
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $seconds = (int)explode(' ', $uptime)[0];
            
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            
            return "{$days}d {$hours}h";
        }
        
        return 'N/A';
    }
    
    /**
     * Get error log summary (REAL DATA from file)
     */
    public function getErrorLogSummary(int $limit = 50): array
    {
        $logFile = ABSPATH . 'logs/error.log';
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        
        $lines = array_slice($lines, -$limit);
        
        $errors = [];
        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\]\s*(ERROR|WARNING|INFO|NOTICE):\s*(.+)/', $line, $matches)) {
                $errors[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3]
                ];
            }
        }
        
        return array_reverse($errors);
    }
    
    /**
     * Get cache statistics (REAL DATA from cache table)
     */
    public function getCacheStats(): array
    {
        try {
            // Total cache items
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM {$this->db->getPrefix()}cache
            ");
            
            if (!$stmt) {
                return $this->getEmptyCacheStats();
            }
            
            $stmt->execute();
            $total = (int)$stmt->fetch(\PDO::FETCH_OBJ)->total;
            
            // Expired items
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as expired 
                FROM {$this->db->getPrefix()}cache
                WHERE expires_at < NOW()
            ");
            
            $stmt->execute();
            $expired = (int)$stmt->fetch(\PDO::FETCH_OBJ)->expired;
            
            // Estimate size
            $stmt = $this->db->prepare("
                SELECT SUM(LENGTH(cache_value)) as size 
                FROM {$this->db->getPrefix()}cache
            ");
            
            $stmt->execute();
            $sizeBytes = (int)$stmt->fetch(\PDO::FETCH_OBJ)->size;
            $sizeMB = round($sizeBytes / 1024 / 1024, 2);
            
            return [
                'hits' => 0, // Would need hit counter in cache table
                'misses' => 0,
                'size' => $sizeMB . ' MB',
                'items' => $total,
                'expired' => $expired,
            ];
        } catch (\Exception $e) {
            Logger::instance()->withChannel('analytics')->warning('Cache statistics could not be loaded.', [
                'exception' => $e,
            ]);
            return $this->getEmptyCacheStats();
        }
    }
    
    /**
     * Empty cache stats fallback
     */
    private function getEmptyCacheStats(): array
    {
        return [
            'hits' => 0,
            'misses' => 0,
            'size' => '0 MB',
            'items' => 0,
            'expired' => 0,
        ];
    }
    
    /**
     * Get recent activity log (REAL DATA)
     */
    public function getRecentActivity(int $limit = 20): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    al.action,
                    al.description,
                    al.created_at as timestamp,
                    u.display_name as user
                FROM {$this->db->getPrefix()}activity_log al
                LEFT JOIN {$this->db->getPrefix()}users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT ?
            ");
            
            if (!$stmt) {
                return [];
            }
            
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            Logger::instance()->withChannel('analytics')->warning('Recent activity could not be loaded.', [
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }
}

