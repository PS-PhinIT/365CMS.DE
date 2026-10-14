<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Hooks;
use CMS\Services\ImageService;
use CMS\Services\MediaService;
use CMS\Services\OpcacheWarmupService;
use CMS\Services\PerformanceSafetyNetService;

if (!class_exists('PerformanceModule', false)) {

final class PerformanceModule
{
    private const MEDIA_EXCLUDED_PATH_PARTS = [
        '/uploads/.elfinder/.tmb/',
        '\\uploads\\.elfinder\\.tmb\\',
        '/cache/performance-webp-backups/',
        '\\cache\\performance-webp-backups\\',
    ];

    private const WEBP_DEFAULT_BATCH_LIMIT = 25;
    private const WEBP_MAX_BATCH_LIMIT = 200;
    private const CAPACITY_WARNING_FREE_BYTES = 1073741824;
    private const CAPACITY_CRITICAL_FREE_BYTES = 536870912;
    private const CAPACITY_WARNING_LOAD = 4.0;
    private const CAPACITY_CRITICAL_LOAD = 8.0;
    private const ACTIVE_QUEUE_LOCK_WINDOW_MINUTES = 15;
    private const MEDIA_JOB_STATUS_MAX_FILE_BYTES = 1048576;
    private const BROWSER_CACHE_TTL_OPTIONS = ['259200', '604800', '2678400'];

    private const DEFAULT_SETTINGS = [
        'perf_lazy_loading' => '1',
        'perf_lazy_loading_eager_images' => '1',
        'perf_minify_css' => '0',
        'perf_minify_js' => '0',
        'perf_browser_cache' => '1',
        'perf_page_cache' => '1',
        'perf_browser_cache_ttl' => '604800',
        'perf_html_cache_ttl' => '300',
        'perf_webp_uploads' => '1',
        'perf_strip_exif' => '1',
        'perf_auto_clear_content_cache' => '1',
        'perf_session_timeout_admin' => '28800',
        'perf_session_timeout_member' => '2592000',
    ];

    private const BOOLEAN_SETTING_KEYS = [
        'perf_lazy_loading',
        'perf_minify_css',
        'perf_minify_js',
        'perf_browser_cache',
        'perf_page_cache',
        'perf_webp_uploads',
        'perf_strip_exif',
        'perf_auto_clear_content_cache',
    ];

    private const SETTING_KEYS_BY_ACTION = [
        'save_settings' => [
            'perf_lazy_loading',
            'perf_lazy_loading_eager_images',
            'perf_minify_css',
            'perf_minify_js',
            'perf_browser_cache',
            'perf_page_cache',
            'perf_browser_cache_ttl',
            'perf_html_cache_ttl',
            'perf_auto_clear_content_cache',
            'perf_session_timeout_admin',
            'perf_session_timeout_member',
        ],
        'save_cache_settings' => [
            'perf_page_cache',
            'perf_browser_cache',
            'perf_browser_cache_ttl',
            'perf_html_cache_ttl',
            'perf_auto_clear_content_cache',
        ],
        'save_media_settings' => [
            'perf_lazy_loading',
            'perf_lazy_loading_eager_images',
            'perf_browser_cache',
            'perf_browser_cache_ttl',
            'perf_webp_uploads',
            'perf_strip_exif',
        ],
        'save_session_settings' => [
            'perf_session_timeout_admin',
            'perf_session_timeout_member',
        ],
    ];

    private const MAX_AUDIT_STRING_LENGTH = 240;
    private const MAX_HISTORY_EVENTS = 30;

    private readonly \CMS\Database $db;
    private readonly \CMS\CacheManager $cacheManager;
    private readonly \CMS\Services\SystemService $systemService;
    private readonly PerformanceSafetyNetService $safetyNetService;
    private readonly string $prefix;
    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    public function __construct()
    {
        $this->db = \CMS\Database::instance();
        $this->cacheManager = \CMS\CacheManager::instance();
        $this->systemService = \CMS\Services\SystemService::instance();
        $this->safetyNetService = PerformanceSafetyNetService::getInstance();
        $this->prefix = $this->db->getPrefix();
    }

    public function getData(): array
    {
        $settings = $this->getSettings();
        $cache = $this->getCacheMetrics();
        $media = $this->getMediaMetrics();
        $database = $this->getDatabaseMetrics();
        $sessions = $this->getSessionMetrics();
        $phpInfo = $this->getPhpInfo();
        $history = $this->getPerformanceHistoryState();

        return [
            'cache_size' => (int)($cache['file_cache']['size_bytes'] ?? 0),
            'cache_files' => (int)($cache['file_cache']['files'] ?? 0),
            'session_size' => (int)($sessions['session_dir_size'] ?? 0),
            'session_files' => (int)($sessions['session_dir_files'] ?? 0),
            'upload_size' => (int)($media['upload_size'] ?? 0),
            'db_size' => (int)($database['total_size_bytes'] ?? 0),
            'php_info' => $phpInfo,
            'settings' => $settings,
            'overview' => [
                'cache_score' => $cache['health_score'],
                'media_score' => $media['health_score'],
                'database_score' => $database['health_score'],
                'session_score' => $sessions['health_score'],
            ],
            'cache' => $cache,
            'media' => $media,
            'database' => $database,
            'sessions' => $sessions,
            'history' => $history,
        ];
    }

    public function getSectionData(string $section): array
    {
        return match ($section) {
            'cache' => (function (): array {
                $cache = $this->getCacheMetrics();

                return [
                    'cache' => $cache,
                    'safety' => [
                        'cache' => $this->safetyNetService->getLatestCacheSnapshot(),
                    ],
                    'capacity' => $this->getCapacityPrecheck('cache', ['cache' => $cache]),
                ];
            })(),
            'database' => (function (): array {
                $database = $this->getDatabaseMetrics();

                return [
                    'database' => $database,
                    'safety' => [
                        'database' => $this->safetyNetService->getLatestDatabaseSnapshot(),
                    ],
                    'capacity' => $this->getCapacityPrecheck('database', ['database' => $database]),
                ];
            })(),
            'media' => (function (): array {
                $media = $this->getMediaMetrics();

                return [
                    'media' => $media,
                    'settings' => $this->getSettings(),
                    'capacity' => $this->getCapacityPrecheck('media', ['media' => $media]),
                ];
            })(),
            'sessions' => [
                'sessions' => $this->getSessionMetrics(),
                'settings' => $this->getSettings(),
            ],
            'settings' => [
                'settings' => $this->getSettings(),
                'php_info' => $this->getPhpInfo(),
            ],
            'overview' => $this->getData(),
            default => $this->getData(),
        };
    }

    public function handleAction(string $section, string $action, array $post): array
    {
        $startedAt = microtime(true);

        $result = match ($action) {
            'clear_all_cache' => $this->clearAllCacheLayers(),
            'clear_file_cache' => $this->clearFileCache(),
            'rollback_cache_cleanup' => $this->rollbackCacheCleanup(),
            'clear_opcache' => $this->clearOpcache(),
            'warmup_opcache' => $this->warmupOpcache(),
            'optimize_database' => $this->optimizeDatabase(),
            'repair_tables' => $this->repairDatabase(),
            'rollback_database_maintenance' => $this->rollbackDatabaseMaintenance(),
            'clear_expired_sessions' => $this->clearExpiredSessions(),
            'convert_media_to_webp' => $this->convertMediaLibraryToWebp($post),
            'rollback_webp_conversion' => $this->rollbackLastWebpConversion(),
            'save_settings', 'save_cache_settings', 'save_media_settings', 'save_session_settings' => $this->saveSettings($post, $action),
            default => ['success' => false, 'error' => 'Unbekannte Aktion.'],
        };

        $this->recordPerformanceHistory($section, $action, $post, $result, $startedAt);

        return $result;
    }

    /** @return array<string, mixed> */
    private function getPerformanceHistoryState(): array
    {
        try {
            $rows = $this->loadPerformanceHistoryRows(true);
            $sourceMode = 'standardized';

            if ($rows === []) {
                $rows = $this->loadPerformanceHistoryRows(false);
                $sourceMode = 'legacy';
            }

            $entries = [];
            foreach ($rows as $row) {
                $entry = $this->normalizePerformanceHistoryEntry($row);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            $successCount = 0;
            $partialCount = 0;
            $errorCount = 0;
            $durationTotal = 0;
            $durationCount = 0;

            foreach ($entries as $entry) {
                $result = (string) ($entry['result'] ?? 'success');
                if ($result === 'error') {
                    ++$errorCount;
                } elseif ($result === 'partial') {
                    ++$partialCount;
                } else {
                    ++$successCount;
                }

                if (($entry['duration_ms'] ?? null) !== null) {
                    $durationTotal += (int) $entry['duration_ms'];
                    ++$durationCount;
                }
            }

            $note = $sourceMode === 'standardized'
                ? 'Die Historie wird read-only aus standardisierten Performance-Audit-Einträgen gespeist.'
                : 'Die Historie fällt fail-soft auf bereits vorhandene Performance-Audits zurück; Trigger- und Laufzeitdaten erscheinen dabei nur, wenn sie zuvor schon in den Metadaten protokolliert wurden.';

            return [
                'entries' => $entries,
                'summary' => [
                    'total' => count($entries),
                    'success_count' => $successCount,
                    'partial_count' => $partialCount,
                    'error_count' => $errorCount,
                    'avg_duration_ms' => $durationCount > 0 ? (int) round($durationTotal / $durationCount) : null,
                ],
                'source_mode' => $sourceMode,
                'note' => $note,
                'unavailable' => false,
            ];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                'performance.history.load_failed',
                'Performance-Historie konnte nicht geladen werden',
                'performance',
                null,
                ['exception' => $this->sanitizeAuditString($e::class, 120)],
                'error'
            );

            return [
                'entries' => [],
                'summary' => [
                    'total' => 0,
                    'success_count' => 0,
                    'partial_count' => 0,
                    'error_count' => 0,
                    'avg_duration_ms' => null,
                ],
                'source_mode' => 'unavailable',
                'note' => 'Die Performance-Historie ist derzeit nicht verfügbar.',
                'unavailable' => true,
            ];
        }
    }

    /** @return list<object> */
    private function loadPerformanceHistoryRows(bool $standardizedOnly): array
    {
        $where = $standardizedOnly
            ? "action LIKE 'performance.history.%'"
            : "(action LIKE 'performance.cache.%' OR action LIKE 'performance.database.%' OR action LIKE 'performance.sessions.%' OR action LIKE 'performance.media.%' OR action LIKE 'performance.settings.%')";

        return $this->db->get_results(
            "SELECT id, user_id, category, action, description, severity, metadata, created_at
             FROM {$this->prefix}audit_log
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT " . self::MAX_HISTORY_EVENTS
        ) ?: [];
    }

    /** @param object $row @return array<string, mixed>|null */
    private function normalizePerformanceHistoryEntry(object $row): ?array
    {
        $metadata = $this->decodeAuditMetadata($row->metadata ?? null);
        $actionPayload = $this->normalizePerformanceHistoryActionPayload((string) ($row->action ?? ''));
        if ($actionPayload === null) {
            return null;
        }

        $section = (string) ($actionPayload['section'] ?? 'overview');
        $action = (string) ($actionPayload['action'] ?? '');
        $trigger = $this->normalizePerformanceHistoryTrigger((string) ($metadata['trigger'] ?? 'admin'));
        $result = $this->normalizePerformanceHistoryResult(
            (string) ($metadata['result'] ?? ''),
            (string) ($row->severity ?? 'info'),
            $action
        );
        $message = $this->sanitizeAuditString(
            (string) ($metadata['message'] ?? ($row->description ?? '')),
            180
        );
        $userId = (int) ($row->user_id ?? 0);

        return [
            'created_at' => (string) ($row->created_at ?? ''),
            'section' => $section,
            'section_label' => $this->mapPerformanceHistorySectionLabel($section),
            'action' => $action,
            'action_label' => $this->mapPerformanceHistoryActionLabel($section, $action),
            'trigger' => $trigger,
            'trigger_label' => $this->mapPerformanceHistoryTriggerLabel($trigger),
            'result' => $result,
            'result_label' => $this->mapPerformanceHistoryResultLabel($result),
            'result_badge' => $this->mapPerformanceHistoryResultBadge($result),
            'duration_ms' => $this->normalizePositiveNullable($metadata['duration_ms'] ?? null),
            'user_label' => $userId > 0 ? 'User #' . $userId : 'System',
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeAuditMetadata(mixed $rawMetadata): array
    {
        if (!is_string($rawMetadata) || trim($rawMetadata) === '') {
            return [];
        }

        $decoded = json_decode($rawMetadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{section:string,action:string}|null */
    private function normalizePerformanceHistoryActionPayload(string $action): ?array
    {
        $action = trim($action);
        if ($action === '') {
            return null;
        }

        if (str_starts_with($action, 'performance.history.')) {
            $normalized = substr($action, strlen('performance.history.'));
            $parts = explode('.', $normalized, 2);
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                return ['section' => $parts[0], 'action' => $parts[1]];
            }
        }

        return match ($action) {
            'performance.cache.clear_all' => ['section' => 'cache', 'action' => 'clear_all_cache'],
            'performance.cache.clear_file' => ['section' => 'cache', 'action' => 'clear_file_cache'],
            'performance.cache.rollback' => ['section' => 'cache', 'action' => 'rollback_cache_cleanup'],
            'performance.cache.clear_opcache' => ['section' => 'cache', 'action' => 'clear_opcache'],
            'performance.cache.warmup_opcache' => ['section' => 'cache', 'action' => 'warmup_opcache'],
            'performance.database.optimize' => ['section' => 'database', 'action' => 'optimize_database'],
            'performance.database.repair' => ['section' => 'database', 'action' => 'repair_tables'],
            'performance.database.rollback' => ['section' => 'database', 'action' => 'rollback_database_maintenance'],
            'performance.sessions.clear_expired' => ['section' => 'sessions', 'action' => 'clear_expired_sessions'],
            'performance.media.convert_webp' => ['section' => 'media', 'action' => 'convert_media_to_webp'],
            'performance.media.rollback_webp' => ['section' => 'media', 'action' => 'rollback_webp_conversion'],
            'performance.settings.save', 'performance.settings.save_failed' => ['section' => 'settings', 'action' => 'save_settings'],
            default => null,
        };
    }

    private function normalizePerformanceHistoryTrigger(string $trigger): string
    {
        $trigger = strtolower(trim($trigger));

        return match ($trigger) {
            'admin', 'manual', 'cron', 'system', 'rollback' => $trigger,
            default => 'admin',
        };
    }

    private function normalizePerformanceHistoryResult(string $result, string $severity, string $action): string
    {
        $result = strtolower(trim($result));
        if (in_array($result, ['success', 'partial', 'error'], true)) {
            return $result;
        }

        if ($action === 'save_settings' && str_contains(strtolower($severity), 'error')) {
            return 'error';
        }

        return in_array(strtolower(trim($severity)), ['error', 'critical'], true) ? 'error' : 'success';
    }

    private function mapPerformanceHistorySectionLabel(string $section): string
    {
        return match ($section) {
            'cache' => 'Cache',
            'database' => 'Datenbank',
            'media' => 'Medien',
            'sessions' => 'Sessions',
            'settings' => 'Einstellungen',
            default => 'Performance',
        };
    }

    private function mapPerformanceHistoryActionLabel(string $section, string $action): string
    {
        return match ($action) {
            'clear_all_cache' => 'Alle Cache-Layer leeren',
            'clear_file_cache' => 'Nur Datei-Cache leeren',
            'rollback_cache_cleanup' => 'Cache-Bereinigung zurückrollen',
            'clear_opcache' => 'OPcache zurücksetzen',
            'warmup_opcache' => 'OPcache-Warmup',
            'optimize_database' => 'Tabellen optimieren',
            'repair_tables' => 'Tabellen prüfen & reparieren',
            'rollback_database_maintenance' => 'DB-Wartung zurückrollen',
            'clear_expired_sessions' => 'Abgelaufene Sessions bereinigen',
            'convert_media_to_webp' => 'Medien zu WebP konvertieren',
            'rollback_webp_conversion' => 'WebP-Konvertierung zurückrollen',
            'save_cache_settings' => 'Cache-Einstellungen speichern',
            'save_media_settings' => 'Medien-Performance speichern',
            'save_session_settings' => 'Session-Einstellungen speichern',
            'save_settings' => $section === 'settings' ? 'Performance-Einstellungen speichern' : 'Performance-Einstellungen speichern',
            default => $action !== '' ? $action : 'Performance-Maßnahme',
        };
    }

    private function mapPerformanceHistoryTriggerLabel(string $trigger): string
    {
        return match ($trigger) {
            'admin' => 'Admin-Aktion',
            'manual' => 'Manuell',
            'cron' => 'Cron',
            'system' => 'System',
            'rollback' => 'Rollback',
            default => 'Admin-Aktion',
        };
    }

    private function mapPerformanceHistoryResultLabel(string $result): string
    {
        return match ($result) {
            'partial' => 'teilweise',
            'error' => 'fehlgeschlagen',
            default => 'erfolgreich',
        };
    }

    private function mapPerformanceHistoryResultBadge(string $result): string
    {
        return match ($result) {
            'partial' => 'warning',
            'error' => 'danger',
            default => 'success',
        };
    }

    /** @return int|null */
    private function normalizePositiveNullable(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = (int) $value;

        return $normalized >= 0 ? $normalized : null;
    }

    private function recordPerformanceHistory(string $section, string $action, array $post, array $result, float $startedAt): void
    {
        $section = trim($section);
        $action = trim($action);

        if ($section === '' || $action === '') {
            return;
        }

        $resultState = $this->determinePerformanceHistoryOutcome($result);
        $severity = match ($resultState) {
            'error' => 'error',
            'partial' => 'warning',
            default => 'info',
        };

        $message = (string) ($result['message'] ?? $result['error'] ?? '');
        $metadata = [
            'section' => $section,
            'trigger' => 'admin',
            'result' => $resultState,
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'message' => $this->sanitizeAuditString($message, 180),
        ];

        foreach ($this->buildPerformanceHistoryContext($section, $action, $post) as $key => $value) {
            $metadata[$key] = $value;
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.history.' . $section . '.' . $action,
            'Performance-Historie: ' . $this->mapPerformanceHistoryActionLabel($section, $action),
            'performance',
            null,
            $metadata,
            $severity
        );
    }

    private function determinePerformanceHistoryOutcome(array $result): string
    {
        if (empty($result['success'])) {
            return 'error';
        }

        $message = strtolower((string) ($result['message'] ?? ''));
        if ($message !== '' && preg_match('/\b[1-9]\d*\s+fehlgeschlagen\b/u', $message) === 1) {
            return 'partial';
        }

        return 'success';
    }

    /** @return array<string, scalar|null> */
    private function buildPerformanceHistoryContext(string $section, string $action, array $post): array
    {
        if ($action === 'convert_media_to_webp') {
            return [
                'mode' => (string) (($post['webp_mode'] ?? '') === 'dry_run' ? 'dry_run' : 'live'),
                'batch_limit' => max(1, min(self::WEBP_MAX_BATCH_LIMIT, (int) ($post['webp_batch_limit'] ?? self::WEBP_DEFAULT_BATCH_LIMIT))),
                'replace_originals' => !empty($post['webp_replace_originals']),
            ];
        }

        return match ($action) {
            'save_cache_settings' => ['settings_scope' => 'cache'],
            'save_media_settings' => ['settings_scope' => 'media'],
            'save_session_settings' => ['settings_scope' => 'sessions'],
            'save_settings' => ['settings_scope' => $section],
            default => [],
        };
    }

    private function getSettings(): array
    {
        $keys = array_keys(self::DEFAULT_SETTINGS);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $keys
        );

        $settings = self::DEFAULT_SETTINGS;
        $foundKeys = [];
        foreach ($rows as $row) {
            $name = (string)($row->option_name ?? '');
            if ($name !== '' && array_key_exists($name, $settings)) {
                $settings[$name] = (string)($row->option_value ?? '');
                $foundKeys[$name] = true;
            }
        }

        if (!isset($foundKeys['perf_webp_uploads']) || !isset($foundKeys['perf_strip_exif'])) {
            try {
                $mediaSettings = MediaService::getInstance()->getSettings();
                if (!isset($foundKeys['perf_webp_uploads'])) {
                    $settings['perf_webp_uploads'] = !empty($mediaSettings['auto_webp']) ? '1' : '0';
                }
                if (!isset($foundKeys['perf_strip_exif'])) {
                    $settings['perf_strip_exif'] = !empty($mediaSettings['strip_exif']) ? '1' : '0';
                }
            } catch (\Throwable) {
                // Defaults bleiben erhalten, wenn die Medienkonfiguration nicht lesbar ist.
            }
        }

        return $settings;
    }

    private function getPhpInfo(): array
    {
        $opcacheStatus = $this->getOpcacheStatus();

        return [
            'version' => PHP_VERSION,
            'memory_limit' => (string)ini_get('memory_limit'),
            'max_execution' => (string)ini_get('max_execution_time'),
            'upload_max' => (string)ini_get('upload_max_filesize'),
            'post_max' => (string)ini_get('post_max_size'),
            'opcache_enabled' => $opcacheStatus !== false,
            'gzip_enabled' => extension_loaded('zlib'),
            'brotli_configured' => $this->isHtaccessModuleConfigured('mod_brotli'),
            'deflate_configured' => $this->isHtaccessModuleConfigured('mod_deflate'),
            'opcache_memory_used' => isset($opcacheStatus['memory_usage']['used_memory']) ? (int)$opcacheStatus['memory_usage']['used_memory'] : 0,
            'opcache_memory_free' => isset($opcacheStatus['memory_usage']['free_memory']) ? (int)$opcacheStatus['memory_usage']['free_memory'] : 0,
        ];
    }

    private function getCacheMetrics(): array
    {
        $status = $this->cacheManager->getStatus();
        $warmupStatus = OpcacheWarmupService::getInstance()->getStatus(30);
        $cacheDir = ABSPATH . 'cache/';
        $oldestAge = null;
        $newestAge = null;

        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '*') ?: [];
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $age = time() - (int)filemtime($file);
                $oldestAge = $oldestAge === null ? $age : max($oldestAge, $age);
                $newestAge = $newestAge === null ? $age : min($newestAge, $age);
            }
        }

        $activeDbCache = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}cache WHERE expires_at IS NULL OR expires_at > NOW()") ?? 0);
        $expiredDbCache = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}cache WHERE expires_at IS NOT NULL AND expires_at <= NOW()") ?? 0);

        $apcuInfo = $status['apcu']['info'] ?? null;
        $apcuHits = is_array($apcuInfo) ? (int)($apcuInfo['num_hits'] ?? 0) : 0;
        $apcuMisses = is_array($apcuInfo) ? (int)($apcuInfo['num_misses'] ?? 0) : 0;
        $hitRatio = ($apcuHits + $apcuMisses) > 0 ? round(($apcuHits / ($apcuHits + $apcuMisses)) * 100, 1) : null;

        $fileCacheFiles = (int)($status['file_cache']['files'] ?? 0);
        $fileCacheSizeText = (string)($status['file_cache']['size'] ?? '0 B');
        $fileCacheSizeBytes = $this->getDirSize($cacheDir);

        $healthScore = 100;
        if ($expiredDbCache > 100) {
            $healthScore -= 15;
        }
        if ($oldestAge !== null && $oldestAge > 86400) {
            $healthScore -= 10;
        }
        if ($hitRatio !== null && $hitRatio < 70.0) {
            $healthScore -= 10;
        }

        return [
            'health_score' => max(0, $healthScore),
            'file_cache' => [
                'files' => $fileCacheFiles,
                'size' => $fileCacheSizeText,
                'size_bytes' => $fileCacheSizeBytes,
                'writable' => (bool)($status['file_cache']['writable'] ?? false),
                'directory' => '/cache/',
                'oldest_age' => $oldestAge,
                'newest_age' => $newestAge,
            ],
            'apcu' => [
                'enabled' => (bool)($status['apcu']['enabled'] ?? false),
                'hits' => $apcuHits,
                'misses' => $apcuMisses,
                'hit_ratio' => $hitRatio,
            ],
            'opcache' => [
                'enabled' => (bool)($status['opcache']['enabled'] ?? false),
                'used_memory' => (int)($status['opcache']['status']['memory_usage']['used_memory'] ?? 0),
                'free_memory' => (int)($status['opcache']['status']['memory_usage']['free_memory'] ?? 0),
                'cached_scripts' => (int)($status['opcache']['status']['opcache_statistics']['num_cached_scripts'] ?? 0),
                'hits' => (int)($status['opcache']['status']['opcache_statistics']['hits'] ?? 0),
                'misses' => (int)($status['opcache']['status']['opcache_statistics']['misses'] ?? 0),
                'warmup' => $warmupStatus,
            ],
            'db_cache' => [
                'active_entries' => $activeDbCache,
                'expired_entries' => $expiredDbCache,
            ],
            'purge_preview' => [
                'file_count' => $fileCacheFiles,
                'file_size_bytes' => $fileCacheSizeBytes,
                'apcu_reset' => (bool)($status['apcu']['enabled'] ?? false),
                'opcache_reset' => (bool)($status['opcache']['enabled'] ?? false),
                'restores_runtime_caches' => false,
                'rollback_window_seconds' => $this->safetyNetService->getRollbackWindowSeconds(),
            ],
        ];
    }

    private function getMediaMetrics(): array
    {
        $uploadDir = ABSPATH . 'uploads/';
        $uploadSize = is_dir($uploadDir) ? $this->getDirSize($uploadDir, [$this, 'shouldExcludeMediaPath']) : 0;
        $imageService = ImageService::getInstance();
        $imageSupport = $imageService->getInfo();

        $mediaTotals = [
            'total_files' => 0,
            'total_size' => 0,
            'missing_alt' => 0,
            'webp_files' => 0,
        ];

        try {
            $totals = $this->db->get_row(
                "SELECT COUNT(*) AS total_files,
                        COALESCE(SUM(filesize), 0) AS total_size,
                        SUM(CASE WHEN COALESCE(alt_text, '') = '' THEN 1 ELSE 0 END) AS missing_alt,
                        SUM(CASE WHEN LOWER(filetype) LIKE '%webp%' OR LOWER(filename) LIKE '%.webp' THEN 1 ELSE 0 END) AS webp_files
                 FROM {$this->prefix}media"
            );

            if ($totals !== null) {
                $mediaTotals = [
                    'total_files' => (int)($totals->total_files ?? 0),
                    'total_size' => (int)($totals->total_size ?? 0),
                    'missing_alt' => (int)($totals->missing_alt ?? 0),
                    'webp_files' => (int)($totals->webp_files ?? 0),
                ];
            }
        } catch (\Throwable) {
        }

        $largestImages = [];
        $conversionCandidates = [];
        $convertibleFiles = 0;
        $convertibleBytes = 0;
        if (is_dir($uploadDir)) {
            $iterator = $this->createMediaIterator($uploadDir);
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $extension = strtolower((string)$file->getExtension());
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    continue;
                }

                $dimensions = $this->getImageSize($file->getPathname()) ?: [0, 0];
                $largestImages[] = [
                    'path' => $this->toRelativeRuntimePath($file->getPathname()),
                    'size' => $file->getSize(),
                    'width' => (int)($dimensions[0] ?? 0),
                    'height' => (int)($dimensions[1] ?? 0),
                    'is_webp' => $extension === 'webp',
                ];

                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                    $convertibleFiles++;
                    $convertibleBytes += $file->getSize();
                    $conversionCandidates[] = [
                        'path' => $this->toRelativeRuntimePath($file->getPathname()),
                        'size' => $file->getSize(),
                        'extension' => $extension,
                    ];
                }
            }
        }

        usort($largestImages, static fn(array $a, array $b): int => $b['size'] <=> $a['size']);
        $largestImages = array_slice($largestImages, 0, 10);
        usort($conversionCandidates, static fn(array $a, array $b): int => $b['size'] <=> $a['size']);
        $conversionCandidates = array_slice($conversionCandidates, 0, 15);

        $oversizedCount = 0;
        foreach ($largestImages as $image) {
            if ($image['size'] > 500 * 1024 || $image['width'] > 2560) {
                $oversizedCount++;
            }
        }

        $healthScore = 100;
        if ($mediaTotals['missing_alt'] > 0) {
            $healthScore -= min(25, $mediaTotals['missing_alt']);
        }
        if ($oversizedCount > 0) {
            $healthScore -= min(20, $oversizedCount * 5);
        }

        return [
            'health_score' => max(0, $healthScore),
            'upload_size' => $uploadSize,
            'library' => $mediaTotals,
            'largest_images' => $largestImages,
            'oversized_images' => $oversizedCount,
            'conversion' => [
                'supported' => !empty($imageSupport['available']) && !empty($imageSupport['webp_support']),
                'convertible_files' => $convertibleFiles,
                'convertible_bytes' => $convertibleBytes,
                'candidates' => $conversionCandidates,
            ],
        ];
    }

    private function convertMediaLibraryToWebp(array $post = []): array
    {
        $imageService = ImageService::getInstance();
        $imageInfo = $imageService->getInfo();

        if (empty($imageInfo['available']) || empty($imageInfo['webp_support'])) {
            return ['success' => false, 'error' => 'WebP-Konvertierung ist auf diesem Server nicht verfügbar.'];
        }

        $uploadDir = ABSPATH . 'uploads/';
        if (!is_dir($uploadDir)) {
            return ['success' => false, 'error' => 'Uploads-Verzeichnis nicht gefunden.'];
        }

        $dryRun = (string)($post['webp_mode'] ?? '') === 'dry_run';
        $batchLimit = max(1, min(self::WEBP_MAX_BATCH_LIMIT, (int)($post['webp_batch_limit'] ?? self::WEBP_DEFAULT_BATCH_LIMIT)));
        $deleteOriginals = !$dryRun && !empty($post['webp_replace_originals']);
        $runId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        $converted = 0;
        $skipped = 0;
        $failed = 0;
        $savedBytes = 0;
        $updatedReferences = 0;
        $seenCandidates = 0;
        $manifest = [
            'run_id' => $runId,
            'created_at' => date('c'),
            'delete_originals' => $deleteOriginals,
            'entries' => [],
        ];

        $iterator = $this->createMediaIterator($uploadDir);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $sourcePath = $file->getPathname();
            $extension = strtolower((string)$file->getExtension());
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                continue;
            }

            $seenCandidates++;
            if ($seenCandidates > $batchLimit) {
                break;
            }

            $originalSize = (int)$file->getSize();
            $targetWebpPath = preg_replace('/\.[a-z]+$/i', '.webp', $sourcePath) ?: '';
            if ($targetWebpPath === '' || $targetWebpPath === $sourcePath) {
                $failed++;
                continue;
            }

            if (is_file($targetWebpPath)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $skipped++;
                $savedBytes += 0;
                continue;
            }

            $webpPath = $imageService->convertToWebP($sourcePath, 82, false);
            if ($webpPath === null || !is_file($webpPath)) {
                $failed++;
                continue;
            }

            $webpSize = (int)filesize($webpPath);
            if ($webpSize <= 0 || $webpSize >= $originalSize) {
                if ($webpPath !== $sourcePath && is_file($webpPath)) {
                    $this->deleteFileIfExists($webpPath);
                }
                $skipped++;
                continue;
            }

            $backupPath = null;
            if ($deleteOriginals) {
                $backupPath = $this->backupWebpOriginal($sourcePath, $runId);
                if ($backupPath === null) {
                    $this->deleteFileIfExists($webpPath);
                    $failed++;
                    continue;
                }
            }

            $updatedForFile = $this->replaceMediaReferences($sourcePath, $webpPath, $webpSize, 'image/webp');
            $updatedReferences += $updatedForFile;

            if ($deleteOriginals && is_file($sourcePath)) {
                $this->deleteFileIfExists($sourcePath);
            }

            $manifest['entries'][] = [
                'source_path' => $sourcePath,
                'webp_path' => $webpPath,
                'backup_path' => $backupPath,
                'original_size' => $originalSize,
                'webp_size' => $webpSize,
                'had_existing_webp' => false,
                'updated_references' => $updatedForFile,
            ];
            $converted++;
            $savedBytes += max(0, $originalSize - $webpSize);
        }

        if ($dryRun) {
            return [
                'success' => true,
                'message' => sprintf(
                    'Dry-Run: %d mögliche Bild%s im aktuellen Batch erkannt. Es wurden keine Dateien geändert. Batch-Limit: %d.',
                    min($seenCandidates, $batchLimit),
                    min($seenCandidates, $batchLimit) === 1 ? '' : 'er',
                    $batchLimit
                ),
            ];
        }

        if ($converted === 0 && $failed === 0) {
            return ['success' => true, 'message' => 'Keine geeigneten Bilder für eine kleinere WebP-Version gefunden.'];
        }

        if ($converted > 0) {
            $this->writeWebpManifest($runId, $manifest);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'performance.media.convert_webp',
            'Medienbibliothek in WebP konvertiert',
            'media',
            null,
            [
                'converted' => $converted,
                'skipped' => $skipped,
                'failed' => $failed,
                'saved_bytes' => $savedBytes,
                'updated_references' => $updatedReferences,
                'batch_limit' => $batchLimit,
                'delete_originals' => $deleteOriginals,
                'run_id' => $runId,
            ],
            $failed > 0 ? 'warning' : 'info'
        );

        return [
            'success' => $converted > 0,
            'message' => sprintf(
                '%d Bild%s in WebP umgewandelt, %s eingespart, %d Referenz%s aktualisiert, %d übersprungen, %d fehlgeschlagen. Batch-Limit: %d. Rollback-ID: %s.',
                $converted,
                $converted === 1 ? '' : 'er',
                $this->formatBytes($savedBytes),
                $updatedReferences,
                $updatedReferences === 1 ? '' : 'en',
                $skipped,
                $failed,
                $batchLimit,
                $runId
            ),
        ];
    }

    private function rollbackLastWebpConversion(): array
    {
        $manifestPath = $this->findLatestWebpManifestPath();
        if ($manifestPath === null) {
            return ['success' => false, 'error' => 'Kein WebP-Rollback-Manifest gefunden.'];
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !is_array($manifest['entries'] ?? null)) {
            return ['success' => false, 'error' => 'Das letzte WebP-Rollback-Manifest ist ungültig.'];
        }

        $restored = 0;
        $references = 0;
        $failed = 0;
        foreach ($manifest['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sourcePath = (string)($entry['source_path'] ?? '');
            $webpPath = (string)($entry['webp_path'] ?? '');
            $backupPath = (string)($entry['backup_path'] ?? '');
            $originalSize = (int)($entry['original_size'] ?? 0);

            if ($sourcePath === '' || $webpPath === '') {
                $failed++;
                continue;
            }

            if ($backupPath !== '' && is_file($backupPath) && !is_file($sourcePath)) {
                if (!is_dir(dirname($sourcePath))) {
                    mkdir(dirname($sourcePath), 0775, true);
                }
                if (!rename($backupPath, $sourcePath)) {
                    $failed++;
                    continue;
                }
                $restored++;
            }

            if (is_file($sourcePath)) {
                $references += $this->replaceMediaReferences($webpPath, $sourcePath, $originalSize, $this->guessImageMimeType($sourcePath));
            }

            if (empty($entry['had_existing_webp']) && is_file($webpPath)) {
                $this->deleteFileIfExists($webpPath);
            }
        }

        $rolledBackPath = $manifestPath . '.rolled-back';
        @rename($manifestPath, $rolledBackPath);

        AuditLogger::instance()->log(
            AuditLogger::CAT_MEDIA,
            'performance.media.rollback_webp',
            'Letzte WebP-Konvertierung zurückgerollt',
            'media',
            null,
            ['restored' => $restored, 'references' => $references, 'failed' => $failed, 'manifest' => basename($manifestPath)],
            $failed > 0 ? 'warning' : 'info'
        );

        return [
            'success' => $failed === 0 || $restored > 0 || $references > 0,
            'message' => sprintf('%d Originaldatei%s wiederhergestellt, %d Referenz%s zurückgesetzt, %d fehlgeschlagen.', $restored, $restored === 1 ? '' : 'en', $references, $references === 1 ? '' : 'en', $failed),
        ];
    }

    private function getDatabaseMetrics(): array
    {
        $tables = [];
        $totalSize = 0;
        $totalOverhead = 0;

        try {
            $tables = $this->db->get_results(
                "SELECT table_name,
                        engine,
                        table_rows,
                        data_length,
                        index_length,
                        data_free
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 AND table_name LIKE ?
                 ORDER BY (data_length + index_length) DESC",
                [$this->prefix . '%']
            );
        } catch (\Throwable) {
            $tables = [];
        }

        $tableMetrics = [];
        $optimizeSupportedEngines = ['INNODB', 'MYISAM', 'ARCHIVE'];
        $repairSupportedEngines = ['MYISAM', 'ARCHIVE', 'CSV'];
        $optimizeSupportedCount = 0;
        $repairSupportedCount = 0;
        $tablesWithOverhead = 0;
        foreach ($tables as $table) {
            $size = (int)($table->data_length ?? 0) + (int)($table->index_length ?? 0);
            $overhead = (int)($table->data_free ?? 0);
            $engine = strtoupper((string)($table->engine ?? ''));
            $optimizeSupported = in_array($engine, $optimizeSupportedEngines, true);
            $repairSupported = in_array($engine, $repairSupportedEngines, true);
            $totalSize += $size;
            $totalOverhead += $overhead;

            if ($optimizeSupported) {
                $optimizeSupportedCount++;
            }
            if ($repairSupported) {
                $repairSupportedCount++;
            }
            if ($overhead > 0) {
                $tablesWithOverhead++;
            }

            $tableMetrics[] = [
                'name' => (string)($table->table_name ?? ''),
                'engine' => $engine !== '' ? $engine : 'UNKNOWN',
                'rows' => (int)($table->table_rows ?? 0),
                'size' => $size,
                'overhead' => $overhead,
                'optimize_supported' => $optimizeSupported,
                'repair_supported' => $repairSupported,
            ];
        }

        $revisionCount = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}page_revisions") ?? 0);
        $expiredSessions = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}sessions WHERE expires_at IS NOT NULL AND expires_at <= NOW()") ?? 0);
        $expiredCache = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}cache WHERE expires_at IS NOT NULL AND expires_at <= NOW()") ?? 0);
        $failedLogins24h = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}login_attempts WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") ?? 0);

        $healthScore = 100;
        if ($totalOverhead > 20 * 1024 * 1024) {
            $healthScore -= 20;
        }
        if ($revisionCount > 500) {
            $healthScore -= 10;
        }
        if ($expiredSessions > 0 || $expiredCache > 0) {
            $healthScore -= 10;
        }

        return [
            'health_score' => max(0, $healthScore),
            'total_size_bytes' => $totalSize,
            'total_overhead_bytes' => $totalOverhead,
            'table_count' => count($tableMetrics),
            'top_tables' => array_slice($tableMetrics, 0, 8),
            'maintenance_plan' => array_slice($tableMetrics, 0, 12),
            'maintenance_preview' => [
                'optimize_supported_count' => $optimizeSupportedCount,
                'repair_supported_count' => $repairSupportedCount,
                'optimize_skipped_count' => max(0, count($tableMetrics) - $optimizeSupportedCount),
                'repair_skipped_count' => max(0, count($tableMetrics) - $repairSupportedCount),
                'tables_with_overhead' => $tablesWithOverhead,
                'rollback_window_seconds' => $this->safetyNetService->getRollbackWindowSeconds(),
            ],
            'revision_count' => $revisionCount,
            'expired_sessions' => $expiredSessions,
            'expired_cache_entries' => $expiredCache,
            'failed_logins_last_24h' => $failedLogins24h,
        ];
    }

    private function getSessionMetrics(): array
    {
        $sessionDir = ABSPATH . 'sessions/';
        $sessionDirFiles = is_dir($sessionDir) ? count(glob($sessionDir . '*') ?: []) : 0;
        $sessionDirSize = is_dir($sessionDir) ? $this->getDirSize($sessionDir) : 0;

        $activeSessions = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}sessions WHERE expires_at IS NULL OR expires_at > NOW()") ?? 0);
        $expiredSessions = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}sessions WHERE expires_at IS NOT NULL AND expires_at <= NOW()") ?? 0);
        $recentSessions = [];

        try {
            $recentSessions = $this->db->get_results(
                "SELECT id, user_id, ip_address, user_agent, last_activity, expires_at
                 FROM {$this->prefix}sessions
                 ORDER BY last_activity DESC
                 LIMIT 10"
            );
        } catch (\Throwable) {
            $recentSessions = [];
        }

        $settings = $this->getSettings();
        $healthScore = 100;
        if ($expiredSessions > 50) {
            $healthScore -= 20;
        }
        if ($activeSessions > 200) {
            $healthScore -= 10;
        }

        return [
            'health_score' => max(0, $healthScore),
            'active_sessions' => $activeSessions,
            'expired_sessions' => $expiredSessions,
            'session_dir_files' => $sessionDirFiles,
            'session_dir_size' => $sessionDirSize,
            'recent_sessions' => array_map(function (object $session): array {
                return [
                    'id' => (string)($session->id ?? ''),
                    'user_id' => (int)($session->user_id ?? 0),
                    'ip_address' => $this->maskIpAddress((string)($session->ip_address ?? '')),
                    'user_agent' => $this->sanitizeUserAgent((string)($session->user_agent ?? '')),
                    'last_activity' => (string)($session->last_activity ?? ''),
                    'expires_at' => (string)($session->expires_at ?? ''),
                ];
            }, $recentSessions),
            'timeouts' => [
                'admin' => (int)$settings['perf_session_timeout_admin'],
                'member' => (int)$settings['perf_session_timeout_member'],
            ],
        ];
    }

    /** @param array<string, mixed> $sectionData @return array<string, mixed> */
    private function getCapacityPrecheck(string $section, array $sectionData = []): array
    {
        $diskTotal = @disk_total_space(ABSPATH);
        $diskFree = @disk_free_space(ABSPATH);
        $diskUsed = ($diskTotal !== false && $diskFree !== false) ? max(0, (int) $diskTotal - (int) $diskFree) : 0;
        $diskUsedPercent = ($diskTotal !== false && (int) $diskTotal > 0)
            ? round(($diskUsed / (int) $diskTotal) * 100, 1)
            : null;

        $recommendedFreeBytes = $this->getRecommendedCapacityReserveBytes($section, $sectionData);
        $loadAverage = $this->getLoadAverageSnapshot();
        $activeJobs = $this->getActiveBackgroundCapacityJobs();
        $warnings = [];

        if ($diskFree === false || $diskTotal === false) {
            $warnings[] = [
                'level' => 'warning',
                'title' => 'Freien Speicher derzeit nicht messbar',
                'detail' => 'Der Server liefert aktuell keine belastbaren Disk-Werte. Optimierungsjobs sollten nur mit zusätzlicher Vorsicht gestartet werden.',
            ];
        } else {
            if ((int) $diskFree < max(self::CAPACITY_CRITICAL_FREE_BYTES, (int) round($recommendedFreeBytes * 0.5))) {
                $warnings[] = [
                    'level' => 'danger',
                    'title' => 'Kritisch wenig freier Speicher',
                    'detail' => 'Nur ' . $this->formatBytes((int) $diskFree) . ' frei; empfohlener Puffer für diesen Job: ' . $this->formatBytes($recommendedFreeBytes) . '.',
                ];
            } elseif ((int) $diskFree < max(self::CAPACITY_WARNING_FREE_BYTES, $recommendedFreeBytes)) {
                $warnings[] = [
                    'level' => 'warning',
                    'title' => 'Freier Speicher knapp',
                    'detail' => 'Aktuell frei: ' . $this->formatBytes((int) $diskFree) . '; empfohlener Puffer: ' . $this->formatBytes($recommendedFreeBytes) . '.',
                ];
            }

            if ($diskUsedPercent !== null && $diskUsedPercent >= 90.0) {
                $warnings[] = [
                    'level' => $diskUsedPercent >= 95.0 ? 'danger' : 'warning',
                    'title' => 'Hohe Disk-Auslastung',
                    'detail' => 'Die Partition unter ABSPATH ist bereits zu ' . number_format((float) $diskUsedPercent, 1, ',', '.') . ' % belegt.',
                ];
            }
        }

        $loadOneMinute = $loadAverage['one_minute'];
        if ($loadOneMinute !== null) {
            if ($loadOneMinute >= self::CAPACITY_CRITICAL_LOAD) {
                $warnings[] = [
                    'level' => 'danger',
                    'title' => 'Hohe Systemlast',
                    'detail' => '1-Minuten-Load liegt bei ' . number_format($loadOneMinute, 2, ',', '.') . '.',
                ];
            } elseif ($loadOneMinute >= self::CAPACITY_WARNING_LOAD) {
                $warnings[] = [
                    'level' => 'warning',
                    'title' => 'Erhöhte Systemlast',
                    'detail' => '1-Minuten-Load liegt bei ' . number_format($loadOneMinute, 2, ',', '.') . '.',
                ];
            }
        }

        if ($activeJobs !== []) {
            $warnings[] = [
                'level' => 'warning',
                'title' => 'Parallele Hintergrundjobs erkannt',
                'detail' => implode(' · ', array_map(static fn(array $job): string => (string) ($job['detail'] ?? $job['label'] ?? ''), $activeJobs)),
            ];
        }

        $status = 'success';
        foreach ($warnings as $warning) {
            if (($warning['level'] ?? '') === 'danger') {
                $status = 'danger';
                break;
            }

            if (($warning['level'] ?? '') === 'warning') {
                $status = 'warning';
            }
        }

        return [
            'status' => $status,
            'status_label' => $status === 'danger' ? 'Engpass' : ($status === 'warning' ? 'Beobachten' : 'Bereit'),
            'disk_total_bytes' => $diskTotal !== false ? (int) $diskTotal : 0,
            'disk_free_bytes' => $diskFree !== false ? (int) $diskFree : 0,
            'disk_used_percent' => $diskUsedPercent,
            'recommended_free_bytes' => $recommendedFreeBytes,
            'load_1m' => $loadAverage['one_minute'],
            'load_5m' => $loadAverage['five_minutes'],
            'load_15m' => $loadAverage['fifteen_minutes'],
            'active_jobs' => $activeJobs,
            'active_job_count' => count($activeJobs),
            'warnings' => $warnings,
            'confirm_suffix' => $this->buildCapacityConfirmSuffix($diskFree !== false ? (int) $diskFree : null, $diskUsedPercent, $recommendedFreeBytes, $loadAverage, $activeJobs),
        ];
    }

    /** @param array<string, mixed> $sectionData */
    private function getRecommendedCapacityReserveBytes(string $section, array $sectionData): int
    {
        return match ($section) {
            'cache' => max(
                268435456,
                min(2147483648, (int) (($sectionData['cache']['purge_preview']['file_size_bytes'] ?? 0)))
            ),
            'database' => max(
                1073741824,
                min(4294967296, (int) round(((int) ($sectionData['database']['total_size_bytes'] ?? 0)) * 0.10))
            ),
            'media' => max(
                536870912,
                min(2147483648, $this->estimateMediaBatchReserveBytes((array) ($sectionData['media']['conversion']['candidates'] ?? [])))
            ),
            default => self::CAPACITY_WARNING_FREE_BYTES,
        };
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function estimateMediaBatchReserveBytes(array $candidates): int
    {
        $bytes = 0;
        $count = 0;

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $bytes += max(0, (int) ($candidate['size'] ?? 0));
            ++$count;

            if ($count >= self::WEBP_DEFAULT_BATCH_LIMIT) {
                break;
            }
        }

        return $bytes > 0 ? $bytes * 2 : 536870912;
    }

    /** @return array{one_minute:?float,five_minutes:?float,fifteen_minutes:?float} */
    private function getLoadAverageSnapshot(): array
    {
        $load = false;
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
        } elseif (function_exists('getloadavg')) {
            $load = @getloadavg();
        }

        if (!is_array($load)) {
            return [
                'one_minute' => null,
                'five_minutes' => null,
                'fifteen_minutes' => null,
            ];
        }

        $normalize = static function (mixed $value): ?float {
            return is_numeric($value) ? round((float) $value, 2) : null;
        };

        return [
            'one_minute' => $normalize($load[0] ?? null),
            'five_minutes' => $normalize($load[1] ?? null),
            'fifteen_minutes' => $normalize($load[2] ?? null),
        ];
    }

    /** @return list<array<string, string>> */
    private function getActiveBackgroundCapacityJobs(): array
    {
        $jobs = [];

        $mailQueueJobs = $this->countActiveMailQueueLocks();
        if ($mailQueueJobs > 0) {
            $jobs[] = [
                'label' => 'Mail-Queue',
                'detail' => $mailQueueJobs . ' Mail-Queue-Job(s) aktuell gesperrt oder in Verarbeitung.',
            ];
        }

        $mediaJob = $this->getActiveMediaDerivativeJobSignal();
        if ($mediaJob !== null) {
            $jobs[] = $mediaJob;
        }

        return $jobs;
    }

    private function countActiveMailQueueLocks(): int
    {
        $tableName = $this->prefix . 'mail_queue';
        if (!$this->tableExistsByFullName($tableName)) {
            return 0;
        }

        try {
            return (int) ($this->db->get_var(
                "SELECT COUNT(*) FROM {$tableName} WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at >= DATE_SUB(NOW(), INTERVAL " . self::ACTIVE_QUEUE_LOCK_WINDOW_MINUTES . " MINUTE)"
            ) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, string>|null */
    private function getActiveMediaDerivativeJobSignal(): ?array
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'media-processing-job.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > self::MEDIA_JOB_STATUS_MAX_FILE_BYTES) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? 'queued')));
        $total = max(0, (int) ($decoded['total'] ?? 0));
        $processed = max(0, min($total, (int) ($decoded['processed'] ?? $decoded['cursor'] ?? 0)));
        if (!in_array($status, ['queued', 'running'], true) || $processed >= $total) {
            return null;
        }

        return [
            'label' => 'Medien-Derivat-Job',
            'detail' => 'Medien-Derivat-Job aktiv: ' . $processed . ' / ' . $total . ' Datei(en), Status ' . $status . '.',
        ];
    }

    private function tableExistsByFullName(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }

        try {
            $quotedTable = $this->db->getPdo()->quote($tableName);
            if (!is_string($quotedTable)) {
                $this->tableExistsCache[$tableName] = false;

                return false;
            }

            $result = $this->db->getPdo()->query('SHOW TABLES LIKE ' . $quotedTable);
            $this->tableExistsCache[$tableName] = $result !== false && $result->fetchColumn() !== false;
        } catch (\Throwable) {
            $this->tableExistsCache[$tableName] = false;
        }

        return $this->tableExistsCache[$tableName];
    }

    /**
     * @param array{one_minute:?float,five_minutes:?float,fifteen_minutes:?float} $loadAverage
     * @param list<array<string, string>> $activeJobs
     */
    private function buildCapacityConfirmSuffix(?int $diskFreeBytes, ?float $diskUsedPercent, int $recommendedFreeBytes, array $loadAverage, array $activeJobs): string
    {
        $lines = [
            '- Freier Speicher: ' . ($diskFreeBytes !== null ? $this->formatBytes($diskFreeBytes) : 'unbekannt'),
            '- Empfohlener Puffer: ' . $this->formatBytes($recommendedFreeBytes),
            '- Disk-Auslastung: ' . ($diskUsedPercent !== null ? number_format($diskUsedPercent, 1, ',', '.') . ' %' : 'unbekannt'),
            '- Last (1m): ' . ($loadAverage['one_minute'] !== null ? number_format((float) $loadAverage['one_minute'], 2, ',', '.') : 'nicht verfügbar'),
        ];

        if ($activeJobs === []) {
            $lines[] = '- Aktive Hintergrundjobs: keine erkannt';
        } else {
            $lines[] = '- Aktive Hintergrundjobs: ' . implode(' · ', array_map(static fn(array $job): string => (string) ($job['label'] ?? 'Job'), $activeJobs));
        }

        return "\n\nPre-Check:\n" . implode("\n", $lines);
    }

    private function clearAllCacheLayers(): array
    {
        $snapshot = $this->safetyNetService->createCacheSnapshot('clear_all_cache');
        if (empty($snapshot['success'])) {
            return ['success' => false, 'error' => (string)($snapshot['error'] ?? 'Vor der Cache-Bereinigung konnte kein Snapshot erstellt werden.')];
        }

        $snapshotInfo = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : [];
        $report = $this->cacheManager->clearAll();
        $warmup = (!empty($report['opcache']))
            ? OpcacheWarmupService::getInstance()->warmTopFiles(30, true)
            : ['success' => false, 'message' => 'Warmup übersprungen, weil OPcache nicht geleert wurde.'];
        $details = [];
        foreach (($report['details'] ?? []) as $label => $message) {
            $details[] = $label . ': ' . $message;
        }
        if (!empty($report['opcache'])) {
            $details[] = 'warmup: ' . ($warmup['message'] ?? 'nicht ausgeführt');
        }

        // ADDED: Vollständige Cache-Bereinigung im Audit-Log erfassen.
        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.cache.clear_all',
            'Alle Cache-Layer bereinigt',
            'cache',
            null,
            [
                'details' => $this->sanitizeAuditArray((array)($report['details'] ?? [])),
                'warmup' => $this->summarizeWarmupResult($warmup),
                'snapshot_id' => $this->sanitizeAuditString((string)($snapshotInfo['snapshot_id'] ?? ''), 64),
            ],
            'warning'
        );

        Hooks::doAction('performance_cache_purged', 'all', $report);
        Hooks::doAction('performance_cdn_purge_requested', [
            'scope' => 'all',
            'source' => 'performance.cache.clear_all',
            'purged_at' => date('c'),
        ]);

        return [
            'success' => true,
            'message' => 'Alle Cache-Layer bereinigt. ' . implode(' | ', $details) . $this->buildCacheRollbackHint($snapshotInfo),
        ];
    }

    private function clearFileCache(): array
    {
        $snapshot = $this->safetyNetService->createCacheSnapshot('clear_file_cache');
        if (empty($snapshot['success'])) {
            return ['success' => false, 'error' => (string)($snapshot['error'] ?? 'Vor der Cache-Bereinigung konnte kein Snapshot erstellt werden.')];
        }

        $snapshotInfo = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : [];
        $cacheDir = ABSPATH . 'cache/';
        if (!is_dir($cacheDir)) {
            return ['success' => false, 'error' => 'Cache-Verzeichnis nicht gefunden.'];
        }

        $count = 0;
        foreach (glob($cacheDir . '*') ?: [] as $file) {
            if (is_link($file)) {
                continue;
            }

            if (is_file($file) && unlink($file)) {
                $count++;
            }
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.cache.clear_file',
            'Datei-Cache geleert',
            'cache',
            null,
            [
                'deleted_files' => $count,
                'snapshot_id' => $this->sanitizeAuditString((string)($snapshotInfo['snapshot_id'] ?? ''), 64),
            ],
            'warning'
        );

        Hooks::doAction('performance_cache_purged', 'file', ['deleted_files' => $count]);
        Hooks::doAction('performance_cdn_purge_requested', [
            'scope' => 'file',
            'source' => 'performance.cache.clear_file',
            'purged_at' => date('c'),
        ]);

        return ['success' => true, 'message' => $count . ' Datei-Cache(s) gelöscht.' . $this->buildCacheRollbackHint($snapshotInfo)];
    }

    private function rollbackCacheCleanup(): array
    {
        $rollback = $this->safetyNetService->rollbackLatestCacheSnapshot();
        $snapshotInfo = is_array($rollback['snapshot'] ?? null) ? $rollback['snapshot'] : [];

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.cache.rollback',
            'Cache-Bereinigung zurückgerollt',
            'cache',
            null,
            [
                'snapshot_id' => $this->sanitizeAuditString((string)($snapshotInfo['snapshot_id'] ?? ''), 64),
                'restored_files' => isset($rollback['restored_files']) ? (int)$rollback['restored_files'] : null,
            ],
            !empty($rollback['success']) ? 'warning' : 'error'
        );

        if (empty($rollback['success'])) {
            return ['success' => false, 'error' => (string)($rollback['error'] ?? $rollback['message'] ?? 'Das Cache-Rollback ist fehlgeschlagen.')];
        }

        return ['success' => true, 'message' => (string)($rollback['message'] ?? 'Das Cache-Rollback wurde ausgeführt.')];
    }

    private function clearOpcache(): array
    {
        if (!function_exists('opcache_reset')) {
            return ['success' => false, 'error' => 'OPcache ist nicht verfügbar.'];
        }

        opcache_reset();
        $warmup = OpcacheWarmupService::getInstance()->warmTopFiles(30, true);
        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.cache.clear_opcache',
            'OPcache zurückgesetzt',
            'cache',
            null,
            ['warmup' => $this->summarizeWarmupResult($warmup)],
            'warning'
        );

        $message = 'OPcache wurde geleert. ' . ($warmup['message'] ?? 'Warmup nicht ausgeführt.');

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    private function warmupOpcache(): array
    {
        $warmup = OpcacheWarmupService::getInstance()->warmTopFiles(30, true);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.cache.warmup_opcache',
            'OPcache-Warmup ausgeführt',
            'cache',
            null,
            $this->summarizeWarmupResult($warmup),
            $warmup['success'] ?? false ? 'info' : 'warning'
        );

        return [
            'success' => (bool)($warmup['success'] ?? false),
            'message' => (string)($warmup['message'] ?? 'OPcache-Warmup wurde ausgeführt.'),
            'error' => empty($warmup['success']) ? (string)($warmup['message'] ?? 'OPcache-Warmup fehlgeschlagen.') : null,
        ];
    }

    private function optimizeDatabase(): array
    {
        $metrics = $this->getDatabaseMetrics();
        $preview = $metrics['maintenance_preview'] ?? [];
        if ((int)($preview['optimize_supported_count'] ?? 0) <= 0) {
            return ['success' => false, 'error' => 'Es wurden keine unterstützten Tabellen für OPTIMIZE TABLE gefunden.'];
        }

        $snapshot = $this->safetyNetService->createDatabaseSnapshot('optimize_database');
        if (empty($snapshot['success'])) {
            return ['success' => false, 'error' => (string)($snapshot['error'] ?? 'Vor der Datenbank-Wartung konnte kein Backup erstellt werden.')];
        }

        $snapshotInfo = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : [];
        $result = $this->systemService->optimizeTables();
        $success = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($result as $row) {
            if (!empty($row['success'])) {
                $success++;
            } elseif (!empty($row['skipped'])) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.database.optimize',
            'Datenbanktabellen optimiert',
            'database',
            null,
            [
                'success_count' => $success,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'snapshot_id' => $this->sanitizeAuditString((string)($snapshotInfo['snapshot_id'] ?? ''), 64),
                'backup_name' => $this->sanitizeAuditString((string)($snapshotInfo['backup_name'] ?? ''), 120),
            ],
            $failed > 0 ? 'error' : 'warning'
        );

        return [
            'success' => $failed === 0 || $success > 0 || $skipped > 0,
            'message' => sprintf('%d Tabelle(n) optimiert, %d übersprungen, %d fehlgeschlagen.', $success, $skipped, $failed) . $this->buildDatabaseRollbackHint($snapshotInfo),
        ];
    }

    private function repairDatabase(): array
    {
        $metrics = $this->getDatabaseMetrics();
        $preview = $metrics['maintenance_preview'] ?? [];
        if ((int)($preview['repair_supported_count'] ?? 0) <= 0) {
            return ['success' => false, 'error' => 'Es wurden keine unterstützten Tabellen für REPAIR TABLE gefunden.'];
        }

        $snapshot = $this->safetyNetService->createDatabaseSnapshot('repair_tables');
        if (empty($snapshot['success'])) {
            return ['success' => false, 'error' => (string)($snapshot['error'] ?? 'Vor der Datenbank-Wartung konnte kein Backup erstellt werden.')];
        }

        $snapshotInfo = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : [];
        $result = $this->systemService->repairTables();
        $success = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($result as $row) {
            if (!empty($row['success'])) {
                $success++;
            } elseif (!empty($row['skipped'])) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.database.repair',
            'Datenbanktabellen geprüft oder repariert',
            'database',
            null,
            [
                'success_count' => $success,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'snapshot_id' => $this->sanitizeAuditString((string)($snapshotInfo['snapshot_id'] ?? ''), 64),
                'backup_name' => $this->sanitizeAuditString((string)($snapshotInfo['backup_name'] ?? ''), 120),
            ],
            $failed > 0 ? 'error' : 'warning'
        );

        return [
            'success' => $failed === 0 || $success > 0 || $skipped > 0,
            'message' => sprintf('%d Tabelle(n) repariert bzw. geprüft, %d übersprungen, %d fehlgeschlagen.', $success, $skipped, $failed) . $this->buildDatabaseRollbackHint($snapshotInfo),
        ];
    }

    private function rollbackDatabaseMaintenance(): array
    {
        $rollback = $this->safetyNetService->rollbackLatestDatabaseSnapshot();
        $snapshotInfo = is_array($rollback['snapshot'] ?? null) ? $rollback['snapshot'] : [];

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.database.rollback',
            'Datenbank-Wartung zurückgerollt',
            'database',
            null,
            [
                'snapshot_id' => $this->sanitizeAuditString((string)($snapshotInfo['snapshot_id'] ?? ''), 64),
                'rollback_backup' => $this->sanitizeAuditString((string)($rollback['rollback_backup'] ?? ''), 120),
            ],
            !empty($rollback['success']) ? 'warning' : 'error'
        );

        if (empty($rollback['success'])) {
            return ['success' => false, 'error' => (string)($rollback['error'] ?? $rollback['message'] ?? 'Das Datenbank-Rollback ist fehlgeschlagen.')];
        }

        return ['success' => true, 'message' => (string)($rollback['message'] ?? 'Das Datenbank-Rollback wurde ausgeführt.')];
    }

    private function clearExpiredSessions(): array
    {
        $dbDeleted = false;
        try {
            $this->db->execute("DELETE FROM {$this->prefix}sessions WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
            $dbDeleted = true;
        } catch (\Throwable) {
        }

        $sessionDir = ABSPATH . 'sessions/';
        $fileCount = 0;
        if (is_dir($sessionDir)) {
            $settings = $this->getSettings();
            $sessionLifetime = max(
                300,
                (int)($settings['perf_session_timeout_admin'] ?? self::DEFAULT_SETTINGS['perf_session_timeout_admin']),
                (int)($settings['perf_session_timeout_member'] ?? self::DEFAULT_SETTINGS['perf_session_timeout_member'])
            );
            $threshold = time() - $sessionLifetime;
            foreach (glob($sessionDir . '*') ?: [] as $file) {
                if (is_link($file)) {
                    continue;
                }

                if (is_file($file) && filemtime($file) < $threshold && unlink($file)) {
                    $fileCount++;
                }
            }
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'performance.sessions.clear_expired',
            'Abgelaufene Sessions bereinigt',
            'session',
            null,
            ['db_deleted' => $dbDeleted, 'file_deleted' => $fileCount, 'file_lifetime_seconds' => $sessionLifetime ?? null],
            'warning'
        );

        return [
            'success' => true,
            'message' => 'Abgelaufene Sessions bereinigt' . ($dbDeleted ? ' (DB)' : '') . '; Dateisessions gelöscht: ' . $fileCount . '.',
        ];
    }

    private function saveSettings(array $post, string $action = 'save_settings'): array
    {
        $settings = $this->getSettings();
        $settingKeys = self::SETTING_KEYS_BY_ACTION[$action] ?? self::SETTING_KEYS_BY_ACTION['save_settings'];
        $settingsToSave = [];

        foreach ($settingKeys as $key) {
            $default = $settings[$key] ?? self::DEFAULT_SETTINGS[$key] ?? '0';
            if (in_array($key, self::BOOLEAN_SETTING_KEYS, true)) {
                $settingsToSave[$key] = !empty($post[$key]) ? '1' : '0';
                continue;
            }

            $settingsToSave[$key] = match ($key) {
                'perf_browser_cache_ttl' => $this->normalizeBrowserCacheTtl($post[$key] ?? $default),
                'perf_html_cache_ttl' => (string)max(0, min(86400, (int)($post[$key] ?? $default))),
                'perf_lazy_loading_eager_images' => (string)max(0, min(5, (int)($post[$key] ?? $default))),
                'perf_session_timeout_admin' => (string)max(300, min(604800, (int)($post[$key] ?? $default))),
                'perf_session_timeout_member' => (string)max(300, min(31536000, (int)($post[$key] ?? $default))),
                default => (string)max(0, (int)($post[$key] ?? $default)),
            };
        }

        try {
            $existing = $this->loadExistingSettingNames(array_keys($settingsToSave));

            foreach ($settingsToSave as $key => $value) {
                if (isset($existing[$key])) {
                    $this->db->update('settings', ['option_value' => $value], ['option_name' => $key]);
                } else {
                    $this->db->insert('settings', ['option_name' => $key, 'option_value' => $value]);
                }
            }

            if (array_key_exists('perf_session_timeout_admin', $settingsToSave) || array_key_exists('perf_session_timeout_member', $settingsToSave)) {
                // index.php hält die Session-GC-Lebensdauer 10 Minuten im Cache.
                @unlink(ABSPATH . 'cache/session_gc_lifetime.cache');
            }

            if ($action === 'save_media_settings' || array_key_exists('perf_webp_uploads', $settingsToSave) || array_key_exists('perf_strip_exif', $settingsToSave)) {
                $mediaService = MediaService::getInstance();
                $mediaSettings = $mediaService->getSettings();
                if (array_key_exists('perf_webp_uploads', $settingsToSave)) {
                    $mediaSettings['auto_webp'] = $settingsToSave['perf_webp_uploads'] === '1';
                }
                if (array_key_exists('perf_strip_exif', $settingsToSave)) {
                    $mediaSettings['strip_exif'] = $settingsToSave['perf_strip_exif'] === '1';
                }
                $mediaResult = $mediaService->saveSettings($mediaSettings);
                if ($mediaResult instanceof \CMS\WP_Error) {
                    throw new \RuntimeException($mediaResult->get_error_message());
                }
            }

            if (array_key_exists('perf_browser_cache', $settingsToSave) || array_key_exists('perf_browser_cache_ttl', $settingsToSave)) {
                $policyResult = MediaService::getInstance()->syncUploadDirectoryPolicy();
                if ($policyResult instanceof \CMS\WP_Error) {
                    throw new \RuntimeException($policyResult->get_error_message());
                }
            }
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'performance.settings.save_failed',
                'Performance-Einstellungen konnten nicht gespeichert werden',
                'setting',
                null,
                ['exception' => $this->sanitizeAuditString($e->getMessage())],
                'error'
            );

            return ['success' => false, 'error' => 'Performance-Einstellungen konnten nicht gespeichert werden. Bitte Logs prüfen.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'performance.settings.save',
            'Performance-Einstellungen gespeichert',
            'setting',
            null,
            $settingsToSave,
            'warning'
        );

        return ['success' => true, 'message' => 'Performance-Einstellungen gespeichert.'];
    }

    private function normalizeBrowserCacheTtl(mixed $value): string
    {
        $ttl = (string) max(0, (int) $value);

        return in_array($ttl, self::BROWSER_CACHE_TTL_OPTIONS, true)
            ? $ttl
            : self::DEFAULT_SETTINGS['perf_browser_cache_ttl'];
    }

    /** @param list<string> $keys @return array<string, true> */
    private function loadExistingSettingNames(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $keys
        ) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $name = (string)($row->option_name ?? '');
            if ($name !== '') {
                $existing[$name] = true;
            }
        }

        return $existing;
    }

    private function getDirSize(string $dir, ?callable $excludePath = null): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                continue;
            }

            if ($excludePath !== null && $excludePath($file->getPathname())) {
                continue;
            }

            $size += $file->getSize();
        }

        return $size;
    }

    private function createMediaIterator(string $uploadDir): \RecursiveIteratorIterator
    {
        $directoryIterator = new \RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (\SplFileInfo $current): bool {
                if ($current->isLink()) {
                    return false;
                }

                return !$this->shouldExcludeMediaPath($current->getPathname());
            }
        );

        return new \RecursiveIteratorIterator($filter);
    }

    private function shouldExcludeMediaPath(string $path): bool
    {
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        foreach (self::MEDIA_EXCLUDED_PATH_PARTS as $excludedPart) {
            $normalizedExcludedPart = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $excludedPart);
            if (str_contains($normalizedPath, $normalizedExcludedPart)) {
                return true;
            }
        }

        return false;
    }

    private function replaceMediaReferences(string $sourcePath, string $targetPath, int $targetSize, string $targetMimeType): int
    {
        $oldRelative = ltrim(str_replace('\\', '/', str_replace(ABSPATH, '', $sourcePath)), '/');
        $newRelative = ltrim(str_replace('\\', '/', str_replace(ABSPATH, '', $targetPath)), '/');
        $oldPublic = '/' . $oldRelative;
        $newPublic = '/' . $newRelative;
        $oldUrl = rtrim((string)SITE_URL, '/') . $oldPublic;
        $newUrl = rtrim((string)SITE_URL, '/') . $newPublic;

        $updates = 0;

        $stmt = $this->db->prepare(
            "UPDATE {$this->prefix}media
             SET filename = ?, filepath = ?, filetype = ?, filesize = ?
             WHERE REPLACE(filepath, '\\\\', '/') IN (?, ?, ?) OR filename = ?"
        );
        $stmt->execute([
            basename($targetPath),
            $newRelative,
            $targetMimeType,
            $targetSize,
            $oldRelative,
            $oldPublic,
            $oldUrl,
            basename($sourcePath),
        ]);
        $updates += $stmt->rowCount();

        foreach ([
            ['table' => 'pages', 'columns' => ['featured_image', 'content']],
            ['table' => 'posts', 'columns' => ['featured_image', 'content', 'excerpt']],
            ['table' => 'seo_meta', 'columns' => ['og_image', 'twitter_image']],
        ] as $target) {
            foreach ($target['columns'] as $column) {
                $updates += $this->replaceInColumn($target['table'], $column, $oldUrl, $newUrl);
                $updates += $this->replaceInColumn($target['table'], $column, $oldPublic, $newPublic);
                $updates += $this->replaceInColumn($target['table'], $column, $oldRelative, $newRelative);
            }
        }

        return $updates;
    }

    private function backupWebpOriginal(string $sourcePath, string $runId): ?string
    {
        $relative = ltrim(str_replace('\\', '/', str_replace(ABSPATH, '', $sourcePath)), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $backupPath = ABSPATH . 'cache/performance-webp-backups/' . $runId . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath), 0775, true);
        }

        return copy($sourcePath, $backupPath) ? $backupPath : null;
    }

    private function writeWebpManifest(string $runId, array $manifest): void
    {
        $dir = ABSPATH . 'cache/performance-webp-manifests/';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir . $runId . '.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function findLatestWebpManifestPath(): ?string
    {
        $files = glob(ABSPATH . 'cache/performance-webp-manifests/*.json') ?: [];
        if ($files === []) {
            return null;
        }

        usort($files, static fn(string $a, string $b): int => ((int)filemtime($b)) <=> ((int)filemtime($a)));
        return $files[0] ?? null;
    }

    private function guessImageMimeType(string $path): string
    {
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function replaceInColumn(string $table, string $column, string $search, string $replace): int
    {
        if ($search === '' || $search === $replace) {
            return 0;
        }

        $stmt = $this->db->prepare(
            "UPDATE {$this->prefix}{$table}
             SET {$column} = REPLACE({$column}, ?, ?)
             WHERE {$column} IS NOT NULL AND {$column} LIKE ?"
        );
        $stmt->execute([$search, $replace, '%' . $search . '%']);

        return $stmt->rowCount();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }

    private function getOpcacheStatus(): array|false
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        return $this->runSuppressedOperation(static fn() => opcache_get_status(false));
    }

    private function getImageSize(string $path): array|false
    {
        return $this->runSuppressedOperation(static fn() => getimagesize($path));
    }

    private function isHtaccessModuleConfigured(string $moduleName): bool
    {
        $path = ABSPATH . '.htaccess';
        if (!is_file($path)) {
            return false;
        }

        $content = (string)file_get_contents($path);
        return str_contains($content, '<IfModule ' . $moduleName . '.c>');
    }

    private function deleteFileIfExists(string $path): bool
    {
        if (is_link($path)) {
            return false;
        }

        if (!is_file($path)) {
            return true;
        }

        return unlink($path);
    }

    private function toRelativeRuntimePath(string $path): string
    {
        $normalizedBase = str_replace('\\', '/', rtrim(ABSPATH, '\\/')) . '/';
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalizedPath, $normalizedBase)) {
            return ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');
        }

        return basename($normalizedPath);
    }

    private function maskIpAddress(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';

                return implode('.', $parts);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $segments = explode(':', $ip);
            $segments = array_pad(array_slice($segments, 0, 4), 4, '');

            return implode(':', $segments) . '::';
        }

        return $this->sanitizeAuditString($ip, 64);
    }

    private function sanitizeUserAgent(string $userAgent): string
    {
        return $this->sanitizeAuditString($userAgent, 160);
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    private function sanitizeAuditArray(array $values): array
    {
        $sanitized = [];
        foreach ($values as $key => $value) {
            $encodedValue = is_scalar($value)
                ? (string)$value
                : ((json_encode($value, JSON_UNESCAPED_UNICODE) ?: ''));
            $sanitized[(string)$key] = $this->sanitizeAuditString($encodedValue, 160);
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $warmup */
    private function summarizeWarmupResult(array $warmup): array
    {
        return [
            'success' => !empty($warmup['success']),
            'message' => $this->sanitizeAuditString((string)($warmup['message'] ?? ''), 160),
            'processed' => isset($warmup['processed']) ? (int)$warmup['processed'] : null,
            'warmed' => isset($warmup['warmed']) ? (int)$warmup['warmed'] : null,
            'failed' => isset($warmup['failed']) ? (int)$warmup['failed'] : null,
        ];
    }

    private function sanitizeAuditString(string $value, int $maxLength = self::MAX_AUDIT_STRING_LENGTH): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    /** @param array<string, mixed> $snapshotInfo */
    private function buildCacheRollbackHint(array $snapshotInfo): string
    {
        $expiresAt = trim((string)($snapshotInfo['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return '';
        }

        return ' Rollback des Datei-Cache-Snapshots bis ' . $expiresAt . ' über die Cache-Seite verfügbar; APCu und OPcache werden dabei nicht konserviert, sondern danach regulär neu aufgebaut.';
    }

    /** @param array<string, mixed> $snapshotInfo */
    private function buildDatabaseRollbackHint(array $snapshotInfo): string
    {
        $expiresAt = trim((string)($snapshotInfo['expires_at'] ?? ''));
        $backupName = trim((string)($snapshotInfo['backup_name'] ?? ''));
        if ($expiresAt === '' || $backupName === '') {
            return '';
        }

        return ' Rollback bis ' . $expiresAt . ' über das vorab erstellte DB-Backup ' . $backupName . ' verfügbar.';
    }

    private function runSuppressedOperation(callable $operation): mixed
    {
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}

}
