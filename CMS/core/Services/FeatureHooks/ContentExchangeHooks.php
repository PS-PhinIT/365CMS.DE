<?php
/**
 * Hooks des Inhalts-Export/-Imports (System → Import/Export).
 *
 * - Export/Import-Provider des Cores: Kategorien, Schlagwörter, Medien, Seiten, Beiträge, Hub-Sites
 *   (Formulare meldet `FormHooks` an).
 * - Täglicher Cron: alte Arbeits- und Staging-Verzeichnisse sowie Exporte (älter als 48 Stunden) entfernen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\Exchange\ContentExchangeService;
use CMS\Services\Exchange\ContentRowExchangeProvider;
use CMS\Services\Exchange\HubSiteExchangeProvider;
use CMS\Services\Exchange\MediaExchangeProvider;
use CMS\Services\Exchange\TaxonomyExchangeProvider;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentExchangeHooks
{
    public static function register(): void
    {
        Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
            $registry->register(new TaxonomyExchangeProvider('category'));
            $registry->register(new TaxonomyExchangeProvider('tag'));
            $registry->register(new MediaExchangeProvider());
            $registry->register(new ContentRowExchangeProvider('page'));
            $registry->register(new ContentRowExchangeProvider('post'));
            $registry->register(new HubSiteExchangeProvider());
        }, 30);

        Hooks::addAction('cms_cron_daily', static function (): void {
            try {
                ContentExchangeService::getInstance()->cleanup();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('exchange')->warning('Aufräumen der Import/Export-Dateien fehlgeschlagen.', ['exception' => $e->getMessage()]);
            }
        }, 50);
    }
}
