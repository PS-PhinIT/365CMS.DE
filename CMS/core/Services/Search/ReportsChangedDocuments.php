<?php
/**
 * Optionale Ergänzung für Such-Provider: geänderte Einträge seit einem Zeitpunkt melden.
 *
 * Die Suchindex-Verwaltung gleicht damit stündlich Änderungen ab, für die keine Inhalts-Hooks feuern –
 * etwa Sammelaktionen (Status mehrerer Einträge per UPDATE) oder geplante Veröffentlichungen, deren
 * Zeitpunkt inzwischen erreicht ist. Für jede gemeldete ID fragt der Abgleich `document()` neu ab;
 * `null` entfernt den Eintrag aus dem Index.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

if (!defined('ABSPATH')) {
    exit;
}

interface ReportsChangedDocuments
{
    /**
     * IDs aller Einträge (auch nicht öffentlicher), die sich seit `$since` geändert haben oder deren
     * Veröffentlichungszeitpunkt in (`$since`, `$until`] liegt.
     *
     * @param string $since Zeitpunkt `Y-m-d H:i:s` (Zeitzone der Anwendung)
     * @param string $until Zeitpunkt `Y-m-d H:i:s` (Zeitzone der Anwendung)
     * @return list<int>
     */
    public function changedDocumentIds(string $since, string $until, int $limit): array;
}
