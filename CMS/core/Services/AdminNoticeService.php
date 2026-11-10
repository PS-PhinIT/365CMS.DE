<?php
/**
 * Systemhinweise für den Fußbereich der Admin-Seitenleiste (nur Administratoren).
 *
 * Prüft: letzter Cron-Lauf fehlgeschlagen, letztes Backup älter als 24 Stunden (oder keins),
 * verfügbare Core- bzw. Plugin-Updates. Das Gesamtergebnis wird 5 Minuten gecacht, die teure
 * Plugin-Update-Prüfung (Remote-Abfragen) zusätzlich 1 Stunde.
 *
 * @package 365CMS
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\CacheManager;
use CMS\Logger;
use CMS\PluginManager;
use CMS\Version;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminNoticeService
{
    public const CACHE_KEY = 'admin_system_notices_v1';
    public const CACHE_TTL = 300;
    private const PLUGIN_UPDATES_CACHE_KEY = 'admin_system_notices_plugin_updates_v1';
    private const PLUGIN_UPDATES_CACHE_TTL = 3600;
    private const BACKUP_MAX_AGE = 86400;

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array{notices:list<array{id:string,severity:string,title:string,text:string,url:string,link_label:string}>,generated_at:int}
     */
    public function getNotices(bool $forceRefresh = false): array
    {
        $cache = CacheManager::instance();
        if (!$forceRefresh) {
            $cached = $cache->get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['notices']) && is_array($cached['notices'])) {
                return $cached;
            }
        }

        $notices = [];
        $checkFailed = false;
        foreach ([
            'Cron-Status' => $this->cronNotice(...),
            'Backup-Status' => $this->backupNotice(...),
            'Core-Updates' => $this->coreUpdateNotice(...),
            'Plugin-Updates' => $this->pluginUpdateNotice(...),
        ] as $checkName => $check) {
            try {
                $notice = $check();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('admin.ui')->warning('Systemstatus-Prüfung fehlgeschlagen.', [
                    'check' => $checkName,
                    'exception_class' => get_debug_type($e),
                    'exception' => $e->getMessage(),
                ]);
                $checkFailed = true;
                $notice = null;
            }
            if ($notice !== null) {
                $notices[] = $notice;
            }
        }
        if ($checkFailed) {
            $notices[] = $this->notice(
                'check_failed',
                'warning',
                'Systemstatus nicht vollständig geprüft',
                'Mindestens eine Systemprüfung ist fehlgeschlagen. Öffnen Sie die Diagnose.',
                '/admin/diagnose',
                'Diagnose öffnen'
            );
        }

        $payload = ['notices' => $notices, 'generated_at' => time()];
        $cache->set(self::CACHE_KEY, $payload, self::CACHE_TTL);

        return $payload;
    }

    public function clearCache(): void
    {
        $cache = CacheManager::instance();
        $cache->delete(self::CACHE_KEY);
        $cache->delete(self::PLUGIN_UPDATES_CACHE_KEY);
    }

    /**
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}|null
     */
    private function cronNotice(): ?array
    {
        $settings = SettingsService::getInstance();
        if ($settings->getString('cron', 'last_run_status', '') !== 'failed') {
            return null;
        }

        $lastRunAt = (int) $settings->getString('cron', 'last_run_at', '0');
        $task = $settings->getString('cron', 'last_run_task', '');
        $text = $lastRunAt > 0
            ? sprintf('Der Cron-Lauf am %s Uhr%s ist fehlgeschlagen.', date('d.m.Y H:i', $lastRunAt), $task !== '' ? ' (Aufgabe „' . $task . '“)' : '')
            : 'Der letzte Cron-Lauf ist fehlgeschlagen.';

        return $this->notice('cron_failed', 'danger', 'Cron-Lauf fehlgeschlagen', $text, '/admin/monitor-cron-status', 'Cron-Status öffnen');
    }

    /**
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}|null
     */
    private function backupNotice(): ?array
    {
        $overview = BackupService::getInstance()->getBackupOverview();
        $latest = is_array($overview['latest'] ?? null) ? $overview['latest'] : null;
        $timestamp = (int) ($latest['timestamp'] ?? 0);

        if ($timestamp <= 0) {
            return $this->notice('backup_missing', 'warning', 'Kein Backup vorhanden', 'Es wurde noch kein vollständiges Backup gefunden.', '/admin/backups', 'Backups öffnen');
        }

        if ((time() - $timestamp) <= self::BACKUP_MAX_AGE) {
            return null;
        }

        return $this->notice(
            'backup_outdated',
            'warning',
            'Backup älter als 24 Stunden',
            sprintf('Das letzte Backup stammt vom %s Uhr.', date('d.m.Y H:i', $timestamp)),
            '/admin/backups',
            'Backups öffnen'
        );
    }

    /**
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}|null
     */
    private function coreUpdateNotice(): ?array
    {
        $result = UpdateService::getInstance()->checkCoreUpdates();
        $latest = (string) ($result['latest_version'] ?? '');
        $current = defined('CMS_VERSION') ? (string) CMS_VERSION : Version::CURRENT;
        if ($latest === '' || !version_compare($latest, $current, '>')) {
            return null;
        }

        return $this->notice(
            'core_update',
            'info',
            'Core-Update verfügbar',
            sprintf('365CMS %s ist verfügbar (installiert: %s).', $latest, $current),
            '/admin/updates',
            'Updates öffnen'
        );
    }

    /**
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}|null
     */
    private function pluginUpdateNotice(): ?array
    {
        $cache = CacheManager::instance();
        $known = $cache->get(self::PLUGIN_UPDATES_CACHE_KEY);
        if (!is_array($known)) {
            $known = [];
            foreach (UpdateService::getInstance()->checkPluginUpdates() as $folder => $update) {
                $known[(string) $folder] = [
                    'name' => (string) ($update['name'] ?? $folder),
                    'new_version' => (string) ($update['new_version'] ?? ''),
                ];
            }
            $cache->set(self::PLUGIN_UPDATES_CACHE_KEY, $known, self::PLUGIN_UPDATES_CACHE_TTL);
        }

        // Bereits installierte Updates sofort ausblenden, ohne erneute Remote-Abfrage.
        $installed = PluginManager::instance()->getAvailablePlugins();
        $pending = [];
        foreach ($known as $folder => $update) {
            $current = (string) ($installed[$folder]['version'] ?? '');
            $newVersion = (string) ($update['new_version'] ?? '');
            if ($current !== '' && $newVersion !== '' && version_compare($newVersion, $current, '>')) {
                $pending[] = (string) ($update['name'] ?? $folder);
            }
        }

        if ($pending === []) {
            return null;
        }

        $count = count($pending);
        $names = implode(', ', array_slice($pending, 0, 3)) . ($count > 3 ? ' …' : '');

        return $this->notice(
            'plugin_updates',
            'info',
            $count === 1 ? '1 Plugin-Update verfügbar' : $count . ' Plugin-Updates verfügbar',
            $names,
            '/admin/updates',
            'Updates öffnen'
        );
    }

    /**
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}
     */
    private function notice(string $id, string $severity, string $title, string $text, string $url, string $linkLabel): array
    {
        return [
            'id' => $id,
            'severity' => $severity,
            'title' => $title,
            'text' => $text,
            'url' => $url,
            'link_label' => $linkLabel,
        ];
    }
}
