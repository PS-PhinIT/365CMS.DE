<?php
/**
 * Hooks des Funktionsbereichs „Papierkorb“.
 *
 * - meldet die Papierkorb-Provider für Beiträge, Seiten, Medien und Hub-Sites bei der
 *   `ContentTypeRegistry` an (`cms_register_content_type_providers`),
 * - registriert die Aufbewahrungsregel `trash`: der tägliche Cron (`cms_cron_daily` →
 *   `DataRetentionService::run()`) löscht Einträge, die länger als die eingestellte Frist im
 *   Papierkorb liegen (0 = nie), über `expiredIds()`/`deletePermanently()` aller Provider.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\DataRetentionService;
use CMS\Services\Retention\RetentionRule;
use CMS\Services\Trash\HubSiteTrashProvider;
use CMS\Services\Trash\MediaTrashProvider;
use CMS\Services\Trash\PageTrashProvider;
use CMS\Services\Trash\PostTrashProvider;
use CMS\Services\Trash\TrashService;
use CMS\Services\Trash\TrashSupport;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashHooks
{
    public static function register(): void
    {
        Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
            $registry->register(new PostTrashProvider());
            $registry->register(new PageTrashProvider());
            $registry->register(new MediaTrashProvider());
            $registry->register(new HubSiteTrashProvider());
        }, 5);

        Hooks::addAction('cms_init', [self::class, 'registerRetentionRule'], 20);
    }

    public static function registerRetentionRule(): void
    {
        $translate = static fn (string $key): string => function_exists('__') ? (string) __($key) : $key;
        $service = TrashService::getInstance();

        DataRetentionService::registerRule(new RetentionRule(
            'trash',
            $translate('trash.retention.rule_label'),
            $translate('trash.retention.rule_description'),
            static fn (): int => $service->retentionDays(),
            static fn (): int => $service->countExpired(),
            static fn (): int => $service->purgeExpired()['deleted'],
            TrashSupport::centralPageUrl(),
        ));
    }
}
