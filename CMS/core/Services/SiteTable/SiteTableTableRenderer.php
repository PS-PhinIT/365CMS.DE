<?php
declare(strict_types=1);

namespace CMS\Services\SiteTable;

use CMS\Database;
use CMS\Hooks;
use CMS\Json;
use CMS\Services\PurifierService;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteTableTableRenderer
{
    private const PAGINATION_ROW_THRESHOLD = 20;

    /** @var array<string,mixed>|null */
    private static ?array $displaySettings = null;

    private static bool $frontendScriptIncluded = false;

    public function __construct(private SiteTableTemplateRegistry $templateRegistry)
    {
    }

    public function renderTable(int $tableId, array $table): string
    {
        $settings = $this->getSettings($table);
        [$sourceColumns, $sourceRows] = $this->resolveContentSourceData($settings);
        $columns = $this->normalizeColumns($sourceColumns !== [] ? $sourceColumns : ($table['columns'] ?? []));
        if ($columns === []) {
            return '';
        }

        $rows = $this->normalizeRows($sourceColumns !== [] ? $sourceRows : ($table['rows'] ?? []), $columns);
        $displaySettings = $this->loadDisplaySettings();

        $themeClassMap = [
            'default' => 'cms-site-table--default',
            'stripe' => 'cms-site-table--stripe',
            'hover' => 'cms-site-table--hover',
            'cell-border' => 'cms-site-table--cell-border',
        ];
        $activeStyle = in_array((string) ($settings['style_theme'] ?? ''), $displaySettings['enabled_styles'], true)
            ? (string) ($settings['style_theme'] ?? '')
            : (string) $displaySettings['default_style'];
        $themeClass = $themeClassMap[$activeStyle] ?? $themeClassMap['default'];
        $wrapperClasses = ['cms-site-table-wrap'];
        $interactiveConfig = $this->buildInteractiveConfig($settings, count($rows));
        if (!empty($settings['responsive']) && !empty($displaySettings['responsive_default'])) {
            $wrapperClasses[] = 'cms-site-table-wrap--responsive';
        }
        if (!empty($interactiveConfig['interactiveEnabled'])) {
            $wrapperClasses[] = 'cms-site-table-wrap--interactive';
        }

        $caption = trim((string) ($settings['caption'] ?? ''));
        $ariaLabel = trim((string) ($settings['aria_label'] ?? ''));
        $tableName = htmlspecialchars((string) ($table['name'] ?? 'Tabelle'), ENT_QUOTES, 'UTF-8');
        $tableLabel = htmlspecialchars($ariaLabel !== '' ? $ariaLabel : (string) ($table['name'] ?? 'Tabelle'), ENT_QUOTES, 'UTF-8');
        $descriptionId = !empty($displaySettings['show_description']) && !empty($table['description'])
            ? 'cms-site-table-desc-' . $tableId
            : '';
        $tableClasses = ['cms-site-table', $themeClass];
        if (!empty($settings['highlight_rows'])) {
            $tableClasses[] = 'cms-site-table--highlighted';
        }

        $wrapperAttributes = [
            'class="' . implode(' ', $wrapperClasses) . '"',
            'data-site-table-id="' . $tableId . '"',
        ];

        if (!empty($interactiveConfig['interactiveEnabled'])) {
            $wrapperAttributes[] = 'data-site-table-config="' . htmlspecialchars(
                (string) json_encode($interactiveConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ENT_QUOTES,
                'UTF-8'
            ) . '"';
        }

        $html = '<div ' . implode(' ', $wrapperAttributes) . '>';
        if (!empty($displaySettings['show_meta_panel'])) {
            $metaHtml = '';
            if (!empty($displaySettings['show_table_name'])) {
                $metaHtml .= '<h3 class="cms-site-table-title">' . $tableName . '</h3>';
            }
            if (!empty($displaySettings['show_description']) && !empty($table['description'])) {
                $metaHtml .= '<div class="cms-site-table-description" id="' . htmlspecialchars($descriptionId, ENT_QUOTES, 'UTF-8') . '">' . $this->renderEmbeddedContent((string) $table['description'], $tableId, 'table') . '</div>';
            }
            if (!empty($displaySettings['show_export_links'])) {
                $metaHtml .= $this->renderExportLinks($tableId, $settings);
            }
            if ($metaHtml !== '') {
                $html .= '<div class="cms-site-table-meta">' . $metaHtml . '</div>';
            }
        }
        $html .= $this->renderToolbar($interactiveConfig);

        $tableAttributes = [
            'class="' . implode(' ', $tableClasses) . '"',
        ];
        if ($caption === '') {
            $tableAttributes[] = 'aria-label="' . $tableLabel . '"';
        }
        if ($descriptionId !== '') {
            $tableAttributes[] = 'aria-describedby="' . htmlspecialchars($descriptionId, ENT_QUOTES, 'UTF-8') . '"';
        }

        $html .= '<table ' . implode(' ', $tableAttributes) . '>';
        if (!empty($displaySettings['show_caption']) && $caption !== '') {
            $html .= '<caption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</caption>';
        }
        $html .= '<thead><tr>';
        foreach ($columns as $index => $column) {
            $html .= '<th scope="col">';
            if (!empty($interactiveConfig['sortingEnabled'])) {
                $html .= '<button type="button" class="cms-site-table__sort" data-site-table-sort="' . $index . '">';
                $html .= '<span class="cms-site-table__sort-label">' . $this->renderColumnLabel((string) ($column['label'] ?? '')) . '</span>';
                $html .= '<span class="cms-site-table__sort-indicator" aria-hidden="true">↕</span>';
                $html .= '</button>';
            } else {
                $html .= $this->renderColumnLabel((string) ($column['label'] ?? ''));
            }
            $html .= '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if ($rows === []) {
            $html .= '<tr><td colspan="' . count($columns) . '">' . htmlspecialchars((string) ($interactiveConfig['labels']['emptyStatic'] ?? 'Keine Tabellenzeilen vorhanden.'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($columns as $index => $column) {
                    $label = (string) ($column['label'] ?? ('Spalte ' . ($index + 1)));
                    $plainLabel = htmlspecialchars((string) ($column['plain_label'] ?? $label), ENT_QUOTES, 'UTF-8');
                    $cellClasses = ['cms-site-table__cell'];
                    if ($index === 0) {
                        $cellClasses[] = 'cms-site-table__cell--primary';
                    }

                    $html .= '<td class="' . implode(' ', $cellClasses) . '" data-label="' . $plainLabel . '" data-col-index="' . ($index + 1) . '">'
                        . $this->renderEmbeddedContent((string) ($row[$label] ?? ''), $tableId)
                        . '</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';
        if (!empty($interactiveConfig['paginationEnabled'])) {
            $html .= '<nav class="cms-site-table-pagination" data-site-table-pagination aria-label="' . htmlspecialchars((string) ($interactiveConfig['labels']['pagination'] ?? 'Seitennavigation der Tabelle'), ENT_QUOTES, 'UTF-8') . '"></nav>';
        }
        $html .= '</div>';

        if (!empty($interactiveConfig['interactiveEnabled']) && !self::$frontendScriptIncluded) {
            self::$frontendScriptIncluded = true;
            $html .= '<script src="' . htmlspecialchars(cms_asset_url('js/site-tables.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
        }

        return $html;
    }

    public function streamExport(array $table, string $format, bool $respectFrontendPermissions, callable $slugSanitizer): bool
    {
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'json'], true)) {
            return false;
        }

        $settings = $this->getSettings($table);
        if ($respectFrontendPermissions) {
            $allowed = match ($format) {
                'csv' => (bool) ($settings['allow_export_csv'] ?? true),
                'json' => (bool) ($settings['allow_export_json'] ?? false),
                default => false,
            };
            if (!$allowed) {
                return false;
            }
        }

        [$sourceColumns, $sourceRows] = $this->resolveContentSourceData($settings);
        $columns = $this->normalizeColumns($sourceColumns !== [] ? $sourceColumns : ($table['columns'] ?? []));
        $rows = $this->normalizeRows($sourceColumns !== [] ? $sourceRows : ($table['rows'] ?? []), $columns);
        $fileName = $slugSanitizer((string) ($table['name'] ?? 'site-table'));
        $fileName = is_string($fileName) && $fileName !== '' ? $fileName : 'site-table';

        if ($format === 'json') {
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '.json"');
            echo json_encode([
                'columns' => $columns,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return false;
        }

        fwrite($out, "\xEF\xBB\xBF");
        // Formel-Injection (=, +, -, @) in Tabellenkalkulationen verhindern.
        fputcsv($out, array_map(fn(array $col): string => \CMS\Services\CsvExchange::sanitizeCell((string) ($col['label'] ?? '')), $columns), ';');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $index => $column) {
                $label = (string) ($column['label'] ?? ('Spalte ' . ($index + 1)));
                $line[] = \CMS\Services\CsvExchange::sanitizeCell((string) ($row[$label] ?? ''));
            }
            fputcsv($out, $line, ';');
        }
        fclose($out);
        exit;
    }

    private function getSettings(array $table): array
    {
        return array_merge($this->templateRegistry->getDefaultSettings(), $table['settings'] ?? []);
    }

    private function normalizeColumns(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $index => $column) {
            if (!is_array($column)) {
                continue;
            }
            $label = trim((string) ($column['label'] ?? ''));
            $plainLabel = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($plainLabel === '') {
                $label = 'Spalte ' . ($index + 1);
                $plainLabel = $label;
            }

            $normalized[] = [
                'label' => $label,
                'plain_label' => mb_substr($plainLabel, 0, 120),
                'type' => 'text',
            ];
        }

        return $normalized;
    }

    private function normalizeRows(array $rows, array $columns): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cleanRow = [];
            foreach ($columns as $index => $column) {
                $label = (string) ($column['label'] ?? ('Spalte ' . ($index + 1)));
                $plainLabel = (string) ($column['plain_label'] ?? $label);
                $value = $row[$label] ?? $row[$plainLabel] ?? $row[$index] ?? '';
                if (is_array($value) || is_object($value)) {
                    $value = '';
                }
                $cleanRow[$label] = trim((string) $value);
            }
            $normalized[] = $cleanRow;
        }

        return $normalized;
    }

    private function renderExportLinks(int $tableId, array $settings): string
    {
        $links = [];
        if (!empty($settings['allow_export_csv'])) {
            $links[] = '<a href="' . SITE_URL . '/site-table/export/' . $tableId . '/csv">CSV</a>';
        }
        if (!empty($settings['allow_export_json'])) {
            $links[] = '<a href="' . SITE_URL . '/site-table/export/' . $tableId . '/json">JSON</a>';
        }

        if ($links === []) {
            return '';
        }

        return '<div class="cms-site-table-actions"><span>Export:</span> ' . implode(' <span aria-hidden="true">·</span> ', $links) . '</div>';
    }

    private function renderToolbar(array $interactiveConfig): string
    {
        if (empty($interactiveConfig['searchEnabled'])) {
            return '';
        }

        $labels = is_array($interactiveConfig['labels'] ?? null) ? $interactiveConfig['labels'] : [];
        $html = '<div class="cms-site-table-toolbar">';
        if (!empty($interactiveConfig['searchEnabled'])) {
            $html .= '<label class="cms-site-table-toolbar__search">';
            $html .= '<span class="cms-site-table-toolbar__search-label">' . htmlspecialchars((string) ($labels['searchLabel'] ?? 'Tabelle durchsuchen'), ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '<input type="search" class="cms-site-table-toolbar__search-input" data-site-table-search placeholder="' . htmlspecialchars((string) ($labels['searchPlaceholder'] ?? 'Suchbegriff eingeben …'), ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars((string) ($labels['searchLabel'] ?? 'Tabelle durchsuchen'), ENT_QUOTES, 'UTF-8') . '">';
            $html .= '</label>';
        }

        $html .= '<div class="cms-site-table-toolbar__meta">';
        $html .= '<span class="cms-site-table-toolbar__status" data-site-table-status aria-live="polite"></span>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderEmbeddedContent(string $value, int $currentTableId, string $htmlProfile = 'table_cell'): string
    {
        if ($value === '') {
            return '';
        }

        $placeholders = [];
        $prepared = (string) preg_replace_callback(
            '/\[(?:site-table|table)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i',
            static function (array $matches) use (&$placeholders, $currentTableId): string {
                $targetTableId = (int) ($matches[1] ?? 0);
                if ($targetTableId <= 0 || $targetTableId === $currentTableId) {
                    return (string) ($matches[0] ?? '');
                }

                $token = '%%CMS_SITE_TABLE_' . count($placeholders) . '%%';
                $placeholders[$token] = \CMS\Services\SiteTableService::getInstance()->renderTableById($targetTableId);

                return $token;
            },
            $value
        );
        $prepared = $this->decodeAllowedTableCellHtmlEntities($prepared);

        $containsHtml = $this->containsHtml($prepared);
        $html = $containsHtml
            ? $this->sanitizeRichHtml($prepared, $htmlProfile)
            : nl2br(htmlspecialchars($prepared, ENT_QUOTES, 'UTF-8'));

        foreach ($placeholders as $token => $replacement) {
            $html = str_replace(
                $containsHtml ? $token : htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
                $replacement,
                $html
            );
        }

        return $html;
    }

    private function renderColumnLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($this->containsHtml($value)) {
            return $this->sanitizeRichHtml($value, 'table_cell');
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function containsHtml(string $value): bool
    {
        return preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $value) === 1;
    }

    private function decodeAllowedTableCellHtmlEntities(string $value): string
    {
        return preg_match('/&lt;\s*\/?\s*(?:a|strong|b|em|i|u)(?:\s|&gt;|>)/i', $value) === 1
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $value;
    }

    private function sanitizeRichHtml(string $value, string $profile): string
    {
        return PurifierService::getInstance()->purify($value, $profile);
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,string>>} */
    private function resolveContentSourceData(array $settings): array
    {
        if (empty($settings['content_source_enabled'])) {
            return [[], []];
        }

        $sourceSettings = [
            'mode' => is_scalar($settings['content_source_mode'] ?? null) ? (string) $settings['content_source_mode'] : SiteTableContentSource::defaultSelectionMode(),
            'item_keys' => is_array($settings['content_source_item_keys'] ?? null) ? $settings['content_source_item_keys'] : SiteTableContentSource::defaultItemKeys(),
            'category_id' => (int) ($settings['content_source_category_id'] ?? SiteTableContentSource::defaultCategoryId()),
            'sources' => is_array($settings['content_source_types'] ?? null) ? $settings['content_source_types'] : SiteTableContentSource::defaultSources(),
            'fields' => is_array($settings['content_source_fields'] ?? null) ? $settings['content_source_fields'] : SiteTableContentSource::defaultFields(),
        ];

        $columns = SiteTableContentSource::buildColumns($sourceSettings);
        if ($columns === []) {
            return [[], []];
        }

        $db = Database::instance();
        $rows = SiteTableContentSource::buildRows($db, $db->getPrefix(), $sourceSettings, 250);

        return [$columns, $rows];
    }

    /** @return array<string,mixed> */
    private function loadDisplaySettings(): array
    {
        if (self::$displaySettings !== null) {
            return self::$displaySettings;
        }

        $db = Database::instance();
        $row = $db->get_row(
            "SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1",
            [SiteTableDisplaySettings::OPTION_KEY]
        );

        $saved = [];
        if ($row && !empty($row->option_value)) {
            $saved = Json::decodeArray($row->option_value ?? null, []);
        }

        self::$displaySettings = SiteTableDisplaySettings::normalize(is_array($saved) ? $saved : []);

        return self::$displaySettings;
    }

    /** @return array<string,mixed> */
    private function buildInteractiveConfig(array $settings, int $rowCount): array
    {
        $locale = $this->resolveCurrentLocale();
        $labels = $locale === 'en'
            ? [
                'searchLabel' => 'Search table',
                'searchPlaceholder' => 'Enter a search term …',
                'emptyStatic' => 'No table rows available.',
                'emptyFiltered' => 'No matching table rows found.',
                'rowsLabelSingle' => 'row',
                'rowsLabelPlural' => 'rows',
                'filteredFrom' => 'filtered from',
                'page' => 'Page',
                'of' => 'of',
                'previous' => 'Previous',
                'next' => 'Next',
                'pagination' => 'Table pagination',
            ]
            : [
                'searchLabel' => 'Tabelle durchsuchen',
                'searchPlaceholder' => 'Suchbegriff eingeben …',
                'emptyStatic' => 'Keine Tabellenzeilen vorhanden.',
                'emptyFiltered' => 'Keine passenden Tabellenzeilen gefunden.',
                'rowsLabelSingle' => 'Zeile',
                'rowsLabelPlural' => 'Zeilen',
                'filteredFrom' => 'gefiltert aus',
                'page' => 'Seite',
                'of' => 'von',
                'previous' => 'Zurück',
                'next' => 'Weiter',
                'pagination' => 'Seitennavigation der Tabelle',
            ];

        $pageSize = (int) ($settings['page_size'] ?? 10);
        if ($pageSize < 5 || $pageSize > 100) {
            $pageSize = 10;
        }
        $pageSize = max($pageSize, self::PAGINATION_ROW_THRESHOLD);

        $searchEnabled = !empty($settings['enable_search']);
        $sortingEnabled = !empty($settings['enable_sorting']);
        $paginationEnabled = !empty($settings['enable_pagination'])
            && $rowCount > self::PAGINATION_ROW_THRESHOLD
            && $rowCount > $pageSize;

        $config = [
            'searchEnabled' => $searchEnabled,
            'sortingEnabled' => $sortingEnabled,
            'paginationEnabled' => $paginationEnabled,
            'pageSize' => $pageSize,
            'rowCount' => $rowCount,
            'paginationRowThreshold' => self::PAGINATION_ROW_THRESHOLD,
            'interactiveEnabled' => $searchEnabled || $sortingEnabled || $paginationEnabled,
            'labels' => $labels,
        ];

        $filteredConfig = Hooks::applyFilters('site_table_interactive_config', $config, $settings, $rowCount);
        if (is_array($filteredConfig)) {
            $config = array_merge($config, $filteredConfig);
        }

        $config['searchEnabled'] = !empty($config['searchEnabled']);
        $config['sortingEnabled'] = !empty($config['sortingEnabled']);
        $config['paginationEnabled'] = !empty($config['paginationEnabled']);
        $config['interactiveEnabled'] = $config['searchEnabled'] || $config['sortingEnabled'] || $config['paginationEnabled'];

        return $config;
    }

    private function resolveCurrentLocale(): string
    {
        $requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($requestUri, PHP_URL_PATH);
        $context = \CMS\Services\ContentLocalizationService::getInstance()->resolveRequestContext(is_string($path) ? $path : '/');
        $locale = (string) ($context['locale'] ?? 'de');

        return in_array($locale, ['de', 'en'], true) ? $locale : 'de';
    }
}
