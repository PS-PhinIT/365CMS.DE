<?php
/**
 * Gemeinsame Basis aller Inhaltstyp-Provider.
 *
 * Ein Provider beschreibt einen Inhaltstyp (Seiten, Beiträge, Medien, Hub-Sites, Taxonomien, Downloads,
 * Formulare …) und implementiert zusätzlich eine oder mehrere Fähigkeits-Schnittstellen:
 * `TrashProvider`, `ExchangeProvider`, `SearchDocumentProvider`. Angemeldet wird er bei der
 * `ContentTypeRegistry`; die Funktionen (Papierkorb, Export/Import, Suchindex) kennen nur die Schnittstellen
 * und enthalten keine Sonderfälle für einzelne Typen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

interface ContentTypeProvider
{
    /** Stabiler Schlüssel des Inhaltstyps, Muster `[a-z][a-z0-9_]{0,39}` (z. B. `page`, `post`, `hub_site`). */
    public function type(): string;

    /** Übersetzte Bezeichnung im Plural für die Oberfläche (z. B. „Seiten“). */
    public function label(): string;

    /**
     * Capability für Einträge dieses Typs. Die Funktionen prüfen sie zusätzlich zu ihrer eigenen
     * Capability (z. B. `manage_trash` + `manage_pages`).
     */
    public function capability(): string;
}
