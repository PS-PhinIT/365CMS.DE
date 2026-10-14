<?php
/**
 * Feature Usage Service
 *
 * Datensparsame Telemetrie für tatsächlich genutzte Admin-/Member-Funktionen.
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

final class FeatureUsageService
{
    private const THROTTLE_SECONDS = 900;

    private static ?self $instance = null;
    private Database $db;
    private bool $tableReady = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->createUsageTable();
    }

    public function trackFeatureUsage(
        string $featureKey,
        string $featureArea,
        string $routePath,
        ?int $userId = null,
        array $context = []
    ): bool {
        if (!$this->tableReady) {
            return false;
        }

        $featureKey = $this->normalizeSlug($featureKey, 190);
        $featureArea = $this->normalizeSlug($featureArea, 50);
        $routePath = $this->normalizeRoutePath($routePath);

        if ($featureKey === '' || $featureArea === '' || $routePath === '') {
            return false;
        }

        if ($userId === null || $userId <= 0) {
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        }

        if (($userId === null || $userId <= 0) && !TrackingService::hasAnalyticsConsent()) {
            return false;
        }

        $sessionId = session_id();
        if ($sessionId === '') {
            $sessionId = $this->buildFallbackSessionId();
        }

        $throttleKey = $featureArea . '|' . $featureKey . '|' . $routePath;
        if ($this->isThrottled($throttleKey)) {
            return false;
        }

        $featureLabel = trim((string) ($context['label'] ?? $this->humanizeKey($featureKey)));
        $featureLabel = substr($featureLabel !== '' ? $featureLabel : $this->humanizeKey($featureKey), 0, 190);
        $routeGroup = $this->normalizeSlug((string) ($context['route_group'] ?? $this->deriveRouteGroup($routePath)), 120);
        $pluginSlug = $this->normalizeSlug((string) ($context['plugin_slug'] ?? ''), 120);
        $usageDate = date('Y-m-d');

        try {
            $sql = "INSERT INTO {$this->db->getPrefix()}feature_usage (
                        feature_key,
                        feature_label,
                        feature_area,
                        route_path,
                        route_group,
                        plugin_slug,
                        user_id,
                        session_id,
                        usage_date,
                        use_count,
                        first_used_at,
                        last_used_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        feature_label = VALUES(feature_label),
                        feature_area = VALUES(feature_area),
                        route_path = VALUES(route_path),
                        route_group = VALUES(route_group),
                        plugin_slug = VALUES(plugin_slug),
                        user_id = COALESCE(VALUES(user_id), user_id),
                        use_count = use_count + 1,
                        last_used_at = NOW()";

            $this->db->execute($sql, [
                $featureKey,
                $featureLabel,
                $featureArea,
                $routePath,
                $routeGroup,
                $pluginSlug !== '' ? $pluginSlug : null,
                $userId,
                $sessionId,
                $usageDate,
            ]);

            $_SESSION['feature_usage_last'][$throttleKey] = time();

            return true;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feature-usage')->warning('Feature usage could not be tracked.', [
                'feature_key' => $featureKey,
                'feature_area' => $featureArea,
                'route_path' => $routePath,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function getUsageTotals(int $days = 30): array
    {
        if (!$this->tableReady) {
            return $this->getEmptyTotals();
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT
                    COALESCE(SUM(use_count), 0) AS total_events,
                    COUNT(DISTINCT feature_key) AS unique_features,
                    COUNT(DISTINCT COALESCE(CAST(user_id AS CHAR), session_id)) AS unique_actors,
                    COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id END) AS unique_users,
                    COUNT(DISTINCT session_id) AS unique_sessions
                 FROM {$this->db->getPrefix()}feature_usage
                 WHERE last_used_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );

            if (!$stmt) {
                return $this->getEmptyTotals();
            }

            $stmt->execute([$days]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            return [
                'total_events' => (int) ($row['total_events'] ?? 0),
                'unique_features' => (int) ($row['unique_features'] ?? 0),
                'unique_actors' => (int) ($row['unique_actors'] ?? 0),
                'unique_users' => (int) ($row['unique_users'] ?? 0),
                'unique_sessions' => (int) ($row['unique_sessions'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feature-usage')->warning('Feature usage totals could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return $this->getEmptyTotals();
        }
    }

    public function getTopFeatures(int $days = 30, int $limit = 10): array
    {
        if (!$this->tableReady) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT
                    feature_key,
                    feature_label,
                    feature_area,
                    route_group,
                    plugin_slug,
                    COALESCE(SUM(use_count), 0) AS total_uses,
                    COUNT(DISTINCT COALESCE(CAST(user_id AS CHAR), session_id)) AS unique_actors,
                    COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id END) AS unique_users,
                    MAX(last_used_at) AS last_used_at
                 FROM {$this->db->getPrefix()}feature_usage
                 WHERE last_used_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY feature_key, feature_label, feature_area, route_group, plugin_slug
                 ORDER BY total_uses DESC, unique_actors DESC, last_used_at DESC
                 LIMIT ?"
            );

            if (!$stmt) {
                return [];
            }

            $stmt->execute([$days, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn(array $row): array => [
                'feature_key' => (string) ($row['feature_key'] ?? ''),
                'feature_label' => (string) ($row['feature_label'] ?? ''),
                'feature_area' => (string) ($row['feature_area'] ?? ''),
                'route_group' => (string) ($row['route_group'] ?? ''),
                'plugin_slug' => (string) ($row['plugin_slug'] ?? ''),
                'total_uses' => (int) ($row['total_uses'] ?? 0),
                'unique_actors' => (int) ($row['unique_actors'] ?? 0),
                'unique_users' => (int) ($row['unique_users'] ?? 0),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
            ], $rows);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feature-usage')->warning('Top feature usage data could not be loaded.', [
                'days' => $days,
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }

    public function getAreaBreakdown(int $days = 30): array
    {
        if (!$this->tableReady) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT
                    feature_area,
                    COALESCE(SUM(use_count), 0) AS total_uses,
                    COUNT(DISTINCT feature_key) AS feature_count,
                    COUNT(DISTINCT COALESCE(CAST(user_id AS CHAR), session_id)) AS unique_actors
                 FROM {$this->db->getPrefix()}feature_usage
                 WHERE last_used_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY feature_area
                 ORDER BY total_uses DESC, feature_area ASC"
            );

            if (!$stmt) {
                return [];
            }

            $stmt->execute([$days]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn(array $row): array => [
                'feature_area' => (string) ($row['feature_area'] ?? ''),
                'total_uses' => (int) ($row['total_uses'] ?? 0),
                'feature_count' => (int) ($row['feature_count'] ?? 0),
                'unique_actors' => (int) ($row['unique_actors'] ?? 0),
            ], $rows);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feature-usage')->warning('Feature usage area breakdown could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return [];
        }
    }

    public function getDailyUsage(int $days = 30): array
    {
        if (!$this->tableReady) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT
                    usage_date,
                    COALESCE(SUM(use_count), 0) AS total_uses
                 FROM {$this->db->getPrefix()}feature_usage
                 WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 GROUP BY usage_date
                 ORDER BY usage_date ASC"
            );

            if (!$stmt) {
                return [];
            }

            $stmt->execute([$days]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn(array $row): array => [
                'day' => (string) ($row['usage_date'] ?? ''),
                'uses' => (int) ($row['total_uses'] ?? 0),
            ], $rows);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feature-usage')->warning('Daily feature usage data could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
            return [];
        }
    }

    private function createUsageTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->db->getPrefix()}feature_usage (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feature_key VARCHAR(190) NOT NULL,
            feature_label VARCHAR(190) NOT NULL,
            feature_area VARCHAR(50) NOT NULL,
            route_path VARCHAR(255) NOT NULL,
            route_group VARCHAR(120) NOT NULL DEFAULT '',
            plugin_slug VARCHAR(120) NULL,
            user_id INT UNSIGNED NULL,
            session_id VARCHAR(128) NOT NULL,
            usage_date DATE NOT NULL,
            use_count INT UNSIGNED NOT NULL DEFAULT 1,
            first_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_feature_session_day (feature_key, session_id, usage_date),
            INDEX idx_area_date (feature_area, usage_date),
            INDEX idx_route_group (route_group),
            INDEX idx_user_id (user_id),
            INDEX idx_last_used_at (last_used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        try {
            $this->db->query($sql);
            $this->tableReady = true;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feature-usage')->warning('Feature usage table could not be created.', [
                'exception' => $e,
            ]);
            $this->tableReady = false;
        }
    }

    private function isThrottled(string $throttleKey): bool
    {
        if (!isset($_SESSION)) {
            return false;
        }

        if (!isset($_SESSION['feature_usage_last']) || !is_array($_SESSION['feature_usage_last'])) {
            $_SESSION['feature_usage_last'] = [];
        }

        $lastSeen = (int) ($_SESSION['feature_usage_last'][$throttleKey] ?? 0);
        if ($lastSeen <= 0) {
            return false;
        }

        return (time() - $lastSeen) < self::THROTTLE_SECONDS;
    }

    private function buildFallbackSessionId(): string
    {
        $raw = TrustedProxyService::clientIp() . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . date('Y-m-d');
        return 'fallback-' . substr(hash('sha256', $raw), 0, 40);
    }

    private function normalizeSlug(string $value, int $maxLength): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._\/-]+/', '-', $value) ?? '';
        $value = trim($value, '-./');

        return substr($value, 0, $maxLength);
    }

    private function normalizeRoutePath(string $routePath): string
    {
        $path = trim((string) parse_url($routePath, PHP_URL_PATH));
        if ($path === '') {
            $path = trim($routePath);
        }
        if ($path === '') {
            return '';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        return substr($path, 0, 255);
    }

    private function deriveRouteGroup(string $routePath): string
    {
        $segments = array_values(array_filter(explode('/', trim($routePath, '/')), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return 'root';
        }

        return $segments[1] ?? $segments[0];
    }

    private function humanizeKey(string $featureKey): string
    {
        $label = str_replace(['.', '-', '_', '/'], ' ', $featureKey);
        $label = preg_replace('/\s+/', ' ', $label) ?? $featureKey;
        return ucwords(trim($label));
    }

    private function getEmptyTotals(): array
    {
        return [
            'total_events' => 0,
            'unique_features' => 0,
            'unique_actors' => 0,
            'unique_users' => 0,
            'unique_sessions' => 0,
        ];
    }
}
