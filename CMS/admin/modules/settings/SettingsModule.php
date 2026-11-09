<?php
declare(strict_types=1);

/**
 * Allgemeine Einstellungen-Modul
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Database;
use CMS\Services\MailService;
use CMS\Services\MarketplaceEndpoints;

class SettingsModule
{
    private Database $db;
    private string $prefix;
    /** @var array<string,bool> */
    private array $tableExistsCache = [];
    /** @var array<string,bool> */
    private array $columnExistsCache = [];

    private const MAX_AUDIT_STRING_LENGTH = 240;
    /** Anzahl aufbewahrter Kopien config/app.backup-*.php (enthalten Zugangsdaten). */
    private const CONFIG_BACKUP_KEEP = 5;

    /** @var array<int,array{table:string,column:string}> */
    private const URL_MIGRATION_TARGETS = [
        ['table' => 'settings', 'column' => 'option_value'],
        ['table' => 'user_meta', 'column' => 'meta_value'],
        ['table' => 'pages', 'column' => 'content'],
        ['table' => 'pages', 'column' => 'content_en'],
        ['table' => 'pages', 'column' => 'featured_image'],
        ['table' => 'pages', 'column' => 'meta_description'],
        ['table' => 'posts', 'column' => 'content'],
        ['table' => 'posts', 'column' => 'content_en'],
        ['table' => 'posts', 'column' => 'excerpt'],
        ['table' => 'posts', 'column' => 'excerpt_en'],
        ['table' => 'posts', 'column' => 'featured_image'],
        ['table' => 'posts', 'column' => 'meta_description'],
        ['table' => 'landing_sections', 'column' => 'data'],
        ['table' => 'plugins', 'column' => 'settings'],
        ['table' => 'plugin_meta', 'column' => 'meta_value'],
        ['table' => 'theme_customizations', 'column' => 'setting_value'],
        ['table' => 'site_tables', 'column' => 'description'],
        ['table' => 'site_tables', 'column' => 'columns_json'],
        ['table' => 'site_tables', 'column' => 'rows_json'],
        ['table' => 'site_tables', 'column' => 'settings_json'],
        ['table' => 'redirect_rules', 'column' => 'target_url'],
        ['table' => 'redirect_rules', 'column' => 'source_path'],
        ['table' => 'messages', 'column' => 'body'],
        ['table' => 'comments', 'column' => 'content'],
        ['table' => 'activity_log', 'column' => 'description'],
        ['table' => 'activity_log', 'column' => 'metadata'],
        ['table' => 'audit_log', 'column' => 'description'],
        ['table' => 'audit_log', 'column' => 'metadata'],
        ['table' => 'mail_log', 'column' => 'error_message'],
        ['table' => 'mail_log', 'column' => 'meta'],
        ['table' => 'mail_queue', 'column' => 'body'],
        ['table' => 'mail_queue', 'column' => 'headers'],
        ['table' => 'mail_queue', 'column' => 'attachment_path'],
        ['table' => 'mail_queue', 'column' => 'last_error'],
    ];

    private const SETTINGS_KEYS = [
        'site_name', 'site_description', 'site_url', 'site_logo', 'site_favicon', 'admin_email',
        'language', 'timezone', 'date_format', 'time_format',
        'posts_per_page', 'comments_enabled',
        'maintenance_mode', 'maintenance_message',
        'google_analytics', 'robots_txt', 'marketplace_enabled',
        'plugin_registry_url', 'theme_marketplace_url', 'core_update_url',
        'theme_registry_url', 'plugin_marketplace_base_url',
        'marketplace_public_url', 'marketplace_submit_url',
        'setting_editor_type', 'setting_page_default_status',
        'setting_post_default_status', 'setting_page_editor_width',
        'setting_post_editor_width', 'setting_post_permalink_structure',
        'setting_post_permalink_custom',
        'routing.category_base_de', 'routing.category_base_en',
        'routing.tag_base_de', 'routing.tag_base_en',
    ];

    private const CONTENT_SETTINGS_KEYS = [
        'setting_editor_type',
        'setting_page_default_status',
        'setting_post_default_status',
        'setting_page_editor_width',
        'setting_post_editor_width',
        'setting_post_permalink_structure',
        'setting_post_permalink_custom',
        'routing.category_base_de',
        'routing.category_base_en',
        'routing.tag_base_de',
        'routing.tag_base_en',
    ];

    private const ARCHIVE_BASE_DEFAULTS = [
        'category' => [
            'de' => 'kategorie',
            'en' => 'category',
        ],
        'tag' => [
            'de' => 'tag',
            'en' => 'tag',
        ],
    ];

    private const TIMEZONES = [
        'Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich',
        'Europe/London', 'Europe/Paris', 'Europe/Rome',
        'Europe/Madrid', 'Europe/Amsterdam', 'Europe/Brussels',
        'UTC', 'America/New_York', 'America/Chicago',
        'America/Los_Angeles', 'Asia/Tokyo', 'Asia/Shanghai',
    ];

    private const LANGUAGES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt' => 'Português',
    ];

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * Alle Einstellungen laden
     */
    public function getData(): array
    {
        $settings = $this->loadSettings();
        $config = $this->parseExistingConfig();
        $postPermalinkStructure = class_exists('\CMS\Services\PermalinkService')
            ? \CMS\Services\PermalinkService::normalizePostStructure((string)($settings['setting_post_permalink_structure'] ?? ''))
            : '/blog/%postname%';
        $postPermalinkPreset = class_exists('\CMS\Services\PermalinkService')
            ? \CMS\Services\PermalinkService::inferPostStructurePreset($postPermalinkStructure)
            : 'blog';
        $postPermalinkCustom = trim((string)($settings['setting_post_permalink_custom'] ?? ''));
        if ($postPermalinkPreset !== 'custom' && $postPermalinkCustom === '') {
            $postPermalinkCustom = $postPermalinkStructure;
        }

        $categoryBaseDe = $this->getArchiveBaseSetting($settings, 'category', 'de');
        $categoryBaseEn = $this->getArchiveBaseSetting($settings, 'category', 'en');
        $tagBaseDe = $this->getArchiveBaseSetting($settings, 'tag', 'de');
        $tagBaseEn = $this->getArchiveBaseSetting($settings, 'tag', 'en');

        $editorType = (string)($settings['setting_editor_type'] ?? 'editorjs');
        if (!in_array($editorType, ['editorjs', 'suneditor'], true)) {
            $editorType = 'editorjs';
        }

        $pageDefaultStatus = (string)($settings['setting_page_default_status'] ?? 'draft');
        if (!in_array($pageDefaultStatus, ['draft', 'published', 'private'], true)) {
            $pageDefaultStatus = 'draft';
        }

        $postDefaultStatus = (string)($settings['setting_post_default_status'] ?? 'draft');
        if (!in_array($postDefaultStatus, ['draft', 'published'], true)) {
            $postDefaultStatus = 'draft';
        }

        $marketplaceEndpoints = MarketplaceEndpoints::resolve($settings);

        return [
            'settings'  => [
                'site_name'            => $settings['site_name'] ?? (defined('SITE_NAME') ? SITE_NAME : ''),
                'site_description'     => $settings['site_description'] ?? '',
                'site_url'             => $settings['site_url'] ?? (defined('SITE_URL') ? SITE_URL : ''),
                'runtime_site_url'     => $config['site_url'] ?? (defined('SITE_URL') ? SITE_URL : ''),
                'site_logo'            => $settings['site_logo'] ?? '',
                'site_favicon'         => $settings['site_favicon'] ?? '',
                'admin_email'          => $settings['admin_email'] ?? '',
                'language'             => $settings['language'] ?? 'de',
                'timezone'             => $settings['timezone'] ?? 'Europe/Berlin',
                'date_format'          => $settings['date_format'] ?? 'd.m.Y',
                'time_format'          => $settings['time_format'] ?? 'H:i',
                'posts_per_page'       => $settings['posts_per_page'] ?? '10',
                'comments_enabled'     => ($settings['comments_enabled'] ?? '1') === '1',
                'maintenance_mode'     => ($settings['maintenance_mode'] ?? '0') === '1',
                'maintenance_message'  => $settings['maintenance_message'] ?? 'Die Website wird gerade gewartet.',
                'google_analytics'     => $settings['google_analytics'] ?? '',
                'robots_txt'           => $settings['robots_txt'] ?? '',
                'marketplace_enabled'  => ($settings['marketplace_enabled'] ?? '1') === '1',
                'marketplace_public_url' => $marketplaceEndpoints['marketplace_public_url'],
                'plugin_registry_url'  => $marketplaceEndpoints['plugin_registry_url'],
                'theme_registry_url'   => $marketplaceEndpoints['theme_registry_url'],
                'plugin_marketplace_base_url' => $marketplaceEndpoints['plugin_marketplace_base_url'],
                'theme_marketplace_url'=> $marketplaceEndpoints['theme_marketplace_url'],
                'marketplace_submit_url' => $marketplaceEndpoints['marketplace_submit_url'],
                'core_update_url'      => $marketplaceEndpoints['core_update_url'],
                'editor_type'          => $editorType,
                'page_default_status'  => $pageDefaultStatus,
                'post_default_status'  => $postDefaultStatus,
                'page_editor_width'    => (string)max(320, min(1600, (int)($settings['setting_page_editor_width'] ?? 1050))),
                'post_editor_width'    => (string)max(320, min(1600, (int)($settings['setting_post_editor_width'] ?? 750))),
                'post_permalink_structure' => $postPermalinkStructure,
                'post_permalink_preset'    => $postPermalinkPreset,
                'post_permalink_custom'    => $postPermalinkCustom,
                'post_permalink_example'   => class_exists('\CMS\Services\PermalinkService')
                    ? \CMS\Services\PermalinkService::buildExamplePath($postPermalinkStructure)
                    : '/blog/beispielbeitrag',
                'category_base_de'     => $categoryBaseDe,
                'category_base_en'     => $categoryBaseEn,
                'tag_base_de'          => $tagBaseDe,
                'tag_base_en'          => $tagBaseEn,
            ],
            'mail'      => $this->getMailData($settings),
            'timezones' => self::TIMEZONES,
            'languages' => self::LANGUAGES,
        ];
    }

    /**
     * Einstellungen speichern
     */
    public function saveSettings(array $post): array
    {
        try {
            $existingConfig = $this->parseExistingConfig();
            if ($existingConfig === false) {
                return ['success' => false, 'error' => 'Die zentrale Konfigurationsdatei konnte nicht gelesen werden.'];
            }

            $isContentSettingsSave = ($post['tab'] ?? '') === 'content'
                || array_key_exists('post_permalink_preset', $post);
            $permalinkPreset = (string)($post['post_permalink_preset'] ?? 'blog');
            $permalinkCustom = trim((string)($post['post_permalink_custom'] ?? ''));
            $permalinkStructure = match ($permalinkPreset) {
                'dated' => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_DATED : '/%year%/%monthnum%/%day%/%postname%',
                'slug' => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_SLUG : '/%postname%',
                'year' => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_YEAR : '/%year%/%postname%',
                'custom' => $permalinkCustom,
                default => class_exists('\CMS\Services\PermalinkService') ? \CMS\Services\PermalinkService::PRESET_BLOG : '/blog/%postname%',
            };

            if (class_exists('\CMS\Services\PermalinkService')) {
                $permalinkStructure = \CMS\Services\PermalinkService::normalizePostStructure($permalinkStructure);
            }

            if ($permalinkPreset === 'custom' && $permalinkCustom === '') {
                return $this->buildValidationFailure(
                    'Bitte eine benutzerdefinierte Beitrags-URL-Struktur angeben.',
                    ['post_permalink_custom'],
                    [
                        'Nutze z. B. /wissen/%postname% oder /%year%/%postname%.',
                        'Erlaubte Platzhalter sind %year%, %monthnum%, %day% und %postname%.',
                    ]
                );
            }

            $categoryBaseDe = $this->normalizeRouteBase(
                (string)($post['category_base_de'] ?? ''),
                self::ARCHIVE_BASE_DEFAULTS['category']['de']
            );
            $categoryBaseEn = $this->normalizeRouteBase(
                (string)($post['category_base_en'] ?? ''),
                self::ARCHIVE_BASE_DEFAULTS['category']['en']
            );
            $tagBaseDe = $this->normalizeRouteBase(
                (string)($post['tag_base_de'] ?? ''),
                self::ARCHIVE_BASE_DEFAULTS['tag']['de']
            );
            $tagBaseEn = $this->normalizeRouteBase(
                (string)($post['tag_base_en'] ?? ''),
                self::ARCHIVE_BASE_DEFAULTS['tag']['en']
            );

            if ($categoryBaseDe === $tagBaseDe) {
                return $this->buildValidationFailure(
                    'Die deutschen Slugs für Kategorie- und Tag-Archive müssen unterschiedlich sein.',
                    ['category_base_de', 'tag_base_de'],
                    [sprintf('Aktuell würden beide Archive unter /%s/... laufen.', $categoryBaseDe)]
                );
            }

            if ($categoryBaseEn === $tagBaseEn) {
                return $this->buildValidationFailure(
                    'Die englischen Slugs für Kategorie- und Tag-Archive müssen unterschiedlich sein.',
                    ['category_base_en', 'tag_base_en'],
                    [sprintf('Aktuell würden beide Archive unter /en/%s/... laufen.', $categoryBaseEn)]
                );
            }

            $editorType = (string)($post['editor_type'] ?? 'editorjs');
            if (!in_array($editorType, ['editorjs', 'suneditor'], true)) {
                $editorType = 'editorjs';
            }

            $pageDefaultStatus = (string)($post['page_default_status'] ?? 'draft');
            if (!in_array($pageDefaultStatus, ['draft', 'published', 'private'], true)) {
                $pageDefaultStatus = 'draft';
            }

            $postDefaultStatus = (string)($post['post_default_status'] ?? 'draft');
            if (!in_array($postDefaultStatus, ['draft', 'published'], true)) {
                $postDefaultStatus = 'draft';
            }

            $siteLogo = $this->normalizeMediaReference((string)($post['site_logo'] ?? ''));

            $siteFavicon = $this->normalizeMediaReference((string)($post['site_favicon'] ?? ''));

            $settings = $this->loadSettings();
            $storedSiteUrl = '';
            foreach ([
                (string)($settings['site_url'] ?? ''),
                (string)($existingConfig['site_url'] ?? ''),
                defined('SITE_URL') ? (string)SITE_URL : '',
            ] as $siteUrlCandidate) {
                $siteUrlCandidate = rtrim(filter_var($siteUrlCandidate, FILTER_SANITIZE_URL), '/');
                if ($siteUrlCandidate !== '' && filter_var($siteUrlCandidate, FILTER_VALIDATE_URL) !== false) {
                    $storedSiteUrl = $siteUrlCandidate;
                    break;
                }
            }
            $submittedSiteUrl = array_key_exists('site_url', $post)
                ? rtrim(filter_var((string)$post['site_url'], FILTER_SANITIZE_URL), '/')
                : $storedSiteUrl;
            $newSiteUrl = $isContentSettingsSave ? $storedSiteUrl : $submittedSiteUrl;
            if (!$isContentSettingsSave && ($newSiteUrl === '' || filter_var($newSiteUrl, FILTER_VALIDATE_URL) === false)) {
                return ['success' => false, 'error' => 'Bitte eine gültige Website-URL angeben.'];
            }

            $marketplaceEndpoints = MarketplaceEndpoints::resolve(
                array_intersect_key($post, MarketplaceEndpoints::DEFAULTS)
            );

            $values = [
                'site_name'            => trim(strip_tags($post['site_name'] ?? '')),
                'site_title'           => trim(strip_tags($post['site_name'] ?? '')),
                'site_description'     => trim(strip_tags($post['site_description'] ?? '')),
                'site_url'             => $newSiteUrl,
                'site_logo'            => $siteLogo,
                'site_favicon'         => $siteFavicon,
                'admin_email'          => filter_var($post['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
                'language'             => array_key_exists($post['language'] ?? 'de', self::LANGUAGES) ? ($post['language'] ?? 'de') : 'de',
                'timezone'             => in_array($post['timezone'] ?? '', self::TIMEZONES, true) ? $post['timezone'] : 'Europe/Berlin',
                'date_format'          => in_array($post['date_format'] ?? 'd.m.Y', ['d.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'], true) ? ($post['date_format'] ?? 'd.m.Y') : 'd.m.Y',
                'time_format'          => in_array($post['time_format'] ?? 'H:i', ['H:i', 'H:i:s', 'g:i A'], true) ? ($post['time_format'] ?? 'H:i') : 'H:i',
                'posts_per_page'       => (string)max(1, min(100, (int)($post['posts_per_page'] ?? 10))),
                'comments_enabled'     => !empty($post['comments_enabled']) ? '1' : '0',
                'maintenance_mode'     => !empty($post['maintenance_mode']) ? '1' : '0',
                'maintenance_message'  => trim(strip_tags($post['maintenance_message'] ?? '', '<p><strong><em><br>')),
                'google_analytics'     => preg_match('/^(G-|UA-)[A-Za-z0-9-]+$/', $post['google_analytics'] ?? '') ? $post['google_analytics'] : '',
                'robots_txt'           => strip_tags($post['robots_txt'] ?? ''),
                'marketplace_enabled'  => !empty($post['marketplace_enabled']) ? '1' : '0',
                'marketplace_public_url' => $marketplaceEndpoints['marketplace_public_url'],
                'plugin_registry_url'  => $marketplaceEndpoints['plugin_registry_url'],
                'theme_registry_url'   => $marketplaceEndpoints['theme_registry_url'],
                'plugin_marketplace_base_url' => $marketplaceEndpoints['plugin_marketplace_base_url'],
                'theme_marketplace_url' => $marketplaceEndpoints['theme_marketplace_url'],
                'marketplace_submit_url' => $marketplaceEndpoints['marketplace_submit_url'],
                'core_update_url'      => $marketplaceEndpoints['core_update_url'],
                'setting_editor_type' => $editorType,
                'setting_page_default_status' => $pageDefaultStatus,
                'setting_post_default_status' => $postDefaultStatus,
                'setting_page_editor_width' => (string)max(320, min(1600, (int)($post['page_editor_width'] ?? 1050))),
                'setting_post_editor_width' => (string)max(320, min(1600, (int)($post['post_editor_width'] ?? 750))),
                'setting_post_permalink_structure' => $permalinkStructure,
                'setting_post_permalink_custom' => $permalinkPreset === 'custom' ? $permalinkCustom : '',
                'routing.category_base_de' => $categoryBaseDe,
                'routing.category_base_en' => $categoryBaseEn,
                'routing.tag_base_de' => $tagBaseDe,
                'routing.tag_base_en' => $tagBaseEn,
            ];

            $oldSiteUrl = rtrim((string)($existingConfig['site_url'] ?? ''), '/');
            $manualMigrationSource = rtrim((string) filter_var($post['migrate_from_site_url'] ?? '', FILTER_SANITIZE_URL), '/');
            if ($manualMigrationSource !== '' && filter_var($manualMigrationSource, FILTER_VALIDATE_URL) === false) {
                return ['success' => false, 'error' => 'Die optionale alte Basis-URL für die Migration ist ungültig.'];
            }

            $migrationSourceUrl = $manualMigrationSource !== '' ? $manualMigrationSource : $oldSiteUrl;
            $shouldMigrateUrls = !$isContentSettingsSave
                && !empty($post['migrate_site_url_references'])
                && $migrationSourceUrl !== ''
                && $migrationSourceUrl !== $newSiteUrl;

            $configResult = $isContentSettingsSave
                ? true
                : $this->updateConfigFile($existingConfig, [
                    'site_name' => $values['site_name'],
                    'site_url' => $newSiteUrl,
                    'admin_email' => $values['admin_email'],
                    'debug_mode' => $existingConfig['debug_mode'] ?? (defined('CMS_DEBUG') && CMS_DEBUG ? 'true' : 'false'),
                ]);

            if ($configResult !== true) {
                return ['success' => false, 'error' => is_string($configResult) ? $configResult : 'Konfigurationsdatei konnte nicht aktualisiert werden.'];
            }

            $settingsToSave = $isContentSettingsSave
                ? array_intersect_key($values, array_fill_keys(self::CONTENT_SETTINGS_KEYS, true))
                : $values;
            $existingSettingNames = $this->loadExistingSettingNames(array_keys($settingsToSave));
            $robotsFileIsGenerated = !$isContentSettingsSave && $this->isUnmodifiedGeneratedRobotsFile();

            foreach ($settingsToSave as $key => $value) {
                if (isset($existingSettingNames[$key])) {
                    $this->db->execute(
                        "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                        [$value, $key]
                    );
                } else {
                    $this->db->execute(
                        "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
                        [$key, $value]
                    );
                }
            }

            MarketplaceEndpoints::flush();
            $publicSeoFilesHint = $isContentSettingsSave ? '' : $this->refreshPublicSeoFiles(
                $storedSiteUrl !== $newSiteUrl,
                trim((string)($settings['robots_txt'] ?? '')) !== trim((string)$values['robots_txt']),
                $robotsFileIsGenerated,
                $newSiteUrl
            );

            $migrationSummary = null;
            if ($shouldMigrateUrls) {
                $migrationSummary = $this->migrateSiteUrls($migrationSourceUrl, $newSiteUrl);
            }

            $message = $isContentSettingsSave
                ? 'Einstellungen für Beiträge & Sites gespeichert.'
                : 'Einstellungen gespeichert. Runtime-URL aktualisiert auf ' . $newSiteUrl . '.';
            if (is_array($migrationSummary)) {
                $migrationSourceLabel = $migrationSourceUrl !== '' ? $migrationSourceUrl : $oldSiteUrl;
                $message .= sprintf(
                    ' Absolute URL-Verweise von %s migriert: %d Feld(er) aktualisiert, %d Datensatz/Durchlauf(e) betroffen.',
                    $migrationSourceLabel !== '' ? $migrationSourceLabel : 'der alten Basis-URL',
                    (int)($migrationSummary['columns_updated'] ?? 0),
                    (int)($migrationSummary['rows_affected'] ?? 0)
                );
            }

            $message .= $publicSeoFilesHint;

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'settings.general.save',
                'Allgemeine Einstellungen gespeichert',
                'setting',
                null,
                [
                    'site_url' => $newSiteUrl,
                    'language' => $values['language'],
                    'timezone' => $values['timezone'],
                    'marketplace_enabled' => $values['marketplace_enabled'],
                    'url_migration' => $this->summarizeMigrationSummary($migrationSummary),
                ],
                'warning'
            );

            return ['success' => true, 'message' => $message];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'settings.general.save_failed',
                'Speichern der allgemeinen Einstellungen fehlgeschlagen',
                'setting',
                null,
                ['exception' => $this->sanitizeExceptionMessage($e)],
                'error'
            );

            return ['success' => false, 'error' => 'Allgemeine Einstellungen konnten nicht gespeichert werden. Bitte Logs prüfen.'];
        }
    }

    /**
     * @param list<string> $invalidFields
     * @param list<string> $details
     * @return array{success:false,error:string,invalid_fields:list<string>,details:list<string>}
     */
    private function buildValidationFailure(string $message, array $invalidFields = [], array $details = []): array
    {
        return [
            'success' => false,
            'error' => $message,
            'invalid_fields' => array_values(array_filter(array_map(
                static fn ($field): string => trim((string) $field),
                $invalidFields
            ), static fn (string $field): bool => $field !== '')),
            'details' => array_values(array_filter(array_map(
                static fn ($detail): string => trim((string) $detail),
                $details
            ), static fn (string $detail): bool => $detail !== '')),
        ];
    }

    public function runSiteUrlMigration(array $post): array
    {
        try {
            $existingConfig = $this->parseExistingConfig();
            if ($existingConfig === false) {
                return ['success' => false, 'error' => 'Die zentrale Konfigurationsdatei konnte nicht gelesen werden.'];
            }

            $settings = $this->loadSettings();
            $defaultTargetUrl = rtrim((string) ($settings['site_url'] ?? $existingConfig['site_url'] ?? (defined('SITE_URL') ? SITE_URL : '')), '/');
            $targetSiteUrl = rtrim((string) filter_var($post['site_url'] ?? $defaultTargetUrl, FILTER_SANITIZE_URL), '/');

            if ($targetSiteUrl === '' || filter_var($targetSiteUrl, FILTER_VALIDATE_URL) === false) {
                return ['success' => false, 'error' => 'Bitte eine gültige Ziel-Website-URL angeben.'];
            }

            $manualMigrationSource = rtrim((string) filter_var($post['migrate_from_site_url'] ?? '', FILTER_SANITIZE_URL), '/');
            if ($manualMigrationSource !== '' && filter_var($manualMigrationSource, FILTER_VALIDATE_URL) === false) {
                return ['success' => false, 'error' => 'Die optionale alte Basis-URL für die Migration ist ungültig.'];
            }

            $fallbackSourceUrl = rtrim((string) ($existingConfig['site_url'] ?? ''), '/');
            $migrationSourceUrl = $manualMigrationSource !== ''
                ? $manualMigrationSource
                : ($fallbackSourceUrl !== $targetSiteUrl ? $fallbackSourceUrl : '');

            if ($migrationSourceUrl === '') {
                return ['success' => false, 'error' => 'Bitte eine alte Basis-URL für die Nachmigration eintragen.'];
            }

            if ($migrationSourceUrl === $targetSiteUrl) {
                return ['success' => false, 'error' => 'Alte Basis-URL und Ziel-Website-URL dürfen nicht identisch sein.'];
            }

            $migrationSummary = $this->migrateSiteUrls($migrationSourceUrl, $targetSiteUrl);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'settings.site_url.migration',
                'URL-Nachmigration ausgeführt',
                'setting',
                null,
                [
                    'source_url' => $migrationSourceUrl,
                    'target_url' => $targetSiteUrl,
                    'summary' => $this->summarizeMigrationSummary($migrationSummary),
                ],
                'warning'
            );

            return [
                'success' => true,
                'message' => sprintf(
                    'URL-Nachmigration ausgeführt: Verweise von %s auf %s geprüft. %d Feld(er) aktualisiert, %d Datensatz/Durchlauf(e) betroffen.',
                    $migrationSourceUrl,
                    $targetSiteUrl,
                    (int) ($migrationSummary['columns_updated'] ?? 0),
                    (int) ($migrationSummary['rows_affected'] ?? 0)
                ),
            ];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'settings.site_url.migration_failed',
                'URL-Nachmigration fehlgeschlagen',
                'setting',
                null,
                ['exception' => $this->sanitizeExceptionMessage($e)],
                'error'
            );

            return ['success' => false, 'error' => 'URL-Nachmigration fehlgeschlagen. Bitte Logs prüfen.'];
        }
    }

    /** @return array{columns_updated:int,rows_affected:int} */
    private function migrateSiteUrls(string $oldUrl, string $newUrl): array
    {
        $summary = ['columns_updated' => 0, 'rows_affected' => 0];
        $quotedOldUrl = $this->encodeUrlForJsonStorage($oldUrl);
        $quotedNewUrl = $this->encodeUrlForJsonStorage($newUrl);

        foreach (self::URL_MIGRATION_TARGETS as $target) {
            $table = $target['table'];
            $column = $target['column'];

            if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
                continue;
            }

            $quotedTable = $this->quoteIdentifier($this->prefix . $table);
            $quotedColumn = $this->quoteIdentifier($column);

            $sql = "UPDATE {$quotedTable}
                    SET {$quotedColumn} = REPLACE(REPLACE({$quotedColumn}, ?, ?), ?, ?)
                    WHERE {$quotedColumn} LIKE ? OR {$quotedColumn} LIKE ?";
            $this->db->execute($sql, [
                $quotedOldUrl,
                $quotedNewUrl,
                $oldUrl,
                $newUrl,
                '%' . $quotedOldUrl . '%',
                '%' . $oldUrl . '%',
            ]);

            $affected = $this->extractAffectedRows();
            if ($affected > 0) {
                $summary['columns_updated']++;
                $summary['rows_affected'] += $affected;
            }
        }

        return $summary;
    }

    private function extractAffectedRows(): int
    {
        try {
            $pdo = $this->db->getPdo();
            return (int) $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function encodeUrlForJsonStorage(string $url): string
    {
        return str_replace('/', '\\/', $url);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        try {
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                $this->columnExistsCache[$cacheKey] = false;

                return false;
            }

            $exists = $this->db->get_var("SHOW COLUMNS FROM {$this->prefix}{$table} LIKE '{$column}'") !== null;
            $this->columnExistsCache[$cacheKey] = $exists;

            return $exists;
        } catch (\Throwable) {
            $this->columnExistsCache[$cacheKey] = false;

            return false;
        }
    }

    /** @return array<string,string>|false */
    private function parseExistingConfig(): array|bool
    {
        $configPath = ABSPATH . 'config/app.php';
        if (!is_file($configPath)) {
            return false;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

        $clean = static fn(string $value): string => str_contains($value, 'YOUR_') ? '' : trim($value);
        // Werte stehen PHP-escaped in der Datei (\' bzw. \\) – vor der Weiterverarbeitung zurückwandeln,
        // sonst werden sie beim Neuschreiben doppelt escaped.
        $unescape = static fn(string $value): string => (string) preg_replace('/\\\\([\\\\\'])/', '$1', $value);
        $config = [];

        if (preg_match("/define\('DB_HOST',\s*'((?:[^'\\\\]|\\\\.)+)'\);/", $content, $m)) {
            $config['db_host'] = $unescape($m[1]);
        }
        if (preg_match("/define\('DB_NAME',\s*'((?:[^'\\\\]|\\\\.)+)'\);/", $content, $m)) {
            $config['db_name'] = $clean($unescape($m[1]));
        }
        if (preg_match("/define\('DB_USER',\s*'((?:[^'\\\\]|\\\\.)+)'\);/", $content, $m)) {
            $config['db_user'] = $clean($unescape($m[1]));
        }
        if (preg_match("/define\('DB_PASS',\s*'((?:[^'\\\\]|\\\\.)+)'\);/", $content, $m)) {
            $config['db_pass'] = str_contains($unescape($m[1]), 'YOUR_') ? '' : $unescape($m[1]);
        }
        if (preg_match("/define\('DB_PREFIX',\s*'((?:[^'\\\\]|\\\\.)+)'\);/", $content, $m)) {
            $config['db_prefix'] = $unescape($m[1]) !== '' ? $unescape($m[1]) : 'cms_';
        }
        if (preg_match("/define\('SITE_NAME',\s*'((?:[^'\\\\]|\\\\.)*)'\);/", $content, $m)) {
            $config['site_name'] = $clean($unescape($m[1]));
        }
        if (preg_match("/define\('ADMIN_EMAIL',\s*'((?:[^'\\\\]|\\\\.)*)'\);/", $content, $m)) {
            $config['admin_email'] = $clean($unescape($m[1]));
        }
        if (preg_match("/define\('SITE_URL',\s*'((?:[^'\\\\]|\\\\.)*)'\);/", $content, $m)) {
            $config['site_url'] = $clean($unescape($m[1]));
        }
        if (preg_match("/define\('CMS_DEBUG',\s*(true|false)\);/", $content, $m)) {
            $config['debug_mode'] = $m[1];
        }
        if (preg_match("/define\('AUTH_KEY',\s*'((?:[^'\\\\]|\\\\.)*)'\);/", $content, $m)) {
            $config['auth_key'] = str_contains($unescape($m[1]), 'REPLACE_VIA_INSTALLER') ? '' : $unescape($m[1]);
        }
        if (preg_match("/define\('SECURE_AUTH_KEY',\s*'((?:[^'\\\\]|\\\\.)*)'\);/", $content, $m)) {
            $config['secure_auth_key'] = str_contains($unescape($m[1]), 'REPLACE_VIA_INSTALLER') ? '' : $unescape($m[1]);
        }
        if (preg_match("/define\('NONCE_KEY',\s*'((?:[^'\\\\]|\\\\.)*)'\);/", $content, $m)) {
            $config['nonce_key'] = str_contains($unescape($m[1]), 'REPLACE_VIA_INSTALLER') ? '' : $unescape($m[1]);
        }

        return (!empty($config['db_user']) && !empty($config['db_name'])) ? $config : false;
    }

    /** @param array<string,string> $existing
     *  @param array<string,string> $updates
     */
    private function updateConfigFile(array $existing, array $updates): bool|string
    {
        $data = [
            'created_at' => date('Y-m-d H:i:s'),
            'debug_mode' => $updates['debug_mode'] ?? $existing['debug_mode'] ?? 'false',
            'db_host' => $existing['db_host'] ?? '',
            'db_name' => $existing['db_name'] ?? '',
            'db_user' => $existing['db_user'] ?? '',
            'db_pass' => $existing['db_pass'] ?? '',
            'db_prefix' => $existing['db_prefix'] ?? 'cms_',
            'auth_key' => $existing['auth_key'] ?? '',
            'secure_auth_key' => $existing['secure_auth_key'] ?? '',
            'nonce_key' => $existing['nonce_key'] ?? '',
            'site_name' => $updates['site_name'] ?? ($existing['site_name'] ?? ''),
            'site_url' => $updates['site_url'] ?? ($existing['site_url'] ?? ''),
            'admin_email' => $updates['admin_email'] ?? ($existing['admin_email'] ?? ''),
        ];

        $configDir = ABSPATH . 'config';
        $configPath = $configDir . '/app.php';
        $htaccessPath = $configDir . '/.htaccess';

        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            return 'Fehler: config/-Verzeichnis konnte nicht erstellt werden.';
        }

        $htaccessContent = "# Auto-generated by CMS Installer (C-02)\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n";
        $currentHtaccess = is_file($htaccessPath) ? @file_get_contents($htaccessPath) : false;
        if ($currentHtaccess !== $htaccessContent && !$this->writeFileAtomically($htaccessPath, $htaccessContent)) {
            return 'Fehler: config/.htaccess konnte nicht geschrieben werden.';
        }

        $escape = static fn(string $value): string => str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        if (is_file($configPath)) {
            // Bevorzugt nur die verwalteten Werte in der bestehenden Datei ersetzen, damit manuell
            // gesetzte Konstanten (LDAP_*, JWT_*, SMTP_*, HTTPS/HSTS, Zeitzone …) erhalten bleiben.
            $existingContent = (string) @file_get_contents($configPath);
            $patched = $this->patchConfigContent($existingContent, $data, $escape);
            if ($patched !== null && $patched === $existingContent) {
                // Werte unverändert: weder schreiben noch eine weitere Kopie der Zugangsdaten anlegen.
                return true;
            }

            $this->backupConfigFile($configPath);

            if ($patched !== null) {
                $validationResult = $this->validateGeneratedPhpFile($patched, 'config/app.php');
                if ($validationResult === true) {
                    return $this->writeFileAtomically($configPath, $patched)
                        ? true
                        : 'Fehler: config/app.php konnte nicht geschrieben werden.';
                }
            }
        }

        // Schlüssel für gespeicherte Geheimnisse und der Backup-Pfad müssen erhalten bleiben,
        // sonst wären verschlüsselte Einstellungen, TOTP-Secrets und Backups nicht mehr lesbar.
        $preservedConstants = '';
        foreach (['CMS_ENCRYPTION_KEY', 'CMS_ENCRYPTION_KEYS_PREVIOUS', 'CMS_BACKUP_PATH'] as $constant) {
            $value = defined($constant) ? constant($constant) : '';
            $value = is_array($value) ? implode(',', array_map('strval', $value)) : (is_scalar($value) ? (string) $value : '');
            $preservedConstants .= "defined('{$constant}') || define('{$constant}', '" . $escape($value) . "');\n";
        }

        $content = <<<PHP
<?php
/**
 * CMS Application Configuration
 *
 * Automatisch erstellt am {$data['created_at']}
 * C-01: Security-Keys via random_bytes() generiert – NICHT in VCS einchecken!
 * C-02: Konfiguration in config/ isoliert (via .htaccess geschützt)
 *
 * @package 365CMS
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

define('CMS_DEBUG', {$data['debug_mode']});

define('DB_HOST',    '{$escape($data['db_host'])}');
define('DB_NAME',    '{$escape($data['db_name'])}');
define('DB_USER',    '{$escape($data['db_user'])}');
define('DB_PASS',    '{$escape($data['db_pass'])}');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX',  '{$escape($data['db_prefix'])}');

define('AUTH_KEY',        '{$escape($data['auth_key'])}');
define('SECURE_AUTH_KEY', '{$escape($data['secure_auth_key'])}');
define('NONCE_KEY',       '{$escape($data['nonce_key'])}');

define('SITE_NAME',    '{$escape($data['site_name'])}');
define('SITE_URL',     '{$escape(rtrim($data['site_url'], '/'))}');
define('ADMIN_EMAIL',  '{$escape($data['admin_email'])}');

require_once ABSPATH . 'core/Version.php';
define('CMS_VERSION',  \CMS\Version::CURRENT);

define('CORE_PATH',   ABSPATH . 'core/');
define('THEME_PATH',  ABSPATH . 'themes/');
define('PLUGIN_PATH', ABSPATH . 'plugins/');
define('UPLOAD_PATH', ABSPATH . 'uploads/');
define('ASSETS_PATH', ABSPATH . 'assets/');


\$cmsLogDir = ABSPATH . 'logs' . DIRECTORY_SEPARATOR;
if (!is_dir(\$cmsLogDir)) {
    @mkdir(\$cmsLogDir, 0755, true);
}

defined('LOG_PATH') || define('LOG_PATH', \$cmsLogDir);
defined('CMS_ERROR_LOG') || define('CMS_ERROR_LOG', rtrim(LOG_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'error.log');

define('SITE_URL_PATH', '/');
define('ASSETS_URL',    SITE_URL . '/assets');
define('UPLOAD_URL',    SITE_URL . '/uploads');

define('DEFAULT_THEME',      'cms-default');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT',      300);
defined('CMS_HTTPS_REDIRECT_STRATEGY') || define('CMS_HTTPS_REDIRECT_STRATEGY', 'upstream');
defined('CMS_HSTS_MODE') || define('CMS_HSTS_MODE', 'https-only');
defined('CMS_HSTS_MAX_AGE') || define('CMS_HSTS_MAX_AGE', 31536000);

if (CMS_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', CMS_ERROR_LOG);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', CMS_ERROR_LOG);
}

date_default_timezone_set('Europe/Berlin');

defined('LDAP_HOST')         || define('LDAP_HOST',         '');
defined('LDAP_PORT')         || define('LDAP_PORT',         389);
defined('LDAP_BASE_DN')      || define('LDAP_BASE_DN',      '');
defined('LDAP_USERNAME')     || define('LDAP_USERNAME',      '');
defined('LDAP_PASSWORD')     || define('LDAP_PASSWORD',      '');
defined('LDAP_USE_SSL')      || define('LDAP_USE_SSL',      false);
defined('LDAP_USE_TLS')      || define('LDAP_USE_TLS',      true);
defined('LDAP_FILTER')       || define('LDAP_FILTER',       '');
defined('LDAP_DEFAULT_ROLE') || define('LDAP_DEFAULT_ROLE', 'member');

defined('JWT_SECRET')        || define('JWT_SECRET',  '');
defined('JWT_TTL')           || define('JWT_TTL',     3600);
defined('JWT_ISSUER')        || define('JWT_ISSUER',  SITE_URL);

defined('SMTP_HOST')       || define('SMTP_HOST',       '');
defined('SMTP_PORT')       || define('SMTP_PORT',       587);
defined('SMTP_USER')       || define('SMTP_USER',       '');
defined('SMTP_PASS')       || define('SMTP_PASS',       '');
defined('SMTP_ENCRYPTION') || define('SMTP_ENCRYPTION', 'tls');
defined('SMTP_FROM_EMAIL') || define('SMTP_FROM_EMAIL', ADMIN_EMAIL);
defined('SMTP_FROM_NAME')  || define('SMTP_FROM_NAME',  SITE_NAME);

{$preservedConstants}
PHP;

        $validationResult = $this->validateGeneratedPhpFile($content, 'config/app.php');
        if ($validationResult !== true) {
            return $validationResult;
        }

        return $this->writeFileAtomically($configPath, $content)
            ? true
            : 'Fehler: config/app.php konnte nicht geschrieben werden.';
    }

    /**
     * Ersetzt SITE_NAME, SITE_URL, ADMIN_EMAIL und CMS_DEBUG direkt in einer bestehenden config/app.php.
     * Liefert null, wenn eine der Konstanten nicht genau einmal gefunden wird (dann Vorlage verwenden).
     *
     * @param array<string,string> $data
     */
    private function patchConfigContent(string $content, array $data, callable $escape): ?string
    {
        if ($content === '' || !str_starts_with(ltrim($content), '<?php')) {
            return null;
        }

        $stringConstants = [
            'SITE_NAME' => (string) $data['site_name'],
            'SITE_URL' => rtrim((string) $data['site_url'], '/'),
            'ADMIN_EMAIL' => (string) $data['admin_email'],
        ];

        foreach ($stringConstants as $constant => $value) {
            $pattern = "/define\\('" . $constant . "',(\\s*)'(?:[^'\\\\]|\\\\.)*'\\);/";
            $replacement = $escape($value);
            $count = 0;
            $content = (string) preg_replace_callback(
                $pattern,
                static fn(array $m): string => "define('" . $constant . "'," . $m[1] . "'" . $replacement . "');",
                $content,
                -1,
                $count
            );
            if ($count !== 1) {
                return null;
            }
        }

        $debug = ($data['debug_mode'] ?? 'false') === 'true' ? 'true' : 'false';
        $count = 0;
        $content = (string) preg_replace('/define\(\'CMS_DEBUG\',(\s*)(?:true|false)\);/', "define('CMS_DEBUG',\${1}" . $debug . ');', $content, -1, $count);

        return $count === 1 ? $content : null;
    }

    public function repairImportedSlugs(): array
    {
        try {
            $this->ensureImportItemsTable();

            if (class_exists('CMS_Importer_DB')) {
                \CMS_Importer_DB::create_tables();
            }

            if (!$this->tableExists('import_items')) {
                $importLogCount = $this->tableExists('import_log')
                    ? (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}import_log") ?? 0)
                    : 0;

                if ($importLogCount > 0) {
                    return [
                        'success' => false,
                        'error' => 'Es wurden Import-Logs gefunden, aber keine Einzel-Mappings in `import_items`. Bitte die betroffenen XML-Dateien einmal mit der aktuellen Importer-Version erneut importieren, damit die Original-Slugs für die Nachkorrektur gespeichert werden.',
                    ];
                }

                return ['success' => false, 'error' => 'Keine Import-Mappings gefunden. Bitte zuerst Inhalte mit dem WordPress-Importer importieren.'];
            }

            $mappingCount = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}import_items") ?? 0);
            if ($mappingCount === 0) {
                $backfill = $this->backfillImportMappingsFromSources();
                $mappingCount = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}import_items") ?? 0);

                if ($mappingCount > 0) {
                    // weiter unten normal mit den neu aufgebauten Mappings fortfahren
                } else {
                $importLogCount = $this->tableExists('import_log')
                    ? (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}import_log") ?? 0)
                    : 0;

                if ($importLogCount > 0) {
                    return [
                        'success' => false,
                        'error' => sprintf(
                            'Import-Logs sind vorhanden, aber `import_items` enthält noch keine nutzbaren Einträge. Ein automatischer Backfill hat %d Datei(en) geprüft und %d Mapping(s) aufgebaut, %d Treffer blieben uneindeutig oder fehlten. Bitte die betroffenen XML-Dateien mit der aktuellen Importer-Version erneut importieren, falls noch Inhalte ohne Mapping übrig sind.',
                            (int)($backfill['files_scanned'] ?? 0),
                            (int)($backfill['mappings_created'] ?? 0),
                            (int)($backfill['unmatched_items'] ?? 0)
                        ),
                    ];
                }

                return ['success' => false, 'error' => 'Keine Import-Mappings gefunden. Bitte zuerst Inhalte mit dem WordPress-Importer importieren.'];
                }
            }

            $rows = $this->db->get_results(
                "SELECT ii.id, ii.target_type, ii.target_id, ii.source_slug, ii.source_url,
                        p.slug AS post_slug, p.published_at AS post_published_at, p.created_at AS post_created_at,
                        pg.slug AS page_slug
                 FROM {$this->prefix}import_items ii
                 LEFT JOIN {$this->prefix}posts p ON ii.target_type = 'post' AND p.id = ii.target_id
                 LEFT JOIN {$this->prefix}pages pg ON ii.target_type = 'page' AND pg.id = ii.target_id
                 WHERE ii.target_type IN ('post', 'page')
                 ORDER BY ii.id DESC"
            ) ?: [];

            if ($rows === []) {
                return ['success' => false, 'error' => 'Keine importierten Beiträge oder Seiten gefunden.'];
            }

            $permalinkService = class_exists('\CMS\Services\PermalinkService')
                ? \CMS\Services\PermalinkService::getInstance()
                : null;
            $seenTargets = [];
            $updatedPosts = 0;
            $updatedPages = 0;
            $checked = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $targetType = trim((string)($row->target_type ?? ''));
                $targetId = (int)($row->target_id ?? 0);
                if ($targetId <= 0 || !in_array($targetType, ['post', 'page'], true)) {
                    $skipped++;
                    continue;
                }

                $targetKey = $targetType . ':' . $targetId;
                if (isset($seenTargets[$targetKey])) {
                    continue;
                }
                $seenTargets[$targetKey] = true;
                $checked++;

                $desiredSlug = class_exists('\CMS\Services\PermalinkService')
                    ? \CMS\Services\PermalinkService::resolveImportedSourceSlug(
                        (string)($row->source_slug ?? ''),
                        (string)($row->source_url ?? '')
                    )
                    : trim((string)($row->source_slug ?? ''));

                if ($desiredSlug === '') {
                    $skipped++;
                    continue;
                }

                if ($targetType === 'post') {
                    $currentSlug = trim((string)($row->post_slug ?? ''));
                    if ($currentSlug === '') {
                        $skipped++;
                        continue;
                    }

                    $newSlug = $this->ensureUniqueSlug('posts', $desiredSlug, $targetId);
                    $newUrl = $permalinkService !== null
                        ? $permalinkService->buildPostUrlFromValues($newSlug, (string)($row->post_published_at ?? ''), (string)($row->post_created_at ?? ''))
                        : rtrim((string)SITE_URL, '/') . '/blog/' . ltrim($newSlug, '/');

                    if ($newSlug !== $currentSlug) {
                        $oldPath = $permalinkService !== null
                            ? $permalinkService->buildPostPathFromValues($currentSlug, (string)($row->post_published_at ?? ''), (string)($row->post_created_at ?? ''))
                            : '/blog/' . ltrim($currentSlug, '/');
                        $newPath = $permalinkService !== null
                            ? $permalinkService->buildPostPathFromValues($newSlug, (string)($row->post_published_at ?? ''), (string)($row->post_created_at ?? ''))
                            : '/blog/' . ltrim($newSlug, '/');

                        $this->db->execute(
                            "UPDATE {$this->prefix}posts SET slug = ?, updated_at = NOW() WHERE id = ?",
                            [$newSlug, $targetId]
                        );

                        if (class_exists('\CMS\Services\RedirectService')) {
                            \CMS\Services\RedirectService::getInstance()->createAutomaticRedirect(
                                $oldPath,
                                $newPath,
                                'Manuelle Import-Slug-Korrektur (Beiträge)'
                            );

                            if ($permalinkService !== null) {
                                $legacyOldPath = $permalinkService->getLegacyPostPath($currentSlug);
                                if ($legacyOldPath !== $oldPath) {
                                    \CMS\Services\RedirectService::getInstance()->createAutomaticRedirect(
                                        $legacyOldPath,
                                        $newPath,
                                        'Legacy-Weiterleitung nach Import-Slug-Korrektur'
                                    );
                                }
                            }

                            foreach (\CMS\Services\ContentLocalizationService::getInstance()->getContentLocales() as $locale) {
                                if ($locale === 'de') {
                                    continue;
                                }

                                $localizedOldPath = $permalinkService !== null
                                    ? $permalinkService->buildPostPathFromValues($currentSlug, (string)($row->post_published_at ?? ''), (string)($row->post_created_at ?? ''), $locale)
                                    : '/blog/' . ltrim($currentSlug, '/') . '/' . $locale;
                                $localizedNewPath = $permalinkService !== null
                                    ? $permalinkService->buildPostPathFromValues($newSlug, (string)($row->post_published_at ?? ''), (string)($row->post_created_at ?? ''), $locale)
                                    : '/blog/' . ltrim($newSlug, '/') . '/' . $locale;

                                \CMS\Services\RedirectService::getInstance()->createAutomaticRedirect(
                                    $localizedOldPath,
                                    $localizedNewPath,
                                    'Lokalisierte Import-Slug-Korrektur (Beiträge)'
                                );

                                if ($permalinkService !== null) {
                                    $localizedLegacyOldPath = $permalinkService->getLegacyPostPath($currentSlug, $locale);
                                    if ($localizedLegacyOldPath !== $localizedOldPath) {
                                        \CMS\Services\RedirectService::getInstance()->createAutomaticRedirect(
                                            $localizedLegacyOldPath,
                                            $localizedNewPath,
                                            'Legacy-Weiterleitung bei lokalisierter Import-Slug-Korrektur'
                                        );
                                    }
                                }
                            }
                        }

                        $updatedPosts++;
                    } else {
                        $skipped++;
                    }

                    $this->db->execute(
                        "UPDATE {$this->prefix}import_items SET target_slug = ?, target_url = ? WHERE target_type = 'post' AND target_id = ?",
                        [$newSlug, $newUrl, $targetId]
                    );

                    continue;
                }

                $currentSlug = trim((string)($row->page_slug ?? ''));
                if ($currentSlug === '') {
                    $skipped++;
                    continue;
                }

                $newSlug = $this->ensureUniqueSlug('pages', $desiredSlug, $targetId);
                $newUrl = rtrim((string)SITE_URL, '/') . '/' . ltrim($newSlug, '/');

                if ($newSlug !== $currentSlug) {
                    $this->db->execute(
                        "UPDATE {$this->prefix}pages SET slug = ?, updated_at = NOW() WHERE id = ?",
                        [$newSlug, $targetId]
                    );

                    if (class_exists('\CMS\Services\RedirectService')) {
                        \CMS\Services\RedirectService::getInstance()->createAutomaticRedirect(
                            '/' . ltrim($currentSlug, '/'),
                            '/' . ltrim($newSlug, '/'),
                            'Manuelle Import-Slug-Korrektur (Seiten)'
                        );

                        foreach (\CMS\Services\ContentLocalizationService::getInstance()->getContentLocales() as $locale) {
                            if ($locale === 'de') {
                                continue;
                            }

                            \CMS\Services\RedirectService::getInstance()->createAutomaticRedirect(
                                '/' . ltrim($currentSlug, '/') . '/' . $locale,
                                '/' . ltrim($newSlug, '/') . '/' . $locale,
                                'Lokalisierte Import-Slug-Korrektur (Seiten)'
                            );
                        }
                    }

                    $updatedPages++;
                } else {
                    $skipped++;
                }

                $this->db->execute(
                    "UPDATE {$this->prefix}import_items SET target_slug = ?, target_url = ? WHERE target_type = 'page' AND target_id = ?",
                    [$newSlug, $newUrl, $targetId]
                );
            }

            return [
                'success' => true,
                'message' => sprintf(
                    'Slug-Nachkorrektur abgeschlossen: %d Beitrag/Beiträge und %d Seite(n) aktualisiert, %d geprüft, %d ohne Änderung.',
                    $updatedPosts,
                    $updatedPages,
                    $checked,
                    $skipped
                ),
            ];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'settings.import_slug_repair.failed',
                'Slug-Nachkorrektur fehlgeschlagen',
                'setting',
                null,
                ['exception' => $this->sanitizeExceptionMessage($e)],
                'error'
            );

            return ['success' => false, 'error' => 'Slug-Nachkorrektur fehlgeschlagen. Bitte Logs prüfen.'];
        }
    }

    private function ensureImportItemsTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}import_items (
                id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                log_id           INT UNSIGNED DEFAULT NULL,
                source_type      VARCHAR(50) NOT NULL,
                source_wp_id     BIGINT UNSIGNED DEFAULT NULL,
                source_reference VARCHAR(191) DEFAULT NULL,
                source_slug      VARCHAR(255) DEFAULT NULL,
                source_url       VARCHAR(500) DEFAULT NULL,
                target_type      VARCHAR(50) NOT NULL,
                target_id        BIGINT UNSIGNED DEFAULT NULL,
                target_slug      VARCHAR(255) DEFAULT NULL,
                target_url       VARCHAR(500) DEFAULT NULL,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_log (log_id),
                INDEX idx_source_wp (source_type, source_wp_id),
                INDEX idx_source_ref (source_type, source_reference),
                INDEX idx_target (target_type, target_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * @return array{files_scanned:int,mappings_created:int,unmatched_items:int}
     */
    private function backfillImportMappingsFromSources(): array
    {
        $result = [
            'files_scanned' => 0,
            'mappings_created' => 0,
            'unmatched_items' => 0,
        ];

        $parser = $this->resolveImporterXmlParser();
        if ($parser === null || !$this->tableExists('import_log')) {
            return $result;
        }

        $logs = $this->db->get_results(
            "SELECT id, filename FROM {$this->prefix}import_log ORDER BY id DESC"
        ) ?: [];

        if ($logs === []) {
            return $result;
        }

        $sourceFiles = $this->getImporterSourceFiles();
        if ($sourceFiles === []) {
            return $result;
        }

        $processed = [];
        foreach ($logs as $log) {
            $filename = trim((string)($log->filename ?? ''));
            if ($filename === '' || isset($processed[$filename]) || !isset($sourceFiles[$filename])) {
                continue;
            }

            $processed[$filename] = true;
            $parsed = $parser->parse($sourceFiles[$filename]);
            if (!empty($parsed['errors'])) {
                continue;
            }

            $result['files_scanned']++;
            foreach (($parsed['posts'] ?? []) as $item) {
                if ($this->backfillImportedContentMapping($item, 'post', 'post', (int)($log->id ?? 0))) {
                    $result['mappings_created']++;
                } else {
                    $result['unmatched_items']++;
                }
            }

            foreach (($parsed['pages'] ?? []) as $item) {
                if ($this->backfillImportedContentMapping($item, 'page', 'page', (int)($log->id ?? 0))) {
                    $result['mappings_created']++;
                } else {
                    $result['unmatched_items']++;
                }
            }
        }

        return $result;
    }

    private function resolveImporterXmlParser(): ?object
    {
        if (class_exists('CMS_Importer_XML_Parser')) {
            return new \CMS_Importer_XML_Parser();
        }

        $pluginDir = $this->resolveImporterPluginDir();
        if ($pluginDir === '' || !is_file($pluginDir . 'includes/class-xml-parser.php')) {
            return null;
        }

        require_once $pluginDir . 'includes/class-xml-parser.php';
        return class_exists('CMS_Importer_XML_Parser') ? new \CMS_Importer_XML_Parser() : null;
    }

    private function resolveImporterPluginDir(): string
    {
        $candidates = [];

        if (defined('CMS_IMPORTER_PLUGIN_DIR')) {
            $candidates[] = (string) CMS_IMPORTER_PLUGIN_DIR;
        }

        $candidates[] = dirname(ABSPATH) . '/plugins/cms-importer/';
        $candidates[] = dirname(dirname(ABSPATH)) . '/365CMS.DE-PLUGINS/cms-importer/';

        foreach ($candidates as $candidate) {
            $normalized = rtrim(str_replace('\\', '/', $candidate), '/') . '/';
            if (is_dir($normalized)) {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * @return array<string,string>
     */
    private function getImporterSourceFiles(): array
    {
        $files = [];

        $pluginDir = $this->resolveImporterPluginDir();
        if ($pluginDir !== '' && is_dir($pluginDir . 'wp_import_files/')) {
            foreach (glob($pluginDir . 'wp_import_files/*.xml') ?: [] as $path) {
                $files[basename($path)] = str_replace('\\', '/', $path);
            }
        }

        if (defined('UPLOAD_PATH')) {
            $importDir = rtrim(str_replace('\\', '/', (string) UPLOAD_PATH), '/') . '/import/';
            if (is_dir($importDir)) {
                foreach (glob($importDir . '*.xml') ?: [] as $path) {
                    $files[basename($path)] = str_replace('\\', '/', $path);
                }
            }
        }

        return $files;
    }

    private function backfillImportedContentMapping(array $item, string $sourceType, string $targetType, int $logId): bool
    {
        $sourceWpId = (int)($item['wp_id'] ?? 0);
        if ($sourceWpId <= 0) {
            return false;
        }

        $existing = (int)($this->db->get_var(
            "SELECT id FROM {$this->prefix}import_items WHERE source_type = ? AND source_wp_id = ? AND target_type = ? LIMIT 1",
            [$sourceType, $sourceWpId, $targetType]
        ) ?? 0);
        if ($existing > 0) {
            return true;
        }

        $desiredSlug = class_exists('\\CMS\\Services\\PermalinkService')
            ? \CMS\Services\PermalinkService::resolveImportedSourceSlug((string)($item['slug'] ?? ''), (string)($item['link'] ?? ''))
            : trim((string)($item['slug'] ?? ''));

        $match = $this->findExistingContentMatch($targetType, $desiredSlug, (string)($item['title'] ?? ''), (string)($item['date'] ?? ''));
        if ($match === null) {
            return false;
        }

        $targetUrl = $targetType === 'post'
            ? (class_exists('\\CMS\\Services\\PermalinkService')
                ? \CMS\Services\PermalinkService::getInstance()->buildPostUrlFromValues((string)$match['slug'], (string)($match['published_at'] ?? ''), (string)($match['created_at'] ?? ''))
                : rtrim((string)SITE_URL, '/') . '/blog/' . ltrim((string)$match['slug'], '/'))
            : rtrim((string)SITE_URL, '/') . '/' . ltrim((string)$match['slug'], '/');

        $this->db->insert('import_items', [
            'log_id' => $logId > 0 ? $logId : null,
            'source_type' => $sourceType,
            'source_wp_id' => $sourceWpId,
            'source_reference' => null,
            'source_slug' => (string)($item['slug'] ?? ''),
            'source_url' => (string)($item['link'] ?? ''),
            'target_type' => $targetType,
            'target_id' => (int)$match['id'],
            'target_slug' => (string)$match['slug'],
            'target_url' => $targetUrl,
        ]);

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findExistingContentMatch(string $targetType, string $desiredSlug, string $title, string $sourceDate): ?array
    {
        $table = $targetType === 'post' ? 'posts' : 'pages';
        $dateField = $targetType === 'post' ? 'COALESCE(published_at, created_at)' : 'created_at';

        if ($desiredSlug !== '') {
            $row = $this->db->get_row(
                "SELECT id, slug, created_at, published_at FROM {$this->prefix}{$table} WHERE slug = ? LIMIT 1",
                [$desiredSlug]
            );
            if ($row !== null) {
                return (array)$row;
            }
        }

        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $rows = $this->db->get_results(
            "SELECT id, slug, created_at, published_at FROM {$this->prefix}{$table} WHERE title = ? ORDER BY id ASC LIMIT 10",
            [$title]
        ) ?: [];

        if (count($rows) === 1) {
            return (array)$rows[0];
        }

        if ($sourceDate !== '') {
            $sameDay = [];
            $sourceDay = substr($sourceDate, 0, 10);
            foreach ($rows as $row) {
                $candidateDate = $targetType === 'post'
                    ? (string)($row->published_at ?? $row->created_at ?? '')
                    : (string)($row->created_at ?? '');
                if ($candidateDate !== '' && substr($candidateDate, 0, 10) === $sourceDay) {
                    $sameDay[] = (array)$row;
                }
            }

            if (count($sameDay) === 1) {
                return $sameDay[0];
            }
        }

        return null;
    }

    public function sendTestEmail(array $post): array
    {
        $recipient = trim((string)($post['test_email_recipient'] ?? ''));
        $result = MailService::getInstance()->sendBackendTestEmail($recipient, 'admin-settings');

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.test',
            !empty($result['success']) ? 'Test-E-Mail aus den Admin-Einstellungen versendet' : 'Test-E-Mail aus den Admin-Einstellungen fehlgeschlagen',
            'setting',
            null,
            [
                'recipient' => $this->maskEmailAddress($recipient),
                'result' => !empty($result['success']) ? 'success' : 'error',
                'transport' => $result['transport'] ?? null,
            ],
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    private function loadSettings(): array
    {
        $settings = [];
        try {
            $rows = $this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ('" . implode("','", self::SETTINGS_KEYS) . "')"
            ) ?: [];
            foreach ($rows as $row) {
                $settings[$row->option_name] = $row->option_value;
            }
        } catch (\Throwable $e) {
            // Defaults werden in getData() gesetzt
        }
        return $settings;
    }

    /**
     * Gespeicherte Sitemaps enthalten absolute URLs und werden nach einem Domainwechsel verworfen (die nächste
     * Anfrage auf /sitemap.xml erzeugt sie mit der neuen URL). Eine statische robots.txt im Webroot hat Vorrang
     * vor der Einstellung: Vom CMS geschriebene, unveränderte Dateien werden neu geschrieben, manuell
     * angepasste bleiben erhalten – der Admin erhält dann einen Hinweis.
     */
    private function refreshPublicSeoFiles(bool $siteUrlChanged, bool $robotsChanged, bool $robotsFileIsGenerated, string $siteUrl): string
    {
        if ($siteUrlChanged) {
            try {
                \CMS\Services\SEOService::getInstance()->invalidateSavedSitemaps();
            } catch (\Throwable) {
            }
        }

        if ((!$siteUrlChanged && !$robotsChanged) || !is_file(ABSPATH . 'robots.txt')) {
            return '';
        }

        if ($robotsFileIsGenerated) {
            try {
                if (\CMS\Services\SEOService::getInstance()->saveRobotsTxt($siteUrl)) {
                    return ' Die statische robots.txt im Webroot wurde aktualisiert.';
                }
            } catch (\Throwable) {
            }
        }

        return ' Hinweis: Im Webroot liegt eine manuell angepasste oder nicht beschreibbare robots.txt, die Vorrang vor dieser Einstellung hat – unter SEO → Sitemap & robots.txt neu schreiben oder die Datei löschen.';
    }

    /**
     * Vor dem Speichern prüfen, ob eine statische robots.txt exakt der bisherigen CMS-Ausgabe entspricht
     * (Zeitstempel- und Kopfkommentar ausgenommen) und damit gefahrlos neu geschrieben werden darf.
     */
    private function isUnmodifiedGeneratedRobotsFile(): bool
    {
        $file = ABSPATH . 'robots.txt';
        if (!is_file($file)) {
            return false;
        }

        $current = @file_get_contents($file, false, null, 0, 65536);
        if (!is_string($current)) {
            return false;
        }

        try {
            $expected = \CMS\Services\SEOService::getInstance()->generateRobotsTxt();
        } catch (\Throwable) {
            return false;
        }

        $normalize = static function (string $content): string {
            $content = str_replace(["\r\n", "\r"], "\n", $content);
            $content = (string) preg_replace('/^# (?:robots\.txt for |Generated: ).*$/m', '', $content);

            return trim((string) preg_replace("/\n{2,}/", "\n", $content));
        };

        return $normalize($current) === $normalize($expected);
    }

    /**
     * @param array<int,string> $keys
     * @return array<string,true>
     */
    private function loadExistingSettingNames(array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys), static fn(string $key): bool => $key !== ''));
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
            $optionName = (string)($row->option_name ?? '');
            if ($optionName !== '') {
                $existing[$optionName] = true;
            }
        }

        return $existing;
    }

    private function getArchiveBaseSetting(array $settings, string $type, string $locale): string
    {
        $default = self::ARCHIVE_BASE_DEFAULTS[$type][$locale] ?? '';
        $value = (string)($settings['routing.' . $type . '_base_' . $locale] ?? '');

        return $this->normalizeRouteBase($value, $default);
    }

    private function normalizeRouteBase(string $value, string $fallback): string
    {
        $normalized = trim($this->lowercaseUtf8($value));
        $normalized = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function normalizeMediaReference(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return '/' . ltrim($value, '/');
        }

        $sanitized = trim((string)filter_var($value, FILTER_SANITIZE_URL));
        if ($sanitized === '' || filter_var($sanitized, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string)parse_url($sanitized, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $sanitized : '';
    }

    /**
     * Sichert config/app.php vor dem Überschreiben. Die Kopie enthält Zugangsdaten und ist zu keinem
     * Zeitpunkt für andere lokale Konten lesbar (createConfigBackupCopy()); aufbewahrt werden
     * CONFIG_BACKUP_KEEP Kopien.
     */
    private function backupConfigFile(string $configPath): void
    {
        $content = @file_get_contents($configPath);
        if (!is_string($content) || $content === '') {
            return;
        }

        $configDir = dirname($configPath);
        $createdBackup = $this->createConfigBackupCopy($configDir, $content);

        // Älteste zuerst (der Name enthält den Zeitstempel); die soeben angelegte Kopie wird nie entfernt.
        $backups = array_values(array_filter(
            glob($configDir . '/app.backup-*.php') ?: [],
            static fn(string $file): bool => $file !== $createdBackup
        ));
        sort($backups, SORT_STRING);
        $excess = count($backups) - (self::CONFIG_BACKUP_KEEP - ($createdBackup !== null ? 1 : 0));
        foreach ($backups as $index => $backupFile) {
            if ($index < $excess) {
                @unlink($backupFile);
            } elseif ((((int) @fileperms($backupFile)) & 0o007) !== 0) {
                // Ältere Kopien wurden mit Standardrechten (meist 0644, für alle lesbar) angelegt.
                @chmod($backupFile, 0640);
            }
        }
    }

    /**
     * Legt eine Kopie wie writeFileAtomically() über tempnam() an: Die temporäre Datei hat 0600, die fertige
     * Kopie 0640 wie app.php. Per fopen() angelegt, hätte sie bis zum chmod() die umask-Rechte (meist 0644).
     * Gibt den Pfad der Kopie zurück, bei Fehlern null.
     */
    private function createConfigBackupCopy(string $configDir, string $content): ?string
    {
        $realDir = realpath($configDir);
        $tempPath = $realDir !== false ? @tempnam($realDir, 'cmscfg_') : false;
        if ($tempPath === false) {
            return null;
        }

        // tempnam() weicht bei nicht beschreibbarem Verzeichnis auf das System-Temp-Verzeichnis aus.
        if (dirname($tempPath) !== $realDir || file_put_contents($tempPath, $content) !== strlen($content)) {
            @unlink($tempPath);
            return null;
        }
        @chmod($tempPath, 0640);

        // .php-Endung: bei fehlendem .htaccess-Schutz (z. B. nginx) wird die Datei ausgeführt statt
        // als Klartext mit DB-Zugangsdaten ausgeliefert. Mikrosekunden halten die Namen eindeutig und
        // chronologisch sortierbar (auch mehrere Kopien je Sekunde).
        for ($attempt = 0; $attempt < 5; $attempt++) {
            [$fraction, $seconds] = explode(' ', microtime());
            $backupPath = $configDir . '/app.backup-' . date('Y-m-d_H-i-s', (int) $seconds)
                . '_' . substr($fraction, 2, 6) . '.php';
            // Namen exklusiv als leere Datei reservieren, dann atomar durch die fertige Kopie ersetzen.
            $handle = @fopen($backupPath, 'xb');
            if ($handle === false) {
                continue;
            }
            fclose($handle);

            if (@rename($tempPath, $backupPath)) {
                return $backupPath;
            }

            @unlink($backupPath);
            break;
        }

        @unlink($tempPath);
        return null;
    }

    private function writeFileAtomically(string $path, string $content): bool
    {
        $directory = dirname($path);
        $tempPath = tempnam($directory, 'cmscfg_');
        if ($tempPath === false) {
            return false;
        }

        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            @unlink($tempPath);
            return false;
        }

        if (@rename($tempPath, $path)) {
            @chmod($path, 0640);
            return true;
        }

        $backupPath = null;
        if (is_file($path)) {
            $backupPath = $path . '.swap.' . str_replace('.', '', uniqid('', true));
            if (!@rename($path, $backupPath)) {
                @unlink($tempPath);
                return false;
            }
        }

        if (!@rename($tempPath, $path)) {
            if ($backupPath !== null && is_file($backupPath)) {
                @rename($backupPath, $path);
            }

            @unlink($tempPath);
            return false;
        }

        if ($backupPath !== null && is_file($backupPath)) {
            @unlink($backupPath);
        }

        @chmod($path, 0640);

        return true;
    }

    private function validateGeneratedPhpFile(string $content, string $label): bool|string
    {
        if (!str_starts_with($content, "<?php")) {
            return sprintf('Fehler: Die generierte Datei %s ist kein gültiges PHP-Dokument.', $label);
        }

        $requiredFragments = [
            "define('DB_HOST'",
            "define('DB_NAME'",
            "define('SITE_URL'",
            "defined('LOG_PATH') || define('LOG_PATH'",
            "defined('CMS_ERROR_LOG') || define('CMS_ERROR_LOG'",
        ];

        foreach ($requiredFragments as $fragment) {
            if (!str_contains($content, $fragment)) {
                return sprintf('Fehler: Die generierte Datei %s ist unvollständig und wurde nicht geschrieben.', $label);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'cmscfg_validate_');
        if ($tempPath === false) {
            return 'Fehler: Die generierte Konfiguration konnte nicht validiert werden.';
        }

        try {
            if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
                return 'Fehler: Die generierte Konfiguration konnte nicht validiert werden.';
            }

            if (@php_strip_whitespace($tempPath) === false) {
                return sprintf('Fehler: Die generierte Datei %s enthält ungültiges PHP und wurde nicht geschrieben.', $label);
            }
        } finally {
            @unlink($tempPath);
        }

        return true;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            $tableName = $this->prefix . $table;
            $quotedTable = $this->db->getPdo()->quote($tableName);
            if (!is_string($quotedTable)) {
                $this->tableExistsCache[$table] = false;
                return false;
            }

            $result = $this->db->getPdo()->query('SHOW TABLES LIKE ' . $quotedTable);
            $exists = $result !== false && $result->fetchColumn() !== false;
            $this->tableExistsCache[$table] = $exists;

            return $exists;
        } catch (\Throwable) {
            $this->tableExistsCache[$table] = false;

            return false;
        }
    }

    private function ensureUniqueSlug(string $table, string $baseSlug, int $ignoreId): string
    {
        $slug = trim($baseSlug, '/');
        $try = $slug;
        $suffix = 2;

        while ($try !== '') {
            $count = (int)($this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}{$table} WHERE slug = ? AND id != ?",
                [$try, $ignoreId]
            ) ?? 0);

            if ($count === 0) {
                return $try;
            }

            $try = $slug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, bool|int|string>
     */
    private function getMailData(array $settings): array
    {
        $transport = MailService::getInstance()->getTransportInfo();
        $fallbackRecipient = trim((string)($settings['admin_email'] ?? ''));
        if ($fallbackRecipient === '' && defined('ADMIN_EMAIL')) {
            $fallbackRecipient = (string) ADMIN_EMAIL;
        }

        return $transport + [
            'test_recipient' => $fallbackRecipient,
        ];
    }

    private function summarizeMigrationSummary(?array $summary): ?array
    {
        if (!is_array($summary)) {
            return null;
        }

        return [
            'columns_updated' => max(0, (int)($summary['columns_updated'] ?? 0)),
            'rows_affected' => max(0, (int)($summary['rows_affected'] ?? 0)),
        ];
    }

    private function sanitizeExceptionMessage(\Throwable $throwable): string
    {
        return $this->sanitizeAuditString($throwable->getMessage());
    }

    private function sanitizeAuditString(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return 'n/a';
        }

        return $this->substringUtf8($value, 0, self::MAX_AUDIT_STRING_LENGTH);
    }

    private function maskEmailAddress(string $email): string
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $localLength = $this->lengthUtf8($local);
        if ($localLength <= 2) {
            $maskedLocal = $this->substringUtf8($local, 0, 1) . '*';
        } else {
            $maskedLocal = $this->substringUtf8($local, 0, 1)
                . str_repeat('*', max(1, $localLength - 2))
                . $this->substringUtf8($local, -1, 1);
        }

        return $maskedLocal . '@' . strtolower($domain);
    }

    private function lowercaseUtf8(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower(strtr($value, [
            'Ä' => 'ä',
            'Ö' => 'ö',
            'Ü' => 'ü',
            'ẞ' => 'ß',
        ]));
    }

    private function substringUtf8(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }

        return substr($value, $start, $length);
    }

    private function lengthUtf8(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
