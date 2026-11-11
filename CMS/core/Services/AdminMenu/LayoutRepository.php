<?php
/**
 * Speicher der eigenen Sidebar-Layouts (Profil „custom“) in der Tabelle `admin_menu_layouts`.
 *
 * - Ein Datensatz je Geltungsbereich: site_key '' = globales eigenes Layout, sonst Site-Key aus
 *   „MultiSites“ (überschreibt das globale Profil für diese Site im Adminbereich).
 * - layout_json enthält nur bereits validierte Layouts (LayoutValidator); Routen und Capabilities
 *   stehen nie darin.
 * - `version` steigt bei jedem Speichern und Zurücksetzen. Zurücksetzen löscht die Zeile nicht,
 *   sondern leert layout_json (NULL). So wiederholt sich eine Version nie, und der Menü-Cache
 *   (Version im Schlüssel) kann kein veraltetes Layout liefern.
 * - Alle Werte sind gebundene Parameter.
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

use CMS\Database;
use CMS\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

final class LayoutRepository
{
    public const GLOBAL_SCOPE = '';
    public const TABLE = 'admin_menu_layouts';
    /** Wie AdminSiteContextService (Site-Keys aus „MultiSites“). */
    private const SITE_KEY_PATTERN = '/^[a-z0-9][a-z0-9.-]{0,62}$/';

    private readonly string $table;

    public function __construct(private readonly Database $db)
    {
        $this->table = $db->getPrefix() . self::TABLE;
        $db->runSchemaOnce('admin_menu_layouts', __FILE__, fn (): bool => self::ensureSchema($db));
    }

    /**
     * Legt die Tabelle an, falls die Migration auf Schema v25 noch aussteht. Die Definition stammt
     * aus SchemaManager (Neuinstallation) und wird auch von MigrationManager (Update) verwendet.
     */
    public static function ensureSchema(Database $db): bool
    {
        $charset = defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4';
        $sql = SchemaManager::getSchemaQueries($db->getPrefix(), $charset)[self::TABLE] ?? '';
        if ($sql === '') {
            return false;
        }
        $db->query($sql);

        return true;
    }

    public static function isValidScope(string $siteKey): bool
    {
        return $siteKey === self::GLOBAL_SCOPE || preg_match(self::SITE_KEY_PATTERN, $siteKey) === 1;
    }

    /**
     * Versionen der gespeicherten Layouts (nur Bereiche mit Layout), ohne layout_json zu lesen.
     *
     * @param list<string> $siteKeys
     * @return array<string, int> site_key => version
     */
    public function versions(array $siteKeys): array
    {
        $siteKeys = array_values(array_unique(array_filter($siteKeys, static fn ($key): bool => is_string($key) && self::isValidScope($key))));
        if ($siteKeys === []) {
            return [];
        }

        $rows = $this->db->get_results(
            'SELECT site_key, version FROM ' . $this->table
            . ' WHERE layout_json IS NOT NULL AND site_key IN (' . implode(', ', array_fill(0, count($siteKeys), '?')) . ')',
            $siteKeys
        );

        $versions = [];
        foreach ($rows as $row) {
            $versions[(string) $row->site_key] = (int) $row->version;
        }

        return $versions;
    }

    /**
     * @return array{site_key:string,layout_json:string,version:int,updated_by:int|null,updated_at:string}|null
     */
    public function find(string $siteKey): ?array
    {
        if (!self::isValidScope($siteKey)) {
            return null;
        }

        $row = $this->db->get_row(
            'SELECT site_key, layout_json, version, updated_by, updated_at FROM ' . $this->table
            . ' WHERE site_key = ? AND layout_json IS NOT NULL LIMIT 1',
            [$siteKey]
        );
        if ($row === null) {
            return null;
        }

        return [
            'site_key' => (string) $row->site_key,
            'layout_json' => (string) $row->layout_json,
            'version' => (int) $row->version,
            'updated_by' => $row->updated_by !== null ? (int) $row->updated_by : null,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * Speichert ein bereits validiertes Layout und liefert die neue Version.
     */
    public function save(string $siteKey, string $layoutJson, ?int $userId): int
    {
        if (!self::isValidScope($siteKey) || $layoutJson === '') {
            throw new \InvalidArgumentException('Ungültiger Geltungsbereich oder leeres Layout.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO ' . $this->table . ' (site_key, layout_json, version, updated_by, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json), version = version + 1,
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$siteKey, $layoutJson, $userId, $now, $now]
        );

        return (int) ($this->db->get_var('SELECT version FROM ' . $this->table . ' WHERE site_key = ?', [$siteKey]) ?? 0);
    }

    /**
     * Entfernt das Layout eines Bereichs (Zeile bleibt als Platzhalter mit erhöhter Version).
     *
     * @return bool true, wenn ein Layout vorhanden war
     */
    public function delete(string $siteKey, ?int $userId): bool
    {
        if (!self::isValidScope($siteKey)) {
            return false;
        }

        $statement = $this->db->execute(
            'UPDATE ' . $this->table . ' SET layout_json = NULL, version = version + 1, updated_by = ?, updated_at = ?
             WHERE site_key = ? AND layout_json IS NOT NULL',
            [$userId, date('Y-m-d H:i:s'), $siteKey]
        );

        return $statement->rowCount() > 0;
    }

    /**
     * Alle Bereiche mit gespeichertem Layout (für die Übersicht im Einstellungs-Tab).
     *
     * @return array<string, array{version:int,updated_by:int|null,updated_at:string}>
     */
    public function scopes(): array
    {
        $rows = $this->db->get_results(
            'SELECT site_key, version, updated_by, updated_at FROM ' . $this->table
            . ' WHERE layout_json IS NOT NULL ORDER BY site_key ASC'
        );

        $scopes = [];
        foreach ($rows as $row) {
            $scopes[(string) $row->site_key] = [
                'version' => (int) $row->version,
                'updated_by' => $row->updated_by !== null ? (int) $row->updated_by : null,
                'updated_at' => (string) $row->updated_at,
            ];
        }

        return $scopes;
    }
}
