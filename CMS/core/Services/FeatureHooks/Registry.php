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
        FeedHooks::class,
        DownloadHooks::class,
        KnowledgebaseHooks::class,
        NewsletterHooks::class,
        FormHooks::class,
        TrashHooks::class,
        SystemFeatureHooks::class,
        EditorialCalendarHooks::class,
        ContentOverviewHooks::class,
        SearchIndexHooks::class,
        ContentExchangeHooks::class,
        MediaFeatureHooks::class,
    ];

    private static bool $registered = false;

    /**
     * Markup eines Eintrags für den Hook `main_nav` – wie die Menüeinträge des Themes cms-default
     * (Desktop: reines `<a>`, Mobil: `mobile-nav-link`; aktiv mit `active` und `aria-current`).
     */
    public static function navItem(string $href, string $label, bool $active = false, string $context = 'desktop'): string
    {
        $classes = trim(($context === 'mobile' ? 'mobile-nav-link ' : '') . ($active ? 'active' : ''));

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
            . ($classes !== '' ? ' class="' . $classes . '"' : '')
            . ($active ? ' aria-current="page"' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        foreach (self::FEATURES as $class) {
            if (!class_exists($class) || !method_exists($class, 'register')) {
                Logger::instance()->withChannel('core.features')->warning('Funktionsbereich fehlt oder besitzt keine register()-Methode.', [
                    'feature' => $class,
                ]);
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
