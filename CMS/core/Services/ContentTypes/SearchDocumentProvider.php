<?php
/**
 * Fähigkeit „Suchindex“: Dokumente liefern.
 *
 * Verträge:
 * - Geliefert werden nur öffentlich sichtbare Inhalte (veröffentlicht, nicht im Papierkorb, Sprache
 *   vorhanden). Hub-Sites melden keinen Such-Provider an.
 * - `documents()` liefert aufsteigend nach ID nur IDs größer als `$cursor`; damit lässt sich ein Neuaufbau
 *   in Stapeln fortsetzen.
 * - `document()` liefert den aktuellen Stand für inkrementelle Aktualisierungen; `null` bedeutet
 *   „nicht (mehr) indexieren“.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

interface SearchDocumentProvider extends ContentTypeProvider
{
    public function countDocuments(string $locale): int;

    /** @return list<SearchDocument> */
    public function documents(string $locale, int $cursor, int $limit): array;

    public function document(int $id, string $locale): ?SearchDocument;
}
