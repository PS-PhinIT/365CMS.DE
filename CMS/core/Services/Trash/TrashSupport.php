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
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashSupport
{
    /**
     * URL der zentralen Papierkorb-Seite – leer, solange `admin/trash.php` nicht ausgeliefert ist.
     */
    public static function centralPageUrl(): string
    {
        return is_file(ABSPATH . 'admin/trash.php') ? '/admin/trash' : '';
    }

    /**
     * Übersetzter Text; mit Argumenten per `sprintf()` (Platzhalter `%s`/`%d`).
     */
    public static function t(string $key, string|int ...$args): string
    {
        $text = function_exists('__') ? (string) __($key) : $key;
        if ($args === []) {
            return $text;
        }

        try {
            return vsprintf($text, $args);
        } catch (\ValueError) {
            // Übersetzung mit abweichenden Platzhaltern – lieber Rohtext als Fatal Error.
            return $text;
        }
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
     * „Bestätigung erforderlich“ mit Warnungen und Titel (`data['title']`) für Rückmeldungen und Listen.
     *
     * @param list<string> $warnings
     */
    public static function confirmationRequired(array $warnings, string $title): TrashResult
    {
        return TrashResult::failure(
            TrashResult::CODE_CONFIRMATION_REQUIRED,
            self::t('trash.confirm.required', $title),
            ['warnings' => array_values($warnings), 'title' => $title]
        );
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
