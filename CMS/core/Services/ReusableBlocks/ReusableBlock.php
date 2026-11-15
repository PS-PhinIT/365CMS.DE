<?php
/**
 * Editor.js-Block „Wiederverwendbarer Block“ (`reusableBlock`, 4.1.0, Bereich 3b).
 *
 * Blockdaten: nur `{ "id": 12 }` – nie Inhalt oder HTML. Im Frontend rendert der aktuelle Stand des Blocks in der
 * Sprache der Anfrage (EN fällt auf DE zurück); ein gelöschter Block oder eine Rekursion ergibt nichts, die
 * Editor-Vorschau zeigt stattdessen einen Hinweis.
 *
 * @package CMSv2\Core\Services\ReusableBlocks
 */

declare(strict_types=1);

namespace CMS\Services\ReusableBlocks;

if (!defined('ABSPATH')) {
    exit;
}

final class ReusableBlock
{
    public const TYPE = ReusableBlockService::BLOCK_TYPE;
    public const SCRIPT = 'js/editor-blocks/reusable-block.js';

    /**
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public static function sanitize(array $data): array
    {
        return ReusableBlockService::sanitizeBlockData($data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $tunes
     * @param array{mode?: string} $context
     */
    public static function render(array $data, array $tunes = [], array $context = []): string
    {
        $data = self::sanitize($data);

        return ReusableBlockService::getInstance()->render(
            $data['id'],
            self::requestLocale(),
            ($context['mode'] ?? 'frontend') === 'preview' ? 'preview' : 'frontend'
        );
    }

    private static function requestLocale(): string
    {
        try {
            if (class_exists(\CMS\Router::class) && defined('DB_NAME')) {
                return \CMS\Router::instance()->getRequestLocale() === 'en' ? 'en' : 'de';
            }
        } catch (\Throwable) {
        }

        return 'de';
    }
}
