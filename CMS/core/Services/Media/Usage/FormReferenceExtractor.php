<?php
/**
 * Extraktor des Cores für den Verwendungsindex (4.1.0, Bereich 2b). Nur lesende Abfragen mit gebundenen Parametern.
 *
 * @package CMSv2\Core\Services\Media\Usage
 */

declare(strict_types=1);

namespace CMS\Services\Media\Usage;

use CMS\Database;
use CMS\Services\Media\MediaReferenceExtractor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulare: Hinweistexte und Einstellungen (z. B. Bilder in Beschreibungsfeldern).
 */
final class FormReferenceExtractor implements MediaReferenceExtractor
{
    public function contentType(): string
    {
        return 'form';
    }

    public function label(): string
    {
        return 'Formular';
    }

    public function ids(int $afterId, int $limit): array
    {
        $db = Database::instance();

        return array_map('intval', $db->get_col(
            "SELECT id FROM {$db->getPrefix()}form_forms WHERE id > ? ORDER BY id ASC LIMIT " . max(1, min(1000, $limit)),
            [$afterId]
        ));
    }

    public function load(int $id): ?array
    {
        $db = Database::instance();
        $row = $db->get_row(
            "SELECT id, title, form_key, fields_json, settings_json FROM {$db->getPrefix()}form_forms WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($row === null) {
            return null;
        }

        return [
            'title' => ContentTableReferenceExtractor::title((string) ($row->title ?? ''), (string) ($row->form_key ?? ''), 'Formular', $id),
            'edit_url' => '/admin/forms?action=edit&id=' . $id,
            'values' => [
                ['context' => 'content', 'value' => (string) ($row->fields_json ?? '')],
                ['context' => 'setting', 'value' => (string) ($row->settings_json ?? '')],
            ],
        ];
    }
}
