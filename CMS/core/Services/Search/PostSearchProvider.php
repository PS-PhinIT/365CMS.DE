<?php
/**
 * Such-Provider für Beiträge (Typ `post`).
 *
 * Ein Beitrag erscheint in einer Sprache nur, wenn er dort verfügbar ist
 * (`ContentLocalizationService::buildPostAvailabilitySql()`, wie Archive und Suchseite). Geplante
 * Beiträge kommen hinzu, sobald ihr Veröffentlichungszeitpunkt erreicht ist (stündlicher Abgleich über
 * `ReportsChangedDocuments`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

use CMS\Services\ContentLocalizationService;
use CMS\Services\PermalinkService;

if (!defined('ABSPATH')) {
    exit;
}

final class PostSearchProvider extends AbstractTableSearchProvider
{
    public function type(): string
    {
        return 'post';
    }

    public function label(): string
    {
        return self::translate('search_index.type_post', 'Beiträge');
    }

    public function capability(): string
    {
        return 'edit_all_posts';
    }

    protected function table(): string
    {
        return 'posts';
    }

    protected function localeCondition(string $locale): ?string
    {
        // Sprachen ohne eigene Inhaltsspalten bietet das Frontend für Beiträge nicht an.
        if ($locale !== 'de' && !$this->hasColumn('content_' . $locale)) {
            return null;
        }

        return ContentLocalizationService::getInstance()->buildPostAvailabilitySql('c', $locale);
    }

    protected function buildUrl(array $row, string $locale): string
    {
        return PermalinkService::getInstance()->buildPostPath($row, $locale);
    }
}
