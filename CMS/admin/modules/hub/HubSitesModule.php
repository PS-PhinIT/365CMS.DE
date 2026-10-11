<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/HubTemplateProfileManager.php';

use CMS\AuditLogger;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

class HubSitesModule
{
    private Database $db;
    private string $prefix;
    private HubTemplateProfileManager $templateProfileManager;
    private ?bool $hasTableSlugColumn = null;
    /**
     * @var array<string, int>|null
     */
    private ?array $hubDomainAssignmentsCache = null;

    /** @var string[] */
    private const ALLOWED_CARD_LAYOUTS = ['standard', 'feature', 'compact'];
    /** @var string[] */
    private const ALLOWED_IMAGE_POSITIONS = ['top', 'left', 'right'];
    /** @var string[] */
    private const ALLOWED_IMAGE_FITS = ['cover', 'contain'];
    /** @var string[] */
    private const ALLOWED_IMAGE_RATIOS = ['wide', 'square', 'portrait'];
    /** @var string[] */
    private const ALLOWED_META_LAYOUTS = ['split', 'stacked'];

    private const DEFAULT_SETTINGS = [
        'content_mode' => 'hub',
        'hub_slug' => '',
        'hub_domains' => [],
        'hub_template' => 'general-it',
        'hub_feature_card_interval' => 0,
        'hub_feature_cards_json' => '[]',
        'hub_badge' => '',
        'hub_badge_en' => '',
        'hub_description_en' => '',
        'hub_hero_title' => '',
        'hub_hero_title_en' => '',
        'hub_hero_text' => '',
        'hub_hero_text_en' => '',
        'hub_cta_label' => '',
        'hub_cta_label_en' => '',
        'hub_cta_url' => '',
        'hub_meta_audience' => '',
        'hub_meta_audience_en' => '',
        'hub_meta_owner' => '',
        'hub_meta_owner_en' => '',
        'hub_meta_update_cycle' => '',
        'hub_meta_update_cycle_en' => '',
        'hub_meta_focus' => '',
        'hub_meta_focus_en' => '',
        'hub_meta_kpi' => '',
        'hub_meta_kpi_en' => '',
        'hub_links_json' => '[]',
        'hub_sections_json' => '[]',
        'hub_card_layout' => '',
        'hub_card_image_position' => '',
        'hub_card_image_fit' => '',
        'hub_card_image_ratio' => '',
        'hub_card_meta_layout' => '',
        'hub_card_radius' => '',
        'hub_card_row_gap' => '',
        'hub_feature_image_width' => '',
        'hub_feature_image_height' => '',
        'hub_show_author_box' => false,
    ];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->templateProfileManager = new HubTemplateProfileManager($this->db, $this->prefix);
    }

    public function getListData(): array
    {
        $search = $this->sanitizeSearchTerm((string)($_GET['q'] ?? ''));
        $where = '';
        $params = [];
        $selectSlug = $this->hasTableSlugColumn() ? 'table_slug,' : "'' AS table_slug,";

        if ($search !== '') {
            $where = ' AND (table_name LIKE ? OR description LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $rows = $this->db->get_results(
            "SELECT id, table_name, description, {$selectSlug} rows_json, settings_json,
                    JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.hub_slug')) AS hub_slug,
                    created_at, updated_at
             FROM {$this->prefix}site_tables
             WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'{$where}
             ORDER BY updated_at DESC
             LIMIT 200",
            $params
        ) ?: [];

        $sites = array_map(function ($row): array {
            $item = (array)$row;
            $settings = \CMS\Json::decodeArray($item['settings_json'] ?? null, []);
            return [
                'id' => (int)($item['id'] ?? 0),
                'table_name' => (string)($item['table_name'] ?? ''),
                'description' => trim(strip_tags((string)($item['description'] ?? ''))),
                'hub_slug' => (string)(($item['hub_slug'] ?? '') !== '' ? $item['hub_slug'] : ($item['table_slug'] ?? '')),
                'template' => (string)($settings['hub_template'] ?? 'general-it'),
                'card_count' => count(\CMS\Json::decodeArray($item['rows_json'] ?? null, [])),
                'updated_at' => (string)($item['updated_at'] ?? $item['created_at'] ?? ''),
            ];
        }, $rows);

        return [
            'sites' => $sites,
            'total' => count($sites),
            'search' => $search,
            'templateOptions' => $this->templateProfileManager->getChoices(),
        ];
    }

    public function getEditData(?int $id, array $formValues = []): array
    {
        $site = null;

        if ($id !== null) {
            $row = $this->db->get_row(
                "SELECT * FROM {$this->prefix}site_tables WHERE id = ? LIMIT 1",
                [$id]
            );

            if ($row) {
                $site = (array)$row;
                $site['cards'] = \CMS\Json::decodeArray($site['rows_json'] ?? null, []);
                $site['settings'] = array_merge(
                    self::DEFAULT_SETTINGS,
                    \CMS\Json::decodeArray($site['settings_json'] ?? null, [])
                );
                if (($site['settings']['hub_slug'] ?? '') === '' && !empty($site['table_slug'])) {
                    $site['settings']['hub_slug'] = (string)$site['table_slug'];
                }
            }
        }

        if ($formValues !== []) {
            $site = $this->applyFormValuesToSite($site, $formValues);
        }

        $hubDomainsInput = isset($formValues['hub_domains'])
            ? (string) $formValues['hub_domains']
            : implode("\n", array_map('strval', is_array($site['settings']['hub_domains'] ?? null) ? $site['settings']['hub_domains'] : []));

        return [
            'site' => $site,
            'isNew' => $site === null,
            'defaults' => self::DEFAULT_SETTINGS,
            'templateOptions' => $this->templateProfileManager->getChoices(),
            'templateProfiles' => $this->templateProfileManager->getProfiles(),
            'hubDomainsInput' => $hubDomainsInput,
        ];
    }

    public function getTemplateListData(): array
    {
        return $this->templateProfileManager->getTemplateListData();
    }

    public function getTemplateEditData(?string $key): array
    {
        return $this->templateProfileManager->getTemplateEditData($key);
    }

    public function save(array $post): array
    {
        $id = (int)($post['id'] ?? 0);
        $name = $this->sanitizePlainText((string)($post['site_name'] ?? ''), 160);
        $description = $this->sanitizeRichText((string)($post['description'] ?? ''), 4000);
        $cards = \CMS\Json::decodeArray($post['cards_json'] ?? null, []);
        $normalizedDomains = $this->normalizeHubDomains((string)($post['hub_domains'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'error' => 'Name darf nicht leer sein.'];
        }

        if (!empty($normalizedDomains['errors'])) {
            return ['success' => false, 'error' => (string)$normalizedDomains['errors'][0]];
        }

        if (!is_array($cards)) {
            $cards = [];
        }

        $hubDomains = $normalizedDomains['domains'] ?? [];
        foreach ($hubDomains as $domain) {
            if ($this->hubDomainExists((string)$domain, $id > 0 ? $id : null)) {
                return ['success' => false, 'error' => 'Die Zusatzdomain „' . $domain . '“ ist bereits einer anderen Hub-Site zugeordnet.'];
            }
        }

        $slug = $this->buildUniqueHubSlug($name, $id > 0 ? $id : null);

        $templateChoices = $this->templateProfileManager->getChoices();

        $settings = [
            'content_mode' => 'hub',
            'hub_slug' => $slug,
            'hub_domains' => $hubDomains,
            'hub_template' => array_key_exists((string)($post['hub_template'] ?? ''), $templateChoices) ? (string)$post['hub_template'] : 'general-it',
            'hub_feature_card_interval' => array_key_exists('hub_feature_card_interval', $post)
                ? $this->normalizeNumber((int)($post['hub_feature_card_interval'] ?? 0), 0, 12, 0)
                : 0,
            'hub_feature_cards_json' => array_key_exists('hub_feature_cards_json', $post)
                ? $this->normalizeFeatureCardsJson((string)$post['hub_feature_cards_json'])
                : '[]',
            'hub_badge' => $this->sanitizePlainText((string)($post['hub_badge'] ?? ''), 80),
            'hub_badge_en' => $this->sanitizePlainText((string)($post['hub_badge_en'] ?? ''), 80),
            'hub_description_en' => $this->sanitizeRichText((string)($post['hub_description_en'] ?? ''), 4000),
            'hub_hero_title' => $this->sanitizePlainText((string)($post['hub_hero_title'] ?? ''), 160),
            'hub_hero_title_en' => $this->sanitizePlainText((string)($post['hub_hero_title_en'] ?? ''), 160),
            'hub_hero_text' => $this->sanitizeRichText((string)($post['hub_hero_text'] ?? ''), 4000),
            'hub_hero_text_en' => $this->sanitizeRichText((string)($post['hub_hero_text_en'] ?? ''), 4000),
            'hub_cta_label' => $this->sanitizePlainText((string)($post['hub_cta_label'] ?? ''), 60),
            'hub_cta_label_en' => $this->sanitizePlainText((string)($post['hub_cta_label_en'] ?? ''), 60),
            'hub_cta_url' => $this->normalizeUrlValue((string)($post['hub_cta_url'] ?? ''), 500),
            'hub_meta_audience' => $this->sanitizePlainText((string)($post['hub_meta_audience'] ?? ''), 120),
            'hub_meta_audience_en' => $this->sanitizePlainText((string)($post['hub_meta_audience_en'] ?? ''), 120),
            'hub_meta_owner' => $this->sanitizePlainText((string)($post['hub_meta_owner'] ?? ''), 120),
            'hub_meta_owner_en' => $this->sanitizePlainText((string)($post['hub_meta_owner_en'] ?? ''), 120),
            'hub_meta_update_cycle' => $this->sanitizePlainText((string)($post['hub_meta_update_cycle'] ?? ''), 120),
            'hub_meta_update_cycle_en' => $this->sanitizePlainText((string)($post['hub_meta_update_cycle_en'] ?? ''), 120),
            'hub_meta_focus' => $this->sanitizePlainText((string)($post['hub_meta_focus'] ?? ''), 160),
            'hub_meta_focus_en' => $this->sanitizePlainText((string)($post['hub_meta_focus_en'] ?? ''), 160),
            'hub_meta_kpi' => $this->sanitizePlainText((string)($post['hub_meta_kpi'] ?? ''), 120),
            'hub_meta_kpi_en' => $this->sanitizePlainText((string)($post['hub_meta_kpi_en'] ?? ''), 120),
            'hub_links_json' => array_key_exists('hub_links_json', $post)
                ? $this->normalizeJsonArray((string)$post['hub_links_json'], 'link')
                : '[]',
            'hub_sections_json' => array_key_exists('hub_sections_json', $post)
                ? $this->normalizeJsonArray((string)$post['hub_sections_json'], 'section')
                : '[]',
            'hub_card_layout' => array_key_exists('hub_card_layout', $post)
                ? $this->normalizeSetting((string)$post['hub_card_layout'], self::ALLOWED_CARD_LAYOUTS, 'standard')
                : '',
            'hub_card_image_position' => array_key_exists('hub_card_image_position', $post)
                ? $this->normalizeSetting((string)$post['hub_card_image_position'], self::ALLOWED_IMAGE_POSITIONS, 'top')
                : '',
            'hub_card_image_fit' => array_key_exists('hub_card_image_fit', $post)
                ? $this->normalizeSetting((string)$post['hub_card_image_fit'], self::ALLOWED_IMAGE_FITS, 'cover')
                : '',
            'hub_card_image_ratio' => array_key_exists('hub_card_image_ratio', $post)
                ? $this->normalizeSetting((string)$post['hub_card_image_ratio'], self::ALLOWED_IMAGE_RATIOS, 'wide')
                : '',
            'hub_card_meta_layout' => array_key_exists('hub_card_meta_layout', $post)
                ? $this->normalizeSetting((string)$post['hub_card_meta_layout'], self::ALLOWED_META_LAYOUTS, 'split')
                : '',
            'hub_card_radius' => array_key_exists('hub_card_radius', $post) && trim((string)$post['hub_card_radius']) !== ''
                ? $this->normalizeNumber((int)$post['hub_card_radius'], 0, 48, 20)
                : '',
            'hub_card_row_gap' => array_key_exists('hub_card_row_gap', $post) && trim((string)$post['hub_card_row_gap']) !== ''
                ? $this->normalizeNumber((int)$post['hub_card_row_gap'], 30, 160, 30)
                : '',
            'hub_feature_image_width' => array_key_exists('hub_feature_image_width', $post) && trim((string)$post['hub_feature_image_width']) !== ''
                ? $this->normalizeNumber((int)$post['hub_feature_image_width'], 20, 60, 34)
                : '',
            'hub_feature_image_height' => array_key_exists('hub_feature_image_height', $post) && trim((string)$post['hub_feature_image_height']) !== ''
                ? $this->normalizeNumber((int)$post['hub_feature_image_height'], 160, 520, 260)
                : '',
            'hub_show_author_box' => $this->normalizeBoolean($post['hub_show_author_box'] ?? false),
        ];

        $filteredSettings = Hooks::applyFilters('cms_prepare_hub_settings_payload', $settings, $post, $id);
        if (is_array($filteredSettings)) {
            $settings = array_merge($settings, $filteredSettings);
        }

        $normalizedCards = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }

            $title = $this->sanitizePlainText((string)($card['title'] ?? ''), 160);
            $url = $this->normalizeUrlValue((string)($card['url'] ?? ''), 500);
            if ($title === '') {
                continue;
            }

            $normalizedCards[] = [
                'is_feature' => $this->normalizeBoolean($card['is_feature'] ?? false),
                'feature_spacing_top' => $this->normalizeNumber((int)($card['feature_spacing_top'] ?? 0), 0, 240, 0),
                'title' => $title,
                'title_en' => $this->sanitizePlainText((string)($card['title_en'] ?? ''), 160),
                'url' => $url !== '' ? $url : '#',
                'summary' => $this->sanitizeRichText((string)($card['summary'] ?? ''), 4000),
                'summary_en' => $this->sanitizeRichText((string)($card['summary_en'] ?? ''), 4000),
                'badge' => $this->sanitizePlainText((string)($card['badge'] ?? ''), 80),
                'badge_en' => $this->sanitizePlainText((string)($card['badge_en'] ?? ''), 80),
                'meta' => $this->sanitizePlainText((string)($card['meta'] ?? ''), 120),
                'meta_en' => $this->sanitizePlainText((string)($card['meta_en'] ?? ''), 120),
                'meta_left' => $this->sanitizePlainText((string)($card['meta_left'] ?? ''), 120),
                'meta_left_en' => $this->sanitizePlainText((string)($card['meta_left_en'] ?? ''), 120),
                'meta_right' => $this->sanitizePlainText((string)($card['meta_right'] ?? ''), 120),
                'meta_right_en' => $this->sanitizePlainText((string)($card['meta_right_en'] ?? ''), 120),
                'image_url' => $this->normalizeUrlValue((string)($card['image_url'] ?? ''), 500),
                'image_alt' => $this->sanitizePlainText((string)($card['image_alt'] ?? ''), 160),
                'image_alt_en' => $this->sanitizePlainText((string)($card['image_alt_en'] ?? ''), 160),
                'button_text' => $this->sanitizePlainText((string)($card['button_text'] ?? ''), 80),
                'button_text_en' => $this->sanitizePlainText((string)($card['button_text_en'] ?? ''), 80),
                'button_link' => $this->normalizeUrlValue((string)($card['button_link'] ?? ''), 500),
            ];
        }

        $filteredCards = Hooks::applyFilters('cms_prepare_hub_cards_payload', $normalizedCards, $post, $id);
        if (is_array($filteredCards)) {
            $normalizedCards = $filteredCards;
        }

        try {
            if ($id > 0) {
                $params = [
                    $name,
                    $description,
                    json_encode($normalizedCards, JSON_UNESCAPED_UNICODE),
                    json_encode($settings, JSON_UNESCAPED_UNICODE),
                ];

                $sql = "UPDATE {$this->prefix}site_tables
                        SET table_name = ?, description = ?, columns_json = '[]', rows_json = ?, settings_json = ?";

                if ($this->hasTableSlugColumn()) {
                    $sql .= ', table_slug = ?';
                    $params[] = $slug;
                }

                $sql .= ', updated_at = NOW() WHERE id = ?';
                $params[] = $id;

                $this->db->execute($sql, $params);

                Hooks::doAction('cms_after_hub_save', $id, $settings, $normalizedCards, $post);

                return ['success' => true, 'id' => $id, 'slug' => $slug, 'message' => 'Routing / Hub Site aktualisiert.'];
            }

            $columns = ['table_name', 'description', 'columns_json', 'rows_json', 'settings_json'];
            $placeholders = ['?', '?', "'[]'", '?', '?'];
            $params = [
                $name,
                $description,
                json_encode($normalizedCards, JSON_UNESCAPED_UNICODE),
                json_encode($settings, JSON_UNESCAPED_UNICODE),
            ];

            if ($this->hasTableSlugColumn()) {
                $columns[] = 'table_slug';
                $placeholders[] = '?';
                $params[] = $slug;
            }

            $columns[] = 'created_at';
            $columns[] = 'updated_at';
            $placeholders[] = 'NOW()';
            $placeholders[] = 'NOW()';

            $this->db->execute(
                "INSERT INTO {$this->prefix}site_tables (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );

            Hooks::doAction('cms_after_hub_save', (int)$this->db->insert_id(), $settings, $normalizedCards, $post);

            return ['success' => true, 'id' => (int)$this->db->insert_id(), 'slug' => $slug, 'message' => 'Routing / Hub Site erstellt.'];
        } catch (\Throwable $e) {
            return $this->failResult(
                'hub.save.failed',
                'Hub-Site konnte nicht gespeichert werden.',
                $e,
                ['site_id' => $id, 'hub_slug' => $slug, 'hub_template' => (string)($settings['hub_template'] ?? '')]
            );
        }
    }

    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Hub-Site-ID.'];
        }

        try {
            $this->db->execute("DELETE FROM {$this->prefix}site_tables WHERE id = ?", [$id]);
            $this->resetHubDomainAssignmentsCache();
            return ['success' => true, 'message' => 'Routing / Hub Site gelöscht.'];
        } catch (\Throwable $e) {
            return $this->failResult('hub.delete.failed', 'Hub-Site konnte nicht gelöscht werden.', $e, ['site_id' => $id]);
        }
    }

    public function duplicate(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige Hub-Site-ID.'];
        }

        $source = $this->db->get_row(
            "SELECT * FROM {$this->prefix}site_tables WHERE id = ? LIMIT 1",
            [$id]
        );

        if (!$source) {
            return ['success' => false, 'error' => 'Routing / Hub Site nicht gefunden.'];
        }

        $data = (array)$source;

        try {
            $copyName = ((string)($data['table_name'] ?? 'Routing / Hub Site')) . ' (Kopie)';
            $settings = \CMS\Json::decodeArray($data['settings_json'] ?? null, []);
            $settings = array_merge(self::DEFAULT_SETTINGS, $settings);
            $settings['hub_slug'] = $this->buildUniqueHubSlug($copyName, null);
            $settings['hub_domains'] = [];

            $columns = ['table_name', 'description', 'columns_json', 'rows_json', 'settings_json', 'created_at', 'updated_at'];
            $placeholders = ['?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
            $params = [
                $copyName,
                (string)($data['description'] ?? ''),
                (string)($data['columns_json'] ?? '[]'),
                (string)($data['rows_json'] ?? '[]'),
                json_encode($settings, JSON_UNESCAPED_UNICODE),
            ];

            if ($this->hasTableSlugColumn()) {
                $columns[] = 'table_slug';
                $placeholders[] = '?';
                $params[] = (string)$settings['hub_slug'];
            }

            $this->db->execute(
                "INSERT INTO {$this->prefix}site_tables (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );

            return ['success' => true, 'id' => (int)$this->db->insert_id(), 'slug' => (string)$settings['hub_slug'], 'message' => 'Routing / Hub Site dupliziert.'];
        } catch (\Throwable $e) {
            return $this->failResult('hub.duplicate.failed', 'Hub-Site konnte nicht dupliziert werden.', $e, ['site_id' => $id]);
        }
    }

    public function saveTemplate(array $post): array
    {
        return $this->templateProfileManager->saveTemplate($post);
    }

    public function duplicateTemplate(string $key): array
    {
        return $this->templateProfileManager->duplicateTemplate($key);
    }

    public function deleteTemplate(string $key): array
    {
        return $this->templateProfileManager->deleteTemplate($key);
    }

    private function buildUniqueHubSlug(string $title, ?int $excludeId = null): string
    {
        $baseSlug = $this->sanitizeSlug($title);
        if ($baseSlug === '' || $this->isReservedSlug($baseSlug)) {
            $baseSlug = 'hub-site';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->hubSlugExists($slug, $excludeId) || $this->pageSlugExists($slug)) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function applyFormValuesToSite(?array $site, array $formValues): array
    {
        $site ??= [
            'id' => max(0, (int) ($formValues['id'] ?? 0)),
            'table_name' => '',
            'description' => '',
            'cards' => [],
            'settings' => self::DEFAULT_SETTINGS,
        ];

        $site['table_name'] = (string) ($formValues['site_name'] ?? ($site['table_name'] ?? ''));
        $site['description'] = (string) ($formValues['description'] ?? ($site['description'] ?? ''));

        $cards = \CMS\Json::decodeArray($formValues['cards_json'] ?? null, null);
        if (is_array($cards)) {
            $site['cards'] = $cards;
        }

        $settings = array_merge(self::DEFAULT_SETTINGS, is_array($site['settings'] ?? null) ? $site['settings'] : []);
        foreach (array_keys(self::DEFAULT_SETTINGS) as $key) {
            if ($key === 'hub_domains') {
                $normalizedDomains = $this->normalizeHubDomains((string) ($formValues['hub_domains'] ?? ''));
                $settings[$key] = $normalizedDomains['domains'] ?? [];
                continue;
            }

            if (!array_key_exists($key, $formValues)) {
                continue;
            }

            $settings[$key] = $formValues[$key];
        }

        $settings['hub_feature_card_interval'] = $this->normalizeNumber((int) ($settings['hub_feature_card_interval'] ?? 0), 0, 12, 0);
        $settings['hub_feature_cards_json'] = $this->normalizeFeatureCardsJson((string) ($settings['hub_feature_cards_json'] ?? '[]'));
        $settings['hub_template'] = $this->normalizeTemplateChoice((string) ($settings['hub_template'] ?? 'general-it'));
        $settings['hub_badge'] = $this->sanitizePlainText((string) ($settings['hub_badge'] ?? ''), 80);
        $settings['hub_badge_en'] = $this->sanitizePlainText((string) ($settings['hub_badge_en'] ?? ''), 80);
        $settings['hub_description_en'] = $this->sanitizeRichText((string) ($settings['hub_description_en'] ?? ''), 4000);
        $settings['hub_hero_title'] = $this->sanitizePlainText((string) ($settings['hub_hero_title'] ?? ''), 160);
        $settings['hub_hero_title_en'] = $this->sanitizePlainText((string) ($settings['hub_hero_title_en'] ?? ''), 160);
        $settings['hub_hero_text'] = $this->sanitizeRichText((string) ($formValues['hub_hero_text'] ?? ($settings['hub_hero_text'] ?? '')), 4000);
        $settings['hub_hero_text_en'] = $this->sanitizeRichText((string) ($formValues['hub_hero_text_en'] ?? ($settings['hub_hero_text_en'] ?? '')), 4000);
        $settings['hub_cta_label'] = $this->sanitizePlainText((string) ($settings['hub_cta_label'] ?? ''), 60);
        $settings['hub_cta_label_en'] = $this->sanitizePlainText((string) ($settings['hub_cta_label_en'] ?? ''), 60);
        $settings['hub_cta_url'] = (string) ($formValues['hub_cta_url'] ?? ($settings['hub_cta_url'] ?? ''));
        $settings['hub_meta_audience'] = $this->sanitizePlainText((string) ($settings['hub_meta_audience'] ?? ''), 120);
        $settings['hub_meta_audience_en'] = $this->sanitizePlainText((string) ($settings['hub_meta_audience_en'] ?? ''), 120);
        $settings['hub_meta_owner'] = $this->sanitizePlainText((string) ($settings['hub_meta_owner'] ?? ''), 120);
        $settings['hub_meta_owner_en'] = $this->sanitizePlainText((string) ($settings['hub_meta_owner_en'] ?? ''), 120);
        $settings['hub_meta_update_cycle'] = $this->sanitizePlainText((string) ($settings['hub_meta_update_cycle'] ?? ''), 120);
        $settings['hub_meta_update_cycle_en'] = $this->sanitizePlainText((string) ($settings['hub_meta_update_cycle_en'] ?? ''), 120);
        $settings['hub_meta_focus'] = $this->sanitizePlainText((string) ($settings['hub_meta_focus'] ?? ''), 160);
        $settings['hub_meta_focus_en'] = $this->sanitizePlainText((string) ($settings['hub_meta_focus_en'] ?? ''), 160);
        $settings['hub_meta_kpi'] = $this->sanitizePlainText((string) ($settings['hub_meta_kpi'] ?? ''), 120);
        $settings['hub_meta_kpi_en'] = $this->sanitizePlainText((string) ($settings['hub_meta_kpi_en'] ?? ''), 120);
        $settings['hub_links_json'] = (string) ($formValues['hub_links_json'] ?? ($settings['hub_links_json'] ?? '[]'));
        $settings['hub_sections_json'] = (string) ($formValues['hub_sections_json'] ?? ($settings['hub_sections_json'] ?? '[]'));
        $settings['hub_card_layout'] = trim((string) ($settings['hub_card_layout'] ?? '')) !== ''
            ? $this->normalizeSetting((string) $settings['hub_card_layout'], self::ALLOWED_CARD_LAYOUTS, 'standard')
            : '';
        $settings['hub_card_image_position'] = trim((string) ($settings['hub_card_image_position'] ?? '')) !== ''
            ? $this->normalizeSetting((string) $settings['hub_card_image_position'], self::ALLOWED_IMAGE_POSITIONS, 'top')
            : '';
        $settings['hub_card_image_fit'] = trim((string) ($settings['hub_card_image_fit'] ?? '')) !== ''
            ? $this->normalizeSetting((string) $settings['hub_card_image_fit'], self::ALLOWED_IMAGE_FITS, 'cover')
            : '';
        $settings['hub_card_image_ratio'] = trim((string) ($settings['hub_card_image_ratio'] ?? '')) !== ''
            ? $this->normalizeSetting((string) $settings['hub_card_image_ratio'], self::ALLOWED_IMAGE_RATIOS, 'wide')
            : '';
        $settings['hub_card_meta_layout'] = trim((string) ($settings['hub_card_meta_layout'] ?? '')) !== ''
            ? $this->normalizeSetting((string) $settings['hub_card_meta_layout'], self::ALLOWED_META_LAYOUTS, 'split')
            : '';
        $settings['hub_card_radius'] = trim((string) ($settings['hub_card_radius'] ?? '')) !== ''
            ? $this->normalizeNumber((int) $settings['hub_card_radius'], 0, 48, 20)
            : '';
        $settings['hub_card_row_gap'] = trim((string) ($settings['hub_card_row_gap'] ?? '')) !== ''
            ? $this->normalizeNumber((int) $settings['hub_card_row_gap'], 30, 160, 30)
            : '';
        $settings['hub_feature_image_width'] = trim((string) ($settings['hub_feature_image_width'] ?? '')) !== ''
            ? $this->normalizeNumber((int) $settings['hub_feature_image_width'], 20, 60, 34)
            : '';
        $settings['hub_feature_image_height'] = trim((string) ($settings['hub_feature_image_height'] ?? '')) !== ''
            ? $this->normalizeNumber((int) $settings['hub_feature_image_height'], 160, 520, 260)
            : '';
        $settings['hub_show_author_box'] = $this->normalizeBoolean($formValues['hub_show_author_box'] ?? false);

        if (!empty($site['table_slug']) && trim((string) ($settings['hub_slug'] ?? '')) === '') {
            $settings['hub_slug'] = (string) $site['table_slug'];
        }

        $site['settings'] = $settings;

        return $site;
    }

    private function normalizeTemplateChoice(string $value): string
    {
        $choices = $this->templateProfileManager->getChoices();

        return array_key_exists($value, $choices) ? $value : 'general-it';
    }

    private function hubSlugExists(string $slug, ?int $excludeId = null): bool
    {
                $slugSql = $this->hasTableSlugColumn()
                        ? "(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.hub_slug')) = ? OR table_slug = ?)"
                        : "JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.hub_slug')) = ?";

                $sql = "SELECT id
                                FROM {$this->prefix}site_tables
                                WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'
                                    AND {$slugSql}";
                $params = $this->hasTableSlugColumn() ? [$slug, $slug] : [$slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->get_var($sql . ' LIMIT 1', $params) !== null;
    }

    private function pageSlugExists(string $slug): bool
    {
        return $this->db->get_var(
            "SELECT id FROM {$this->prefix}pages WHERE slug = ? LIMIT 1",
            [$slug]
        ) !== null || $this->isReservedSlug($slug);
    }

    private function isReservedSlug(string $slug): bool
    {
        return in_array($slug, $this->getReservedSlugs(), true);
    }

    /**
     * @return list<string>
     */
    private function getReservedSlugs(): array
    {
        static $reserved = null;

        if (is_array($reserved)) {
            return $reserved;
        }

        $candidates = [
            'admin',
            'api',
            'login',
            'logout',
            'register',
            'member',
            'dashboard',
            'order',
            'search',
            'blog',
            'feed',
            'contact',
            'kontakt',
            'authors',
            'autoren',
            'author',
            'sitemap',
            'sitemap.xml',
            'robots.txt',
            'security.txt',
            '.well-known/security.txt',
            'cookie-einstellungen',
            'site-table',
        ];

        if (function_exists('cms_get_archive_locales') && function_exists('cms_get_archive_base')) {
            foreach (['category', 'tag'] as $archiveType) {
                foreach (cms_get_archive_locales() as $locale) {
                    $archiveBase = trim((string) cms_get_archive_base($archiveType, (string) $locale), '/');
                    if ($archiveBase !== '') {
                        $candidates[] = $archiveBase;
                    }
                }
            }
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $normalizedSlug = $this->sanitizeSlug((string) $candidate);
            if ($normalizedSlug === '') {
                continue;
            }

            $normalized[$normalizedSlug] = $normalizedSlug;
        }

        $reserved = array_values($normalized);

        return $reserved;
    }

    private function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = (string)preg_replace('/[^a-z0-9]+/i', '-', $value);
        return trim($value, '-');
    }

    private function normalizeJsonArray(string $json, string $mode): string
    {
        $items = \CMS\Json::decodeArray($json, []);
        if (!is_array($items)) {
            return '[]';
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($mode === 'link') {
                $label = $this->sanitizePlainText((string)($item['label'] ?? ''), 80);
                $url = $this->normalizeUrlValue((string)($item['url'] ?? ''), 240);
                if ($label === '') {
                    continue;
                }
                $normalized[] = [
                    'label' => $label,
                    'url' => $url !== '' ? $url : '#',
                ];
                continue;
            }

            $title = $this->sanitizePlainText((string)($item['title'] ?? ''), 120);
            $text = $this->truncateText((string)($item['text'] ?? ''), 600);
            $actionLabel = $this->sanitizePlainText((string)($item['actionLabel'] ?? ''), 80);
            $actionUrl = $this->normalizeUrlValue((string)($item['actionUrl'] ?? ''), 240);

            if ($title === '' && $text === '') {
                continue;
            }

            $normalized[] = [
                'title' => $title,
                'text' => $text,
                'actionLabel' => $actionLabel,
                'actionUrl' => $actionUrl,
            ];
        }

        return json_encode($normalized, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function normalizeSetting(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function normalizeNumber(int $value, int $min, int $max, int $fallback): int
    {
        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function normalizeFeatureCardsJson(string $json): string
    {
        $items = \CMS\Json::decodeArray($json, []);
        if (!is_array($items)) {
            return '[]';
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = $this->sanitizePlainText((string)($item['title'] ?? ''), 160);
            $titleEn = $this->sanitizePlainText((string)($item['title_en'] ?? ''), 160);
            $text = $this->sanitizeRichText((string)($item['text'] ?? ''), 4000);
            $textEn = $this->sanitizeRichText((string)($item['text_en'] ?? ''), 4000);
            $imageUrl = $this->normalizeUrlValue((string)($item['image_url'] ?? ''), 500);
            $imageAlt = $this->sanitizePlainText((string)($item['image_alt'] ?? ''), 160);
            $imageAltEn = $this->sanitizePlainText((string)($item['image_alt_en'] ?? ''), 160);
            $insertAfter = $this->normalizeNumber((int)($item['insert_after'] ?? 0), 0, 999, 0);
            $featureSpacingTop = $this->normalizeNumber((int)($item['feature_spacing_top'] ?? 0), 0, 240, 0);

            if ($title === '' && $titleEn === '' && $text === '' && $textEn === '' && $imageUrl === '') {
                continue;
            }

            $normalized[] = [
                'insert_after' => $insertAfter,
                'feature_spacing_top' => $featureSpacingTop,
                'title' => $title,
                'title_en' => $titleEn,
                'text' => $text,
                'text_en' => $textEn,
                'image_url' => $imageUrl,
                'image_alt' => $imageAlt,
                'image_alt_en' => $imageAltEn,
            ];
        }

        return json_encode($normalized, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function sanitizeRichText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $payload = json_decode($value, true);
        if (is_array($payload) && isset($payload['blocks']) && is_array($payload['blocks'])) {
            return \CMS\Services\EditorJsService::getInstance()->sanitize($value);
        }

        $sanitized = \CMS\Services\PurifierService::getInstance()->purify($value, 'table');

        return $this->truncateText($sanitized, $maxLength);
    }

    private function truncateText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return cms_truncate_text($value, $maxLength, '');
    }

    private function sanitizePlainText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '';

        return $this->truncateText($value, $maxLength);
    }

    private function sanitizeSearchTerm(string $value): string
    {
        return $this->sanitizePlainText($value, 120);
    }

    private function normalizeUrlValue(string $value, int $maxLength): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);

        if ($value === '#') {
            return '#';
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if ($scheme === '') {
            return '#';
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            return '#';
        }

        return filter_var($value, FILTER_SANITIZE_URL) ?: '#';
    }

    private function failResult(string $action, string $message, ?\Throwable $exception = null, array $context = []): array
    {
        $this->logFailure($action, $message, $exception, $context);

        return ['success' => false, 'error' => $message . ' Bitte Logs prüfen.'];
    }

    private function logFailure(string $action, string $message, ?\Throwable $exception = null, array $context = []): void
    {
        if ($exception !== null) {
            $context['exception'] = $exception->getMessage();
        }

        Logger::instance()->withChannel('admin.hub-sites')->error($message, $context);
        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $action,
            $message,
            'hub-sites',
            null,
            $context,
            'error'
        );
    }

    private function getExistingHubSettings(int $id): array
    {
        $row = $this->db->get_row("SELECT settings_json FROM {$this->prefix}site_tables WHERE id = ? LIMIT 1", [$id]);
        if (!$row || empty($row->settings_json)) {
            return self::DEFAULT_SETTINGS;
        }

        $decoded = \CMS\Json::decodeArray($row->settings_json ?? null, []);
        return array_merge(self::DEFAULT_SETTINGS, is_array($decoded) ? $decoded : []);
    }

    /**
     * @return array{domains: array<int, string>, errors: array<int, string>}
     */
    private function normalizeHubDomains(string $rawDomains): array
    {
        $entries = preg_split('/[\r\n,;]+/', $rawDomains) ?: [];
        $domains = [];
        $errors = [];

        foreach ($entries as $entry) {
            $normalizedHost = $this->normalizeDomainHost($entry);
            if ($normalizedHost === '') {
                if (trim($entry) !== '') {
                    $errors[] = 'Die Zusatzdomain „' . trim($entry) . '“ ist ungültig.';
                }
                continue;
            }

            if ($this->isMainDomainHost($normalizedHost)) {
                $errors[] = 'Die Hauptdomain darf nicht als Hub-Zusatzdomain verwendet werden.';
                continue;
            }

            $domains[] = $normalizedHost;
        }

        return [
            'domains' => array_values(array_unique($domains)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function hubDomainExists(string $domain, ?int $excludeId = null): bool
    {
        foreach ($this->getHubDomainAssignments() as $assignedDomain => $siteId) {
            if ($assignedDomain !== $domain) {
                continue;
            }

            if ($excludeId !== null && $siteId === $excludeId) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    private function getHubDomainAssignments(): array
    {
        if ($this->hubDomainAssignmentsCache !== null) {
            return $this->hubDomainAssignmentsCache;
        }

        $rows = $this->db->get_results(
            "SELECT id, settings_json
             FROM {$this->prefix}site_tables
             WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'",
            []
        ) ?: [];

        $assignments = [];

        foreach ($rows as $row) {
            $rowId = (int)($row->id ?? 0);
            $settings = \CMS\Json::decodeArray($row->settings_json ?? null, []);
            $domains = is_array($settings['hub_domains'] ?? null) ? $settings['hub_domains'] : [];

            foreach ($domains as $candidate) {
                $normalizedDomain = $this->normalizeDomainHost((string)$candidate);
                if ($normalizedDomain === '') {
                    continue;
                }

                $assignments[$normalizedDomain] = $rowId;
            }
        }

        $this->hubDomainAssignmentsCache = $assignments;

        return $this->hubDomainAssignmentsCache;
    }

    private function resetHubDomainAssignmentsCache(): void
    {
        $this->hubDomainAssignmentsCache = null;
    }

    private function isMainDomainHost(string $host): bool
    {
        $siteHost = $this->normalizeDomainHost((string)(parse_url((string)SITE_URL, PHP_URL_HOST) ?? ''));
        return $siteHost !== '' && $siteHost === $host;
    }

    private function normalizeDomainHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $candidate = preg_match('#^https?://#i', $value) === 1 ? $value : 'https://' . ltrim($value, '/');
        $parts = parse_url($candidate);
        if ($parts === false) {
            return '';
        }

        $host = strtolower(trim((string)($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return '';
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return '';
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        if (!preg_match('/^(?=.{1,253}$)(?:xn--)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.(?:xn--)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host)) {
            return '';
        }

        return $host;
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
}
