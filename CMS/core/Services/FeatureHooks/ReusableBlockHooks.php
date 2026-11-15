<?php
/**
 * Hooks der wiederverwendbaren Editor.js-Blöcke (4.1.0, Bereich 3b): Block `reusableBlock` registrieren (Editor,
 * Sanitizer, Renderer), Nutzungsindex beim Speichern und Löschen von Beiträgen und Seiten pflegen und den
 * Verwendungsnachweis der Medien um die Blöcke erweitern.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\EditorJs\EditorJsBlockRegistry;
use CMS\Services\Media\MediaUsageIndex;
use CMS\Services\ReusableBlocks\ReusableBlock;
use CMS\Services\ReusableBlocks\ReusableBlockMediaExtractor;
use CMS\Services\ReusableBlocks\ReusableBlockService;

if (!defined('ABSPATH')) {
    exit;
}

final class ReusableBlockHooks
{
    private const TABLES = ['post' => 'posts', 'page' => 'pages'];

    public static function register(): void
    {
        EditorJsBlockRegistry::register(
            ReusableBlock::TYPE,
            'Vorlagen',
            ReusableBlock::SCRIPT,
            [ReusableBlock::class, 'sanitize'],
            [ReusableBlock::class, 'render']
        );

        // Suche: Text der Vorlage statt des bloßen Verweises indizieren.
        \CMS\Services\SiteSearchService::registerBlockTextResolver(
            ReusableBlock::TYPE,
            static fn (array $data, string $locale): string => ReusableBlockService::getInstance()->searchContent($data, $locale)
        );

        // Nach den eigenen Folgeaktionen der Module (Priorität 30): Inhalte aus der Datenbank lesen, nicht aus dem Formular.
        Hooks::addAction('cms_after_post_save', static function (mixed $id = 0): void {
            self::sync('post', (int) $id);
        }, 30);
        Hooks::addAction('cms_after_page_save', static function (mixed $id = 0): void {
            self::sync('page', (int) $id);
        }, 30);
        Hooks::addAction('post_deleted', static function (mixed $id = 0): void {
            self::guard(static fn () => ReusableBlockService::getInstance()->removeUsage('post', (int) $id));
        });
        Hooks::addAction('page_deleted', static function (mixed $id = 0): void {
            self::guard(static fn () => ReusableBlockService::getInstance()->removeUsage('page', (int) $id));
        });

        Hooks::addAction(MediaUsageIndex::REGISTER_ACTION, static function (mixed $index = null): void {
            if ($index instanceof MediaUsageIndex) {
                $index->register(new ReusableBlockMediaExtractor());
            }
        });
    }

    private static function sync(string $type, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        self::guard(static function () use ($type, $id): void {
            $db = Database::instance();
            $row = $db->get_row(
                'SELECT content, content_en FROM ' . $db->getPrefix() . self::TABLES[$type] . ' WHERE id = ? LIMIT 1',
                [$id]
            );
            if ($row === null) {
                ReusableBlockService::getInstance()->removeUsage($type, $id);

                return;
            }
            ReusableBlockService::getInstance()->syncUsage($type, $id, [(string) ($row->content ?? ''), (string) ($row->content_en ?? '')]);
        });
    }

    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('content.reusable_blocks')->warning('Nutzungsindex der Blöcke konnte nicht aktualisiert werden.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
