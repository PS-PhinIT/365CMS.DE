<?php
/**
 * Core Web Vitals Service
 *
 * Erfasst reale Browser-Metriken per Beacon und bereitet sie für das Admin-Reporting auf.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Auth;
use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class CoreWebVitalsService
{
    private const DEFAULT_SETTINGS = [
        'seo_analytics_web_vitals_enabled' => '1',
        'seo_analytics_web_vitals_sample_rate' => '100',
        'seo_analytics_exclude_admins' => '1',
        'seo_analytics_respect_dnt' => '1',
    ];

    private static ?self $instance = null;
    private Database $db;
    private string $prefix;

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
        $this->prefix = $this->db->getPrefix();
        $this->db->runSchemaOnce('core_web_vitals', __FILE__, fn (): bool => $this->createTable());
    }

    private function createTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->prefix}core_web_vitals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_path VARCHAR(500) NOT NULL,
            page_title VARCHAR(255) DEFAULT '',
            user_id INT UNSIGNED NULL,
            session_id VARCHAR(128) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(500) DEFAULT '',
            device_type VARCHAR(20) DEFAULT 'unknown',
            effective_connection VARCHAR(32) DEFAULT '',
            navigation_type VARCHAR(32) DEFAULT '',
            viewport_width SMALLINT UNSIGNED NULL,
            viewport_height SMALLINT UNSIGNED NULL,
            ttfb_ms INT UNSIGNED NULL,
            lcp_ms INT UNSIGNED NULL,
            inp_ms INT UNSIGNED NULL,
            cls DECIMAL(6,3) NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_path (page_path(191)),
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id),
            INDEX idx_recorded_at (recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        try {
            $this->db->query($sql);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('web-vitals')->warning('Core Web Vitals table could not be created.', [
                'exception' => $e,
            ]);
            return false;
        }

        return true;
    }

    public function renderTrackingScript(): void
    {
        if (!$this->shouldRenderTrackingScript()) {
            return;
        }

        $config = [
            'endpoint' => rtrim((string)SITE_URL, '/') . '/api/v1/analytics/web-vitals',
            'sampleRate' => $this->getSampleRate(),
        ];

        echo '<script ' . \CMS\Security::instance()->nonceAttr() . '>window.CMS_WEB_VITALS_CONFIG = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
        echo '<script src="' . htmlspecialchars(\cms_asset_url('js/web-vitals.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }

    public function storeReport(array $payload): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->shouldSkipForCurrentVisitor()) {
            return false;
        }

        $pagePath = $this->sanitizePath((string)($payload['path'] ?? '/'));
        if ($pagePath === '' || $this->shouldIgnorePath($pagePath)) {
            return false;
        }

        $ttfb = $this->sanitizeMetric($payload['ttfb'] ?? null, 60000);
        $lcp = $this->sanitizeMetric($payload['lcp'] ?? null, 60000);
        $inp = $this->sanitizeMetric($payload['inp'] ?? null, 60000);
        $cls = $this->sanitizeCls($payload['cls'] ?? null);

        if ($ttfb === null && $lcp === null && $inp === null && $cls === null) {
            return false;
        }

        $title = trim((string)($payload['title'] ?? ''));
        $title = mb_substr($title, 0, 255);

        $viewportWidth = $this->sanitizeDimension($payload['viewport_width'] ?? null);
        $viewportHeight = $this->sanitizeDimension($payload['viewport_height'] ?? null);
        $userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $sessionId = session_id() !== '' ? session_id() : null;

        try {
            return $this->db->insert('core_web_vitals', [
                'page_path' => $pagePath,
                'page_title' => $title,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip_address' => TrackingService::anonymizeIp(\CMS\Security::getClientIp()),
                'user_agent' => $userAgent,
                'device_type' => $this->detectDeviceType($userAgent),
                'effective_connection' => mb_substr(trim((string)($payload['effective_type'] ?? '')), 0, 32),
                'navigation_type' => mb_substr(trim((string)($payload['navigation_type'] ?? '')), 0, 32),
                'viewport_width' => $viewportWidth,
                'viewport_height' => $viewportHeight,
                'ttfb_ms' => $ttfb,
                'lcp_ms' => $lcp,
                'inp_ms' => $inp,
                'cls' => $cls,
            ]) !== false;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('web-vitals')->warning('Core Web Vitals report could not be stored.', [
                'page_path' => $pagePath,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function getDashboardSummary(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $rows = [];

        try {
            $stmt = $this->db->execute(
                "SELECT page_path, ttfb_ms, lcp_ms, inp_ms, cls
                 FROM {$this->prefix}core_web_vitals
                 WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 ORDER BY recorded_at DESC
                 LIMIT 5000",
                [$days]
            );
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('web-vitals')->warning('Core Web Vitals dashboard summary could not be loaded.', [
                'days' => $days,
                'exception' => $e,
            ]);
        }

        $ttfbValues = [];
        $lcpValues = [];
        $inpValues = [];
        $clsValues = [];

        foreach ($rows as $row) {
            if (isset($row['ttfb_ms']) && $row['ttfb_ms'] !== null) {
                $ttfbValues[] = (int)$row['ttfb_ms'];
            }
            if (isset($row['lcp_ms']) && $row['lcp_ms'] !== null) {
                $lcpValues[] = (int)$row['lcp_ms'];
            }
            if (isset($row['inp_ms']) && $row['inp_ms'] !== null) {
                $inpValues[] = (int)$row['inp_ms'];
            }
            if (isset($row['cls']) && $row['cls'] !== null) {
                $clsValues[] = (float)$row['cls'];
            }
        }

        $sampleCount = count($rows);

        return [
            'has_data' => $sampleCount > 0,
            'is_enabled' => $this->isEnabled(),
            'sample_count' => $sampleCount,
            'window_days' => $days,
            'sample_rate' => $this->getSampleRate(),
            'metrics' => [
                'ttfb' => $this->buildMetricSummary($ttfbValues, 800, 1800, 'ms'),
                'lcp' => $this->buildMetricSummary($lcpValues, 2500, 4000, 'ms'),
                'inp' => $this->buildMetricSummary($inpValues, 200, 500, 'ms'),
                'cls' => $this->buildMetricSummary($clsValues, 0.1, 0.25, 'score'),
            ],
            'problem_pages' => $this->getProblemPages($days),
            'note' => $sampleCount > 0
                ? 'Reale Felddaten aus Browser-Beacons (PerformanceObserver + sendBeacon) der letzten ' . $days . ' Tage.'
                : 'Noch keine Felddaten vorhanden. Werte werden erst nach neuer Seitenansicht mit Analytics-Einwilligung gesammelt.',
        ];
    }

    private function getProblemPages(int $days, int $limit = 5): array
    {
        try {
            $stmt = $this->db->execute(
                "SELECT page_path,
                        COUNT(*) AS samples,
                        ROUND(AVG(ttfb_ms)) AS avg_ttfb_ms,
                        ROUND(AVG(lcp_ms)) AS avg_lcp_ms,
                        ROUND(AVG(inp_ms)) AS avg_inp_ms,
                        ROUND(AVG(cls), 3) AS avg_cls
                 FROM {$this->prefix}core_web_vitals
                 WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY page_path
                 HAVING COUNT(*) >= 3
                 ORDER BY (
                    COALESCE(AVG(lcp_ms), 0) +
                    COALESCE(AVG(inp_ms), 0) +
                    COALESCE(AVG(ttfb_ms), 0) +
                    (COALESCE(AVG(cls), 0) * 1000)
                 ) DESC
                 LIMIT ?",
                [$days, $limit]
            );

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            return array_map(static function (array $row): array {
                return [
                    'page_path' => (string)($row['page_path'] ?? '/'),
                    'samples' => (int)($row['samples'] ?? 0),
                    'avg_ttfb_ms' => isset($row['avg_ttfb_ms']) ? (int)$row['avg_ttfb_ms'] : null,
                    'avg_lcp_ms' => isset($row['avg_lcp_ms']) ? (int)$row['avg_lcp_ms'] : null,
                    'avg_inp_ms' => isset($row['avg_inp_ms']) ? (int)$row['avg_inp_ms'] : null,
                    'avg_cls' => isset($row['avg_cls']) ? (float)$row['avg_cls'] : null,
                ];
            }, $rows);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('web-vitals')->warning('Core Web Vitals problem pages could not be loaded.', [
                'days' => $days,
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }

    private function buildMetricSummary(array $values, float|int $goodThreshold, float|int $needsImprovementThreshold, string $type): array
    {
        $value = $this->percentile($values, 0.75);
        $rating = $value === null
            ? 'unknown'
            : $this->determineRating($value, (float)$goodThreshold, (float)$needsImprovementThreshold);

        return [
            'value' => $value,
            'display' => $this->formatMetricValue($value, $type),
            'rating' => $rating,
            'rating_label' => match ($rating) {
                'good' => 'Gut',
                'needs-improvement' => 'Optimieren',
                'poor' => 'Schlecht',
                default => 'Keine Daten',
            },
            'threshold' => match ($type) {
                'score' => 'gut < 0.1',
                default => 'p75-Ziel: ≤ ' . (string)$goodThreshold . ' ' . $type,
            },
            'samples' => count($values),
        ];
    }

    private function percentile(array $values, float $percentile): int|float|null
    {
        $values = array_values(array_filter($values, static fn($value): bool => $value !== null));
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 1) {
            return $values[0];
        }

        $position = ($count - 1) * $percentile;
        $lowerIndex = (int)floor($position);
        $upperIndex = (int)ceil($position);
        $weight = $position - $lowerIndex;

        if ($lowerIndex === $upperIndex) {
            return $values[$lowerIndex];
        }

        return $values[$lowerIndex] + (($values[$upperIndex] - $values[$lowerIndex]) * $weight);
    }

    private function determineRating(float $value, float $goodThreshold, float $needsImprovementThreshold): string
    {
        if ($value <= $goodThreshold) {
            return 'good';
        }

        if ($value <= $needsImprovementThreshold) {
            return 'needs-improvement';
        }

        return 'poor';
    }

    private function formatMetricValue(int|float|null $value, string $type): string
    {
        if ($value === null) {
            return '—';
        }

        if ($type === 'score') {
            return number_format((float)$value, 3, ',', '.');
        }

        return number_format((float)$value, 0, ',', '.') . ' ms';
    }

    private function isEnabled(): bool
    {
        if (!$this->isSeoModuleEnabled()) {
            return false;
        }

        return ($this->getSettings()['seo_analytics_web_vitals_enabled'] ?? '1') === '1';
    }

    private function isSeoModuleEnabled(): bool
    {
        if (!class_exists(CoreModuleService::class)) {
            return true;
        }

        try {
            return CoreModuleService::getInstance()->isModuleEnabled('seo');
        } catch (\Throwable) {
            return true;
        }
    }

    private function getSampleRate(): int
    {
        return max(1, min(100, (int)($this->getSettings()['seo_analytics_web_vitals_sample_rate'] ?? '100')));
    }

    private function shouldRenderTrackingScript(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->shouldSkipForCurrentVisitor()) {
            return false;
        }

        $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($requestMethod !== 'GET') {
            return false;
        }

        $path = $this->sanitizePath((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        return !$this->shouldIgnorePath($path);
    }

    private function shouldSkipForCurrentVisitor(): bool
    {
        $settings = $this->getSettings();

        if (($settings['seo_analytics_exclude_admins'] ?? '1') === '1' && Auth::isAdmin()) {
            return true;
        }

        if (($settings['seo_analytics_respect_dnt'] ?? '1') === '1' && $this->isDoNotTrackRequested()) {
            return true;
        }

        return !TrackingService::hasAnalyticsConsent();
    }

    private function isDoNotTrackRequested(): bool
    {
        $header = (string)($_SERVER['HTTP_DNT'] ?? $_SERVER['DNT'] ?? '');
        return $header === '1' || strtolower($header) === 'yes';
    }

    private function sanitizePath(string $path): string
    {
        $path = trim((string)parse_url($path, PHP_URL_PATH));
        if ($path === '') {
            return '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        return mb_substr($path, 0, 500);
    }

    private function shouldIgnorePath(string $path): bool
    {
        foreach (['/api', '/admin', '/member'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeMetric(mixed $value, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $metric = (int)round((float)$value);
        if ($metric <= 0 || $metric > $max) {
            return null;
        }

        return $metric;
    }

    private function sanitizeCls(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cls = round((float)$value, 3);
        if ($cls < 0 || $cls > 10) {
            return null;
        }

        return $cls;
    }

    private function sanitizeDimension(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $dimension = (int)$value;
        if ($dimension <= 0 || $dimension > 10000) {
            return null;
        }

        return $dimension;
    }

    private function detectDeviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);
        if (preg_match('/mobile|iphone|android.+mobile|windows phone/', $userAgent) === 1) {
            return 'mobile';
        }
        if (preg_match('/ipad|tablet|android(?!.*mobile)/', $userAgent) === 1) {
            return 'tablet';
        }

        return 'desktop';
    }

    private function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;
        $keys = array_keys($settings);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        try {
            $rows = $this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
                $keys
            ) ?: [];

            foreach ($rows as $row) {
                $name = (string)($row->option_name ?? '');
                if ($name !== '' && array_key_exists($name, $settings)) {
                    $settings[$name] = (string)($row->option_value ?? '');
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('web-vitals')->warning('Core Web Vitals settings could not be loaded.', [
                'exception' => $e,
            ]);
        }

        return $settings;
    }
}
