<?php
/**
 * Übernahme von Daten eines in den Core integrierten Plugins.
 *
 * Implementierungen liegen in diesem Namespace und sind idempotent: Ein erneuter Lauf legt keine
 * Duplikate an (ID-Zuordnung wird gespeichert). Große Datenmengen werden in Blöcken übernommen;
 * `migrate()` liefert `false`, solange noch Daten offen sind, und wird dann beim nächsten Lauf fortgesetzt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\PluginMigration;

if (!defined('ABSPATH')) {
    exit;
}

interface PluginMigrator
{
    /** Plugin-Slug, z. B. `cms-downloads`. */
    public function pluginSlug(): string;

    /** Gibt es Plugin-Tabellen oder -Optionen, die übernommen werden müssen? */
    public function hasSourceData(): bool;

    /**
     * Übernimmt den nächsten Block. `true` = vollständig übernommen, `false` = später fortsetzen.
     */
    public function migrate(): bool;

    /**
     * Kurzbericht für Audit-Log und Hinweis, z. B. `['downloads' => 12, 'versions' => 15]`.
     *
     * @return array<string, int>
     */
    public function report(): array;
}
