<?php
/**
 * Der Modul-Schalter `community` an einer Stelle: Slug und fail-closed Abfrage.
 *
 * `CoreModuleService::isModuleEnabled()` liefert für unbekannte Slugs `true`; ein Tippfehler im Slug würde die Prüfung
 * still abschalten. Deshalb steht der Slug nur hier, und die Suite `community-settings` stellt sicher, dass er in
 * `CoreModuleService::MODULES` vorkommt. Das Modul ist standardmäßig aus; bei jeder Störung (Datenbank nicht erreichbar,
 * Dienst fehlt) gilt es als aus.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Services\CoreModuleService;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityModule
{
    public const SLUG = 'community';

    /** @var \Closure(): bool|null Ersatz für die Abfrage des CoreModuleService (nur Tests) */
    private static ?\Closure $gate = null;

    public static function isEnabled(): bool
    {
        try {
            if (self::$gate !== null) {
                return (self::$gate)() === true;
            }

            return class_exists(CoreModuleService::class) && CoreModuleService::getInstance()->isModuleEnabled(self::SLUG);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Nur für Tests: ersetzt die Abfrage des Modulzustands (null = CoreModuleService).
     *
     * @param \Closure(): bool|null $gate
     */
    public static function setGate(?\Closure $gate): void
    {
        self::$gate = $gate;
    }
}
