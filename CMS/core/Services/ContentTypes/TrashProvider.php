<?php
/**
 * Fähigkeit „Papierkorb“: auflisten, verschieben, wiederherstellen, endgültig löschen, Fristablauf.
 *
 * Verträge:
 * - In den Papierkorb verschiebt der Inhaltstyp selbst (Liste, Editor, Bulk-Aktion): Er merkt sich vorherigen
 *   Status, Zeitpunkt und Benutzer (`previous_status`, `trashed_at`, `trashed_by`); der Eintrag verschwindet aus
 *   Frontend, Sitemap, Feeds und Suchindex (über die bestehenden Inhalts-Hooks bzw. Status-Filter).
 * - IDs sind die IDs des Typs. Typen ohne eigene numerische ID (Medien sind dateibasiert) führen eine
 *   Papierkorb-Tabelle und verwenden deren ID.
 * - `restore()` stellt den vorherigen Status wieder her und löst Konflikte auf (Slug, Dateiname); der neue
 *   Wert steht in `TrashResult::$data['slug']`.
 * - `deletePermanently()` entfernt auch abhängige Daten (Revisionen, Verwendungen, Dateien). Liefert
 *   `deletionWarnings()` Einträge, wird ohne `$confirmed = true` nicht gelöscht
 *   (`TrashResult::CODE_CONFIRMATION_REQUIRED`, Hinweise in `$data['warnings']`).
 * - Das automatische Leeren ruft `deletePermanently(…, confirmed: false)` auf; Einträge mit Warnungen
 *   bleiben deshalb liegen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

interface TrashProvider extends ContentTypeProvider
{
    public function countTrashed(string $search = ''): int;

    /**
     * Einträge im Papierkorb, zuletzt gelöschte zuerst.
     *
     * @return list<TrashItem>
     */
    public function listTrashed(int $offset, int $limit, string $search = ''): array;

    public function restore(int $id, int $userId): TrashResult;

    /**
     * Hinweise vor dem endgültigen Löschen (z. B. Verwendungen eines Mediums); leer = unbedenklich.
     *
     * @return list<string>
     */
    public function deletionWarnings(int $id): array;

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult;

    /**
     * IDs der Einträge, die vor `$trashedBefore` in den Papierkorb gelegt wurden (höchstens `$limit`).
     *
     * @return list<int>
     */
    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array;
}
