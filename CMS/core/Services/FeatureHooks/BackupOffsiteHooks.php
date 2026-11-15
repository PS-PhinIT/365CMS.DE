<?php
/**
 * Offsite-Backups (4.1.0, Bereich 4a): nach jedem erfolgreichen Backup kopieren (`cms_backup_created`), täglich
 * fehlgeschlagene Uploads nachholen und die Aufbewahrung anwenden.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Backup\OffsiteBackupService;

if (!defined('ABSPATH')) {
    exit;
}

final class BackupOffsiteHooks
{
    public static function register(): void
    {
        Hooks::addAction('cms_backup_created', static function (mixed $backupName = ''): void {
            self::guard(static fn () => OffsiteBackupService::getInstance()->handleBackupCreated((string) $backupName));
        });
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard(static fn () => OffsiteBackupService::getInstance()->retryPending());
        });
    }

    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup.offsite')->warning('Offsite-Backup: Aktion fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
