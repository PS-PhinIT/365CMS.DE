<?php
/**
 * Merkt Papierkorb-Einträge vor, deren endgültiges Löschen Warnungen auslöst (z. B. noch verwendete Medien,
 * zugeordnete Rechtstexte). Die Liste zeigt sie nach dem Redirect mit ihren Warnungen an; erst ein
 * ausdrücklich bestätigtes Formular (`confirmed=1`) löscht sie.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashConfirmationStore
{
    private const SESSION_KEY = 'cms_trash_confirmation';
    private const MAX_AGE_SECONDS = 900;
    private const MAX_ITEMS = 50;
    private const MAX_WARNINGS = 20;

    /**
     * @param array<int, array{title:string,warnings:list<string>}> $items ID => Titel und Warnungen
     */
    public static function remember(string $context, string $type, array $items): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $items === []) {
            return;
        }

        $normalized = [];
        foreach (array_slice($items, 0, self::MAX_ITEMS, true) as $id => $item) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $normalized[$id] = [
                'title' => (string) ($item['title'] ?? ('#' . $id)),
                'warnings' => array_slice(array_values(array_map('strval', (array) ($item['warnings'] ?? []))), 0, self::MAX_WARNINGS),
            ];
        }

        if ($normalized === []) {
            return;
        }

        $_SESSION[self::SESSION_KEY][$context] = [
            'type' => $type,
            'items' => $normalized,
            'created' => time(),
        ];
    }

    /**
     * Liefert und entfernt die vorgemerkten Einträge eines Kontexts.
     *
     * @return array{type:string,items:array<int,array{title:string,warnings:list<string>}>}|null
     */
    public static function pull(string $context): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $entry = $_SESSION[self::SESSION_KEY][$context] ?? null;
        unset($_SESSION[self::SESSION_KEY][$context]);
        if (empty($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        if (!is_array($entry) || !is_array($entry['items'] ?? null) || $entry['items'] === []) {
            return null;
        }

        if ((int) ($entry['created'] ?? 0) < time() - self::MAX_AGE_SECONDS) {
            return null;
        }

        return [
            'type' => (string) ($entry['type'] ?? ''),
            'items' => $entry['items'],
        ];
    }
}
