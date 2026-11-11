<?php
/**
 * Such-Provider für Seiten (Typ `page`).
 *
 * Seiten stehen in jeder Inhaltssprache bereit; fehlt die Übersetzung, gilt wie im Frontend die deutsche
 * Fassung. Die Suchseite gibt Seiten selbst aus (`RendersOwnSearchResults`) und nutzt diesen Index als
 * zusätzliche Kandidatenquelle.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

use CMS\Services\ContentLocalizationService;

if (!defined('ABSPATH')) {
    exit;
}

final class PageSearchProvider extends AbstractTableSearchProvider
{
    public function type(): string
    {
        return 'page';
    }

    public function label(): string
    {
        return self::translate('search_index.type_page', 'Seiten');
    }

    public function capability(): string
    {
        return 'manage_pages';
    }

    protected function table(): string
    {
        return 'pages';
    }

    protected function localeCondition(string $locale): ?string
    {
        return '';
    }

    protected function buildUrl(array $row, string $locale): string
    {
        $slug = '';
        foreach ($this->localizedColumns('slug', $locale) as $column) {
            $slug = trim((string) ($row[$column] ?? ''), " \t\n\r\0\x0B/");
            if ($slug !== '') {
                break;
            }
        }

        return ContentLocalizationService::getInstance()->buildLocalizedPath('/' . $slug, $locale);
    }
}
