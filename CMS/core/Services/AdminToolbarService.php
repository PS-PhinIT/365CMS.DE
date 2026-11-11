<?php
/**
 * Aktionen der Admin-Toolbar: Cache leeren (nach Bereich) und Wartungsmodus schalten.
 *
 * Bereiche:
 * - `all`     Seiten-/Objekt-Cache, optimierte Assets und – falls vorhanden – OPcache (danach erneut aufgewärmt),
 * - `pages`   Seiten-/Objekt-Cache (APCu, Datei-Cache, Cache-Tabelle, LiteSpeed-Purge),
 * - `assets`  minimierte CSS/JS unter `cache/optimized-assets/` (werden beim nächsten Aufruf neu erzeugt),
 * - `opcache` OPcache zurücksetzen und die meistgenutzten Dateien neu laden (`OpcacheWarmupService`).
 *
 * Der Wartungsmodus ist die bestehende Option `maintenance_mode` (Router liefert Besuchern 503).
 * Jede Aktion wird im Audit-Log festgehalten; die Berechtigungsprüfung (`manage_system`) und CSRF liegen beim
 * Aufrufer (`admin/admin-ui.php`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;
use CMS\CacheManager;
use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminToolbarService
{
    public const CAPABILITY = 'manage_system';
    public const CACHE_SCOPES = ['all', 'pages', 'assets', 'opcache'];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function opcacheAvailable(): bool
    {
        if (!function_exists('opcache_reset') || !function_exists('opcache_get_status')) {
            return false;
        }
        try {
            $status = @opcache_get_status(false);
        } catch (\Throwable) {
            return false;
        }

        return is_array($status) && !empty($status['opcache_enabled']);
    }

    public static function maintenanceActive(): bool
    {
        try {
            return OptionStore::getInstance()->get('maintenance_mode', '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{success:bool,message:string,details:list<string>}
     */
    public function clearCache(string $scope, int $userId): array
    {
        if (!in_array($scope, self::CACHE_SCOPES, true)) {
            return ['success' => false, 'message' => $this->t('toolbar.cache.error_scope'), 'details' => []];
        }

        $details = [];
        $ok = true;
        if ($scope === 'all' || $scope === 'pages') {
            $pagesOk = $this->clearPageCache();
            $ok = $ok && $pagesOk;
            $details[] = $this->t($pagesOk ? 'toolbar.cache.done_pages' : 'toolbar.cache.failed_pages');
        }
        if ($scope === 'all' || $scope === 'assets') {
            $count = $this->clearOptimizedAssets();
            $ok = $ok && $count >= 0;
            $details[] = $count >= 0 ? sprintf($this->t('toolbar.cache.done_assets'), $count) : $this->t('toolbar.cache.failed_assets');
        }
        if ($scope === 'all' || $scope === 'opcache') {
            if (self::opcacheAvailable()) {
                $warmed = $this->resetOpcache();
                $ok = $ok && $warmed >= 0;
                $details[] = $warmed >= 0 ? sprintf($this->t('toolbar.cache.done_opcache'), $warmed) : $this->t('toolbar.cache.failed_opcache');
            } elseif ($scope === 'opcache') {
                return ['success' => false, 'message' => $this->t('toolbar.cache.opcache_unavailable'), 'details' => []];
            }
        }

        try {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SYSTEM,
                'system.cache.clear_toolbar',
                sprintf('Cache über die Admin-Toolbar geleert (Bereich: %s).', $scope),
                'system',
                null,
                ['scope' => $scope, 'details' => $details, 'user_id' => $userId],
                'info'
            );
        } catch (\Throwable) {
        }

        return [
            'success' => $ok,
            'message' => $this->t($ok ? 'toolbar.cache.done_' . $scope . '_title' : 'toolbar.cache.partial'),
            'details' => $details,
        ];
    }

    /**
     * @return array{success:bool,message:string,active:bool}
     */
    public function setMaintenance(bool $active, int $userId): array
    {
        $before = self::maintenanceActive();
        try {
            $db = Database::instance();
            $prefix = $db->getPrefix();
            $db->execute(
                "INSERT INTO {$prefix}settings (option_name, option_value) VALUES ('maintenance_mode', ?)
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                [$active ? '1' : '0']
            );
            OptionStore::getInstance()->flush();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.toolbar')->error('Wartungsmodus konnte nicht gespeichert werden.', ['exception' => $e->getMessage()]);

            return ['success' => false, 'message' => $this->t('toolbar.maintenance.error'), 'active' => $before];
        }

        if ($before !== $active) {
            try {
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    $active ? 'system.maintenance.enabled' : 'system.maintenance.disabled',
                    $active ? 'Wartungsmodus über die Admin-Toolbar eingeschaltet.' : 'Wartungsmodus über die Admin-Toolbar ausgeschaltet.',
                    'setting',
                    null,
                    ['user_id' => $userId],
                    'warning'
                );
            } catch (\Throwable) {
            }
            // Gecachte Seiten dürfen den alten Zustand nicht weiter ausliefern.
            $this->clearPageCache();
        }

        return [
            'success' => true,
            'message' => $this->t($active ? 'toolbar.maintenance.enabled' : 'toolbar.maintenance.disabled'),
            'active' => $active,
        ];
    }

    private function clearPageCache(): bool
    {
        $ok = true;
        try {
            $ok = CacheManager::instance()->clear();
        } catch (\Throwable) {
            $ok = false;
        }
        try {
            $ok = SystemService::instance()->clearCache() && $ok;
        } catch (\Throwable) {
            $ok = false;
        }
        try {
            FeedService::invalidatePublicFeedCache();
        } catch (\Throwable) {
        }

        return $ok;
    }

    /** Anzahl gelöschter Dateien, -1 bei Fehler. */
    private function clearOptimizedAssets(): int
    {
        $directory = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'optimized-assets';
        if (!is_dir($directory)) {
            return 0;
        }
        $root = realpath($directory);
        if ($root === false) {
            return -1;
        }

        $count = 0;
        foreach (glob($root . DIRECTORY_SEPARATOR . '*.min.{css,js}', GLOB_BRACE) ?: [] as $file) {
            $resolved = realpath($file);
            if ($resolved !== false && dirname($resolved) === $root && is_file($resolved) && !is_link($file) && unlink($resolved)) {
                $count++;
            }
        }

        return $count;
    }

    /** Anzahl neu geladener Dateien, -1 bei Fehler. */
    private function resetOpcache(): int
    {
        try {
            if (!opcache_reset()) {
                return -1;
            }
            $warm = OpcacheWarmupService::getInstance()->warmTopFiles();

            return (int) ($warm['compiled'] ?? 0);
        } catch (\Throwable) {
            return -1;
        }
    }

    private function t(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
    }
}
