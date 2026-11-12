<?php
/**
 * Hooks des Newsletters (ehemals Plugin cms-newsletter, seit 4.0.0 im Core).
 *
 * - Öffentliche Seiten `/newsletter`, Anmeldung, Bestätigung, Abmeldung (inkl. RFC-8058-One-Click), Webversion.
 * - Versand in Blöcken über den Mail-Queue-Cron und stündlich (`NewsletterDispatcher::run()`).
 * - Löschfrist `newsletter.pending`: unbestätigte Anmeldungen nach `confirm_expiry_days` Tagen entfernen.
 * - DSGVO-Auskunft/-Löschung, Selbstauskunft im Mitgliederbereich, Mitgliederbereich „Newsletter“.
 * - Optionaler Menüpunkt (`main_nav`), Stylesheet nur auf Newsletter-Seiten bzw. bei eingebettetem Formular.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\DataRetentionService;
use CMS\Services\Newsletter\NewsletterDispatcher;
use CMS\Services\Newsletter\NewsletterFrontendController;
use CMS\Services\Newsletter\NewsletterInstaller;
use CMS\Services\Newsletter\NewsletterMemberSection;
use CMS\Services\Newsletter\NewsletterPrivacy;
use CMS\Services\Newsletter\NewsletterRepository;
use CMS\Services\Newsletter\NewsletterSettings;
use CMS\Services\Retention\RetentionRule;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterHooks
{
    public const RETENTION_RULE = 'newsletter.pending';

    public static function register(): void
    {
        Hooks::addAction('cms_init', static function (): void {
            self::guard('Schema-Prüfung', static fn () => NewsletterInstaller::maybeUpgrade());
            self::guard('Löschfrist', static fn () => self::registerRetentionRule());
        }, 10);

        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (is_object($router) && method_exists($router, 'addRoute')) {
                NewsletterFrontendController::registerRoutes($router);
            }
        }, 20);

        foreach (['cms_cron_mail_queue' => 20, 'cms_cron_hourly' => 30] as $hook => $priority) {
            Hooks::addAction($hook, static function (): void {
                self::guard('Versand', static fn () => NewsletterDispatcher::run());
            }, $priority);
        }

        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard('Aufräumen der DSGVO-Exporte', static fn () => NewsletterPrivacy::pruneExports());
        }, 46);

        Hooks::addAction('dsgvo_export_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard('DSGVO-Auskunft', static fn () => NewsletterPrivacy::handleExport($userId, $email));
        }, 12);
        Hooks::addAction('dsgvo_delete_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard('DSGVO-Löschung', static fn () => NewsletterPrivacy::handleDelete($userId, $email));
        }, 12);
        Hooks::addFilter('cms_member_export_data', static function (mixed $export = [], mixed $userId = 0, mixed $email = ''): mixed {
            if (!is_array($export)) {
                return $export;
            }
            try {
                $entries = NewsletterPrivacy::collect((int) $userId, is_string($email) ? $email : '');
                if ($entries !== []) {
                    $export['newsletter'] = $entries;
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('newsletter')->warning('Selbstauskunft: Newsletter nicht ergänzt.', ['error' => $e->getMessage()]);
            }

            return $export;
        }, 25);

        Hooks::addAction('member_dashboard_init', static function (mixed $registry = null): void {
            if ($registry instanceof \CMS\Member\PluginDashboardRegistry) {
                self::guard('Mitgliederbereich', static fn () => NewsletterMemberSection::register($registry));
            }
        }, 25);

        Hooks::addAction('main_nav', static function (mixed $context = 'desktop'): void {
            self::guard('Menüpunkt', static fn () => NewsletterFrontendController::renderNavItem(is_string($context) ? $context : 'desktop'));
        }, 35);

        Hooks::addAction('head', static function (): void {
            if (!NewsletterFrontendController::isNewsletterRequest()) {
                return;
            }
            $href = function_exists('cms_asset_url') ? cms_asset_url('css/newsletter-public.css') : rtrim((string) SITE_URL, '/') . '/assets/css/newsletter-public.css';
            echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }, 10);
    }

    public static function registerRetentionRule(): void
    {
        $repo = NewsletterRepository::instance();
        DataRetentionService::registerRule(new RetentionRule(
            self::RETENTION_RULE,
            (string) __('newsletter.retention.rule_label'),
            (string) __('newsletter.retention.rule_description'),
            static fn (): int => $repo->isAvailable() ? NewsletterSettings::int('confirm_expiry_days') : 0,
            static fn (): int => $repo->countExpiredPending(NewsletterSettings::int('confirm_expiry_days')),
            static fn (): int => $repo->deleteExpiredPending(NewsletterSettings::int('confirm_expiry_days')),
            '/admin/newsletter?section=settings'
        ));
    }

    private static function guard(string $task, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('newsletter')->warning('Newsletter: ' . $task . ' fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
