<?php
/**
 * Datenübernahme aus dem Plugin cms-knowledgebase.
 *
 * Die Knowledgebase des Cores nutzt dieselben Tabellen wie das Plugin (`kb_entries`, `kb_categories`,
 * `kb_settings`); die Daten bleiben deshalb, wo sie sind. Die Übernahme bringt nur das Schema älterer
 * Plugin-Stände auf den aktuellen Stand (Inhaltsbereiche, Papierkorb-Spalten, Aufteilung von Alt-HTML,
 * Standardwerte, Kategorien aus den Einträgen) – über `Knowledgebase\Database\Installer::install()`.
 * Idempotent: ein erneuter Lauf ändert nichts mehr.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\PluginMigration;

use CMS\Database;
use CMS\Services\Knowledgebase\Database\Installer;

if (!defined('ABSPATH')) {
    exit;
}

final class KnowledgebasePluginMigrator implements PluginMigrator
{
    private Database $db;
    private string $prefix;

    /** @var array<string, int> */
    private array $report = [];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function pluginSlug(): string
    {
        return 'cms-knowledgebase';
    }

    public function hasSourceData(): bool
    {
        if (!$this->db->tableExists($this->prefix . 'kb_entries')) {
            return false;
        }
        $version = (string) ($this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = 'cms_knowledgebase_version' LIMIT 1"
        ) ?? '');

        return $version !== Installer::SCHEMA_VERSION;
    }

    public function migrate(): bool
    {
        Installer::instance()->install();
        $this->report = [
            'entries' => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}kb_entries"),
            'categories' => $this->db->tableExists($this->prefix . 'kb_categories')
                ? (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}kb_categories")
                : 0,
        ];

        return true;
    }

    public function report(): array
    {
        return $this->report;
    }
}
