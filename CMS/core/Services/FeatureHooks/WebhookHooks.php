<?php
/**
 * Ausgehende Webhooks (4.1.0, Bereich 4b): Core-Ereignisse in die Warteschlange legen, im Cron zustellen und die
 * Löschfrist des Zustellprotokolls registrieren. Nutzdaten enthalten IDs statt personenbezogener Daten.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Content\ContentStatus;
use CMS\Services\DataRetentionService;
use CMS\Services\Retention\RetentionRule;
use CMS\Services\Webhooks\WebhookService;

if (!defined('ABSPATH')) {
    exit;
}

final class WebhookHooks
{
    public const RETENTION_RULE = 'webhooks.deliveries';
    public const RETENTION_DAYS = 30;

    public static function register(): void
    {
        Hooks::addAction('cms_backup_offsite_uploaded', static function (mixed $backup = '', mixed $bytes = 0): void {
            self::dispatch('backup.offsite_uploaded', ['backup' => (string) $backup, 'bytes' => (int) $bytes]);
        });
        Hooks::addAction('cms_backup_offsite_failed', static function (mixed $backup = '', mixed $error = ''): void {
            self::dispatch('backup.offsite_failed', ['backup' => (string) $backup, 'error' => mb_substr((string) $error, 0, 300)]);
        });
        Hooks::addAction('cms_content_submitted', static function (mixed $type = '', mixed $id = 0, mixed $userId = 0): void {
            self::dispatch('content.submitted', ['type' => (string) $type, 'id' => (int) $id, 'user_id' => (int) $userId]);
        });
        // Übergang in „veröffentlicht“ (Speichern, Freigabe, Sammelaktion); bei Datum in der Zukunft mit published_at.
        Hooks::addAction('cms_content_status_changed', static function (mixed $type = '', mixed $id = 0, mixed $from = '', mixed $to = ''): void {
            if ((string) $to === ContentStatus::PUBLISHED && !ContentStatus::isPublishing((string) $from)) {
                self::dispatch('content.published', ['type' => (string) $type, 'id' => (int) $id]);
            }
        });
        Hooks::addAction('user_registered', static function (mixed $userId = 0): void {
            self::dispatch('user.registered', ['user_id' => (int) $userId]);
        });

        foreach (['cms_cron_mail_queue', 'cms_cron_hourly'] as $cron) {
            Hooks::addAction($cron, static function (): void {
                self::guard(static fn () => WebhookService::getInstance()->deliverDue());
            });
        }

        Hooks::addAction('cms_init', [self::class, 'registerRetentionRule'], 20);
    }

    public static function registerRetentionRule(): void
    {
        $service = WebhookService::getInstance();
        DataRetentionService::registerRule(new RetentionRule(
            self::RETENTION_RULE,
            'Webhook-Zustellprotokoll',
            'Zugestellte und aufgegebene Webhook-Zustellungen nach 30 Tagen löschen.',
            static fn (): int => self::RETENTION_DAYS,
            static fn (): int => $service->countPurgeable(self::RETENTION_DAYS),
            static fn (): int => $service->purgeDeliveries(self::RETENTION_DAYS),
            '/admin/webhooks',
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function dispatch(string $event, array $payload): void
    {
        self::guard(static fn () => WebhookService::getInstance()->dispatch($event, $payload));
    }

    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('webhooks')->warning('Webhook-Aktion fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
