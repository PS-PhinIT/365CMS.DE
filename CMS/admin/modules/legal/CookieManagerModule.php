<?php
declare(strict_types=1);

/**
 * CookieManagerModule – Cookie-Consent-Kategorien & Einstellungen
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\AuditLogger;
use CMS\Logger;

class CookieManagerModule
{
    private readonly \CMS\Database $db;
    private readonly string $prefix;
    /** @var array<string, true> */
    private array $existingSettingNamesCache = [];
    /** @var array<string, true> */
    private array $existingCategorySlugsCache = [];

    private const int MAX_CATEGORY_NAME_LENGTH = 100;
    private const int MAX_CATEGORY_DESCRIPTION_LENGTH = 1000;
    private const int MAX_CATEGORY_SCRIPTS_LENGTH = 20000;
    private const int MAX_SERVICE_NAME_LENGTH = 150;
    private const int MAX_PROVIDER_LENGTH = 120;
    private const int MAX_SERVICE_DESCRIPTION_LENGTH = 2000;
    private const int MAX_COOKIE_NAMES_LENGTH = 1000;
    private const int MAX_CODE_SNIPPET_LENGTH = 30000;
    private const int MAX_SETTING_TEXT_LENGTH = 1000;
    private const int MAX_SETTING_HTML_LENGTH = 5000;
    private const int MAX_SCAN_FILES = 400;
    private const int MAX_SCAN_FILE_BYTES = 262144;
    private const int MAX_SCAN_PAGE_ROWS = 100;
    private const int MAX_SCAN_RESULTS = 25;
    private const int MAX_SCAN_SOURCES_PER_SERVICE = 5;
    private const int MAX_SCAN_SOURCE_LENGTH = 160;
    private const int MAX_SORT_ORDER = 10000;
    /** @var list<string> */
    private const array SCAN_SKIP_PATH_FRAGMENTS = [
        '/cache/',
        '/logs/',
        '/uploads/',
        '/vendor/',
        '/node_modules/',
        '/tests/',
        '/staging/',
        '/backup/',
    ];

    /** @var array<int, array<string, mixed>> */
    private const array DEFAULT_CATEGORIES = [
        ['name' => 'Essenziell', 'slug' => 'necessary', 'description' => 'Technisch notwendige Services und Cookies.', 'is_required' => 1, 'sort_order' => 0],
        ['name' => 'Funktional', 'slug' => 'functional', 'description' => 'Erweiterte Komfort- und Bedienfunktionen.', 'is_required' => 0, 'sort_order' => 10],
        ['name' => 'Analytics', 'slug' => 'analytics', 'description' => 'Messung und Optimierung der Nutzung.', 'is_required' => 0, 'sort_order' => 20],
        ['name' => 'Marketing', 'slug' => 'marketing', 'description' => 'Tracking, Kampagnen und Remarketing.', 'is_required' => 0, 'sort_order' => 30],
        ['name' => 'Externe Medien', 'slug' => 'external_media', 'description' => 'Eingebettete Videos, Maps und Social Widgets.', 'is_required' => 0, 'sort_order' => 40],
    ];

    /** @var array<string, array<string, mixed>> */
    private const array CURATED_SERVICES = [
        'google_analytics' => ['name' => 'Google Analytics', 'provider' => 'Google', 'category_slug' => 'analytics', 'description' => 'Webanalyse mit Events, Seitenaufrufen und Kampagnenmessung.', 'patterns' => ['google-analytics.com', 'www.googletagmanager.com/gtag/js', 'gtag("config"', "gtag('config'"]],
        'google_tag_manager' => ['name' => 'Google Tag Manager', 'provider' => 'Google', 'category_slug' => 'analytics', 'description' => 'Container für Tracking- und Marketing-Tags.', 'patterns' => ['googletagmanager.com/gtm.js', 'googletagmanager.com/ns.html']],
        'matomo' => ['name' => 'Matomo', 'provider' => 'Matomo', 'category_slug' => 'analytics', 'description' => 'Datenschutzfreundliche Webanalyse.', 'patterns' => ['matomo', '_paq']],
        'facebook_pixel' => ['name' => 'Facebook Pixel', 'provider' => 'Meta', 'category_slug' => 'marketing', 'description' => 'Conversion- und Remarketing-Tracking für Facebook/Instagram.', 'patterns' => ['connect.facebook.net', 'fbq(']],
        'linkedin_insight' => ['name' => 'LinkedIn Insight Tag', 'provider' => 'LinkedIn', 'category_slug' => 'marketing', 'description' => 'Kampagnen- und Zielgruppenmessung für LinkedIn Ads.', 'patterns' => ['snap.licdn.com', '_linkedin_partner_id']],
        'youtube' => ['name' => 'YouTube', 'provider' => 'Google', 'category_slug' => 'external_media', 'description' => 'Eingebettete YouTube-Videos.', 'patterns' => ['youtube.com/embed', 'youtu.be/', 'youtube-nocookie.com']],
        'vimeo' => ['name' => 'Vimeo', 'provider' => 'Vimeo', 'category_slug' => 'external_media', 'description' => 'Eingebettete Vimeo-Videos.', 'patterns' => ['player.vimeo.com', 'vimeo.com']],
        'google_maps' => ['name' => 'Google Maps', 'provider' => 'Google', 'category_slug' => 'external_media', 'description' => 'Karten und Standort-Einbindungen.', 'patterns' => ['maps.googleapis.com', 'www.google.com/maps/embed', 'google.com/maps/embed']],
        'hubspot' => ['name' => 'HubSpot', 'provider' => 'HubSpot', 'category_slug' => 'marketing', 'description' => 'CRM-, Form- und Marketing-Automation.', 'patterns' => ['js.hs-scripts.com', 'hs-script-loader']],
        'cms_core' => ['name' => '365CMS Kernfunktionen', 'provider' => '365CMS', 'category_slug' => 'necessary', 'description' => 'Session-, Login-, Sicherheits- und Formularfunktionen.', 'patterns' => ['csrf_token', 'PHPSESSID'], 'is_essential' => true],
    ];

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->ensureTables();
        $this->ensureDefaultCategories();
        $this->ensureManagedDefaultServices();
        $this->cleanupDeprecatedManagedServices();
    }

    private function ensureTables(): void
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}cookie_categories (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                slug        VARCHAR(100) NOT NULL,
                description TEXT,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                scripts     TEXT,
                sort_order  INT NOT NULL DEFAULT 0,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}cookie_services (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                provider VARCHAR(120) DEFAULT NULL,
                category_slug VARCHAR(100) NOT NULL DEFAULT 'necessary',
                description TEXT DEFAULT NULL,
                cookie_names TEXT DEFAULT NULL,
                code_snippet MEDIUMTEXT DEFAULT NULL,
                is_essential TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_slug (slug),
                INDEX idx_category (category_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureDefaultCategories(): void
    {
        if ($this->existingCategorySlugsCache === []) {
            $rows = $this->db->get_results("SELECT slug FROM {$this->prefix}cookie_categories") ?: [];
            foreach ($rows as $row) {
                $slug = trim((string)($row->slug ?? ''));
                if ($slug !== '') {
                    $this->existingCategorySlugsCache[$slug] = true;
                }
            }
        }

        foreach (self::DEFAULT_CATEGORIES as $category) {
            $slug = (string)($category['slug'] ?? '');
            if (!isset($this->existingCategorySlugsCache[$slug])) {
                $this->db->insert('cookie_categories', [
                    'name' => $category['name'],
                    'slug' => $slug,
                    'description' => $category['description'],
                    'is_required' => $category['is_required'],
                    'is_active' => 1,
                    'scripts' => '',
                    'sort_order' => $category['sort_order'],
                ]);
                $this->existingCategorySlugsCache[$slug] = true;
            }
        }
    }

    private function ensureManagedDefaultServices(): void
    {
        foreach (['cms_core'] as $slug) {
            if (!isset(self::CURATED_SERVICES[$slug])) {
                continue;
            }

            $service = self::CURATED_SERVICES[$slug];
            $existing = $this->db->get_row(
                "SELECT id, category_slug, description, provider FROM {$this->prefix}cookie_services WHERE slug = ? LIMIT 1",
                [$slug]
            );

            $data = [
                'name' => (string)$service['name'],
                'provider' => (string)$service['provider'],
                'category_slug' => !empty($service['is_essential']) ? 'necessary' : (string)($service['category_slug'] ?? 'necessary'),
                'description' => (string)($service['description'] ?? ''),
                'cookie_names' => '',
                'code_snippet' => '',
                'is_essential' => !empty($service['is_essential']) ? 1 : 0,
                'is_active' => 1,
            ];

            if ($existing !== null) {
                $this->db->update('cookie_services', $data, ['slug' => $slug]);
                continue;
            }

            $this->db->insert('cookie_services', [
                'name' => (string)$service['name'],
                'slug' => $slug,
                'provider' => (string)$service['provider'],
                'category_slug' => $data['category_slug'],
                'description' => $data['description'],
                'cookie_names' => '',
                'code_snippet' => '',
                'is_essential' => $data['is_essential'],
                'is_active' => 1,
            ]);
        }
    }

    private function cleanupDeprecatedManagedServices(): void
    {
        $service = $this->db->get_row(
            "SELECT id, name, provider, category_slug, cookie_names, code_snippet FROM {$this->prefix}cookie_services WHERE slug = ? LIMIT 1",
            ['cms_feed']
        );

        if ($service === null) {
            return;
        }

        $matchesLegacyManagedEntry = (string)($service->name ?? '') === 'CMS Feed'
            && (string)($service->provider ?? '') === '365CMS'
            && (string)($service->category_slug ?? '') === 'external_media'
            && trim((string)($service->cookie_names ?? '')) === ''
            && trim((string)($service->code_snippet ?? '')) === '';

        if (!$matchesLegacyManagedEntry) {
            return;
        }

        $this->db->delete('cookie_services', ['id' => (int)($service->id ?? 0)]);
    }

    public function getData(): array
    {
        if (!$this->canAccess()) {
            return [
                'categories' => [],
                'services' => [],
                'settings' => [],
                'scan_results' => [],
                'curated_services' => self::CURATED_SERVICES,
                'error' => 'Zugriff verweigert.',
            ];
        }

        $categories = $this->db->get_results(
            "SELECT * FROM {$this->prefix}cookie_categories ORDER BY sort_order, name"
        ) ?: [];

        $services = $this->db->get_results(
            "SELECT * FROM {$this->prefix}cookie_services ORDER BY is_essential DESC, provider ASC, name ASC"
        ) ?: [];

        $settingKeys = [
            'cookie_banner_enabled',
            'cookie_consent_enabled',
            'cookie_banner_position',
            'cookie_banner_text',
            'cookie_banner_style',
            'cookie_primary_color',
            'cookie_lifetime_days',
            'cookie_policy_url',
            'cookie_accept_text',
            'cookie_reject_text',
            'cookie_essential_text',
            'cookie_matomo_self_hosted_url',
            'cookie_matomo_site_id',
            'cookie_matomo_hosting_region',
            'cookie_matomo_ip_anonymization',
            'cookie_matomo_respect_dnt',
            'cookie_matomo_disable_cookies',
            'cookie_matomo_log_retention_days',
            'cookie_matomo_dsgvo_note',
            'cookie_scan_results',
            'cookie_scan_last_run',
        ];
        $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
        $settings = $this->getSettingsMap($settingKeys, array_fill_keys($settingKeys, ''));

        $scanResults = \CMS\Json::decodeArray($settings['cookie_scan_results'] ?? null, []);
        if (!is_array($scanResults)) {
            $scanResults = [];
        }
        $scanResults = $this->sanitizeStoredScanResults($scanResults);

        return [
            'categories' => array_map(fn($c) => (array)$c, $categories),
            'services'   => array_map(fn($s) => (array)$s, $services),
            'settings'   => $settings,
            'scan_results' => $scanResults,
            'curated_services' => self::CURATED_SERVICES,
        ];
    }

    public function saveSettings(array $post): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        $rawMatomoSelfHostedUrl = $this->cleanUrlInput((string)($post['cookie_matomo_self_hosted_url'] ?? ''));
        $matomoSelfHostedUrl = $this->sanitizeOptionalUrl($rawMatomoSelfHostedUrl);
        if ($rawMatomoSelfHostedUrl !== '' && $matomoSelfHostedUrl === '') {
            return ['success' => false, 'error' => 'Die Matomo-URL muss als gültige http(s)-URL ohne Zugangsdaten angegeben werden.'];
        }

        $consentEnabled = isset($post['cookie_banner_enabled'])
            || (isset($post['cookie_consent_enabled']) && (string)$post['cookie_consent_enabled'] === '1');

        $keys = [
            'cookie_banner_enabled'  => $consentEnabled ? '1' : '0',
            'cookie_consent_enabled' => $consentEnabled ? '1' : '0',
            'cookie_banner_position' => in_array($post['cookie_banner_position'] ?? '', ['bottom', 'top', 'center'], true)
                ? $post['cookie_banner_position'] : 'bottom',
            'cookie_banner_text'     => $this->sanitizeLimitedHtml((string)($post['cookie_banner_text'] ?? ''), self::MAX_SETTING_HTML_LENGTH, '<p><a><strong><em><br>'),
            'cookie_banner_style'    => in_array($post['cookie_banner_style'] ?? '', ['light', 'dark', 'custom'], true)
                ? $post['cookie_banner_style'] : 'dark',
            'cookie_primary_color'   => preg_match('/^#[a-f0-9]{6}$/i', (string)($post['cookie_primary_color'] ?? '')) === 1
                ? strtolower((string)$post['cookie_primary_color']) : '#3b82f6',
            'cookie_lifetime_days'   => (string)max(1, min(365, (int)($post['cookie_lifetime_days'] ?? 30))),
            'cookie_policy_url'      => $this->normalizePolicyUrl((string)($post['cookie_policy_url'] ?? '/datenschutz')),
            'cookie_accept_text'     => $this->sanitizeText((string)($post['cookie_accept_text'] ?? 'Akzeptieren'), 80, 'Akzeptieren'),
            'cookie_reject_text'     => $this->sanitizeText((string)($post['cookie_reject_text'] ?? 'Ablehnen'), 80, 'Ablehnen'),
            'cookie_essential_text'  => $this->sanitizeText((string)($post['cookie_essential_text'] ?? 'Nur Essenzielle'), 80, 'Nur Essenzielle'),
            'cookie_matomo_self_hosted_url' => $matomoSelfHostedUrl,
            'cookie_matomo_site_id' => (string)max(1, min(999999, (int)($post['cookie_matomo_site_id'] ?? 1))),
            'cookie_matomo_hosting_region' => $this->sanitizeText((string)($post['cookie_matomo_hosting_region'] ?? 'Deutschland / EU'), 120, 'Deutschland / EU'),
            'cookie_matomo_ip_anonymization' => isset($post['cookie_matomo_ip_anonymization']) ? '1' : '0',
            'cookie_matomo_respect_dnt' => isset($post['cookie_matomo_respect_dnt']) ? '1' : '0',
            'cookie_matomo_disable_cookies' => isset($post['cookie_matomo_disable_cookies']) ? '1' : '0',
            'cookie_matomo_log_retention_days' => (string)max(1, min(3650, (int)($post['cookie_matomo_log_retention_days'] ?? 180))),
            'cookie_matomo_dsgvo_note' => $this->sanitizeLimitedHtml((string)($post['cookie_matomo_dsgvo_note'] ?? ''), self::MAX_SETTING_HTML_LENGTH, '<p><a><strong><em><br><ul><ol><li>'),
        ];

        try {
            $this->storeSettings($keys);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.cookies.settings.save',
                'Cookie-Einstellungen gespeichert',
                'cookie_manager',
                null,
                [
                    'consent_enabled' => $keys['cookie_consent_enabled'],
                    'banner_position' => $keys['cookie_banner_position'],
                    'banner_style' => $keys['cookie_banner_style'],
                ],
                'info'
            );

            return ['success' => true, 'message' => 'Cookie-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('legal.cookies.settings.save_failed', 'Cookie-Einstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    public function saveCategory(array $post): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        $id   = (int)($post['category_id'] ?? 0);
        $name = $this->sanitizeText((string)($post['category_name'] ?? ''), self::MAX_CATEGORY_NAME_LENGTH);
        if ($name === '') {
            return ['success' => false, 'error' => 'Name ist erforderlich.'];
        }

        $slug = $this->sanitizeSlug((string)($post['category_slug'] ?? ''), $name, 'category');
        if ($slug === '') {
            return ['success' => false, 'error' => 'Kategorie-Slug ist ungültig.'];
        }

        $isRequired = isset($post['is_required']) || $slug === 'necessary' ? 1 : 0;
        $isActive = $isRequired === 1 ? 1 : (isset($post['is_active']) ? 1 : 0);

        $data = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $this->sanitizeText((string)($post['category_description'] ?? ''), self::MAX_CATEGORY_DESCRIPTION_LENGTH),
            'is_required' => $isRequired,
            'is_active'   => $isActive,
            'scripts'     => $this->sanitizeLimitedHtml((string)($post['category_scripts'] ?? ''), self::MAX_CATEGORY_SCRIPTS_LENGTH, '<script><noscript>'),
            'sort_order'  => max(-self::MAX_SORT_ORDER, min(self::MAX_SORT_ORDER, (int)($post['sort_order'] ?? 0))),
        ];

        try {
            if ($this->slugExistsInTable('cookie_categories', $slug, $id)) {
                return ['success' => false, 'error' => 'Der Kategorie-Slug ist bereits vergeben.'];
            }

            if ($id > 0) {
                $this->db->update('cookie_categories', $data, ['id' => $id]);
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    'legal.cookies.category.update',
                    'Cookie-Kategorie aktualisiert',
                    'cookie_category',
                    $id,
                    ['slug' => $slug, 'is_required' => $isRequired, 'is_active' => $isActive],
                    'warning'
                );
                return ['success' => true, 'message' => 'Kategorie aktualisiert.'];
            }
            $this->db->insert('cookie_categories', $data);
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.cookies.category.create',
                'Cookie-Kategorie erstellt',
                'cookie_category',
                null,
                ['slug' => $slug, 'is_required' => $isRequired, 'is_active' => $isActive],
                'warning'
            );
            return ['success' => true, 'message' => 'Kategorie erstellt.'];
        } catch (\Throwable $e) {
			return $this->failResult('legal.cookies.category.save_failed', 'Kategorie konnte nicht gespeichert werden.', $e);
        }
    }

    public function deleteCategory(int $id): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }
        $cat = $this->db->get_row(
            "SELECT id, slug, is_required FROM {$this->prefix}cookie_categories WHERE id = ?",
            [$id]
        );
        if ($cat && (int)$cat->is_required === 1) {
            return ['success' => false, 'error' => 'Pflicht-Kategorien können nicht gelöscht werden.'];
        }

        $assignedServices = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}cookie_services WHERE category_slug = ?",
            [(string)($cat->slug ?? '')]
        );
        if ($assignedServices > 0) {
            return ['success' => false, 'error' => 'Kategorie wird noch von Services verwendet und kann nicht gelöscht werden.'];
        }

        $this->db->delete('cookie_categories', ['id' => $id]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.cookies.category.delete',
            'Cookie-Kategorie gelöscht',
            'cookie_category',
            $id,
            ['slug' => (string)($cat->slug ?? '')],
            'warning'
        );

        return ['success' => true, 'message' => 'Kategorie gelöscht.'];
    }

    public function saveService(array $post): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        $id = (int)($post['service_id'] ?? 0);
        $name = $this->sanitizeText((string)($post['service_name'] ?? ''), self::MAX_SERVICE_NAME_LENGTH);
        $slug = trim((string)($post['service_slug'] ?? ''));
        $provider = $this->sanitizeText((string)($post['service_provider'] ?? ''), self::MAX_PROVIDER_LENGTH);
        $categorySlug = trim((string)($post['category_slug'] ?? 'necessary'));
        $description = $this->sanitizeText((string)($post['service_description'] ?? ''), self::MAX_SERVICE_DESCRIPTION_LENGTH);
        $cookieNames = $this->sanitizeCookieNames((string)($post['cookie_names'] ?? ''));
        $codeSnippet = trim((string)($post['code_snippet'] ?? ''));
        $isEssential = isset($post['is_essential']) ? 1 : 0;
        $isActive = $isEssential === 1 ? 1 : (isset($post['is_active']) ? 1 : 0);

        if ($name === '') {
            return ['success' => false, 'error' => 'Service-Name ist erforderlich.'];
        }

        if ($slug === '') {
            $slug = $this->sanitizeSlug('', $name, 'service');
        } else {
            $slug = $this->sanitizeSlug($slug, $name, 'service');
        }

        if ($slug === '') {
            return ['success' => false, 'error' => 'Service-Slug ist ungültig.'];
        }

        if ($isEssential === 1) {
            $categorySlug = 'necessary';
            $isActive = 1;
        }

        $categoryExists = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}cookie_categories WHERE slug = ?",
            [$categorySlug]
        );
        if ($categoryExists === 0) {
            $categorySlug = 'necessary';
        }

        $data = [
            'name' => $name,
            'slug' => $slug,
            'provider' => $provider,
            'category_slug' => $categorySlug,
            'description' => $description,
            'cookie_names' => $cookieNames,
            'code_snippet' => $this->sanitizeLimitedHtml($codeSnippet, self::MAX_CODE_SNIPPET_LENGTH, '<script><noscript><iframe><img><div><span><a>'),
            'is_essential' => $isEssential,
            'is_active' => $isActive,
        ];

        try {
            if ($this->slugExistsInTable('cookie_services', $slug, $id)) {
                return ['success' => false, 'error' => 'Der Service-Slug ist bereits vergeben.'];
            }

            if ($id > 0) {
                $this->db->update('cookie_services', $data, ['id' => $id]);
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SETTING,
                    'legal.cookies.service.update',
                    'Cookie-Service aktualisiert',
                    'cookie_service',
                    $id,
                    ['slug' => $slug, 'category_slug' => $categorySlug, 'is_essential' => $isEssential, 'is_active' => $isActive],
                    'warning'
                );
                return ['success' => true, 'message' => 'Service aktualisiert.'];
            }

            $this->db->insert('cookie_services', $data);
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.cookies.service.create',
                'Cookie-Service angelegt',
                'cookie_service',
                null,
                ['slug' => $slug, 'category_slug' => $categorySlug, 'is_essential' => $isEssential, 'is_active' => $isActive],
                'warning'
            );
            return ['success' => true, 'message' => 'Service angelegt.'];
        } catch (\Throwable $e) {
			return $this->failResult('legal.cookies.service.save_failed', 'Service konnte nicht gespeichert werden.', $e);
        }
    }

    public function deleteService(int $id): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }

        $service = $this->db->get_row(
            "SELECT id, slug, is_essential FROM {$this->prefix}cookie_services WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($service && (int)$service->is_essential === 1) {
            return ['success' => false, 'error' => 'Essenzielle Services dürfen nicht gelöscht werden.'];
        }

        $this->db->delete('cookie_services', ['id' => $id]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.cookies.service.delete',
            'Cookie-Service gelöscht',
            'cookie_service',
            $id,
            ['slug' => (string)($service->slug ?? '')],
            'warning'
        );

        return ['success' => true, 'message' => 'Service gelöscht.'];
    }

    public function importCuratedService(string $slug, bool $selfHosted = false, array $post = []): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        $slug = trim($slug);
        if (!isset(self::CURATED_SERVICES[$slug])) {
            return ['success' => false, 'error' => 'Unbekannter Standard-Service.'];
        }

        $existing = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}cookie_services WHERE slug = ?",
            [$slug]
        );
        if ($existing > 0 && !($slug === 'matomo' && $selfHosted)) {
            return ['success' => true, 'message' => 'Standard-Service ist bereits vorhanden.'];
        }

        $service = self::CURATED_SERVICES[$slug];
        $data = [
            'name' => $this->sanitizeText((string)($post['service_name'] ?? $service['name']), self::MAX_SERVICE_NAME_LENGTH, (string)$service['name']),
            'slug' => $slug,
            'provider' => $this->sanitizeText((string)($post['service_provider'] ?? $service['provider']), self::MAX_PROVIDER_LENGTH, (string)$service['provider']),
            'category_slug' => $this->sanitizeSlug((string)($post['category_slug'] ?? ($service['is_essential'] ?? false ? 'necessary' : $service['category_slug'])), $service['category_slug'], 'necessary'),
            'description' => $this->sanitizeText((string)($post['service_description'] ?? $service['description']), self::MAX_SERVICE_DESCRIPTION_LENGTH, (string)$service['description']),
            'cookie_names' => '',
            'code_snippet' => '',
            'is_essential' => !empty($service['is_essential']) ? 1 : 0,
            'is_active' => 1,
        ];

        $categoryExists = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}cookie_categories WHERE slug = ?",
            [$data['category_slug']]
        );
        if ($categoryExists === 0) {
            $data['category_slug'] = !empty($service['is_essential']) ? 'necessary' : (string)$service['category_slug'];
        }

        if ($slug === 'matomo' && $selfHosted) {
            $data['name'] = 'Matomo (Self-Hosted)';
            $data['provider'] = 'Matomo Self-Hosted';
            $data['category_slug'] = 'necessary';
            $data['description'] = $this->sanitizeText(
                (string)($post['service_description'] ?? 'Self-hosted Matomo-Instanz. Kann bei berechtigtem Interesse bzw. DSGVO-konformer Eigenhosting-Konfiguration als essenzieller Dienst behandelt werden.'),
                self::MAX_SERVICE_DESCRIPTION_LENGTH,
                'Self-hosted Matomo-Instanz. Kann bei berechtigtem Interesse bzw. DSGVO-konformer Eigenhosting-Konfiguration als essenzieller Dienst behandelt werden.'
            );
            $data['is_essential'] = 1;
            $data['is_active'] = 1;
        }

        if ($existing > 0) {
            $this->db->update('cookie_services', $data, ['slug' => $slug]);
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.cookies.service.import.update',
                'Standard-Service aktualisiert',
                'cookie_service',
                null,
                ['slug' => $slug, 'self_hosted' => $selfHosted ? '1' : '0'],
                'info'
            );
            return ['success' => true, 'message' => 'Vorhandener Service wurde auf die gewählte Matomo-Konfiguration aktualisiert.'];
        }

        $this->db->insert('cookie_services', $data);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.cookies.service.import',
            'Standard-Service übernommen',
            'cookie_service',
            null,
            ['slug' => $slug, 'self_hosted' => $selfHosted ? '1' : '0'],
            'info'
        );

        return ['success' => true, 'message' => 'Standard-Service wurde übernommen.'];
    }

    public function runScanner(): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        $detected = [];
        $sources = [];
        $scannedFiles = 0;

        $scanTargets = [
            defined('ABSPATH') ? ABSPATH . 'themes/' : '',
            defined('ABSPATH') ? ABSPATH . 'includes/' : '',
            defined('ABSPATH') ? ABSPATH . 'assets/js/' : '',
        ];

        foreach ($scanTargets as $target) {
            if ($target === '' || !is_dir($target)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                        function (\SplFileInfo $current): bool {
                            if ($current->isLink()) {
                                return false;
                            }

                            return !$this->shouldSkipScanPath($current->getPathname());
                        }
                    )
                );

                foreach ($iterator as $file) {
                    if ($scannedFiles >= self::MAX_SCAN_FILES) {
                        break;
                    }

                    if (!$file instanceof \SplFileInfo || !$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'js', 'html', 'css'], true)) {
                        continue;
                    }

                    $size = (int)$file->getSize();
                    if ($size <= 0 || $size > self::MAX_SCAN_FILE_BYTES) {
                        continue;
                    }

                    $content = @file_get_contents($file->getPathname(), false, null, 0, self::MAX_SCAN_FILE_BYTES + 1);
                    if (!is_string($content) || $content === '') {
                        continue;
                    }

                    $scannedFiles++;
                    $this->collectMatches($content, $this->buildFileScanSource($file->getPathname()), $detected, $sources);
                }
            } catch (\Throwable $e) {
                $this->logFailure('legal.cookies.scan.target_failed', 'Cookie-Scanner konnte ein Zielverzeichnis nicht vollständig lesen.', $e, [
                    'target' => $this->normalizeScanSource(str_replace((string)ABSPATH, '', $target)),
                ]);
            }
        }

        try {
            $pageRows = $this->db->get_results("SELECT id, slug, content FROM {$this->prefix}pages ORDER BY id DESC LIMIT " . self::MAX_SCAN_PAGE_ROWS) ?: [];
            foreach ($pageRows as $row) {
                $content = (string)($row->content ?? '');
                if ($content !== '') {
                    $pageSource = $row->slug !== ''
                        ? 'DB: page/' . (string)$row->slug
                        : 'DB: page/#' . (int)($row->id ?? 0);
                    $this->collectMatches($content, $this->normalizeScanSource($pageSource), $detected, $sources);
                }
            }

            $this->scanConfiguredAnalyticsServices($detected, $sources);
            $this->scanSnippetSettings($detected, $sources);
        } catch (\Throwable $e) {
            $this->logFailure('legal.cookies.scan.read_failed', 'Cookie-Scanner konnte nicht vollständig ausgeführt werden.', $e, ['scanned_files' => $scannedFiles]);
        }

        $results = [];
        foreach ($detected as $slug => $name) {
            if (count($results) >= self::MAX_SCAN_RESULTS) {
                break;
            }

            $results[] = [
                'slug' => $slug,
                'name' => $name,
                'provider' => self::CURATED_SERVICES[$slug]['provider'] ?? '',
                'category_slug' => self::CURATED_SERVICES[$slug]['category_slug'] ?? 'necessary',
                'sources' => array_slice(array_values(array_unique($sources[$slug] ?? [])), 0, self::MAX_SCAN_SOURCES_PER_SERVICE),
                'self_hostable' => $slug === 'matomo',
            ];
        }

        $this->storeSettings([
            'cookie_scan_results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            'cookie_scan_last_run' => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.cookies.scan.run',
            'Cookie-Scanner ausgeführt',
            'cookie_manager',
            null,
            ['matches' => count($results), 'scanned_files' => $scannedFiles],
            'info'
        );

        return ['success' => true, 'message' => count($results) . ' Services/Signaturen erkannt.', 'results' => $results];
    }

    private function collectMatches(string $content, string $source, array &$detected, array &$sources): void
    {
        $contentLower = strtolower($content);
        foreach (self::CURATED_SERVICES as $slug => $service) {
            foreach ((array)($service['patterns'] ?? []) as $pattern) {
                if ($pattern !== '' && str_contains($contentLower, strtolower((string)$pattern))) {
                    $detected[$slug] = (string)$service['name'];
                    if (!isset($sources[$slug])) {
                        $sources[$slug] = [];
                    }

                    if (count($sources[$slug]) < self::MAX_SCAN_SOURCES_PER_SERVICE) {
                        $sources[$slug][] = $this->normalizeScanSource($source);
                    }
                    break;
                }
            }
        }
    }

    private function scanConfiguredAnalyticsServices(array &$detected, array &$sources): void
    {
        $settingNames = [
            'seo_analytics_matomo_enabled',
            'seo_analytics_matomo_code',
            'seo_analytics_matomo_url',
            'seo_analytics_matomo_site_id',
            'seo_analytics_ga4_enabled',
            'seo_analytics_ga4_id',
            'seo_analytics_gtm_enabled',
            'seo_analytics_gtm_id',
            'seo_analytics_fb_pixel_enabled',
            'seo_analytics_fb_pixel_id',
        ];

        $settings = $this->getSettingsMap($settingNames);

        if (($settings['seo_analytics_matomo_enabled'] ?? '') !== '0') {
            $matomoCode = trim((string)($settings['seo_analytics_matomo_code'] ?? ''));
            $matomoUrl = $this->sanitizeOptionalUrl((string)($settings['seo_analytics_matomo_url'] ?? ''));
            if ($matomoCode !== '' || $matomoUrl !== '') {
                $detected['matomo'] = self::CURATED_SERVICES['matomo']['name'];
                $sources['matomo'][] = 'System: Analytics-Einstellungen (Matomo)';
            }
        }

        $ga4Id = trim((string)($settings['seo_analytics_ga4_id'] ?? ''));
        if (($settings['seo_analytics_ga4_enabled'] ?? '') !== '0' && !$this->isPlaceholderAnalyticsId($ga4Id)) {
            $detected['google_analytics'] = self::CURATED_SERVICES['google_analytics']['name'];
            $sources['google_analytics'][] = 'System: Analytics-Einstellungen (GA4)';
        }

        $gtmId = trim((string)($settings['seo_analytics_gtm_id'] ?? ''));
        if (($settings['seo_analytics_gtm_enabled'] ?? '') !== '0' && !$this->isPlaceholderAnalyticsId($gtmId)) {
            $detected['google_tag_manager'] = self::CURATED_SERVICES['google_tag_manager']['name'];
            $sources['google_tag_manager'][] = 'System: Analytics-Einstellungen (GTM)';
        }

        $pixelId = trim((string)($settings['seo_analytics_fb_pixel_id'] ?? ''));
        if (($settings['seo_analytics_fb_pixel_enabled'] ?? '') !== '0' && !$this->isPlaceholderAnalyticsId($pixelId)) {
            $detected['facebook_pixel'] = self::CURATED_SERVICES['facebook_pixel']['name'];
            $sources['facebook_pixel'][] = 'System: Analytics-Einstellungen (Meta Pixel)';
        }
    }

    private function scanSnippetSettings(array &$detected, array &$sources): void
    {
        $settingNames = [
            'seo_analytics_custom_head',
            'seo_analytics_custom_body',
            'seo_analytics_matomo_code',
        ];

        $settings = $this->getSettingsMap($settingNames);
        foreach ($settings as $optionName => $content) {
            if ($this->shouldScanSettingValue($optionName, $content)) {
                $this->collectMatches($content, $this->normalizeScanSource('DB: setting/' . $optionName), $detected, $sources);
            }
        }
    }

    /** @param array<string, string> $defaults
     *  @return array<string, string> */
    private function getSettingsMap(array $settingNames, array $defaults = []): array
    {
        if ($settingNames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($settingNames), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $settingNames
        ) ?: [];

        $settings = $defaults;
        foreach ($rows as $row) {
            $settings[(string)$row->option_name] = (string)$row->option_value;
        }

        return $settings;
    }

    private function shouldScanSettingValue(string $optionName, string $content): bool
    {
        $optionName = strtolower($optionName);
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        $idOnlyPatterns = [
            '/^gtm-[a-z0-9]+$/i',
            '/^g-[a-z0-9]+$/i',
            '/^ua-\d+-\d+$/i',
            '/^aw-\d+$/i',
            '/^\d{6,}$/',
        ];

        foreach ($idOnlyPatterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return false;
            }
        }

        $excludedOptionFragments = ['measurement_id', 'analytics_id', 'tag_manager_id', 'pixel_id', 'tracking_id', 'google_analytics', 'ga4_id', 'gtm_id'];
        foreach ($excludedOptionFragments as $fragment) {
            if (str_contains($optionName, $fragment) && !str_contains($content, '<script')) {
                return false;
            }
        }

        return str_contains($content, '<script')
            || str_contains($content, '<iframe')
            || str_contains($content, '<noscript')
            || str_contains($content, 'google-analytics.com')
            || str_contains($content, 'googletagmanager.com/')
            || str_contains($content, 'connect.facebook.net')
            || str_contains($content, 'snap.licdn.com')
            || str_contains($content, 'player.vimeo.com')
            || str_contains($content, 'youtube.com/embed')
            || str_contains($content, 'youtube-nocookie.com')
            || str_contains($content, 'maps.googleapis.com')
            || str_contains($content, 'google.com/maps/embed')
            || str_contains($content, '_paq');
    }

    private function isPlaceholderAnalyticsId(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        return preg_match('/^(G-|UA-|GTM-|AW-)?X{4,}[A-Z0-9-]*$/i', $value) === 1;
    }

    /** @param array<int, mixed> $scanResults */
    private function sanitizeStoredScanResults(array $scanResults): array
    {
        $normalized = [];

        foreach ($scanResults as $result) {
            if (!is_array($result)) {
                continue;
            }

            $slug = (string)($result['slug'] ?? '');
            if ($slug === '' || !isset(self::CURATED_SERVICES[$slug])) {
                continue;
            }

            $sources = array_slice(
                array_values(array_filter(array_map(fn($value) => $this->normalizeScanSource((string)$value), (array)($result['sources'] ?? [])))),
                0,
                self::MAX_SCAN_SOURCES_PER_SERVICE
            );

            if ($this->isStoredResultFalsePositive($slug, $sources)) {
                continue;
            }

            $result['name'] = $this->sanitizeText((string)($result['name'] ?? self::CURATED_SERVICES[$slug]['name']), self::MAX_SERVICE_NAME_LENGTH, (string)self::CURATED_SERVICES[$slug]['name']);
            $result['provider'] = $this->sanitizeText((string)($result['provider'] ?? self::CURATED_SERVICES[$slug]['provider']), self::MAX_PROVIDER_LENGTH, (string)self::CURATED_SERVICES[$slug]['provider']);
            $result['category_slug'] = $this->sanitizeSlug((string)($result['category_slug'] ?? self::CURATED_SERVICES[$slug]['category_slug']), (string)self::CURATED_SERVICES[$slug]['category_slug'], 'necessary');
            $result['sources'] = $sources;
            $result['self_hostable'] = $slug === 'matomo';
            $normalized[] = $result;
        }

        return $normalized;
    }

    /** @param array<int, string> $sources */
    private function isStoredResultFalsePositive(string $slug, array $sources): bool
    {
        $placeholderSources = [
            'DB: setting/google_analytics',
            'DB: setting/seo_analytics_ga4_id',
            'DB: setting/seo_analytics_gtm_id',
            'DB: setting/seo_analytics_fb_pixel_id',
        ];

        if (in_array($slug, ['google_analytics', 'google_tag_manager', 'facebook_pixel'], true) && $sources === []) {
            return true;
        }

        if (in_array($slug, ['google_analytics', 'google_tag_manager', 'facebook_pixel'], true) && $sources !== []) {
            foreach ($sources as $source) {
                if (!in_array($source, $placeholderSources, true)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function storeSetting(string $key, string $value): void
    {
        $this->upsertSetting($key, $value);
        $this->existingSettingNamesCache[$key] = true;
    }

    /** @param array<string, string> $values */
    private function storeSettings(array $values): void
    {
        if ($values === []) {
            return;
        }

        foreach ($values as $key => $value) {
            $this->upsertSetting((string)$key, (string)$value);
            $this->existingSettingNamesCache[$key] = true;
        }
    }

    private function upsertSetting(string $key, string $value): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Leerer Setting-Key kann nicht gespeichert werden.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->prefix}settings (option_name, option_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
        );
        if ($stmt === false || !$stmt->execute([$key, $value])) {
            throw new \RuntimeException('Setting konnte nicht gespeichert werden: ' . $key);
        }
    }

    /** @param list<string> $keys */
    private function warmSettingNamesCache(array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if ($key !== '' && !isset($this->existingSettingNamesCache[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return;
        }

        foreach ($this->getExistingSettingNames($missing) as $key => $exists) {
            $this->existingSettingNamesCache[$key] = $exists;
        }
    }

    /** @param list<string> $keys
     *  @return array<string, true> */
    private function getExistingSettingNames(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $keys
        ) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $existing[(string)$row->option_name] = true;
        }

        return $existing;
    }

    private function sanitizeOptionalUrl(string $url): string
    {
        $url = $this->cleanUrlInput($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) && preg_match('~^[^\s/:?#]+(?::\d+)?(?:[/?#].*)?$~u', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if (preg_match('/\s/u', $url) === 1) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = $this->normalizeUrlHost((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            return '';
        }

        $normalized = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $normalized .= ':' . (int)$parts['port'];
        }

        $path = trim((string)($parts['path'] ?? ''));
        if ($path !== '') {
            $normalized .= '/' . ltrim($path, '/');
        }

        $query = trim((string)($parts['query'] ?? ''));
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    private function cleanUrlInput(string $url): string
    {
        $url = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], ' ', $url);
        $url = preg_replace('/[\x00-\x1F\x7F]+/u', '', $url) ?? $url;
        $url = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $url) ?? $url;

        return trim($url);
    }

    private function normalizeUrlHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipv6 = trim($host, '[]');
            return filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . strtolower($ipv6) . ']' : '';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (function_exists('idn_to_ascii')) {
            $asciiHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($asciiHost) && $asciiHost !== '') {
                $host = $asciiHost;
            }
        }

        $host = strtolower($host);
        if (preg_match('/^[a-z0-9._-]+$/', $host) !== 1 || str_contains($host, '..')) {
            return '';
        }

        return $host;
    }

    private function normalizePolicyUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/datenschutz';
        }

        if (str_starts_with($url, '/')) {
            return '/' . ltrim($url, '/');
        }

        $sanitized = trim((string)filter_var($url, FILTER_SANITIZE_URL));
        if ($sanitized === '' || filter_var($sanitized, FILTER_VALIDATE_URL) === false) {
            return '/datenschutz';
        }

        $scheme = strtolower((string)parse_url($sanitized, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $sanitized : '/datenschutz';
    }

    private function sanitizeText(string $value, int $maxLength, string $fallback = ''): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function sanitizeLimitedHtml(string $value, int $maxLength, string $allowedTags = ''): string
    {
        $value = trim(strip_tags($value, $allowedTags));
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function sanitizeCookieNames(string $value): string
    {
        $parts = preg_split('/[,\n\r;]+/', $value) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $cookie = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim((string)$part)) ?? '';
            if ($cookie === '' || isset($normalized[$cookie])) {
                continue;
            }

            $normalized[$cookie] = $cookie;
        }

        $result = implode(', ', array_values($normalized));

        return function_exists('mb_substr') ? mb_substr($result, 0, self::MAX_COOKIE_NAMES_LENGTH) : substr($result, 0, self::MAX_COOKIE_NAMES_LENGTH);
    }

    private function sanitizeSlug(string $value, string $fallbackSource, string $prefix): string
    {
        $source = $value !== '' ? $value : $fallbackSource;
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $source))) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = $prefix . '-' . date('His');
        }

        return function_exists('mb_substr') ? mb_substr($slug, 0, 120) : substr($slug, 0, 120);
    }

    private function slugExistsInTable(string $table, string $slug, int $excludeId = 0): bool
    {
        $query = "SELECT COUNT(*) FROM {$this->prefix}{$table} WHERE slug = ?";
        $params = [$slug];

        if ($excludeId > 0) {
            $query .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return (int)$this->db->get_var($query, $params) > 0;
    }

    private function normalizeScanSource(string $source): string
    {
        $source = trim(str_replace('\\', '/', $source));
        $source = preg_replace('#/+#', '/', $source) ?? $source;

        if (defined('ABSPATH')) {
            $source = str_replace(str_replace('\\', '/', (string)ABSPATH), '', $source);
        }

        $source = ltrim($source, '/');
        if (str_starts_with($source, 'DB: ')) {
            $source = 'DB: ' . ltrim(substr($source, 4), '/');
        }

        if ($source === '') {
            return 'unbekannt';
        }

        return function_exists('mb_substr') ? mb_substr($source, 0, self::MAX_SCAN_SOURCE_LENGTH) : substr($source, 0, self::MAX_SCAN_SOURCE_LENGTH);
    }

    private function buildFileScanSource(string $path): string
    {
        return $this->normalizeScanSource(str_replace((string)ABSPATH, '', $path));
    }

    private function shouldSkipScanPath(string $path): bool
    {
        $normalizedPath = '/' . ltrim(str_replace('\\', '/', strtolower($path)), '/');
        foreach (self::SCAN_SKIP_PATH_FRAGMENTS as $fragment) {
            if (str_contains($normalizedPath, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function canAccess(): bool
    {
        return Auth::instance()->isAdmin();
    }

    /** @param array<string, scalar|null> $context */
    private function logFailure(string $action, string $message, \Throwable $e, array $context = []): void
    {
        Logger::error($message, [
            'module' => 'CookieManagerModule',
            'action' => $action,
            'exception' => $e::class,
        ] + $context);
    }

    private function failResult(string $action, string $message, \Throwable $e): array
    {
        $this->logFailure($action, $message, $e);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $message,
            'cookie_manager',
            null,
            ['exception' => $e::class],
            'error'
        );

        return ['success' => false, 'error' => $message . ' Bitte Logs prüfen.'];
    }
}
