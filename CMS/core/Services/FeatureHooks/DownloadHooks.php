<?php
/**
 * Hooks des Funktionsbereichs „Downloads“ (ehemals Plugin cms-downloads).
 *
 * - Routen: kontrollierte Auslieferung `/download/<slug>[/<version>]`, Archiv und Einzelseiten unter `/downloads`,
 *   frühere Plugin-URL `/downloads/file/<slug>` (301),
 * - Editor.js-Block „Download“ (Tool-Skript, Sanitizer, Renderer mit vier Templates),
 * - „Verwendet in“-Index über die Inhalts-Hooks und Prüfpunkt der Veröffentlichungs-Checkliste,
 * - signierter Quellseiten-Parameter an Download-Links aus Blöcken (Filter `content_render`),
 * - Frontend-Assets nur auf Seiten mit Download-Markup,
 * - Statistik: tägliche Verdichtung und Aufbewahrungsregel `downloads.events`,
 * - Inhaltstyp-Provider für den Papierkorb, optionaler Menüpunkt in der Hauptnavigation.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\DataRetentionService;
use CMS\Services\Downloads\DownloadContentProvider;
use CMS\Services\Downloads\DownloadFrontendController;
use CMS\Services\Downloads\DownloadRenderer;
use CMS\Services\Downloads\DownloadSettings;
use CMS\Services\Downloads\DownloadStatsService;
use CMS\Services\Downloads\DownloadUsageService;
use CMS\Services\EditorJs\EditorJsBlockRegistry;
use CMS\Services\PostPublishChecklistService;
use CMS\Services\Retention\RetentionRule;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadHooks
{
    public const BLOCK_SCRIPT = 'js/editor-blocks/download-block.js';
    public const RETENTION_RULE = 'downloads.events';

    public static function register(): void
    {
        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (is_object($router) && method_exists($router, 'addRoute')) {
                DownloadFrontendController::instance()->registerRoutes($router);
            }
        }, 20);

        EditorJsBlockRegistry::register(
            DownloadRenderer::BLOCK_TYPE,
            'Download',
            // Fehlt das Tool-Skript (unvollständiges Deployment), rendert der Block weiter vorhandene Inhalte.
            is_file(ABSPATH . 'assets/' . self::BLOCK_SCRIPT) ? self::BLOCK_SCRIPT : '',
            [DownloadRenderer::class, 'sanitizeBlock'],
            static fn (array $data, array $tunes = [], array $context = []): string => DownloadRenderer::getInstance()->renderBlock($data, $tunes, $context)
        );

        Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
            $registry->register(new DownloadContentProvider());
        }, 20);

        // „Verwendet in“-Index
        $usageHooks = [
            'cms_after_post_save' => ['post', false],
            'cms_after_post_copy_de_to_en' => ['post', false],
            'post_deleted' => ['post', true],
            'cms_after_page_save' => ['page', false],
            'cms_after_page_copy_de_to_en' => ['page', false],
            'page_deleted' => ['page', true],
        ];
        foreach ($usageHooks as $hook => [$type, $deleted]) {
            Hooks::addAction($hook, static function (mixed $id = 0) use ($type, $deleted): void {
                self::guard('Verwendungsindex', static function () use ($type, $deleted, $id): void {
                    $usage = DownloadUsageService::getInstance();
                    $deleted ? $usage->removeContent($type, (int) $id) : $usage->syncContent($type, (int) $id);
                });
            }, 70);
        }

        // Veröffentlichungs-Checkliste: Inhalte des gerade gespeicherten Beitrags für den Prüfpunkt „Downloads“.
        Hooks::addFilter('cms_prepare_post_save_payload', static function (mixed $payload = [], mixed $post = []): mixed {
            if (is_array($payload)) {
                self::guard('Checkliste', static function () use ($payload): void {
                    PostPublishChecklistService::getInstance()->setPendingContents(array_values(array_filter([
                        is_string($payload['content'] ?? null) ? $payload['content'] : '',
                        is_string($payload['content_en'] ?? null) ? $payload['content_en'] : '',
                    ], 'strlen')));
                });
            }

            return $payload;
        }, 20);

        Hooks::addFilter('content_render', static function (mixed $html = '', mixed $type = '', mixed $id = 0): mixed {
            return is_string($html)
                ? DownloadRenderer::getInstance()->applySourceToken($html, is_string($type) ? $type : '', (int) $id)
                : $html;
        }, 30);

        Hooks::addAction('head', static fn () => DownloadRenderer::getInstance()->printHeadAssets(), 45);
        Hooks::addAction('body_end', static fn () => DownloadRenderer::getInstance()->printFooterAssets(), 25);
        Hooks::addAction('main_nav', [self::class, 'renderNavItem'], 10);

        Hooks::addAction('cms_init', [self::class, 'registerRetentionRule'], 20);
        // Vor der Datenaufbewahrung (Priorität 40): erst verdichten, dann alte Rohzeilen löschen.
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard('Statistik-Verdichtung', static fn () => DownloadStatsService::getInstance()->aggregate());
            self::guard('Verwendungsindex-Neuaufbau', static fn () => DownloadUsageService::getInstance()->rebuild());
        }, 30);
    }

    public static function registerRetentionRule(): void
    {
        $translate = static fn (string $key): string => function_exists('__') ? (string) __($key) : $key;
        $stats = DownloadStatsService::getInstance();

        DataRetentionService::registerRule(new RetentionRule(
            self::RETENTION_RULE,
            $translate('downloads.retention.rule_label'),
            $translate('downloads.retention.rule_description'),
            static fn (): int => $stats->retentionDays(),
            static fn (): int => $stats->countPrunable(),
            static fn (): int => $stats->prune(),
            '/admin/downloads?tab=settings',
        ));
    }

    /** Optionaler Menüpunkt „Downloads“ in der Hauptnavigation (Einstellung `show_nav_link`). */
    public static function renderNavItem(mixed $context = 'desktop'): void
    {
        try {
            $settings = DownloadSettings::getInstance();
            if (!$settings->bool('show_nav_link')) {
                return;
            }
            $lang = DownloadRenderer::language();
            $label = $lang === 'en' && $settings->string('nav_label_en') !== '' ? $settings->string('nav_label_en') : $settings->string('nav_label');
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $active = preg_match('#^(?:/en)?/downloads(?:/|$)#', $path) === 1;
            echo Registry::navItem(DownloadRenderer::archiveUrl($lang), $label, $active, $context === 'mobile' ? 'mobile' : 'desktop');
        } catch (\Throwable) {
        }
    }

    private static function guard(string $task, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->warning('Downloads: ' . $task . ' fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
