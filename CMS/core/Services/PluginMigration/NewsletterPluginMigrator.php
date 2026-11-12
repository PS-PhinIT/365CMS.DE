<?php
/**
 * Datenübernahme aus dem Plugin cms-newsletter.
 *
 * Der Core nutzt dieselben Tabellen (`newsletter_*`); die Daten bleiben, wo sie sind. Die Übernahme hebt das Schema
 * älterer Plugin-Stände an (`NewsletterInstaller::install()`: neue Spalten und Schlüssel, Status „ready“ → Entwurf,
 * Einwilligungs- und Abmeldezeitpunkte, Standardwerte). Idempotent.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\PluginMigration;

use CMS\Database;
use CMS\Services\Newsletter\NewsletterInstaller;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterPluginMigrator implements PluginMigrator
{
    /** @var array<string, int> */
    private array $report = [];

    public function pluginSlug(): string
    {
        return 'cms-newsletter';
    }

    public function hasSourceData(): bool
    {
        $db = Database::instance();
        if (!$db->tableExists($db->getPrefix() . 'newsletter_subscribers')) {
            return false;
        }
        $version = (string) ($db->get_var("SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1", [NewsletterInstaller::VERSION_OPTION]) ?? '');

        return $version !== NewsletterInstaller::SCHEMA_VERSION;
    }

    public function migrate(): bool
    {
        NewsletterInstaller::install();
        $db = Database::instance();
        $p = $db->getPrefix();
        $this->report = [
            'subscribers' => (int) $db->get_var("SELECT COUNT(*) FROM {$p}newsletter_subscribers"),
            'templates' => (int) $db->get_var("SELECT COUNT(*) FROM {$p}newsletter_templates"),
            'campaigns' => (int) $db->get_var("SELECT COUNT(*) FROM {$p}newsletter_campaigns"),
        ];

        return true;
    }

    public function report(): array
    {
        return $this->report;
    }
}
