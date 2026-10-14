<?php
/**
 * Tracking Service
 * 
 * Tracks page views, user interactions, and events
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

class TrackingService
{
    private static ?self $instance = null;
    private Database $db;
    
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
        $this->db->runSchemaOnce('tracking', __FILE__, fn (): bool => $this->createTrackingTable());
    }
    
    /**
     * Create tracking table if not exists
     */
    private function createTrackingTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->db->getPrefix()}page_views (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_id INT UNSIGNED NULL,
            page_slug VARCHAR(200),
            page_title VARCHAR(255),
            user_id INT UNSIGNED NULL,
            session_id VARCHAR(128),
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            referrer VARCHAR(500),
            visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_id (page_id),
            INDEX idx_page_slug (page_slug),
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id),
            INDEX idx_visited_at (visited_at),
            INDEX idx_date (visited_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        try {
            $this->db->query($sql);
        } catch (\Exception $e) {
            Logger::instance()->withChannel('tracking')->warning('Tracking table could not be created.', [
                'exception' => $e,
            ]);
            return false;
        }

        return true;
    }
    
    /**
     * Track page view
     *
     * Requires analytics consent (DSGVO/GDPR).
     * Always stores anonymised IP – last octet (IPv4) or last 80 bits (IPv6) zeroed.
     */
    public function trackPageView(
        ?int $pageId,
        string $pageSlug,
        string $pageTitle,
        ?int $userId = null
    ): bool {
        // DSGVO: only track when the visitor has given explicit consent
        if (!self::hasAnalyticsConsent()) {
            return false;
        }

        try {
            return $this->db->insert('page_views', [
                'page_id'    => $pageId,
                'page_slug'  => $pageSlug,
                'page_title' => $pageTitle,
                'user_id'    => $userId,
                'session_id' => session_id(),
                'ip_address' => self::anonymizeIp($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'referrer'   => $_SERVER['HTTP_REFERER'] ?? '',
            ]) !== false;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('tracking')->warning('Page view could not be tracked.', [
                'page_id' => $pageId,
                'page_slug' => $pageSlug,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Check whether the current visitor has given analytics consent.
     *
     * Consent is stored in $_SESSION['analytics_consent'] (bool true).
     * Logged-in users always count as consented (they accepted the T&C).
     */
    public static function hasAnalyticsConsent(): bool
    {
        // Logged-in users implicitly consented via registration T&C
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            return true;
        }
        return !empty($_SESSION['analytics_consent']);
    }

    /**
     * Anonymise an IP address for DSGVO compliance.
     *
     * IPv4: last octet set to 0   (192.168.1.100 → 192.168.1.0)
     * IPv6: last 80 bits zeroed   (2001:db8::1   → 2001:db8::)
     * Returns empty string for invalid / empty input.
     */
    public static function anonymizeIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        // Detect proxy chain and use leftmost trusted IP
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        // IPv6
        if (str_contains($ip, ':')) {
            $binary = inet_pton($ip);
            if ($binary === false) {
                return '';
            }
            // Keep first 48 bits (6 bytes), zero remaining 10 bytes
            $anonymized = substr($binary, 0, 6) . str_repeat("\x00", 10);
            return inet_ntop($anonymized) ?: '';
        }

        // IPv4
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $parts[3] = '0';
        return implode('.', $parts);
    }
    
    /**
     * Get page views for date range
     */
    public function getPageViewsByDate(int $days = 30): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT DATE(visited_at) as date, COUNT(*) as views
                FROM {$this->db->getPrefix()}page_views
                WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(visited_at)
                ORDER BY date ASC
            ");
            
            if (!$stmt) {
                return [];
            }
            
            $stmt->execute([$days]);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Fill missing dates with 0
            $data = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $data[$date] = 0;
            }
            
            foreach ($results as $row) {
                $data[$row['date']] = (int)$row['views'];
            }
            
            return $data;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('tracking')->warning('Page views by date could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return [];
        }
    }
    
    /**
     * Get top pages by views
     */
    public function getTopPages(int $days = 30, int $limit = 10): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    page_slug,
                    page_title,
                    COUNT(*) as views,
                    COUNT(DISTINCT session_id) as unique_visitors
                FROM {$this->db->getPrefix()}page_views
                WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY page_slug, page_title
                ORDER BY views DESC
                LIMIT ?
            ");
            
            if (!$stmt) {
                return [];
            }
            
            $stmt->execute([$days, $limit]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            Logger::instance()->withChannel('tracking')->warning('Top pages could not be loaded.', [
                'days' => $days,
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }
    
    /**
     * Get unique visitors count
     */
    public function getUniqueVisitors(int $days = 30): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT session_id) as count
                FROM {$this->db->getPrefix()}page_views
                WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            if (!$stmt) {
                return 0;
            }
            
            $stmt->execute([$days]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            return $result ? (int)$result->count : 0;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('tracking')->warning('Unique visitor count could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return 0;
        }
    }
}
