<?php
/**
 * Release-Prüfung 4.1.0 (SEC-410-26): Admin-Seiten, die sich über ihre eigene Capability an Rollen ohne
 * Admin-Status delegieren lassen. Alle übrigen Admin-Seiten verlangen weiter die Rolle „admin“.
 *
 * Bewusst klein gehalten: nur die Seiten der 4.1.0-Funktionen, deren Capability genau diesen Bereich abgrenzt.
 * Systemweite Bereiche (Backups, Einstellungen, Benutzer, Plugins, Updates) bleiben Admins vorbehalten.
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

use CMS\Auth;

if (!defined('ABSPATH')) {
    exit;
}

final class DelegatedAdminAccess
{
    /** @var array<string, string> Seiten-Slug => Capability */
    public const PAGES = [
        'payments' => 'payments.manage',
        'invoices' => 'invoices.view',
        'reusable-blocks' => 'blocks.reusable',
        'webhooks' => 'webhooks.manage',
    ];

    public static function capabilityFor(string $page): ?string
    {
        return self::PAGES[$page] ?? null;
    }

    /** Darf das aktuelle Konto die Seite öffnen: Admin mit Capability oder delegierte Rolle mit Capability. */
    public static function canAccess(string $page, ?string $capability = null): bool
    {
        $auth = Auth::instance();
        $capability ??= self::capabilityFor($page);
        if ($capability === null || !$auth->isLoggedIn() || !$auth->hasCapability($capability)) {
            return false;
        }

        return $auth->isAdmin() || isset(self::PAGES[$page]);
    }

    /** Nur der Router: delegierte Seite und Capability vorhanden (die Seite prüft danach selbst). */
    public static function routerAllows(string $page): bool
    {
        return isset(self::PAGES[$page]) && self::canAccess($page);
    }
}
