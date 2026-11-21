<?php
/**
 * Community-Modul (4.2.0): meldet die Listener an, die den Rechte-Cache eines Benutzers verwerfen, wenn sich seine
 * Rolle ändert (`user_role_changed`) oder seine E-Mail-Adresse bestätigt wird (`user_email_verified`).
 *
 * Fail-closed Modul-Gate: Ist das Modul `community` aus oder nicht prüfbar, registriert diese Klasse nichts. Zur
 * Registrierungszeit gibt es keinen weiteren Datenbankzugriff; ein Umschalten unter /admin/modules wirkt ab dem
 * nächsten Request. Routen, Cron und DSGVO-Hooks folgen mit den PRs #4 und #5.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Auth;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\AdminMenu\BadgeProvider;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\ReportService;
use CMS\Services\Community\Support\CommunityModule;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityHooks
{
    public static function register(): void
    {
        if (!CommunityModule::isEnabled()) {
            return;
        }

        // Admin-Menü (PR #3): Zähler im Moderationsbereich des angemeldeten Kontos, nie gecacht.
        BadgeProvider::register(
            BadgeProvider::COMMUNITY_QUEUE,
            static fn (): int => self::currentUserId() > 0 ? (int) (ModerationService::instance()->queueSummary(self::currentUserId())['count'] ?? 0) : 0
        );
        BadgeProvider::register(
            BadgeProvider::COMMUNITY_REPORTS,
            static fn (): int => self::currentUserId() > 0 ? ReportService::instance()->countOpen(self::currentUserId()) : 0
        );

        Hooks::addAction('user_role_changed', static function (mixed $userId = 0): void {
            self::guard(static fn () => self::forgetUser((int) $userId));
        });
        Hooks::addAction('user_email_verified', static function (mixed $userId = 0): void {
            self::guard(static fn () => self::forgetUser((int) $userId));
        });
    }

    private static function currentUserId(): int
    {
        try {
            return (int) (Auth::instance()->currentUser()->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Rolle und E-Mail-Status gehen in die Rechteprüfung ein: der Cache des Benutzers wird verworfen. */
    private static function forgetUser(int $userId): void
    {
        if ($userId > 0 && class_exists(CommunityAccess::class)) {
            CommunityAccess::instance()->forgetUser($userId);
        }
    }

    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Community-Aktion fehlgeschlagen: {exception}', ['exception' => $e]);
        }
    }
}
