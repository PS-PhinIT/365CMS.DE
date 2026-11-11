<?php
/**
 * Hooks des Funktionsbereichs „Feeds“.
 *
 * RSS-Ausgang (`FeedService`): verwirft den Cache der öffentlichen RSS-Feeds über die Inhalts-Hooks, sobald Beiträge
 * gespeichert, veröffentlicht, kopiert oder gelöscht werden. Massenänderungen und Taxonomie-Pflege ohne eigenen Hook
 * fängt zusätzlich `Database::noteWrite()` ab.
 *
 * Feed-Aggregator (ehemals Plugin cms-feed), nur wenn in Einstellungen → Feeds aktiviert:
 * - öffentliche Routen `/<archiv-slug>`, `/<archiv-slug>/embed`, `/feed/<bereich>` samt Assets,
 * - Abruf-Queue im Cron (`cms_cron_mail_queue`, `cms_cron_hourly`; `cron.php?task=feeds` ruft `runCron()` direkt),
 * - E-Mail-Digests und Mitglieder-Abos stündlich, Bereinigung verwaister Abos täglich,
 * - Abo-Verwaltung im Mitgliederbereich (`FeedMemberSection`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Feed\AggregatorFrontendController;
use CMS\Services\Feed\AggregatorRepository;
use CMS\Services\Feed\AggregatorSettings;
use CMS\Services\Feed\DigestMailer;
use CMS\Services\Feed\FeedMemberSection;
use CMS\Services\Feed\FetchQueue;
use CMS\Services\Feed\SourceFetcher;
use CMS\Services\FeedService;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedHooks
{
    public static function register(): void
    {
        $invalidate = static function (): void {
            FeedService::invalidatePublicFeedCache();
        };

        foreach (['cms_after_post_save', 'cms_after_post_copy_de_to_en', 'post_deleted'] as $hook) {
            Hooks::addAction($hook, $invalidate, 90);
        }

        // ── Feed-Aggregator ────────────────────────────────────────────────
        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (is_object($router) && method_exists($router, 'addRoute')) {
                self::guard('Routen', static fn () => AggregatorFrontendController::instance()->registerRoutes($router));
            }
        }, 20);
        Hooks::addAction('head', static fn () => AggregatorFrontendController::instance()->printHeadAssets(), 45);
        Hooks::addAction('body_end', static fn () => AggregatorFrontendController::instance()->printFooterAssets(), 25);

        Hooks::addAction('cms_cron_mail_queue', static function (): void {
            self::runCron();
        }, 20);
        Hooks::addAction('cms_cron_hourly', static function (): void {
            self::runCron();
            self::guard('Digest-Versand', static function (): void {
                if (self::aggregatorActive()) {
                    (new DigestMailer(AggregatorRepository::getInstance()))->sendDue();
                }
            });
        }, 20);
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard('Abo-Bereinigung', static function (): void {
                if (AggregatorRepository::getInstance()->isReady()) {
                    AggregatorRepository::getInstance()->deleteOrphanSubscriptions();
                }
            });
        }, 40);

        FeedMemberSection::register();
    }

    /**
     * Abruf-Queue: fällige Quellen einreihen und einen Block abarbeiten (gesperrt gegen parallele Läufe).
     *
     * @param array{force?:bool,limit?:int} $context
     * @return array<string, mixed>
     */
    public static function runCron(array $context = []): array
    {
        if (!self::aggregatorActive()) {
            return ['executed' => false, 'mode' => 'feed-aggregator-disabled'];
        }

        try {
            $repository = AggregatorRepository::getInstance();

            return (new FetchQueue($repository, new SourceFetcher($repository)))->tick($context);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feeds')->warning('Feeds: Cron-Abruf fehlgeschlagen.', ['exception' => $e->getMessage()]);

            return ['executed' => false, 'mode' => 'feed-cron-tick', 'error' => $e->getMessage()];
        }
    }

    public static function aggregatorActive(): bool
    {
        try {
            return AggregatorSettings::isEnabled() && AggregatorRepository::getInstance()->isReady();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function guard(string $task, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feeds')->warning('Feeds: ' . $task . ' fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
