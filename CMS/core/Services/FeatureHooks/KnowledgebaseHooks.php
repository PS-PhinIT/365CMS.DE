<?php
/**
 * Hooks der Knowledgebase (ehemals Plugin cms-knowledgebase, seit 4.0.0 im Core).
 *
 * - Öffentliche Seiten `/kb`, `/kb/<slug>`, `/glossar`, `/glossar-sitemap.xml` (auch unter `/en/…`).
 * - Auto-Verlinkung von Fachbegriffen über den Filter `content_render` und – für Themes ohne diesen Filter –
 *   über den Output-Buffer-Fallback des Linkers.
 * - Tooltip-Skript, Stylesheets und Design-Variablen nur auf Knowledgebase-Seiten.
 * - Optionaler Menüpunkt in der Hauptnavigation (`main_nav`).
 * - Schema-Prüfung einmal je Version (`Installer::maybeUpgrade()`), Inhaltstyp-Provider für Suche, Papierkorb und
 *   Import/Export.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\Knowledgebase\Database\Installer;
use CMS\Services\Knowledgebase\Http\PublicController;
use CMS\Services\Knowledgebase\KnowledgebaseContentProvider;
use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Service\Linker;
use CMS\Services\Knowledgebase\Support\RequestInspector;

if (!defined('ABSPATH')) {
    exit;
}

final class KnowledgebaseHooks
{
    public static function register(): void
    {
        Hooks::addAction('cms_init', static function (): void {
            self::guard('Schema-Prüfung', static fn () => Installer::instance()->maybeUpgrade());
            self::guard('Output-Buffer-Fallback', static fn () => Linker::instance()->bootOutputBufferFallback());
        }, 10);

        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (is_object($router) && method_exists($router, 'addRoute')) {
                PublicController::instance()->registerRoutes($router);
            }
        }, 20);

        Hooks::addFilter('content_render', static function (mixed $html = '') {
            try {
                return Linker::instance()->filterContent($html);
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('knowledgebase')->warning('Auto-Verlinkung fehlgeschlagen.', ['exception' => $e->getMessage()]);

                return $html;
            }
        }, 20);

        Hooks::addAction('head', [self::class, 'printStyleVariables'], 9);
        Hooks::addAction('head', [self::class, 'printStyles'], 10);
        Hooks::addAction('before_footer', [self::class, 'printScripts'], 10);
        Hooks::addAction('main_nav', static function (mixed $context = 'desktop'): void {
            self::guard('Menüpunkt', static fn () => PublicController::instance()->renderNavItem(is_string($context) ? $context : 'desktop'));
        }, 30);

        // Suchindex aktuell halten (Neuaufbau unter System → Suchindex erfasst alles).
        Hooks::addAction('cms_knowledgebase_entry_saved', static function (mixed $id = 0): void {
            self::guard('Suchindex', static fn () => \CMS\Services\Search\SearchIndexService::getInstance()->refresh(KnowledgebaseContentProvider::TYPE, (int) $id));
        }, 50);
        foreach (['cms_knowledgebase_entry_trashed', 'cms_knowledgebase_entry_deleted'] as $hook) {
            Hooks::addAction($hook, static function (mixed $id = 0): void {
                self::guard('Suchindex', static fn () => \CMS\Services\Search\SearchIndexService::getInstance()->remove(KnowledgebaseContentProvider::TYPE, (int) $id));
            }, 50);
        }

        Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
            $registry->register(new KnowledgebaseContentProvider());
        }, 30);
    }

    public static function printStyleVariables(): void
    {
        if (!RequestInspector::shouldOutputPublicStyleVariables()) {
            return;
        }
        $tokens = [];
        self::guard('Design-Variablen', static function () use (&$tokens): void {
            $tokens = EntryRepository::instance()->getPublicDesignTokens();
        });
        if ($tokens === []) {
            return;
        }

        $nonce = '';
        try {
            $nonce = (string) \CMS\Security::instance()->nonceAttr();
        } catch (\Throwable) {
        }
        echo '<style id="cms-knowledgebase-style-tokens"' . ($nonce !== '' ? ' ' . $nonce : '') . ">\n:root {\n";
        foreach ($tokens as $name => $value) {
            echo '    ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . ";\n";
        }
        echo "}\n</style>\n";
    }

    public static function printStyles(): void
    {
        if (!RequestInspector::shouldLoadTooltipAssets()) {
            return;
        }
        foreach (['css/knowledgebase-tooltip.css', 'css/knowledgebase-public.css'] as $stylesheet) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars(self::assetUrl($stylesheet), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
    }

    public static function printScripts(): void
    {
        if (!RequestInspector::shouldLoadTooltipAssets()) {
            return;
        }
        $nonce = '';
        try {
            $nonce = (string) \CMS\Security::instance()->getNonce();
        } catch (\Throwable) {
        }
        echo '<script src="' . htmlspecialchars(self::assetUrl('js/knowledgebase-tooltip.js'), ENT_QUOTES, 'UTF-8') . '"'
            . ($nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '') . ' defer></script>' . "\n";
    }

    private static function assetUrl(string $relative): string
    {
        return function_exists('cms_asset_url') ? cms_asset_url($relative) : rtrim((string) SITE_URL, '/') . '/assets/' . $relative;
    }

    private static function guard(string $task, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('knowledgebase')->warning('Knowledgebase: ' . $task . ' fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
