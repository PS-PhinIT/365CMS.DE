<?php
declare(strict_types=1);

/**
 * Tables Module – CRUD-Logik für Site-Tabellen
 *
 * Arbeitet direkt mit der cms_site_tables Tabelle.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Logger;
use CMS\Services\PurifierService;
use CMS\Services\SiteTable\SiteTableContentSource;
use CMS\Services\SiteTable\SiteTableDisplaySettings;

class TablesModule
{
    private const MAX_TABLE_NAME_LENGTH = 150;
    private const MAX_DESCRIPTION_LENGTH = 1000;
    private const MAX_COLUMNS = 25;
    private const MAX_ROWS = 250;
    private const MAX_COLUMN_LABEL_LENGTH = 80;
    private const MAX_CELL_LENGTH = 5000;

    private Database $db;
    private Logger $logger;
    private string $prefix;
    private ?bool $hasTableSlugColumn = null;

    /** @var list<string> */
    private const ALLOWED_STYLE_THEMES = ['default', 'stripe', 'hover', 'cell-border'];

    private const DEFAULT_SETTINGS = [
        'responsive'         => true,
        'style_theme'        => 'default',
        'caption'            => '',
        'aria_label'         => '',
        'allow_export_csv'   => true,
        'allow_export_json'  => false,
        'allow_export_excel' => false,
        'enable_search'      => true,
        'enable_sorting'     => true,
        'enable_pagination'  => true,
        'page_size'          => 10,
        'highlight_rows'     => false,
        'custom_css'         => '',
        'content_source_enabled' => false,
        'content_source_mode'    => 'items',
        'content_source_item_keys' => [],
        'content_source_category_id' => 0,
    ];

    private const SETTINGS_BOOL_FIELDS = [
        'show_meta_panel',
        'show_table_name',
        'show_description',
        'show_export_links',
        'show_caption',
        'responsive_default',
    ];

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->logger = Logger::instance()->withChannel('admin.site-tables');
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * Daten für die Listenansicht
     */
    public function getListData(string $search = ''): array
    {
        $search = $this->sanitizeSearchTerm($search);

        $where  = '';
        $params = [];
        if ($search !== '') {
            $where    = 'WHERE table_name LIKE ? OR description LIKE ?';
            $params[] = '%' . \cms_escape_like($search) . '%';
            $params[] = '%' . \cms_escape_like($search) . '%';
        }

        $tables = $this->db->get_results(
            "SELECT id, table_name, description,
                    settings_json,
                    JSON_LENGTH(columns_json) AS col_count,
                    JSON_LENGTH(rows_json)    AS row_count,
                    created_at, updated_at
             FROM {$this->prefix}site_tables
             {$where}
             ORDER BY updated_at DESC
             LIMIT 200",
            $params
        ) ?: [];

        $tables = array_values(array_filter(array_map(function ($table): array {
            $item = (array)$table;
            $settings = \CMS\Json::decodeArray($item['settings_json'] ?? null, []);
            $item['content_mode'] = (string)($settings['content_mode'] ?? 'table');
            return $this->normalizeListRow($item);
        }, $tables), static fn(array $table): bool => ($table['content_mode'] ?? 'table') !== 'hub'));

        $total = count($tables);

        return [
            'tables' => $tables,
            'total'  => $total,
            'search' => $search,
        ];
    }

    /**
     * Daten für Edit/Create
     */
    public function getEditData(?int $id, array $formValues = []): array
    {
        $displaySettings = $this->loadDisplaySettings();
        $styleOptions = array_intersect_key(
            SiteTableDisplaySettings::styleOptions(),
            array_flip($displaySettings['enabled_styles'])
        );

        $table = null;
        $missing = false;
        if ($id !== null) {
            $table = $this->db->get_row(
                "SELECT * FROM {$this->prefix}site_tables WHERE id = ?",
                [$id]
            );
            if ($table) {
                $table = (array)$table;
                $normalizedColumns = $this->normalizeColumns(\CMS\Json::decodeArray($table['columns_json'] ?? null, []));
                $table['columns'] = $normalizedColumns['columns'];
                $normalizedRows = $this->normalizeRows(\CMS\Json::decodeArray($table['rows_json'] ?? null, []), $table['columns']);
                $table['rows'] = $normalizedRows['rows'];
                $table['settings'] = array_merge(
                    self::DEFAULT_SETTINGS,
                    \CMS\Json::decodeArray($table['settings_json'] ?? null, [])
                );

                $selectedStyle = (string) ($table['settings']['style_theme'] ?? '');
                if (!isset($styleOptions[$selectedStyle])) {
                    $table['settings']['style_theme'] = $displaySettings['default_style'];
                }
            } else {
                $missing = true;
            }
        }

        if ($formValues !== []) {
            $table = $this->applyFormValuesToTable($table, $formValues);
            if ($table !== null) {
                $selectedStyle = (string) ($table['settings']['style_theme'] ?? '');
                if (!isset($styleOptions[$selectedStyle])) {
                    $table['settings']['style_theme'] = $displaySettings['default_style'];
                }
            }
        }

        $editorColumns = is_array($table['columns'] ?? null) ? $table['columns'] : [];
        $editorRows = is_array($table['rows'] ?? null) ? $table['rows'] : [];

        return [
            'table'            => $table,
            'isNew'            => $table === null,
            'missing'          => $missing,
            'defaults'         => self::DEFAULT_SETTINGS,
            'displaySettings'  => $displaySettings,
            'styleOptions'     => $styleOptions,
            'editorSummary'    => $this->buildEditorSummary($editorColumns, $editorRows),
            'editorConfigJson' => $this->encodeEditorConfig($editorColumns, $editorRows),
            'contentSource'    => SiteTableContentSource::adminOptions($this->db, $this->prefix),
            'editorLimits'     => [
                'maxColumns' => self::MAX_COLUMNS,
                'maxRows' => self::MAX_ROWS,
                'maxColumnLabelLength' => self::MAX_COLUMN_LABEL_LENGTH,
                'maxCellLength' => self::MAX_CELL_LENGTH,
            ],
        ];
    }

    public function getSettingsData(): array
    {
        $settings = $this->loadDisplaySettings();

        $tableCount = (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}site_tables WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'table'"
        ) ?? 0);

        return [
            'settings' => $settings,
            'styleOptions' => SiteTableDisplaySettings::styleOptions(),
            'stats' => [
                'table_count' => $tableCount,
                'enabled_style_count' => count($settings['enabled_styles']),
            ],
        ];
    }

    public function saveDisplaySettings(array $post): array
    {
        $settings = [];

        foreach (self::SETTINGS_BOOL_FIELDS as $field) {
            $settings[$field] = isset($post[$field]);
        }

        $enabledStyles = $post['enabled_styles'] ?? [];
        $settings['enabled_styles'] = is_array($enabledStyles) ? $enabledStyles : [];
        $settings['default_style'] = trim((string) ($post['default_style'] ?? 'default'));
        $settings = SiteTableDisplaySettings::normalize($settings);

        try {
            $this->saveOption(SiteTableDisplaySettings::OPTION_KEY, json_encode($settings, JSON_UNESCAPED_UNICODE));
            return ['success' => true, 'message' => 'Tabellen-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
            return $this->failResult('Tabellen-Einstellungen konnten nicht gespeichert werden.', $e, [
                'context' => 'save_settings',
            ]);
        }
    }

    /**
     * Tabelle speichern
     */
    public function save(array $post): array
    {
        $id          = (int)($post['id'] ?? 0);
        $tableName   = $this->sanitizeText((string)($post['table_name'] ?? ''), self::MAX_TABLE_NAME_LENGTH);
        $description = $this->sanitizeText((string)($post['description'] ?? ''), self::MAX_DESCRIPTION_LENGTH);
        $displaySettings = $this->loadDisplaySettings();

        if ($tableName === '') {
            return ['success' => false, 'error' => 'Tabellenname darf nicht leer sein.'];
        }

        // Settings zusammenstellen
        $settings = self::DEFAULT_SETTINGS;
        $settings['content_mode'] = 'table';
        foreach (self::DEFAULT_SETTINGS as $key => $default) {
            if (is_bool($default)) {
                $settings[$key] = isset($post['setting_' . $key]);
            } elseif (is_int($default)) {
                $settings[$key] = (int)($post['setting_' . $key] ?? $default);
            } elseif (is_array($default)) {
                $settings[$key] = $default;
            } else {
                $settings[$key] = trim((string)($post['setting_' . $key] ?? $default));
            }
        }

        $settings['style_theme'] = $this->normalizeStyleTheme((string) ($settings['style_theme'] ?? 'default'));
        $settings['caption'] = $this->sanitizeText((string) ($settings['caption'] ?? ''), 255);
        $settings['aria_label'] = $this->sanitizeText((string) ($settings['aria_label'] ?? ''), 255);
        $settings['page_size'] = $this->normalizeInteger((int) ($settings['page_size'] ?? 10), 5, 100, 10);
        $settings['allow_export_excel'] = false;

        $contentSource = SiteTableContentSource::normalizeSettings(
            isset($post['setting_content_source_enabled']),
            $post['content_source_mode'] ?? '',
            $post['content_source_item_keys'] ?? [],
            $post['content_source_category_id'] ?? 0
        );
        if ($contentSource['error'] !== '') {
            return ['success' => false, 'error' => $contentSource['error']];
        }

        $selectionError = SiteTableContentSource::validateSelection($this->db, $this->prefix, $contentSource);
        if ($selectionError !== '') {
            return ['success' => false, 'error' => $selectionError];
        }

        $settings['content_source_enabled'] = $contentSource['enabled'];
        $settings['content_source_mode'] = $contentSource['mode'];
        $settings['content_source_item_keys'] = $contentSource['item_keys'];
        $settings['content_source_category_id'] = $contentSource['category_id'];

        if ($contentSource['enabled']) {
            $columns = SiteTableContentSource::buildColumns([
                'mode' => $contentSource['mode'],
                'item_keys' => $contentSource['item_keys'],
                'category_id' => $contentSource['category_id'],
            ]);
            $rows = SiteTableContentSource::buildRows($this->db, $this->prefix, [
                'mode' => $contentSource['mode'],
                'item_keys' => $contentSource['item_keys'],
                'category_id' => $contentSource['category_id'],
            ], self::MAX_ROWS);
        } else {
            // Spalten & Zeilen aus JSON-Feldern
            $columns = $this->decodeEditorArrayPayload($post['columns_json'] ?? null);
            if ($columns === null) {
                return ['success' => false, 'error' => 'Ungültige Spalten-Konfiguration. Bitte Editor neu laden und erneut versuchen.'];
            }

            $rows = $this->decodeEditorArrayPayload($post['rows_json'] ?? null);
            if ($rows === null) {
                return ['success' => false, 'error' => 'Ungültige Zeilen-Konfiguration. Bitte Editor neu laden und erneut versuchen.'];
            }

            $normalizedColumns = $this->normalizeColumns($columns);
            if ($normalizedColumns['error'] !== '') {
                return ['success' => false, 'error' => $normalizedColumns['error']];
            }

            $normalizedRows = $this->normalizeRows($rows, $normalizedColumns['columns']);
            if ($normalizedRows['error'] !== '') {
                return ['success' => false, 'error' => $normalizedRows['error']];
            }

            $columns = $normalizedColumns['columns'];
            $rows = $normalizedRows['rows'];
        }

        if (!in_array((string) $settings['style_theme'], $displaySettings['enabled_styles'], true)) {
            $settings['style_theme'] = $displaySettings['default_style'];
        }

        $columnsJson  = json_encode($columns, JSON_UNESCAPED_UNICODE);
        $rowsJson     = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $tableSlug    = $this->buildUniqueTableSlug($tableName, $id > 0 ? $id : null);

        try {
            if ($id > 0) {
                $params = [$tableName, $description, $columnsJson, $rowsJson, $settingsJson];
                $sql = "UPDATE {$this->prefix}site_tables 
                        SET table_name = ?, description = ?, columns_json = ?, rows_json = ?, settings_json = ?";
                if ($this->hasTableSlugColumn()) {
                    $sql .= ', table_slug = ?';
                    $params[] = $tableSlug;
                }
                $sql .= ', updated_at = NOW() WHERE id = ?';
                $params[] = $id;
                $this->db->execute($sql, $params);
                return ['success' => true, 'id' => $id, 'message' => 'Tabelle aktualisiert.'];
            } else {
                $columns = ['table_name', 'description', 'columns_json', 'rows_json', 'settings_json', 'created_at', 'updated_at'];
                $placeholders = ['?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
                $params = [$tableName, $description, $columnsJson, $rowsJson, $settingsJson];
                if ($this->hasTableSlugColumn()) {
                    $columns[] = 'table_slug';
                    $placeholders[] = '?';
                    $params[] = $tableSlug;
                }
                $this->db->execute(
                    "INSERT INTO {$this->prefix}site_tables (" . implode(', ', $columns) . ")
                     VALUES (" . implode(', ', $placeholders) . ")",
                    $params
                );
                $newId = (int)$this->db->lastInsertId();
                return ['success' => true, 'id' => $newId, 'message' => 'Tabelle erstellt.'];
            }
        } catch (\Throwable $e) {
            return $this->failResult('Tabelle konnte nicht gespeichert werden.', $e, [
                'context' => 'save',
                'table_id' => $id,
                'table_name' => $tableName,
            ]);
        }
    }

    /**
     * Tabelle löschen
     */
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Tabellen-ID.'];
        }

        $existingId = $this->db->get_var(
            "SELECT id FROM {$this->prefix}site_tables WHERE id = ? LIMIT 1",
            [$id]
        );

        if ($existingId === null) {
            return ['success' => false, 'error' => 'Tabelle wurde nicht gefunden.'];
        }

        try {
            $statement = $this->db->execute("DELETE FROM {$this->prefix}site_tables WHERE id = ? LIMIT 1", [$id]);
            if ($statement->rowCount() < 1) {
                return ['success' => false, 'error' => 'Tabelle wurde zwischenzeitlich entfernt. Bitte Liste neu laden.'];
            }

            return ['success' => true, 'message' => 'Tabelle gelöscht.'];
        } catch (\Throwable $e) {
            return $this->failResult('Tabelle konnte nicht gelöscht werden.', $e, [
                'context' => 'delete',
                'table_id' => $id,
            ]);
        }
    }

    /**
     * Tabelle duplizieren
     */
    public function duplicate(int $id): array
    {
        $source = $this->db->get_row(
            "SELECT * FROM {$this->prefix}site_tables WHERE id = ?",
            [$id]
        );

        if (!$source) {
            return ['success' => false, 'error' => 'Tabelle nicht gefunden.'];
        }

        $sourceData = (array)$source;

        try {
            $copyName = ($sourceData['table_name'] ?? 'Tabelle') . ' (Kopie)';
            $columns = ['table_name', 'description', 'columns_json', 'rows_json', 'settings_json', 'created_at', 'updated_at'];
            $placeholders = ['?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
            $params = [
                $copyName,
                $sourceData['description'] ?? '',
                $sourceData['columns_json'] ?? '[]',
                $sourceData['rows_json'] ?? '[]',
                $sourceData['settings_json'] ?? '{}',
            ];
            if ($this->hasTableSlugColumn()) {
                $columns[] = 'table_slug';
                $placeholders[] = '?';
                $params[] = $this->buildUniqueTableSlug((string)$copyName, null);
            }
            $this->db->execute(
                "INSERT INTO {$this->prefix}site_tables (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );
            $newId = (int)$this->db->lastInsertId();
            return ['success' => true, 'id' => $newId, 'message' => 'Tabelle dupliziert.'];
        } catch (\Throwable $e) {
            return $this->failResult('Tabelle konnte nicht dupliziert werden.', $e, [
                'context' => 'duplicate',
                'table_id' => $id,
            ]);
        }
    }

    private function sanitizeSearchTerm(string $search): string
    {
        $search = preg_replace('/[\x00-\x1F\x7F]/u', '', $search) ?? '';

        return trim($search);
    }

    /**
     * @return array<int, mixed>|null
     */
    private function decodeEditorArrayPayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return [];
        }

        if (is_string($payload)) {
            $payload = trim($payload);
        } elseif (is_scalar($payload)) {
            $payload = trim((string) $payload);
        } else {
            return null;
        }

        if ($payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim(strip_tags($value))) ?? '';

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }

    private function normalizeStyleTheme(string $value): string
    {
        return in_array($value, self::ALLOWED_STYLE_THEMES, true) ? $value : 'default';
    }

    private function normalizeInteger(int $value, int $min, int $max, int $fallback): int
    {
        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    private function applyFormValuesToTable(?array $table, array $formValues): array
    {
        $table ??= [
            'id' => max(0, (int) ($formValues['id'] ?? 0)),
            'table_name' => '',
            'description' => '',
            'columns' => [],
            'rows' => [],
            'settings' => self::DEFAULT_SETTINGS,
        ];

        $table['table_name'] = (string) ($formValues['table_name'] ?? ($table['table_name'] ?? ''));
        $table['description'] = (string) ($formValues['description'] ?? ($table['description'] ?? ''));

        $columns = $this->decodeEditorArrayPayload($formValues['columns_json'] ?? null);
        if (is_array($columns)) {
            $normalizedColumns = $this->normalizeColumns($columns);
            $table['columns'] = $normalizedColumns['columns'];
        }

        $rows = $this->decodeEditorArrayPayload($formValues['rows_json'] ?? null);
        if (is_array($rows)) {
            $normalizedRows = $this->normalizeRows($rows, is_array($table['columns'] ?? null) ? $table['columns'] : []);
            $table['rows'] = $normalizedRows['rows'];
        }

        $settings = array_merge(self::DEFAULT_SETTINGS, is_array($table['settings'] ?? null) ? $table['settings'] : []);
        foreach (self::DEFAULT_SETTINGS as $key => $default) {
            if ($key === 'content_source_item_keys') {
                $settings[$key] = is_array($formValues[$key] ?? null)
                    ? array_values(array_map('strval', $formValues[$key]))
                    : (is_array($settings[$key] ?? null) ? $settings[$key] : []);
                continue;
            }

            if ($key === 'content_source_category_id') {
                $settings[$key] = max(0, (int) ($formValues[$key] ?? ($settings[$key] ?? 0)));
                continue;
            }

            if (is_bool($default)) {
                $settings[$key] = !empty($formValues['setting_' . $key]);
            } elseif (is_int($default)) {
                $settings[$key] = (int) ($formValues['setting_' . $key] ?? $settings[$key] ?? $default);
            } elseif (is_array($default)) {
                $settings[$key] = is_array($settings[$key] ?? null) ? $settings[$key] : $default;
            } else {
                $settings[$key] = (string) ($formValues['setting_' . $key] ?? $settings[$key] ?? $default);
            }
        }

        $contentSource = SiteTableContentSource::normalizeSettings(
            isset($formValues['setting_content_source_enabled']),
            $formValues['content_source_mode'] ?? ($settings['content_source_mode'] ?? ''),
            $formValues['content_source_item_keys'] ?? ($settings['content_source_item_keys'] ?? []),
            $formValues['content_source_category_id'] ?? ($settings['content_source_category_id'] ?? 0)
        );

        $settings['style_theme'] = $this->normalizeStyleTheme((string) ($settings['style_theme'] ?? 'default'));
        $settings['caption'] = $this->sanitizeText((string) ($settings['caption'] ?? ''), 255);
        $settings['aria_label'] = $this->sanitizeText((string) ($settings['aria_label'] ?? ''), 255);
        $settings['page_size'] = $this->normalizeInteger((int) ($settings['page_size'] ?? 10), 5, 100, 10);
        $settings['allow_export_excel'] = false;
        $settings['content_source_enabled'] = $contentSource['enabled'];
        $settings['content_source_mode'] = $contentSource['mode'];
        $settings['content_source_item_keys'] = $contentSource['item_keys'];
        $settings['content_source_category_id'] = $contentSource['category_id'];

        $table['settings'] = $settings;

        return $table;
    }

    /**
     * @param array<int, mixed> $columns
     * @return array{columns:array<int, array{label:string,type:string}>,error:string}
     */
    private function normalizeColumns(array $columns): array
    {
        if (count($columns) > self::MAX_COLUMNS) {
            return [
                'columns' => [],
                'error' => 'Es sind maximal ' . self::MAX_COLUMNS . ' Spalten erlaubt.',
            ];
        }

        $normalized = [];
        $seenLabels = [];

        foreach ($columns as $index => $column) {
            if (!is_array($column)) {
                return [
                    'columns' => [],
                    'error' => 'Ungültige Spaltendefinition in Zeile ' . ($index + 1) . '.',
                ];
            }

            $label = $this->sanitizeText((string)($column['label'] ?? ''), self::MAX_COLUMN_LABEL_LENGTH);
            if ($label === '') {
                $label = 'Spalte ' . ($index + 1);
            }

            if (isset($seenLabels[$label])) {
                $label .= ' ' . ($index + 1);
            }

            $seenLabels[$label] = true;
            $normalized[] = [
                'label' => $label,
                'type' => 'text',
            ];
        }

        return [
            'columns' => $normalized,
            'error' => '',
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<int, array{label:string,type:string}> $columns
     * @return array{rows:array<int, array<string, string>>,error:string}
     */
    private function normalizeRows(array $rows, array $columns): array
    {
        if ($rows !== [] && $columns === []) {
            return [
                'rows' => [],
                'error' => 'Zeilen können nur gespeichert werden, wenn mindestens eine Spalte vorhanden ist.',
            ];
        }

        if (count($rows) > self::MAX_ROWS) {
            return [
                'rows' => [],
                'error' => 'Es sind maximal ' . self::MAX_ROWS . ' Zeilen erlaubt.',
            ];
        }

        $normalizedRows = [];
        $columnLabels = array_map(static fn (array $column): string => (string)($column['label'] ?? ''), $columns);

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                return [
                    'rows' => [],
                    'error' => 'Ungültige Zeile in Position ' . ($rowIndex + 1) . '.',
                ];
            }

            $normalizedRow = [];
            foreach ($columnLabels as $label) {
                $cellValue = $row[$label] ?? '';
                if (is_array($cellValue) || is_object($cellValue)) {
                    $cellValue = '';
                }

                $normalizedRow[$label] = $this->sanitizeTableCell((string)$cellValue, self::MAX_CELL_LENGTH);
            }

            $normalizedRows[] = $normalizedRow;
        }

        return [
            'rows' => $normalizedRows,
            'error' => '',
        ];
    }

    /**
     * @param array<int, array{label:string,type:string}> $columns
     * @param array<int, array<string, string>> $rows
     * @return array{columns:int,rows:int,cells:int}
     */
    private function buildEditorSummary(array $columns, array $rows): array
    {
        return [
            'columns' => count($columns),
            'rows' => count($rows),
            'cells' => count($columns) * count($rows),
        ];
    }

    /**
     * @param array<int, array{label:string,type:string}> $columns
     * @param array<int, array<string, string>> $rows
     */
    private function encodeEditorConfig(array $columns, array $rows): string
    {
        return (string) json_encode([
            'columns' => $columns,
            'rows' => $rows,
            'limits' => [
                'maxColumns' => self::MAX_COLUMNS,
                'maxRows' => self::MAX_ROWS,
                'maxColumnLabelLength' => self::MAX_COLUMN_LABEL_LENGTH,
                'maxCellLength' => self::MAX_CELL_LENGTH,
            ],
            'contentSource' => [
                'enabled' => false,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function sanitizeTableCell(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($value)) ?? '';
        $value = function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);

        if ($value === '') {
            return '';
        }

        $value = $this->decodeAllowedTableCellHtmlEntities($value);

        if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $value) !== 1) {
            return htmlspecialchars_decode(htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8'), ENT_NOQUOTES);
        }

        return PurifierService::getInstance()->purify($value, 'table_cell');
    }

    private function decodeAllowedTableCellHtmlEntities(string $value): string
    {
        return preg_match('/&lt;\s*\/?\s*(?:a|strong|b|em|i|u)(?:\s|&gt;|>)/i', $value) === 1
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $value;
    }

    /**
     * @param array<string, mixed> $table
     * @return array<string, mixed>
     */
    private function normalizeListRow(array $table): array
    {
        $updatedAt = (string) ($table['updated_at'] ?? $table['created_at'] ?? '');

        $table['id'] = (int) ($table['id'] ?? 0);
        $table['table_name'] = trim((string) ($table['table_name'] ?? ''));
        $table['description'] = trim((string) ($table['description'] ?? ''));
        $table['description_excerpt'] = cms_truncate_text($table['description'], 160);
        $table['col_count'] = (int) ($table['col_count'] ?? 0);
        $table['row_count'] = (int) ($table['row_count'] ?? 0);
        $table['updated_label'] = $this->formatDateLabel($updatedAt);

        return $table;
    }

    private function formatDateLabel(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp !== false ? date('d.m.Y', $timestamp) : '–';
    }

    private function buildUniqueTableSlug(string $title, ?int $excludeId = null): string
    {
        $baseSlug = $this->sanitizeSlug($title);
        if ($baseSlug === '') {
            $baseSlug = 'tabelle';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->tableSlugExists($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function tableSlugExists(string $slug, ?int $excludeId = null): bool
    {
        if (!$this->hasTableSlugColumn()) {
            return false;
        }

        $sql = "SELECT id FROM {$this->prefix}site_tables WHERE table_slug = ?";
        $params = [$slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->get_var($sql . ' LIMIT 1', $params) !== null;
    }

    private function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = (string)preg_replace('/[^a-z0-9]+/i', '-', $value);
        return trim($value, '-');
    }

    private function hasTableSlugColumn(): bool
    {
        if ($this->hasTableSlugColumn !== null) {
            return $this->hasTableSlugColumn;
        }

        try {
            $column = $this->db->get_var("SHOW COLUMNS FROM {$this->prefix}site_tables LIKE 'table_slug'");
            $this->hasTableSlugColumn = $column !== null;
        } catch (\Throwable) {
            $this->hasTableSlugColumn = false;
        }

        return $this->hasTableSlugColumn;
    }

    /** @return array<string,mixed> */
    private function loadDisplaySettings(): array
    {
        $row = $this->db->get_row(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [SiteTableDisplaySettings::OPTION_KEY]
        );

        $saved = [];
        if ($row && !empty($row->option_value)) {
            $saved = \CMS\Json::decodeArray($row->option_value ?? null, []);
        }

        return SiteTableDisplaySettings::normalize(is_array($saved) ? $saved : []);
    }

    private function saveOption(string $key, string $value): void
    {
        $exists = (int) ($this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
            [$key]
        ) ?? 0);

        if ($exists > 0) {
            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                [$value, $key]
            );
            return;
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
            [$key, $value]
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{success:false,error:string}
     */
    private function failResult(string $message, \Throwable $exception, array $context = []): array
    {
        $context['exception'] = $exception->getMessage();
        $this->logger->error($message, $context);

        return ['success' => false, 'error' => $message];
    }
}
