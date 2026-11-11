<?php
/**
 * Registriert die Hooks der Core-Funktionsbereiche (Routen, Cron, Inhalts-Hooks, Aufbewahrung, Hinweise).
 *
 * Jeder Bereich besitzt eine eigene Klasse mit `public static function register(): void` in diesem
 * Namespace. Fehlt eine Klasse oder wirft sie, bleiben die übrigen Bereiche aktiv; der Fehler wird
 * protokolliert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class Registry
{
    /** @var list<class-string> Reihenfolge = Registrierungsreihenfolge */
    private const FEATURES = [
        \CMS\Services\IntegratedPluginService::class,
        AdminMenuHooks::class,
        FeedHooks::class,
        DownloadHooks::class,
        FormHooks::class,
        TrashHooks::class,
        ToolbarHooks::class,
        SystemFeatureHooks::class,
        EditorialCalendarHooks::class,
        ContentOverviewHooks::class,
        SearchIndexHooks::class,
        ContentExchangeHooks::class,
        MediaFeatureHooks::class,
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        foreach (self::FEATURES as $class) {
            if (!class_exists($class) || !method_exists($class, 'register')) {
                continue;
            }

            try {
                $class::register();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('core.features')->error('Funktionsbereich konnte nicht registriert werden.', [
                    'feature' => $class,
                    'exception' => $e,
                ]);
            }
        }
    }
}
