<?php
/**
 * Gemeinsame Helfer der Papierkorb-Provider (Übersetzung, Inhalts-Cache, Zeitstempel).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

use CMS\CacheManager;
use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashSupport
{
    /**
     * Übersetzter Text; mit Argumenten per `sprintf()` (Platzhalter `%s`/`%d`).
     */
    public static function t(string $key, string|int ...$args): string
    {
        $text = function_exists('__') ? (string) __($key) : $key;

        return $args === [] ? $text : vsprintf($text, $args);
    }

    /**
     * Leert den Seiten-/Objekt-Cache nach Inhaltsänderungen – wie die Inhaltsmodule nur, wenn
     * „Inhalts-Cache automatisch leeren“ (`perf_auto_clear_content_cache`) nicht deaktiviert ist.
     */
    public static function clearContentCache(Database $db, string $reason, int $contentId): void
    {
        try {
            $value = $db->get_var(
                "SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1",
                ['perf_auto_clear_content_cache']
            );
            if ((string) ($value ?? '1') === '0') {
                return;
            }

            CacheManager::instance()->clear();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Inhalts-Cache konnte nach Papierkorb-Aktion nicht geleert werden.', [
                'reason' => $reason,
                'content_id' => $contentId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalisiert einen DB-Zeitstempel auf `Y-m-d H:i:s` (leer, falls unbekannt).
     */
    public static function timestamp(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' || str_starts_with($value, '0000-00-00') ? '' : substr($value, 0, 19);
    }

    /**
     * LIKE-Muster mit maskierten Platzhaltern: enthält `$search` (Standard) oder beginnt damit.
     */
    public static function likePattern(string $search, bool $contains = true): string
    {
        $escaped = addcslashes($search, '%_\\');

        return ($contains ? '%' : '') . $escaped . '%';
    }
}
