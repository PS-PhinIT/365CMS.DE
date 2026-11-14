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
 * Theme-Einstellungen des aktiven Themes (Logo, Hintergründe …) als Quelle „Design“; ein Eintrag mit ID 0.
 */
final class ThemeReferenceExtractor implements MediaReferenceExtractor
{
    public function contentType(): string
    {
        return 'theme';
    }

    public function label(): string
    {
        return 'Design';
    }

    public function ids(int $afterId, int $limit): array
    {
        return $afterId < 0 ? [0] : [];
    }

    public function load(int $id): ?array
    {
        if ($id !== 0) {
            return null;
        }
        try {
            $theme = \CMS\Services\ThemeCustomizer::instance()->getTheme();
        } catch (\Throwable) {
            return null;
        }
        $db = Database::instance();
        $values = [];
        foreach ($db->get_results(
            "SELECT setting_value FROM {$db->getPrefix()}theme_customizations WHERE theme_slug = ? AND user_id IS NULL",
            [$theme]
        ) as $row) {
            $values[] = ['context' => 'setting', 'value' => (string) ($row->setting_value ?? '')];
        }

        return [
            'title' => 'Theme „' . $theme . '“',
            'edit_url' => '/admin/theme-editor',
            'values' => $values,
        ];
    }
}
