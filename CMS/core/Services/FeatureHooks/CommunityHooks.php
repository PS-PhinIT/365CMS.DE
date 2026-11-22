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
use CMS\Services\AdminNoticeService;
use CMS\Services\Community\CommunityMemberSection;
use CMS\Services\Community\CommunityPrivacy;
use CMS\Services\Community\CommunityRetention;
use CMS\Services\Community\Http\ApiController;
use CMS\Services\Community\Http\AttachmentController;
use CMS\Services\Community\Http\ComposeController;
use CMS\Services\Community\Http\PublicController;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
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
        // Immer (auch bei abgeschaltetem Modul): sonst bleiben Rechte-Zeilen umbenannter oder gelöschter Rollen veraltet und eine neue
        // Rolle mit dem alten Slug erbte sie.
        Hooks::addAction('cms_role_renamed', static function (mixed $from = '', mixed $to = ''): void {
            self::guard(static fn () => self::roleRenamed((string) $from, (string) $to));
        });
        Hooks::addAction('cms_role_deleted', static function (mixed $role = ''): void {
            self::guard(static fn () => self::roleDeleted((string) $role));
        });

        // Datenschutz und Aufbewahrung gelten immer, auch bei abgeschaltetem Modul: die Daten bleiben beim Abschalten erhalten.
        Hooks::addAction('dsgvo_export_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard(static fn () => CommunityPrivacy::handleExport($userId, $email));
        }, 12);
        Hooks::addAction('dsgvo_delete_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard(static fn () => CommunityPrivacy::handleDelete($userId, $email));
        }, 12);
        // Konto endgültig gelöscht (auch ohne Löschantrag über den Admin): dieselbe Anonymisierung, idempotent.
        Hooks::addAction('cms_user_deleted', static function (mixed $userId = null): void {
            self::guard(static fn () => CommunityPrivacy::handleDelete($userId, null));
        }, 12);
        Hooks::addFilter('cms_member_export_data', static function (mixed $export = [], mixed $userId = 0): mixed {
            return CommunityPrivacy::memberExport($export, $userId);
        }, 26);
        Hooks::addAction('cms_init_admin', static function (): void {
            self::guard(static fn () => CommunityRetention::registerAll());
        }, 5);
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard(static fn () => CommunityRetention::registerAll());
        }, 1);
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard(static fn () => CommunityPrivacy::pruneExports());
        }, 47);

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

        // Öffentliche Routen (vor den Beitragsmustern des Routers registrieren).
        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (is_object($router)) {
                // Schreibseiten zuerst: ihre Muster sind enger als `topic_page`/`forum_page`.
                self::guard(static fn () => ApiController::instance()->registerRoutes($router));
                self::guard(static fn () => AttachmentController::instance()->registerRoutes($router));
                self::guard(static fn () => ComposeController::instance()->registerRoutes($router));
                self::guard(static fn () => PublicController::instance()->registerRoutes($router));
            }
        }, 20);

        // Mitgliederbereich /member/plugin/community.
        Hooks::addAction('member_dashboard_init', static function (mixed $registry = null): void {
            if ($registry instanceof \CMS\Member\PluginDashboardRegistry) {
                self::guard(static fn () => CommunityMemberSection::register($registry));
            }
        });

        // Dauerhaftes CSRF-Token für die JSON-API (mehrere Aufrufe je Seite); der Router prüft es vor der Route.
        Hooks::addFilter(\CMS\Router::PUBLIC_FORM_TOKEN_FILTER, [ApiController::class, 'tokenFilter'], 10);

        // Hinweis im Admin: Warteschlange wartet länger als 24 Stunden.
        AdminNoticeService::registerCheck('Community-Warteschlange', static fn (): ?array => self::queueNotice());

        Hooks::addAction('user_role_changed', static function (mixed $userId = 0): void {
            self::guard(static fn () => self::forgetUser((int) $userId));
        });
        Hooks::addAction('user_email_verified', static function (mixed $userId = 0): void {
            self::guard(static fn () => self::forgetUser((int) $userId));
        });
    }

    private static function roleRenamed(string $from, string $to): void
    {
        (new ForumRepository())->renameRoleSubject($from, $to, gmdate('Y-m-d H:i:s'));
        CommunityAccess::instance()->flush();
    }

    private static function roleDeleted(string $role): void
    {
        (new ForumRepository())->deleteRoleSubject($role, gmdate('Y-m-d H:i:s'));
        CommunityAccess::instance()->flush();
    }

    /** @return array<string, string>|null */
    private static function queueNotice(): ?array
    {
        if (!Auth::instance()->hasCapability('community.moderate')) {
            return null;
        }
        $summary = (new PostRepository())->queueSummary(null);
        $oldest = $summary['oldest_at'] ?? null;
        $ts = is_string($oldest) && $oldest !== '' ? strtotime($oldest . ' UTC') : false;
        if ($ts === false || $ts > time() - 86400) {
            return null;
        }

        return AdminNoticeService::makeNotice(
            'community-queue-old',
            'warning',
            'Community: Beiträge warten auf Freigabe',
            sprintf('%d Beitrag/Beiträge warten seit mehr als 24 Stunden in der Warteschlange.', (int) $summary['count']),
            '/admin/community-queue',
            'Freigaben öffnen'
        );
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
