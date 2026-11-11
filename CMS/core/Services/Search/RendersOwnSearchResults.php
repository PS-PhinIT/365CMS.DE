<?php
/**
 * Kennzeichnung für Such-Provider, deren Treffer die Suchseite bereits selbst ausgibt.
 *
 * Die Suchseite (`ThemeRouter::renderSearch()`) durchsucht Seiten und Beiträge direkt und nutzt deren
 * Dokumentindex nur als zusätzliche Kandidatenquelle. Für alle anderen Such-Provider ergänzt
 * `SearchIndexHooks` über den Filter `search_results` Treffer aus dem Dokumentindex. Provider mit dieser
 * Schnittstelle werden dort übersprungen, damit kein Treffer doppelt erscheint.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

if (!defined('ABSPATH')) {
    exit;
}

interface RendersOwnSearchResults
{
}
