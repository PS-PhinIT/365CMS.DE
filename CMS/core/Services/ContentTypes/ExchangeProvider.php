<?php
/**
 * Fähigkeit „Export/Import“: exportieren, Vorschau, importieren mit Konfliktstrategie.
 *
 * Verträge:
 * - Export läuft in Stapeln über einen providerspezifischen Cursor (letzte ID oder Position, 0 = Anfang).
 *   Datensätze sind JSON-serialisierbar und enthalten mindestens `id` (bei Medien der relative Pfad). Dateien (Medien, Anhänge) meldet der Stapel über
 *   `ExportBatch::$files`; das Archiv legt sie unter `files/` ab und schreibt ihre SHA-256 in das Manifest.
 * - Import verarbeitet einen Datensatz nach dem anderen in `importPriority()`-Reihenfolge (kleiner zuerst:
 *   Taxonomien vor Medien vor Inhalten). Neue IDs werden über `ImportContext::map()` gemerkt, damit spätere
 *   Typen Verweise (Kategorien, Medien, Autoren) umschreiben können. Editor.js-Inhalte werden über die
 *   bestehenden Services erneut bereinigt.
 * - `preview()` schreibt nie; `import()` beachtet `ImportContext::$conflictStrategy`
 *   (überspringen / überschreiben / umbenennen) und `$importAsDraft`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

interface ExchangeProvider extends ContentTypeProvider
{
    /** Importreihenfolge, kleinere Werte zuerst (z. B. Taxonomien 10, Medien 20, Seiten/Beiträge 50). */
    public function importPriority(): int;

    public function countExport(ExchangeFilter $filter): int;

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch;

    /** @param array<string, mixed> $record */
    public function preview(array $record, ImportContext $context): ImportPreview;

    /** @param array<string, mixed> $record */
    public function import(array $record, ImportContext $context): ImportResult;
}
