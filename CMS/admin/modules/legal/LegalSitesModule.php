<?php
declare(strict_types=1);

/**
 * LegalSitesModule – Impressum, Datenschutz, AGB, Widerruf und Erklärung zur Barrierefreiheit verwalten
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;
use CMS\Services\ErrorReportService;
use CMS\Services\Legal\AccessibilityStatementService;

class LegalSitesModule
{
    private const MAX_LEGAL_HTML_LENGTH = 60000;
    private const MAX_PROFILE_VALUE_LENGTH = 500;
    private const MAX_PROFILE_TEXTAREA_LENGTH = 4000;
    private const MAX_ERROR_CONTEXT_LENGTH = 180;
    private const TEMPLATE_VERSION = '2026.07.27';

    private readonly \CMS\Database $db;
    private readonly \CMS\PageManager $pageManager;
    private readonly string $prefix;
    private readonly Logger $logger;

    /** @var array{status:string,location:?string}|null Ergebnis des letzten Footer-Abgleichs der Barrierefreiheitserklärung */
    private ?array $lastFooterSync = null;

    /** @var list<string> */
    private const array LEGAL_KEYS = [
        'legal_imprint',
        'legal_privacy',
        'legal_terms',
        'legal_revocation',
        'legal_accessibility',
    ];

    /** @var array<string, string> */
    private const array LABELS = [
        'legal_imprint'    => 'Impressum',
        'legal_privacy'    => 'Datenschutzerklärung',
        'legal_terms'      => 'AGB',
        'legal_revocation' => 'Widerrufsbelehrung',
        'legal_accessibility' => 'Barrierefreiheitserklärung',
    ];

    /** @var array<string, array<string, string>> */
    private const array PAGE_CONFIG = [
        'imprint' => [
            'setting_key' => 'legal_imprint',
            'page_id_key' => 'imprint_page_id',
            'title'       => 'Impressum',
            'slug'        => 'impressum',
            'meta_desc'   => 'Impressum und Anbieterkennzeichnung.',
        ],
        'privacy' => [
            'setting_key' => 'legal_privacy',
            'page_id_key' => 'privacy_page_id',
            'title'       => 'Datenschutzerklärung',
            'slug'        => 'datenschutz',
            'meta_desc'   => 'Informationen zur Verarbeitung personenbezogener Daten.',
        ],
        'terms' => [
            'setting_key' => 'legal_terms',
            'page_id_key' => 'terms_page_id',
            'title'       => 'AGB',
            'slug'        => 'agb',
            'meta_desc'   => 'Allgemeine Geschäftsbedingungen.',
        ],
        'revocation' => [
            'setting_key' => 'legal_revocation',
            'page_id_key' => 'revocation_page_id',
            'title'       => 'Widerrufsbelehrung',
            'slug'        => 'widerruf',
            'meta_desc'   => 'Informationen zum gesetzlichen Widerrufsrecht.',
        ],
        'accessibility' => [
            'setting_key' => 'legal_accessibility',
            'page_id_key' => 'accessibility_page_id',
            'title'       => 'Erklärung zur Barrierefreiheit',
            'slug'        => 'barrierefreiheit',
            'meta_desc'   => 'Erklärung zur Barrierefreiheit dieser Website.',
        ],
    ];

    /** @var array<string, string> */
    private const array PROFILE_DEFAULTS = [
        'legal_template_profile'             => 'dach_de',
        'legal_profile_entity_type'        => 'company',
        'legal_profile_company_name'        => '',
        'legal_profile_legal_form'          => '',
        'legal_profile_owner_name'          => '',
        'legal_profile_managing_director'   => '',
        'legal_profile_content_responsible' => '',
        'legal_profile_street'              => '',
        'legal_profile_postal_code'         => '',
        'legal_profile_city'                => '',
        'legal_profile_country'             => 'Deutschland',
        'legal_profile_email'               => '',
        'legal_profile_phone'               => '',
        'legal_profile_website'             => '',
        'legal_profile_register_court'      => '',
        'legal_profile_register_number'     => '',
        'legal_profile_vat_id'              => '',
        'legal_profile_hosting_provider'    => '',
        'legal_profile_hosting_address'     => '',
        'legal_profile_privacy_contact_name' => '',
        'legal_profile_privacy_contact_email' => '',
        'legal_profile_analytics_name'      => '',
        'legal_profile_analytics_self_hosted' => '0',
        'legal_profile_newsletter_provider' => '',
        'legal_profile_external_media_providers' => '',
        'legal_profile_webfonts_source'     => 'local',
        'legal_profile_webfonts_provider'   => '',
        'legal_profile_payment_providers'   => '',
        'legal_profile_minimal_privacy_mode' => '0',
        'legal_profile_essential_cookie_name' => 'PHPSESSID',
        'legal_profile_essential_cookie_purpose' => 'Speichert technisch notwendige Sitzungs- und Sicherheitseinstellungen.',
        'legal_profile_additional_service_name' => '',
        'legal_profile_additional_service_provider' => '',
        'legal_profile_additional_service_purpose' => '',
        'legal_profile_terms_scope'         => 'b2c',
        'legal_profile_contract_type'       => 'services',
        'legal_profile_return_costs'        => 'customer',
        'legal_profile_service_start_notice' => '0',
        'legal_profile_has_cookies'         => '1',
        'legal_profile_has_contact_form'    => '1',
        'legal_profile_has_registration'    => '0',
        'legal_profile_has_comments'        => '0',
        'legal_profile_has_newsletter'      => '0',
        'legal_profile_has_analytics'       => '0',
        'legal_profile_has_external_media'  => '0',
        'legal_profile_has_webfonts'        => '0',
        'legal_profile_has_shop'            => '0',
        'legal_profile_has_editorial_content' => '0',
        'legal_profile_mstv_responsible_name' => '',
        'legal_profile_mstv_responsible_address' => '',
    ];

    /** @var array<string, array<string, string>> */
    private const array TEMPLATE_PROFILES = [
        'dach_de' => [
            'label' => 'Deutschland · DACH-Basis',
            'country_hint' => 'Deutschland',
            'jurisdiction_note' => 'Berücksichtigt deutsche Anbieterkennzeichnung nach § 5 DDG als technische Strukturhilfe.',
        ],
        'dach_at' => [
            'label' => 'Österreich · DACH-Basis',
            'country_hint' => 'Österreich',
            'jurisdiction_note' => 'Strukturiert die Texte für österreichische Anbieter; länderspezifische Offenlegungs- und Informationspflichten bitte fachlich prüfen.',
        ],
        'dach_ch' => [
            'label' => 'Schweiz · DACH-Basis',
            'country_hint' => 'Schweiz',
            'jurisdiction_note' => 'Strukturiert die Texte für Schweizer Anbieter; DSG/DSGVO-Bezüge und kantonale bzw. branchenspezifische Angaben bitte fachlich prüfen.',
        ],
        'dach_generic' => [
            'label' => 'DACH allgemein · neutrales Skelett',
            'country_hint' => 'DACH',
            'jurisdiction_note' => 'Neutrales DACH-Skelett ohne Anspruch auf eine vollständige Rechtsordnung; Pflichtangaben müssen projektspezifisch ergänzt werden.',
        ],
    ];

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->pageManager = \CMS\PageManager::instance();
        $this->prefix = $this->db->getPrefix();
        $this->logger = Logger::instance()->withChannel('admin.legal-sites');
    }

    public function getData(): array
    {
        if (!$this->canAccess()) {
            return [
                'pages' => [],
                'assigned_pages' => [],
                'all_pages' => [],
                'profile' => array_merge(self::PROFILE_DEFAULTS, [
                    'legal_profile_company_name' => '',
                    'legal_profile_email' => '',
                    'legal_profile_website' => '',
                ]),
                'page_configs' => self::PAGE_CONFIG,
                'error' => 'Zugriff verweigert.',
            ];
        }

        $settings = $this->loadSettingsMap(array_merge(self::LEGAL_KEYS, $this->getAssignmentKeys(), $this->getTemplateMetadataKeys(), array_keys(self::PROFILE_DEFAULTS), [
            'site_name',
            'admin_email',
            'site_url',
        ]));

        $pages = [];
        foreach (self::LEGAL_KEYS as $key) {
            $pages[$key] = [
                'label'   => self::LABELS[$key],
                'content' => (string) ($settings[$key] ?? ''),
            ];
        }

        // Zugewiesene Seiten-IDs
        $assignedPages = [];
        foreach ($this->getAssignmentKeys() as $k) {
            $assignedPages[$k] = (string) ($settings[$k] ?? '');
        }

        // Alle veröffentlichten Seiten für Zuordnung
        $allPages = $this->db->get_results(
            "SELECT id, title FROM {$this->prefix}pages WHERE status = 'published' ORDER BY title"
        ) ?: [];

        return [
            'pages'          => $pages,
            'assigned_pages' => $assignedPages,
            'all_pages'      => array_map(fn($p) => (array)$p, $allPages),
            'profile'        => $this->loadProfile($settings),
            'template_profiles' => $this->getTemplateProfiles(),
            'template_meta'   => $this->getTemplateMetadata($settings),
            'page_configs'   => self::PAGE_CONFIG,
            'accessibility'  => $this->getAccessibilityData($this->loadProfile($settings)),
            'stats'          => [
                'areas' => count(self::LEGAL_KEYS),
                'assigned_pages' => count(array_filter($assignedPages, static fn (string $value): bool => trim($value) !== '' && trim($value) !== '0')),
                'published_pages' => count($allPages),
                'filled_areas' => count(array_filter($pages, static fn (array $page): bool => trim((string) ($page['content'] ?? '')) !== '')),
            ],
            'constraints'    => [
                'max_legal_html_length' => self::MAX_LEGAL_HTML_LENGTH,
                'max_profile_value_length' => self::MAX_PROFILE_VALUE_LENGTH,
                'max_profile_textarea_length' => self::MAX_PROFILE_TEXTAREA_LENGTH,
                'allowed_template_types' => array_keys(self::PAGE_CONFIG),
                'template_version' => self::TEMPLATE_VERSION,
                'template_profile_count' => count(self::TEMPLATE_PROFILES),
                'template_area_count' => count(self::PAGE_CONFIG),
                'profile_toggle_count' => count(array_filter(array_keys(self::PROFILE_DEFAULTS), static fn (string $key): bool => str_starts_with($key, 'legal_profile_has_'))),
            ],
        ];
    }

    public function save(array $post): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen rechtliche Seiten nicht bearbeiten.', 'legal_sites_access_denied');
        }

        try {
            $existingSettings = $this->loadSettingsMap(array_merge(self::LEGAL_KEYS, $this->getAssignmentKeys()));
            $originalSettings = $existingSettings;
            $publishedPageIds = $this->getPublishedPageIds();
            $incomingSettings = [];

            // Rechtliche Inhalte speichern
            foreach (self::LEGAL_KEYS as $key) {
                if (!array_key_exists($key, $post)) {
                    continue;
                }

                $value = $this->sanitizeLegalContent($post[$key] ?? '');
                $incomingSettings[$key] = $value;
                $this->persistSetting($key, $value, $existingSettings);
            }

            // Seiten-Zuordnungen speichern
            foreach ($this->getAssignmentKeys() as $k) {
                if (!array_key_exists($k, $post)) {
                    continue;
                }

                $pageId = (int)($post[$k] ?? 0);
                if ($pageId > 0 && !isset($publishedPageIds[$pageId])) {
                    return $this->buildLegalFailureResult(
                        'Eine ausgewählte Seite ist ungültig oder nicht veröffentlicht.',
                        'legal_sites_invalid_assignment',
                        ['Zuordnung: ' . $k, 'Seiten-ID: ' . $pageId],
                        ['assignment_key' => $k, 'page_id' => $pageId]
                    );
                }

                $incomingSettings[$k] = (string) $pageId;
                $this->persistSetting($k, (string)$pageId, $existingSettings);
            }

            $this->syncRelatedSettingsFromAssignments($post);
            $changedKeys = $this->collectChangedSettingKeys($incomingSettings, $originalSettings);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.sites.save',
                'Rechtliche Inhalte und Seiteneinstellungen gespeichert',
                'legal_site',
                null,
                ['keys' => self::LEGAL_KEYS],
                'warning'
            );

            return [
                'success' => true,
                'message' => 'Rechtliche Seiten gespeichert.',
                'details' => [
                    'Bereiche: ' . count(self::LEGAL_KEYS),
                    'Zuordnungen: ' . count(array_filter($this->getAssignmentKeys(), static fn (string $key): bool => trim((string) ($post[$key] ?? '')) !== '' && trim((string) ($post[$key] ?? '')) !== '0')),
                    'Geänderte Schlüssel: ' . count($changedKeys),
                ],
            ];
        } catch (\Throwable $e) {
			return $this->failResult('legal.sites.save_failed', 'Rechtliche Seiten konnten nicht gespeichert werden.', $e);
        }
    }

    public function saveProfile(array $post): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen Standardwerte für Rechtstexte nicht bearbeiten.', 'legal_profile_access_denied');
        }

        try {
            $sanitized = [];
            $existingSettings = $this->loadSettingsMap(array_keys(self::PROFILE_DEFAULTS));
            $originalSettings = $existingSettings;

            foreach (array_keys(self::PROFILE_DEFAULTS) as $key) {
                $sanitized[$key] = $this->sanitizeProfileValue($key, $post[$key] ?? self::PROFILE_DEFAULTS[$key]);
            }

            $errors = $this->validateProfile($sanitized);
            if ($errors !== []) {
                $result = $this->buildLegalFailureResult(
                    implode(' ', $errors),
                    'legal_profile_validation_failed',
                    array_slice($errors, 0, 5),
                    ['profile_entity_type' => (string) ($sanitized['legal_profile_entity_type'] ?? '')]
                );
                $result['profile'] = $sanitized;

                return $result;
            }

            foreach ($sanitized as $key => $value) {
                $this->persistSetting($key, $value, $existingSettings);
            }

            $changedKeys = $this->collectChangedSettingKeys($sanitized, $originalSettings);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.profile.save',
                'Standardwerte für Rechtstexte gespeichert',
                'legal_profile',
                null,
                ['entity_type' => $sanitized['legal_profile_entity_type'] ?? 'company'],
                'warning'
            );

            return [
                'success' => true,
                'message' => 'Standardwerte für Legal Sites gespeichert.',
                'details' => [
                    'Profiltyp: ' . (string) ($sanitized['legal_profile_entity_type'] ?? 'company'),
                    'Aktive Website-Funktionen: ' . count(array_filter($sanitized, static fn (string $value, string $key): bool => str_starts_with($key, 'legal_profile_has_') && $value === '1', ARRAY_FILTER_USE_BOTH)),
                    'Geänderte Profilfelder: ' . count($changedKeys),
                ],
            ];
        } catch (\Throwable $e) {
            $result = $this->failResult('legal.profile.save_failed', 'Standardwerte für Legal Sites konnten nicht gespeichert werden.', $e);
            $result['profile'] = $sanitized ?? [];

            return $result;
        }
    }

    public function getTemplateContent(string $type): string
    {
        $templates = $this->getTemplates($this->loadProfile());

        return $templates[$type] ?? '';
    }

    public function generateTemplate(string $type): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen Rechtstext-Vorlagen nicht generieren.', 'legal_template_access_denied');
        }

        $profile = $this->loadProfile();
        $templates = $this->getTemplates($profile);

        if (!isset($templates[$type])) {
            return $this->buildLegalFailureResult('Unbekannter Vorlagentyp.', 'legal_template_unknown_type', ['Vorlagentyp: ' . $type], ['template_type' => $type]);
        }

        $key = 'legal_' . $type;
        $this->saveSetting($key, $templates[$type]);
        $this->recordTemplateApplication($type, $profile);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'legal.template.generate',
            'Rechtstext-Vorlage generiert',
            'legal_template',
            null,
            ['type' => $type, 'profile' => $this->templateProfileKey($profile), 'version' => self::TEMPLATE_VERSION],
            'info'
        );

        return [
            'success' => true,
            'message' => self::LABELS[$key] . '-Vorlage generiert.',
            'details' => [
                'Vorlagentyp: ' . $type,
                'Bereich: ' . self::LABELS[$key],
                'Profiltyp: ' . $this->profileValue($profile, 'legal_profile_entity_type', 'company'),
                'DACH-Profil: ' . $this->getTemplateProfileConfig($profile)['label'],
                'Vorlagenversion: ' . self::TEMPLATE_VERSION,
            ],
        ];
    }

    public function createOrUpdatePage(string $type, int $userId): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen Rechtstext-Seiten nicht erzeugen.', 'legal_page_access_denied');
        }

        $config = self::PAGE_CONFIG[$type] ?? null;
        if ($config === null) {
            return $this->buildLegalFailureResult('Unbekannter Seitentyp.', 'legal_page_unknown_type', ['Seitentyp: ' . $type], ['page_type' => $type]);
        }

        if ($userId <= 0) {
            return $this->buildLegalFailureResult('Ungültiger Benutzerkontext für die Seitenerstellung.', 'legal_page_invalid_user', ['User-ID: ' . $userId], ['user_id' => $userId, 'page_type' => $type]);
        }

        if ($type === 'accessibility') {
            $errors = AccessibilityStatementService::validate(AccessibilityStatementService::getInstance()->getFields());
            if ($errors !== []) {
                return $this->buildLegalFailureResult(
                    __('a11y.page.invalid'),
                    'legal_accessibility_invalid',
                    AccessibilityStatementService::errorMessages($errors),
                    ['page_type' => $type, 'errors' => $errors]
                );
            }
        }

        try {
            $profile = $this->loadProfile();
            $templates = $this->getTemplates($profile);
            $content = (string)($templates[$type] ?? '');
            if ($content === '') {
                return $this->buildLegalFailureResult('Für diesen Bereich konnte kein Inhalt generiert werden.', 'legal_page_missing_content', ['Seitentyp: ' . $type], ['page_type' => $type]);
            }
            $this->lastFooterSync = null;

            $this->saveSetting($config['setting_key'], $content);
            $this->recordTemplateApplication($type, $profile);

            $pageId = (int)$this->getSetting($config['page_id_key']);
            $title = $config['title'];
            $slug = $config['slug'];
            $metaDesc = $config['meta_desc'];

            if ($pageId > 0) {
                $page = $this->pageManager->getPage($pageId);
                if ($page !== null) {
                    $this->pageManager->updatePage($pageId, array_merge([
                        'title'            => $title,
                        'slug'             => $slug,
                        'content'          => $content,
                        'status'           => 'published',
                        'meta_title'       => $title,
                        'meta_description' => $metaDesc,
                    ], $this->localizedPageData($type, $profile, $pageId)));

                    $this->saveSetting($config['page_id_key'], (string)$pageId);
                    $this->syncRelatedSettingsForType($type, $pageId);

                    AuditLogger::instance()->log(
                        AuditLogger::CAT_CONTENT,
                        'legal.page.create_or_update',
                        $title . ' als Rechtstext-Seite aktualisiert',
                        'page',
                        $pageId,
                        ['type' => $type, 'slug' => $slug, 'profile' => $this->templateProfileKey($profile), 'version' => self::TEMPLATE_VERSION],
                        'warning'
                    );

                    return ['success' => true, 'message' => $title . ' wurde aktualisiert.', 'page_id' => $pageId, 'details' => array_merge(['Seitentyp: ' . $type, 'Seiten-ID: ' . $pageId, 'Vorlagenversion: ' . self::TEMPLATE_VERSION], $this->footerSyncDetails())];
                }
            }

            $existingBySlug = $this->pageManager->getPageBySlug($slug);
            if ($existingBySlug !== null && !empty($existingBySlug['id'])) {
                $pageId = (int)$existingBySlug['id'];
                $this->pageManager->updatePage($pageId, array_merge([
                    'title'            => $title,
                    'slug'             => $slug,
                    'content'          => $content,
                    'status'           => 'published',
                    'meta_title'       => $title,
                    'meta_description' => $metaDesc,
                ], $this->localizedPageData($type, $profile, $pageId)));
            } else {
                $pageId = $this->pageManager->createPage($title, $content, 'published', $userId, 0);
                $this->pageManager->updatePage($pageId, array_merge([
                    'slug'             => $slug,
                    'meta_title'       => $title,
                    'meta_description' => $metaDesc,
                ], $this->localizedPageData($type, $profile, $pageId)));
            }

            $this->saveSetting($config['page_id_key'], (string)$pageId);
            $this->syncRelatedSettingsForType($type, $pageId);

            AuditLogger::instance()->log(
                AuditLogger::CAT_CONTENT,
                'legal.page.create_or_update',
                $title . ' als Rechtstext-Seite erstellt oder aktualisiert',
                'page',
                $pageId,
                ['type' => $type, 'slug' => $slug, 'profile' => $this->templateProfileKey($profile), 'version' => self::TEMPLATE_VERSION],
                'warning'
            );

            return ['success' => true, 'message' => $title . ' wurde als Seite erstellt.', 'page_id' => $pageId, 'details' => array_merge(['Seitentyp: ' . $type, 'Seiten-ID: ' . $pageId, 'Vorlagenversion: ' . self::TEMPLATE_VERSION], $this->footerSyncDetails())];
        } catch (\Throwable $e) {
			return $this->failResult('legal.page.create_or_update_failed', 'Rechtstext-Seite konnte nicht erstellt oder aktualisiert werden.', $e);
        }
    }

    public function createOrUpdateAllPages(int $userId): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen Rechtstext-Seiten nicht erzeugen.', 'legal_pages_access_denied');
        }

        $created = [];
        $skipped = [];
        foreach (array_keys(self::PAGE_CONFIG) as $type) {
            // Die Barrierefreiheitserklärung ist optional: nur aktualisieren, wenn sie schon als Seite existiert,
            // und unvollständige Angaben nicht zum Abbruch der übrigen Rechtstexte führen lassen.
            if ($type === 'accessibility') {
                if (AccessibilityStatementService::getInstance()->getPublishedPage() === null) {
                    continue;
                }

                $result = $this->createOrUpdatePage($type, $userId);
                if ($result['success']) {
                    $created[] = self::PAGE_CONFIG[$type]['title'];
                } else {
                    $skipped[] = self::PAGE_CONFIG[$type]['title'] . ': ' . (string) ($result['error'] ?? '');
                }
                continue;
            }

            $result = $this->createOrUpdatePage($type, $userId);
            if (!$result['success']) {
                $result['details'] = array_values(array_filter(array_merge(
                    is_array($result['details'] ?? null) ? $result['details'] : [],
                    ['Bereits verarbeitet: ' . count($created)]
                )));

                return $result;
            }

            $created[] = self::PAGE_CONFIG[$type]['title'];
        }

        return [
            'success' => true,
            'message' => 'Rechtstext-Seiten erstellt/aktualisiert: ' . implode(', ', $created) . '.',
            'details' => array_merge(
                ['Bereiche: ' . count($created), 'Seiten: ' . implode(', ', $created)],
                array_map(static fn (string $entry): string => 'Übersprungen – ' . $entry, $skipped)
            ),
        ];
    }

    /**
     * Speichert die Angaben der Barrierefreiheitserklärung; optional wird die Seite direkt erzeugt/aktualisiert.
     *
     * @param array<string, mixed> $post normalisierte Felder (ohne `a11y_`-Präfix) plus optional `update_page`
     */
    public function saveAccessibility(array $post, int $userId): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen die Barrierefreiheitserklärung nicht bearbeiten.', 'legal_accessibility_access_denied');
        }

        $service = AccessibilityStatementService::getInstance();
        $fields = AccessibilityStatementService::normalize($post);
        $errors = AccessibilityStatementService::validate($fields);
        if ($errors !== []) {
            $result = $this->buildLegalFailureResult(
                __('a11y.save.invalid'),
                'legal_accessibility_validation_failed',
                AccessibilityStatementService::errorMessages($errors),
                ['errors' => $errors]
            );
            $result['accessibility'] = ['fields' => $fields, 'errors' => $errors];

            return $result;
        }

        try {
            $before = $service->getFields();
            if (!$service->saveFields($fields)) {
                throw new \RuntimeException('Einstellungen konnten nicht gespeichert werden.');
            }

            $changed = array_keys(array_diff_assoc($fields, $before));
            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.accessibility.save',
                'Angaben zur Barrierefreiheitserklärung gespeichert',
                'legal_accessibility',
                null,
                ['changed' => $changed, 'status' => $fields['status'], 'footer_link' => $fields['footer_link']],
                'info'
            );

            $details = [sprintf(__('a11y.save.changed'), count($changed))];
            if (!empty($post['update_page'])) {
                $pageResult = $this->createOrUpdatePage('accessibility', $userId);
                if (!($pageResult['success'] ?? false)) {
                    return $pageResult;
                }

                return [
                    'success' => true,
                    'message' => __('a11y.save.page_updated'),
                    'details' => array_merge($details, is_array($pageResult['details'] ?? null) ? $pageResult['details'] : []),
                ];
            }

            $this->lastFooterSync = null;
            if ($service->getPageId() > 0) {
                $this->syncAccessibilityFooter($service->getPageId());
            }

            return [
                'success' => true,
                'message' => __('a11y.save.success'),
                'details' => array_merge($details, $this->footerSyncDetails()),
            ];
        } catch (\Throwable $e) {
            return $this->failResult('legal.accessibility.save_failed', 'Barrierefreiheitserklärung konnte nicht gespeichert werden.', $e);
        }
    }

    /** Bestätigt die jährliche Prüfung (Prüfdatum = heute) und aktualisiert eine vorhandene Seite. */
    public function markAccessibilityReviewed(int $userId): array
    {
        if (!$this->canAccess()) {
            return $this->buildLegalFailureResult('Sie dürfen die Barrierefreiheitserklärung nicht bearbeiten.', 'legal_accessibility_access_denied');
        }

        $service = AccessibilityStatementService::getInstance();
        $fields = $service->getFields();
        $today = new \DateTimeImmutable('today');
        if ($fields['created_at'] === '') {
            $fields['created_at'] = $today->format('Y-m-d');
        }
        $fields['reviewed_at'] = $today->format('Y-m-d');

        $errors = AccessibilityStatementService::validate($fields, $today);
        if ($errors !== []) {
            return $this->buildLegalFailureResult(
                __('a11y.review.invalid'),
                'legal_accessibility_review_invalid',
                AccessibilityStatementService::errorMessages($errors),
                ['errors' => $errors]
            );
        }

        try {
            if (!$service->saveFields($fields)) {
                throw new \RuntimeException('Prüfdatum konnte nicht gespeichert werden.');
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'legal.accessibility.reviewed',
                'Prüfung der Barrierefreiheitserklärung bestätigt',
                'legal_accessibility',
                null,
                ['reviewed_at' => $fields['reviewed_at']],
                'info'
            );

            $details = [sprintf(__('a11y.review.next'), AccessibilityStatementService::formatDate($today->modify('+1 year')))];
            if ($service->getPublishedPage() !== null) {
                $pageResult = $this->createOrUpdatePage('accessibility', $userId);
                if (!($pageResult['success'] ?? false)) {
                    return $pageResult;
                }
                $details = array_merge($details, is_array($pageResult['details'] ?? null) ? $pageResult['details'] : []);
            }

            return ['success' => true, 'message' => __('a11y.review.success'), 'details' => $details];
        } catch (\Throwable $e) {
            return $this->failResult('legal.accessibility.review_failed', 'Prüfung der Barrierefreiheitserklärung konnte nicht gespeichert werden.', $e);
        }
    }

    /** @return array<string, mixed> Daten für die Karte „Erklärung zur Barrierefreiheit“ */
    private function getAccessibilityData(array $profile): array
    {
        $service = AccessibilityStatementService::getInstance();
        $fields = $service->getFields();
        $pageId = $service->getPageId();
        $page = $pageId > 0 ? $this->pageManager->getPage($pageId) : null;
        $published = $service->getPublishedPage($pageId);
        $slug = trim((string) ($published['slug'] ?? ''), '/');
        $slugEn = trim((string) ($published['slug_en'] ?? ''), '/');

        return [
            'fields' => $fields,
            'complete' => AccessibilityStatementService::validate($fields) === [],
            'review' => AccessibilityStatementService::reviewState($fields),
            'page_id' => $pageId,
            'page_exists' => $page !== null,
            'page_published' => $published !== null,
            'page_url' => $slug !== '' ? '/' . $slug : '',
            'page_url_en' => $published !== null ? '/en/' . ($slugEn !== '' ? $slugEn : $slug) : '',
            'footer_location' => $service->getFooterLocation(),
            'footer_linked' => $published !== null && $service->isFooterLinked($pageId),
            'preview_en' => sanitize_html(AccessibilityStatementService::buildStatement($fields, $this->accessibilityContext($profile), 'en'), 'default'),
            'errors' => [],
        ];
    }

    /** @return array{provider:string,website:string} */
    private function accessibilityContext(array $profile): array
    {
        return [
            'provider' => $this->getProfileDisplayName($profile, ''),
            'website' => $this->profileValue($profile, 'legal_profile_website', defined('SITE_URL') ? (string) SITE_URL : ''),
        ];
    }

    /** @return array<string, string> zusätzliche Seitenfelder (EN-Fassung der Barrierefreiheitserklärung) */
    private function localizedPageData(string $type, array $profile, int $pageId): array
    {
        if ($type !== 'accessibility') {
            return [];
        }

        $data = AccessibilityStatementService::getInstance()->localizedPageFields($this->accessibilityContext($profile));
        $conflict = $this->db->get_var(
            "SELECT id FROM {$this->prefix}pages WHERE (slug_en = ? OR slug = ?) AND id <> ? LIMIT 1",
            [$data['slug_en'], $data['slug_en'], $pageId]
        );
        if ($conflict !== null) {
            $data['slug_en'] .= '-statement';
        }

        return $data;
    }

    private function syncAccessibilityFooter(int $pageId): void
    {
        $service = AccessibilityStatementService::getInstance();
        try {
            $this->lastFooterSync = $service->syncFooterLink($pageId);
        } catch (\Throwable $e) {
            $this->logger->warning('Footer-Link der Barrierefreiheitserklärung konnte nicht abgeglichen werden.', [
                'exception' => $e::class,
                'message' => $this->sanitizeErrorContext($e->getMessage()),
            ]);
            $this->lastFooterSync = ['status' => 'failed', 'location' => null];
        }

        $service->clearNoticeCache();
    }

    /** @return list<string> */
    private function footerSyncDetails(): array
    {
        if ($this->lastFooterSync === null) {
            return [];
        }

        return [sprintf(
            __('a11y.footer.detail'),
            __('a11y.footer.status.' . $this->lastFooterSync['status']),
            (string) ($this->lastFooterSync['location'] ?? '–')
        )];
    }

    /** @return array<string, string> */
    private function getTemplates(array $profile): array
    {
        return [
            'imprint'    => $this->buildImprintTemplate($profile),
            'privacy'    => $this->buildPrivacyTemplate($profile),
            'terms'      => $this->buildTermsTemplate($profile),
            'revocation' => $this->buildRevocationTemplate($profile),
            'accessibility' => sanitize_html(AccessibilityStatementService::buildStatement(
                AccessibilityStatementService::getInstance()->getFields(),
                $this->accessibilityContext($profile),
                'de'
            ), 'default'),
        ];
    }

    /** @return array<string, string> */
    private function loadProfile(array $settings = []): array
    {
        if ($settings === []) {
            $settings = $this->loadSettingsMap(array_merge(array_keys(self::PROFILE_DEFAULTS), ['site_name', 'admin_email', 'site_url']));
        }

        $defaults = [
            'legal_profile_company_name' => (string)$this->getSetting('site_name', defined('SITE_NAME') ? (string)SITE_NAME : ''),
            'legal_profile_email'        => (string)$this->getSetting('admin_email', ''),
            'legal_profile_website'      => (string)$this->getSetting('site_url', defined('SITE_URL') ? (string)SITE_URL : ''),
        ];

        if (isset($settings['site_name'])) {
            $defaults['legal_profile_company_name'] = (string)$settings['site_name'];
        }
        if (isset($settings['admin_email'])) {
            $defaults['legal_profile_email'] = (string)$settings['admin_email'];
        }
        if (isset($settings['site_url'])) {
            $defaults['legal_profile_website'] = (string)$settings['site_url'];
        }

        return array_merge(self::PROFILE_DEFAULTS, $defaults, $settings);
    }

    private function sanitizeProfileValue(string $key, mixed $value): string
    {
        $booleanKeys = [
            'legal_profile_has_cookies',
            'legal_profile_has_contact_form',
            'legal_profile_has_registration',
            'legal_profile_has_comments',
            'legal_profile_has_newsletter',
            'legal_profile_has_analytics',
            'legal_profile_has_external_media',
            'legal_profile_has_webfonts',
            'legal_profile_has_shop',
            'legal_profile_minimal_privacy_mode',
            'legal_profile_analytics_self_hosted',
            'legal_profile_has_editorial_content',
        ];

        if (in_array($key, $booleanKeys, true)) {
            return !empty($value) ? '1' : '0';
        }

        if ($key === 'legal_profile_entity_type') {
            return in_array((string)$value, ['company', 'private'], true) ? (string)$value : 'company';
        }

        if ($key === 'legal_template_profile') {
            $profile = strtolower(trim((string)$value));
            return isset(self::TEMPLATE_PROFILES[$profile]) ? $profile : 'dach_de';
        }

        if ($key === 'legal_profile_terms_scope') {
            return in_array((string)$value, ['b2c', 'b2b', 'mixed'], true) ? (string)$value : 'b2c';
        }

        if ($key === 'legal_profile_contract_type') {
            return in_array((string)$value, ['goods', 'services', 'digital', 'mixed'], true) ? (string)$value : 'services';
        }

        if ($key === 'legal_profile_return_costs') {
            return in_array((string)$value, ['customer', 'merchant'], true) ? (string)$value : 'customer';
        }

        if ($key === 'legal_profile_webfonts_source') {
            return in_array((string)$value, ['local', 'external', 'mixed'], true) ? (string)$value : 'local';
        }

        if (in_array($key, ['legal_profile_email', 'legal_profile_privacy_contact_email'], true)) {
            return filter_var((string)$value, FILTER_VALIDATE_EMAIL) ?: '';
        }

        if ($key === 'legal_profile_phone') {
            $phone = preg_replace('/[^0-9+()\-\/.\s]/', '', (string)$value) ?? '';
            return trim($phone);
        }

        if ($key === 'legal_profile_website') {
            $website = trim((string)$value);
            if ($website === '') {
                return '';
            }

            $normalized = preg_match('#^https?://#i', $website) === 1 ? $website : 'https://' . ltrim($website, '/');
            $validated = filter_var($normalized, FILTER_VALIDATE_URL);

            return $validated !== false ? (string)$validated : '';
        }

        $normalizedValue = trim(strip_tags((string)$value));
        $maxLength = in_array($key, [
            'legal_profile_external_media_providers',
            'legal_profile_payment_providers',
            'legal_profile_hosting_address',
            'legal_profile_essential_cookie_purpose',
            'legal_profile_additional_service_purpose',
            'legal_profile_mstv_responsible_address',
        ], true) ? self::MAX_PROFILE_TEXTAREA_LENGTH : self::MAX_PROFILE_VALUE_LENGTH;

        return $this->truncate($normalizedValue, $maxLength);
    }

    /** @return list<string> */
    private function validateProfile(array $profile): array
    {
        $errors = [];
        $entityType = $this->profileValue($profile, 'legal_profile_entity_type', 'company');

        $requiredFields = [
            'legal_profile_street' => 'Straße / Hausnummer',
            'legal_profile_postal_code' => 'PLZ',
            'legal_profile_city' => 'Ort',
            'legal_profile_country' => 'Land',
            'legal_profile_email' => 'E-Mail',
        ];

        if ($entityType === 'company') {
            $requiredFields['legal_profile_company_name'] = 'Firma / Name';
            $requiredFields['legal_profile_legal_form'] = 'Rechtsform';
        } else {
            $requiredFields['legal_profile_owner_name'] = 'Vor- und Nachname';
        }

        foreach ($requiredFields as $key => $label) {
            if ($this->profileValue($profile, $key) === '') {
                $errors[] = $label . ' ist als Pflichtfeld erforderlich.';
            }
        }

        return $errors;
    }

    private function isPrivateProfile(array $profile): bool
    {
        return $this->profileValue($profile, 'legal_profile_entity_type', 'company') === 'private';
    }

    private function getProfileDisplayName(array $profile, string $fallback = 'Ihr Unternehmen'): string
    {
        if ($this->isPrivateProfile($profile)) {
            return $this->profileValue(
                $profile,
                'legal_profile_owner_name',
                $this->profileValue($profile, 'legal_profile_company_name', $fallback)
            );
        }

        return $this->profileValue(
            $profile,
            'legal_profile_company_name',
            $this->profileValue($profile, 'legal_profile_owner_name', $fallback)
        );
    }

    private function persistSetting(string $key, string $value, array &$existingSettings = []): void
    {
        if (array_key_exists($key, $existingSettings)) {
            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                [$value, $key]
            );
            $existingSettings[$key] = $value;
            return;
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
            [$key, $value]
        );
        $existingSettings[$key] = $value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $existingSettings = $this->loadSettingsMap([$key]);
        $this->persistSetting($key, $value, $existingSettings);
    }

    private function getSetting(string $key, string $fallback = ''): string
    {
        $value = $this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [$key]
        );

        return $value !== null ? (string)$value : $fallback;
    }

    private function buildImprintTemplate(array $profile): string
    {
        $templateProfile = $this->getTemplateProfileConfig($profile);
        $isPrivateProfile = $this->isPrivateProfile($profile);
        $companyName = $this->getProfileDisplayName($profile, 'Ihr Unternehmen');
        $legalForm = $this->profileValue($profile, 'legal_profile_legal_form');
        $owner = $this->profileValue($profile, 'legal_profile_owner_name');
        $managingDirector = $this->profileValue($profile, 'legal_profile_managing_director');
        $contentResponsible = $this->profileValue($profile, 'legal_profile_content_responsible', $owner !== '' ? $owner : $managingDirector);
        $email = $this->profileValue($profile, 'legal_profile_email');
        $phone = $this->profileValue($profile, 'legal_profile_phone');
        $website = $this->profileValue($profile, 'legal_profile_website');
        $registerCourt = $this->profileValue($profile, 'legal_profile_register_court');
        $registerNumber = $this->profileValue($profile, 'legal_profile_register_number');
        $vatId = $this->profileValue($profile, 'legal_profile_vat_id');

        $dutyLabel = match ($this->templateProfileKey($profile)) {
            'dach_at' => 'Angaben gemäß den anwendbaren österreichischen Offenlegungs- und Informationspflichten',
            'dach_ch' => 'Angaben gemäß den anwendbaren Schweizer Anbieter- und Transparenzpflichten',
            'dach_generic' => 'Angaben gemäß anwendbaren Anbieterkennzeichnungs- und Informationspflichten',
            default => 'Angaben gemäß § 5 DDG',
        };

        $html = '<h2>Impressum</h2>';
        $html .= $this->buildTemplateNotice($profile, 'Impressum');
        $html .= '<p>' . $this->escape($dutyLabel) . '</p>';
        $html .= '<p><em>DACH-Profil:</em> ' . $this->escape($templateProfile['label']) . ' – ' . $this->escape($templateProfile['jurisdiction_note']) . '</p>';
        $html .= '<p><strong>' . $this->escape($companyName) . '</strong>';
        if (!$isPrivateProfile && $legalForm !== '') {
            $html .= '<br>' . $this->escape($legalForm);
        }
        $html .= '<br>' . $this->nl2brEscaped($this->buildAddress($profile)) . '</p>';

        if (!$isPrivateProfile && ($owner !== '' || $managingDirector !== '')) {
            $html .= '<h3>Vertreten durch</h3><p>';
            if ($owner !== '') {
                $html .= '<strong>Inhaber:</strong> ' . $this->escape($owner);
            }
            if ($managingDirector !== '') {
                $html .= ($owner !== '' ? '<br>' : '') . '<strong>Geschäftsführung:</strong> ' . $this->escape($managingDirector);
            }
            $html .= '</p>';
        }

        $contactLines = [];
        if ($phone !== '') {
            $contactLines[] = '<strong>Telefon:</strong> ' . $this->escape($phone);
        }
        if ($email !== '') {
            $contactLines[] = '<strong>E-Mail:</strong> <a href="mailto:' . $this->escapeAttr($email) . '">' . $this->escape($email) . '</a>';
        }
        if ($website !== '') {
            $contactLines[] = '<strong>Website:</strong> <a href="' . $this->escapeAttr($website) . '" target="_blank" rel="noopener noreferrer">' . $this->escape($website) . '</a>';
        }
        if ($contactLines !== []) {
            $html .= '<h3>Kontakt</h3><p>' . implode('<br>', $contactLines) . '</p>';
        }

        if ($registerCourt !== '' || $registerNumber !== '') {
            $html .= '<h3>Registereintrag</h3><p>';
            if ($registerCourt !== '') {
                $html .= '<strong>Registergericht:</strong> ' . $this->escape($registerCourt);
            }
            if ($registerNumber !== '') {
                $html .= ($registerCourt !== '' ? '<br>' : '') . '<strong>Registernummer:</strong> ' . $this->escape($registerNumber);
            }
            $html .= '</p>';
        }

        if ($vatId !== '') {
            $html .= '<h3>Umsatzsteuer-ID</h3><p>Umsatzsteuer-Identifikationsnummer gemäß § 27a UStG: ' . $this->escape($vatId) . '</p>';
        }

        if ($contentResponsible !== '') {
            $html .= '<h3>Inhaltlich verantwortlich</h3><p>' . $this->escape($contentResponsible) . '</p>';
        }

        if ($this->profileValue($profile, 'legal_profile_has_editorial_content', '0') === '1') {
            $mstvName = $this->profileValue($profile, 'legal_profile_mstv_responsible_name', $contentResponsible);
            if ($mstvName !== '') {
                $mstvAddress = $this->profileValue($profile, 'legal_profile_mstv_responsible_address');
                $html .= '<h3>Verantwortlich gemäß § 18 Abs. 2 Medienstaatsvertrag (MStV)</h3>';
                $html .= '<p>Da dieses Angebot journalistisch-redaktionell gestaltete Inhalte bereithält (z. B. Magazin-, Blog- oder News-Beiträge), wird zusätzlich ein Verantwortlicher im Sinne des § 18 Abs. 2 MStV benannt:</p>';
                $html .= '<p><strong>' . $this->escape($mstvName) . '</strong><br>' . $this->nl2brEscaped($mstvAddress !== '' ? $mstvAddress : $this->buildAddress($profile)) . '</p>';
            }
        }

        $html .= '<h3>Haftung für Inhalte</h3><p>Die Inhalte dieser Website werden mit größtmöglicher Sorgfalt erstellt. Eine Gewähr für Vollständigkeit, Richtigkeit und Aktualität kann jedoch nicht in jedem Einzelfall übernommen werden. Bei Bekanntwerden konkreter Rechtsverletzungen werden betroffene Inhalte umgehend geprüft und gegebenenfalls entfernt.</p>';
        $html .= '<h3>Haftung für Links</h3><p>Diese Website kann Links zu externen Angeboten enthalten. Für Inhalte verlinkter Seiten sind ausschließlich deren jeweilige Betreiber verantwortlich. Zum Zeitpunkt der Verlinkung waren keine Rechtsverstöße erkennbar. Bei Bekanntwerden rechtswidriger Inhalte werden entsprechende Links entfernt.</p>';
        $html .= '<h3>Urheberrecht</h3><p>Texte, Bilder, Grafiken und sonstige Inhalte dieser Website unterliegen dem geltenden Urheberrecht. Eine Nutzung außerhalb der gesetzlichen Schranken bedarf der vorherigen Zustimmung des jeweiligen Rechteinhabers.</p>';

        return sanitize_html($html, 'default');
    }

    private function buildPrivacyTemplate(array $profile): string
    {
        $companyName = $this->getProfileDisplayName($profile, 'Ihr Unternehmen');
        $email = $this->profileValue($profile, 'legal_profile_email');
        $privacyContactName = $this->profileValue($profile, 'legal_profile_privacy_contact_name', $companyName);
        $privacyContactEmail = $this->profileValue($profile, 'legal_profile_privacy_contact_email', $email);
        $hostingProvider = $this->profileValue($profile, 'legal_profile_hosting_provider');
        $hostingAddress = $this->profileValue($profile, 'legal_profile_hosting_address');
        $analyticsName = $this->profileValue($profile, 'legal_profile_analytics_name');
        $analyticsSelfHosted = $this->profileValue($profile, 'legal_profile_analytics_self_hosted', '0') === '1';
        $newsletterProvider = $this->profileValue($profile, 'legal_profile_newsletter_provider');
        $externalMediaProviders = $this->profileValue($profile, 'legal_profile_external_media_providers');
        $webfontsSource = $this->profileValue($profile, 'legal_profile_webfonts_source', 'local');
        $webfontsProvider = $this->profileValue($profile, 'legal_profile_webfonts_provider');
        $paymentProviders = $this->profileValue($profile, 'legal_profile_payment_providers');
        $minimalPrivacyMode = $this->profileValue($profile, 'legal_profile_minimal_privacy_mode', '0') === '1';

        $html = '<h2>Datenschutzerklärung</h2>';
        $html .= $this->buildTemplateNotice($profile, 'Datenschutzerklärung');
        $html .= '<h3>1. Verantwortliche Stelle</h3><p>Verantwortlich für die Datenverarbeitung auf dieser Website ist:<br><strong>' . $this->escape($privacyContactName) . '</strong><br>' . $this->nl2brEscaped($this->buildAddress($profile));
        if ($privacyContactEmail !== '') {
            $html .= '<br>E-Mail: <a href="mailto:' . $this->escapeAttr($privacyContactEmail) . '">' . $this->escape($privacyContactEmail) . '</a>';
        }
        $html .= '</p>';

        $html .= '<h3>2. Allgemeine Hinweise</h3><p>Der Schutz personenbezogener Daten wird ernst genommen. Personenbezogene Daten werden nur im Rahmen der gesetzlichen Vorgaben sowie dieser Datenschutzerklärung verarbeitet.</p>';

        if ($hostingProvider !== '') {
            $html .= '<h3>3. Hosting</h3><p>Diese Website wird bei <strong>' . $this->escape($hostingProvider) . '</strong>';
            if ($hostingAddress !== '') {
                $html .= ' (' . $this->escape($hostingAddress) . ')';
            }
            $html .= ' gehostet. Server-Log-Dateien werden verarbeitet, um Stabilität, Sicherheit und den technischen Betrieb der Website sicherzustellen.</p>';
        } else {
            $html .= '<h3>3. Hosting und Server-Log-Dateien</h3><p>Beim Besuch dieser Website werden technisch erforderliche Informationen wie IP-Adresse, Zeitpunkt des Zugriffs, Browsertyp und angeforderte Ressource in Server-Log-Dateien verarbeitet. Die Verarbeitung erfolgt zur Gewährleistung von Sicherheit, Stabilität und Fehleranalyse.</p>';
        }

        $html .= '<h3>4. Kontaktaufnahme</h3><p>Wenn Sie per E-Mail';
        if ($profile['legal_profile_has_contact_form'] === '1') {
            $html .= ' oder Kontaktformular';
        }
        $html .= ' Kontakt aufnehmen, werden Ihre Angaben ausschließlich zur Bearbeitung Ihrer Anfrage verarbeitet.</p>';

        if ($minimalPrivacyMode) {
            $html .= $this->buildMinimalPrivacyModeSection($profile);
        } elseif ($profile['legal_profile_has_cookies'] === '1') {
            $html .= '<h3>5. Cookies und Einwilligungsverwaltung</h3><p>Diese Website verwendet technisch notwendige Cookies. Soweit optionale Dienste eingesetzt werden, erfolgt deren Aktivierung erst nach Ihrer Auswahl im Consent-Banner. Bereits erteilte Einwilligungen können jederzeit angepasst oder widerrufen werden.</p>';
        }

        if ($profile['legal_profile_has_registration'] === '1') {
            $html .= '<h3>6. Registrierung und Benutzerkonto</h3><p>Bei der Registrierung werden nur die für die Einrichtung und Nutzung des Benutzerkontos erforderlichen Daten verarbeitet. Pflichtangaben werden ausschließlich zur Bereitstellung des jeweiligen Dienstes genutzt.</p>';
        }

        if ($profile['legal_profile_has_newsletter'] === '1') {
            $html .= '<h3>7. Newsletter</h3><p>Wenn Sie sich für den Newsletter anmelden, wird Ihre E-Mail-Adresse zur Zustellung der gewünschten Informationen verarbeitet. Die Einwilligung kann jederzeit mit Wirkung für die Zukunft widerrufen werden.';
            if ($newsletterProvider !== '') {
                $html .= ' Für Versand und Verwaltung wird ' . $this->escape($newsletterProvider) . ' eingesetzt.';
            }
            $html .= '</p>';
        }

        if ($profile['legal_profile_has_comments'] === '1') {
            $html .= '<h3>8. Kommentare und Beiträge</h3><p>Beim Hinterlassen von Kommentaren können neben dem Inhalt auch technische Metadaten wie Zeitstempel und IP-Adresse verarbeitet werden, um die Kommentarfunktion bereitzustellen und Missbrauch zu verhindern.</p>';
        }

        if ($profile['legal_profile_has_analytics'] === '1') {
            $html .= '<h3>9. Analyse und Reichweitenmessung</h3><p>';
            if ($analyticsSelfHosted) {
                $toolName = $analyticsName !== '' ? $this->escape($analyticsName) : 'eine selbst gehostete Analyselösung';
                $html .= 'Auf dieser Website wird ' . $toolName . ' zur Reichweitenmessung eingesetzt. Die Analyselösung wird selbst gehostet, sodass die erhobenen Daten grundsätzlich auf dem eigenen Server verbleiben. Sofern die Messung ohne Marketing-Cookies und ohne Weitergabe an externe Dritte erfolgt, kann die Verarbeitung auf Grundlage berechtigter Interessen erfolgen; eine gesonderte Einwilligungsabfrage ist dann in der Regel nicht erforderlich.';
            } else {
                $html .= $analyticsName !== ''
                    ? 'Auf dieser Website wird ' . $this->escape($analyticsName) . ' zur Analyse von Nutzung und Reichweite eingesetzt. Eine Aktivierung erfolgt nur im Rahmen der jeweils gewählten Einwilligung.'
                    : 'Sofern Analyse- oder Trackingdienste eingesetzt werden, erfolgt deren Aktivierung ausschließlich nach entsprechender Einwilligung.';
            }
            $html .= '</p>';
        }

        if ($profile['legal_profile_has_external_media'] === '1' || $profile['legal_profile_has_webfonts'] === '1') {
            $html .= '<h3>10. Externe Inhalte und eingebundene Dienste</h3><p>';
            $parts = [];
            if ($profile['legal_profile_has_external_media'] === '1') {
                $parts[] = $externalMediaProviders !== ''
                    ? 'Externe Medieninhalte oder Drittanbieter-Ressourcen von ' . $this->escape($externalMediaProviders) . ' können erst nach Ihrer Interaktion geladen werden'
                    : 'Externe Medieninhalte oder Drittanbieter-Ressourcen können erst nach Ihrer Interaktion geladen werden';
            }
            if ($profile['legal_profile_has_webfonts'] === '1') {
                $parts[] = match ($webfontsSource) {
                    'external' => $webfontsProvider !== ''
                        ? 'Schriftarten werden über ' . $this->escape($webfontsProvider) . ' eingebunden'
                        : 'Schriftarten werden über einen externen Anbieter eingebunden',
                    'mixed' => $webfontsProvider !== ''
                        ? 'Schriftarten werden teils lokal und teils über ' . $this->escape($webfontsProvider) . ' eingebunden'
                        : 'Schriftarten werden teils lokal und teils extern eingebunden',
                    default => 'eingebundene Schriftarten werden datenschutzfreundlich und möglichst lokal bereitgestellt',
                };
            }
            $html .= implode('. ', $parts) . '.</p>';
        }

        if ($profile['legal_profile_has_shop'] === '1' || $paymentProviders !== '') {
            $html .= '<h3>11. Vertrags- und Zahlungsabwicklung</h3><p>Zur Bearbeitung von Bestellungen und zur Abwicklung von Zahlungen werden die hierfür erforderlichen Daten verarbeitet.';
            if ($paymentProviders !== '') {
                $html .= ' Eingesetzte Zahlungsdienstleister: ' . $this->escape($paymentProviders) . '.';
            }
            $html .= '</p>';
        }

        $html .= '<h3>12. Rechtsgrundlagen</h3><p>Die Verarbeitung personenbezogener Daten erfolgt je nach Sachverhalt auf Grundlage Ihrer Einwilligung, zur Vertragserfüllung, zur Erfüllung rechtlicher Verpflichtungen oder auf Grundlage berechtigter Interessen, sofern diese den Schutz Ihrer Rechte und Freiheiten nicht überwiegen.</p>';
        $html .= '<h3>13. Speicherdauer</h3><p>Personenbezogene Daten werden nur so lange gespeichert, wie dies für den jeweiligen Verarbeitungszweck erforderlich ist oder gesetzliche Aufbewahrungspflichten bestehen.</p>';
        $html .= '<h3>14. Ihre Rechte</h3><p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie Widerspruch gegen bestimmte Verarbeitungen. Bereits erteilte Einwilligungen können jederzeit mit Wirkung für die Zukunft widerrufen werden.</p>';
        $html .= '<h3>15. Beschwerderecht</h3><p>Wenn Sie der Auffassung sind, dass die Verarbeitung Ihrer personenbezogenen Daten rechtswidrig erfolgt, können Sie sich bei einer zuständigen Datenschutzaufsichtsbehörde beschweren.</p>';

        return sanitize_html($html, 'default');
    }

    private function buildMinimalPrivacyModeSection(array $profile): string
    {
        $cookieName = $this->profileValue($profile, 'legal_profile_essential_cookie_name', 'PHPSESSID');
        $cookiePurpose = $this->profileValue(
            $profile,
            'legal_profile_essential_cookie_purpose',
            'Speichert technisch notwendige Sitzungs- und Sicherheitseinstellungen.'
        );
        $serviceName = $this->profileValue($profile, 'legal_profile_additional_service_name');
        $serviceProvider = $this->profileValue($profile, 'legal_profile_additional_service_provider');
        $servicePurpose = $this->profileValue($profile, 'legal_profile_additional_service_purpose');
        $analyticsEnabled = $this->profileValue($profile, 'legal_profile_has_analytics', '0') === '1';
        $analyticsSelfHosted = $this->profileValue($profile, 'legal_profile_analytics_self_hosted', '0') === '1';
        $analyticsName = $this->profileValue($profile, 'legal_profile_analytics_name', 'eine selbst gehostete Analyselösung');

        $html = '<h3>5. Technisch notwendige Cookies und Verbindungen</h3>';
        $html .= '<p>Diese Website verwendet ein reduziertes technisches Setup ohne optionalen Cookie-Manager. Es wird ausschließlich ein technisch erforderliches Cookie gesetzt:</p>';
        $html .= '<p><strong>' . $this->escape($cookieName) . '</strong><br>' . $this->escape($cookiePurpose) . '</p>';
        $html .= '<p>Beim Aufruf der Website wird eine Verbindung zu unserem eigenen Webserver bzw. zum eingesetzten Hosting hergestellt. Diese Verbindung ist technisch erforderlich, um Inhalte auszuliefern, Sicherheit zu gewährleisten und Server-Logs bereitzustellen.</p>';

        if ($analyticsEnabled && $analyticsSelfHosted) {
            $html .= '<p>Zusätzlich wird ' . $this->escape($analyticsName) . ' lokal bzw. auf eigener Infrastruktur zur Reichweitenmessung betrieben. Die Verarbeitung erfolgt ohne Einbindung eines externen Analyseanbieters.</p>';
        }

        if ($serviceName !== '' || $serviceProvider !== '' || $servicePurpose !== '') {
            $label = $serviceName !== '' ? $serviceName : 'Zusätzlicher technischer Dienst';
            $providerSuffix = $serviceProvider !== '' ? ' (' . $this->escape($serviceProvider) . ')' : '';
            $purposeText = $servicePurpose !== ''
                ? $this->escape($servicePurpose)
                : 'Die Verbindung wird ausschließlich für einen technisch erforderlichen Zweck aufgebaut.';

            $html .= '<p>Zusätzlich wird eine weitere technisch erforderliche Verbindung zu <strong>' . $this->escape($label) . '</strong>' . $providerSuffix . ' aufgebaut. Zweck: ' . $purposeText . '</p>';
        } else {
            $html .= $analyticsEnabled && $analyticsSelfHosted
                ? '<p>Abgesehen von der lokal betriebenen Reichweitenmessung werden keine weiteren optionalen Tracking-, Marketing- oder Komfortdienste geladen.</p>'
                : '<p>Neben der Verbindung zum eigenen Server werden keine weiteren optionalen Tracking-, Marketing- oder Komfortdienste geladen.</p>';
        }

        $html .= '<p>Da nur technisch erforderliche Cookies und Verbindungen verwendet werden, ist für dieses reduzierte Setup grundsätzlich keine Auswahlmaske für optionale Kategorien erforderlich.</p>';

        return $html;
    }

    private function buildTermsTemplate(array $profile): string
    {
        $companyName = $this->getProfileDisplayName($profile, 'der Anbieter');
        $email = $this->profileValue($profile, 'legal_profile_email');
        $scope = $this->profileValue($profile, 'legal_profile_terms_scope', 'b2c');
        $contractType = $this->profileValue($profile, 'legal_profile_contract_type', 'services');
        $website = $this->profileValue($profile, 'legal_profile_website');

        $scopeText = match ($scope) {
            'b2b' => 'ausschließlich gegenüber Unternehmern im Sinne des § 14 BGB, juristischen Personen des öffentlichen Rechts oder öffentlich-rechtlichen Sondervermögen',
            'mixed' => 'gegenüber Verbrauchern im Sinne des § 13 BGB sowie gegenüber Unternehmern im Sinne des § 14 BGB',
            default => 'gegenüber Verbrauchern im Sinne des § 13 BGB',
        };

        $subjectText = match ($contractType) {
            'goods' => 'den Verkauf und die Lieferung von Waren',
            'digital' => 'die Bereitstellung digitaler Inhalte und digitaler Leistungen',
            'mixed' => 'den Verkauf von Waren sowie die Erbringung von Dienstleistungen und digitalen Leistungen',
            default => 'die Erbringung von Dienstleistungen und Werkleistungen',
        };

        $html = '<h2>Allgemeine Geschäftsbedingungen</h2>';
        $html .= $this->buildTemplateNotice($profile, 'AGB-Skelett');
        $html .= '<h3>§ 1 Geltungsbereich</h3><p>Diese Allgemeinen Geschäftsbedingungen gelten für alle Verträge zwischen ' . $this->escape($companyName) . ' und unseren Kunden ' . $this->escape($scopeText) . ', soweit nicht ausdrücklich und schriftlich etwas anderes vereinbart wurde.</p>';
        $html .= '<p>Sie regeln insbesondere ' . $this->escape($subjectText) . '. Individuelle Vereinbarungen mit dem Kunden haben stets Vorrang vor diesen AGB.</p>';

        $html .= '<h3>§ 2 Vertragspartner und Vertragsgegenstand</h3><p>Vertragspartner ist ' . $this->escape($companyName) . '. Gegenstand des Vertrages ist jeweils die im Angebot, auf der Produktseite, in der Buchungsübersicht oder in der individuellen Vereinbarung beschriebene Leistung.</p>';

        $html .= '<h3>§ 3 Vertragsschluss</h3><p>Die Präsentation unserer Leistungen';
        if ($website !== '') {
            $html .= ' auf der Website <a href="' . $this->escapeAttr($website) . '" target="_blank" rel="noopener noreferrer">' . $this->escape($website) . '</a>';
        }
        $html .= ' stellt kein rechtlich bindendes Angebot dar, sondern eine unverbindliche Aufforderung zur Abgabe einer Bestellung oder Anfrage.</p>';
        $html .= '<p>Ein Vertrag kommt erst zustande, wenn wir eine Bestellung, Buchung oder Anfrage ausdrücklich bestätigen, mit der Leistung beginnen oder die Ware versenden.</p>';

        $html .= '<h3>§ 4 Preise und Zahlungsbedingungen</h3><p>Alle Preise verstehen sich in Euro. Sofern nicht anders ausgewiesen, enthalten Preise gegenüber Verbrauchern die gesetzliche Umsatzsteuer. Gegenüber Unternehmern können Preise als Nettopreise zuzüglich gesetzlicher Umsatzsteuer ausgewiesen werden.</p>';
        $html .= '<p>Zahlungen sind sofort nach Vertragsschluss bzw. Rechnungsstellung ohne Abzug fällig, sofern keine abweichende Zahlungsfrist vereinbart wurde. Bei Zahlungsverzug gelten die gesetzlichen Vorschriften.</p>';

        $html .= '<h3>§ 5 Leistungserbringung, Lieferung und Verfügbarkeit</h3>';
        if ($contractType === 'goods' || $contractType === 'mixed') {
            $html .= '<p>Lieferzeiten werden individuell angegeben oder ergeben sich aus der jeweiligen Produktbeschreibung. Teillieferungen sind zulässig, soweit sie dem Kunden zumutbar sind.</p>';
        }
        if ($contractType === 'services' || $contractType === 'mixed') {
            $html .= '<p>Dienstleistungen und Werkleistungen werden zu den vereinbarten Terminen oder innerhalb des vereinbarten Leistungszeitraums erbracht. Voraussetzung ist, dass der Kunde die erforderlichen Mitwirkungshandlungen rechtzeitig erfüllt.</p>';
        }
        if ($contractType === 'digital') {
            $html .= '<p>Digitale Inhalte oder digitale Leistungen werden nach Vertragsschluss und nach Eingang einer vereinbarten Zahlung bereitgestellt, sofern keine abweichende Vereinbarung getroffen wurde.</p>';
        }
        $html .= '<p>Sollte eine Leistung aus Gründen, die wir nicht zu vertreten haben, nicht verfügbar sein, werden wir den Kunden hierüber unverzüglich informieren und bereits erhaltene Gegenleistungen erstatten.</p>';

        $html .= '<h3>§ 6 Eigentumsvorbehalt</h3>';
        if ($contractType === 'goods' || $contractType === 'mixed') {
            $html .= '<p>Bis zur vollständigen Bezahlung verbleiben gelieferte Waren in unserem Eigentum.</p>';
        } else {
            $html .= '<p>Soweit körperliche Unterlagen oder Datenträger geliefert werden, bleiben diese bis zur vollständigen Bezahlung unser Eigentum.</p>';
        }

        $html .= '<h3>§ 7 Gewährleistung und Mängelrechte</h3><p>Es gelten die gesetzlichen Mängelrechte. Gegenüber Unternehmern können die Gewährleistungsrechte im gesetzlich zulässigen Umfang eingeschränkt sein. Offensichtliche Mängel sind uns möglichst zeitnah mitzuteilen.</p>';

        if ($scope !== 'b2b') {
            $html .= '<h3>§ 8 Widerrufsrecht für Verbraucher</h3><p>Verbrauchern steht bei Fernabsatzverträgen grundsätzlich ein gesetzliches Widerrufsrecht zu. Die Einzelheiten ergeben sich aus der gesonderten Widerrufsbelehrung.</p>';
        } else {
            $html .= '<h3>§ 8 Kein gesetzliches Widerrufsrecht</h3><p>Ein gesetzliches Widerrufsrecht besteht für Unternehmer nicht, soweit nicht ausdrücklich etwas anderes vereinbart wurde.</p>';
        }

        $html .= '<h3>§ 9 Haftung</h3><p>Wir haften unbeschränkt für Vorsatz und grobe Fahrlässigkeit sowie bei Verletzung von Leben, Körper oder Gesundheit. Bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten ist die Haftung auf den vertragstypischen, vorhersehbaren Schaden begrenzt. Im Übrigen ist die Haftung ausgeschlossen, soweit gesetzlich zulässig.</p>';

        $html .= '<h3>§ 10 Schlussbestimmungen</h3><p>Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des UN-Kaufrechts, soweit keine zwingenden Verbraucherschutzvorschriften entgegenstehen.</p>';
        if ($scope === 'b2b') {
            $html .= '<p>Ist der Kunde Kaufmann, juristische Person des öffentlichen Rechts oder öffentlich-rechtliches Sondervermögen, ist der Sitz unseres Unternehmens Gerichtsstand für alle Streitigkeiten aus dem Vertragsverhältnis.</p>';
        }
        if ($email !== '') {
            $html .= '<p>Für Rückfragen erreichen Sie uns unter <a href="mailto:' . $this->escapeAttr($email) . '">' . $this->escape($email) . '</a>.</p>';
        }

        return sanitize_html($html, 'default');
    }

    private function buildRevocationTemplate(array $profile): string
    {
        $companyName = $this->getProfileDisplayName($profile, 'den Anbieter');
        $email = $this->profileValue($profile, 'legal_profile_email');
        $phone = $this->profileValue($profile, 'legal_profile_phone');
        $scope = $this->profileValue($profile, 'legal_profile_terms_scope', 'b2c');
        $contractType = $this->profileValue($profile, 'legal_profile_contract_type', 'services');
        $returnCosts = $this->profileValue($profile, 'legal_profile_return_costs', 'customer');
        $serviceStartNotice = $this->profileValue($profile, 'legal_profile_service_start_notice', '0') === '1';

        if ($scope === 'b2b') {
            $html = '<h2>Hinweis zum Widerrufsrecht</h2>';
            $html .= $this->buildTemplateNotice($profile, 'Widerrufs-Hinweis');
            $html .= '<p>Diese Angebote richten sich ausschließlich an Unternehmer. Für Unternehmer besteht kein gesetzliches Widerrufsrecht.</p>';
            return sanitize_html($html, 'default');
        }

        $contactDetails = '<strong>' . $this->escape($companyName) . '</strong><br>' . $this->nl2brEscaped($this->buildAddress($profile));
        if ($phone !== '') {
            $contactDetails .= '<br>Telefon: ' . $this->escape($phone);
        }
        if ($email !== '') {
            $contactDetails .= '<br>E-Mail: <a href="mailto:' . $this->escapeAttr($email) . '">' . $this->escape($email) . '</a>';
        }

        $periodText = match ($contractType) {
            'goods' => 'Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht Beförderer ist, die Ware in Besitz genommen haben.',
            'digital' => 'Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsabschlusses, sofern nicht das Widerrufsrecht bei digitalen Inhalten vorzeitig erlischt.',
            'mixed' => 'Die Widerrufsfrist beträgt bei Dienstleistungen vierzehn Tage ab dem Tag des Vertragsabschlusses und bei Warenlieferungen vierzehn Tage ab dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht Beförderer ist, die letzte Ware in Besitz genommen haben.',
            default => 'Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsabschlusses.',
        };

        $returnCostText = $returnCosts === 'merchant'
            ? 'Wir tragen die unmittelbaren Kosten der Rücksendung der Waren.'
            : 'Sie tragen die unmittelbaren Kosten der Rücksendung der Waren.';

        $html = '<h2>Widerrufsbelehrung</h2>';
        $html .= $this->buildTemplateNotice($profile, 'Widerrufsbelehrung');
        $html .= '<p>Verbrauchern steht bei außerhalb von Geschäftsräumen geschlossenen Verträgen und bei Fernabsatzverträgen grundsätzlich ein gesetzliches Widerrufsrecht zu. Verbraucher ist jede natürliche Person, die ein Rechtsgeschäft zu Zwecken abschließt, die überwiegend weder ihrer gewerblichen noch ihrer selbständigen beruflichen Tätigkeit zugerechnet werden können.</p>';
        $html .= '<h3>Widerrufsrecht</h3><p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.</p>';
        $html .= '<p>' . $this->escape($periodText) . '</p>';
        $html .= '<h3>Ausübung des Widerrufs</h3><p>Um Ihr Widerrufsrecht auszuüben, müssen Sie uns mittels einer eindeutigen Erklärung über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren. Unsere Kontaktdaten lauten:</p>';
        $html .= '<p>' . $contactDetails . '</p>';
        $html .= '<p>Sie können dafür das unten stehende Muster-Widerrufsformular verwenden, das jedoch nicht vorgeschrieben ist.';
        if ($email !== '') {
            $html .= ' Die Erklärung kann auch per E-Mail an <a href="mailto:' . $this->escapeAttr($email) . '">' . $this->escape($email) . '</a> erfolgen.';
        }
        $html .= ' Zur Wahrung der Widerrufsfrist reicht es aus, dass Sie die Mitteilung vor Ablauf der Widerrufsfrist absenden.</p>';

        $html .= '<h3>Folgen des Widerrufs</h3><p>Wenn Sie diesen Vertrag widerrufen, haben wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, einschließlich der Standard-Lieferkosten, unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag zurückzuzahlen, an dem Ihre Mitteilung über den Widerruf dieses Vertrags bei uns eingegangen ist. Für die Rückzahlung verwenden wir dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, es sei denn, mit Ihnen wurde ausdrücklich etwas anderes vereinbart. In keinem Fall werden Ihnen wegen dieser Rückzahlung Entgelte berechnet.</p>';

        if ($contractType === 'goods' || $contractType === 'mixed') {
            $html .= '<p>Wir können die Rückzahlung verweigern, bis wir die Waren wieder zurückerhalten haben oder bis Sie den Nachweis erbracht haben, dass Sie die Waren zurückgesandt haben, je nachdem, welcher Zeitpunkt der frühere ist.</p>';
            $html .= '<p>Sie haben die Waren unverzüglich und in jedem Fall spätestens binnen vierzehn Tagen ab dem Tag, an dem Sie uns über den Widerruf dieses Vertrags unterrichten, an uns zurückzusenden oder zu übergeben. Die Frist ist gewahrt, wenn Sie die Waren vor Ablauf der Frist absenden.</p>';
            $html .= '<p>' . $this->escape($returnCostText) . '</p>';
            $html .= '<p>Sie müssen für einen etwaigen Wertverlust der Waren nur aufkommen, wenn dieser Wertverlust auf einen zur Prüfung der Beschaffenheit, Eigenschaften und Funktionsweise der Waren nicht notwendigen Umgang mit ihnen zurückzuführen ist.</p>';
        }

        if ($contractType === 'services' || $contractType === 'mixed') {
            $html .= '<p>Haben Sie verlangt, dass die Dienstleistung bereits während der Widerrufsfrist beginnen soll, so haben Sie uns einen angemessenen Betrag zu zahlen, der dem Anteil der bis zum Zeitpunkt Ihres Widerrufs bereits erbrachten Leistungen im Vergleich zum Gesamtumfang der vertraglich vorgesehenen Leistungen entspricht.</p>';
        }

        if ($contractType === 'digital') {
            $html .= '<p>Bei Verträgen über digitale Inhalte kann das Widerrufsrecht vorzeitig erlöschen, wenn wir mit der Ausführung des Vertrags begonnen haben, nachdem Sie ausdrücklich zugestimmt haben, dass wir vor Ablauf der Widerrufsfrist mit der Ausführung beginnen, und Sie Ihre Kenntnis davon bestätigt haben, dass Sie dadurch Ihr Widerrufsrecht verlieren.</p>';
        }

        if ($serviceStartNotice && ($contractType === 'services' || $contractType === 'digital' || $contractType === 'mixed')) {
            $html .= '<h3>Hinweis zum vorzeitigen Beginn der Ausführung</h3><p>Wenn Sie ausdrücklich verlangen, dass wir vor Ablauf der Widerrufsfrist mit der Leistung beginnen, und die gesetzlichen Voraussetzungen vorliegen, kann Ihr Widerrufsrecht bei vollständiger Vertragserfüllung vorzeitig erlöschen.</p>';
        }

        $html .= '<h3>Muster-Widerrufsformular</h3>';
        $html .= '<p>(Wenn Sie den Vertrag widerrufen wollen, dann füllen Sie bitte dieses Formular aus und senden Sie es zurück.)</p>';
        $html .= '<p>An:<br>' . $contactDetails . '</p>';
        $html .= '<p>Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über '; 
        $html .= match ($contractType) {
            'goods' => 'den Kauf der folgenden Waren: ____________________',
            'digital' => 'die Bereitstellung der folgenden digitalen Inhalte: ____________________',
            'mixed' => 'den Kauf der folgenden Waren / die Erbringung der folgenden Dienstleistung: ____________________',
            default => 'die Erbringung der folgenden Dienstleistung: ____________________',
        };
        $html .= '<br>Bestellt am (*) / erhalten am (*): ____________________';
        $html .= '<br>Name des/der Verbraucher(s): ____________________';
        $html .= '<br>Anschrift des/der Verbraucher(s): ____________________';
        $html .= '<br>Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier): ____________________';
        $html .= '<br>Datum: ____________________';
        $html .= '<br>(*) Unzutreffendes streichen.</p>';

        return sanitize_html($html, 'default');
    }

    private function buildAddress(array $profile): string
    {
        $lines = [];
        $street = $this->profileValue($profile, 'legal_profile_street');
        $postalCode = $this->profileValue($profile, 'legal_profile_postal_code');
        $city = $this->profileValue($profile, 'legal_profile_city');
        $country = $this->profileValue($profile, 'legal_profile_country');

        if ($street !== '') {
            $lines[] = $street;
        }
        $cityLine = trim($postalCode . ' ' . $city);
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }
        if ($country !== '') {
            $lines[] = $country;
        }

        return implode("\n", $lines);
    }

    private function syncRelatedSettingsFromAssignments(array $post): void
    {
        $typeMap = [
            'privacy_page_id' => 'privacy',
            'terms_page_id' => 'terms',
            'revocation_page_id' => 'revocation',
        ];

        foreach ($typeMap as $field => $type) {
            if (!array_key_exists($field, $post)) {
                continue;
            }

            $pageId = (int)($post[$field] ?? 0);
            if ($pageId > 0) {
                $this->syncRelatedSettingsForType($type, $pageId);
            }
        }

        // Zuordnung entfernt oder geändert: Footer-Link der Barrierefreiheitserklärung nachziehen.
        if (array_key_exists('accessibility_page_id', $post)) {
            $this->syncAccessibilityFooter((int) ($post['accessibility_page_id'] ?? 0));
        }
    }

    private function syncRelatedSettingsForType(string $type, int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        switch ($type) {
            case 'privacy':
                $this->saveSetting('cookie_policy_url', $this->buildRelativePageUrl($pageId, '/datenschutz'));
                break;

            case 'terms':
                $this->saveSetting('terms_page_id', (string)$pageId);
                break;

            case 'revocation':
                $this->saveSetting('cancellation_page_id', (string)$pageId);
                break;

            case 'accessibility':
                $this->syncAccessibilityFooter($pageId);
                break;
        }
    }

    private function buildRelativePageUrl(int $pageId, string $fallback = '/'): string
    {
        $page = $this->pageManager->getPage($pageId);
        if ($page === null) {
            return $fallback;
        }

        $slug = trim((string)($page['slug'] ?? ''), '/');
        if ($slug === '') {
            return $fallback;
        }

        return '/' . $slug;
    }

    private function profileValue(array $profile, string $key, string $fallback = ''): string
    {
        $value = trim((string)($profile[$key] ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    /** @return array<string, array<string, string>> */
    private function getTemplateProfiles(): array
    {
        $profiles = [];
        foreach (self::TEMPLATE_PROFILES as $key => $profile) {
            $profiles[$key] = array_merge($profile, [
                'key' => $key,
                'version' => self::TEMPLATE_VERSION,
            ]);
        }

        return $profiles;
    }

    private function templateProfileKey(array $profile): string
    {
        $key = strtolower(trim((string)($profile['legal_template_profile'] ?? 'dach_de')));

        return isset(self::TEMPLATE_PROFILES[$key]) ? $key : 'dach_de';
    }

    /** @return array<string, string> */
    private function getTemplateProfileConfig(array $profile): array
    {
        $key = $this->templateProfileKey($profile);

        return self::TEMPLATE_PROFILES[$key] + ['key' => $key, 'version' => self::TEMPLATE_VERSION];
    }

    private function buildTemplateNotice(array $profile, string $templateLabel): string
    {
        $templateProfile = $this->getTemplateProfileConfig($profile);

        return '<p><strong>Technische Vorlage – bitte prüfen:</strong> Dieses ' . $this->escape($templateLabel) . ' wurde aus dem Profil <em>' . $this->escape($templateProfile['label']) . '</em> in Version ' . $this->escape(self::TEMPLATE_VERSION) . ' erzeugt. Es ist ein überprüfbares Grundgerüst mit Platzhaltern und projektspezifischen Bausteinen und ersetzt keine individuelle rechtliche Beratung.</p>';
    }

    /** @return list<string> */
    private function getTemplateMetadataKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::PAGE_CONFIG) as $type) {
            $keys[] = 'legal_' . $type . '_template_profile';
            $keys[] = 'legal_' . $type . '_template_version';
            $keys[] = 'legal_' . $type . '_template_applied_at';
        }

        return $keys;
    }

    /** @return array<string, array<string, string>> */
    private function getTemplateMetadata(array $settings): array
    {
        $metadata = [];
        foreach (array_keys(self::PAGE_CONFIG) as $type) {
            $metadata[$type] = [
                'profile' => (string)($settings['legal_' . $type . '_template_profile'] ?? ''),
                'version' => (string)($settings['legal_' . $type . '_template_version'] ?? ''),
                'applied_at' => (string)($settings['legal_' . $type . '_template_applied_at'] ?? ''),
            ];
        }

        return $metadata;
    }

    private function recordTemplateApplication(string $type, array $profile): void
    {
        if (!isset(self::PAGE_CONFIG[$type])) {
            return;
        }

        $existingSettings = $this->loadSettingsMap([
            'legal_' . $type . '_template_profile',
            'legal_' . $type . '_template_version',
            'legal_' . $type . '_template_applied_at',
        ]);

        $this->persistSetting('legal_' . $type . '_template_profile', $this->templateProfileKey($profile), $existingSettings);
        $this->persistSetting('legal_' . $type . '_template_version', self::TEMPLATE_VERSION, $existingSettings);
        $this->persistSetting('legal_' . $type . '_template_applied_at', date('c'), $existingSettings);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function nl2brEscaped(string $value): string
    {
        return nl2br($this->escape($value), false);
    }

    private function failResult(string $action, string $message, \Throwable $e): array
    {
        $sanitizedException = $this->sanitizeErrorContext($e->getMessage());

        $this->logger->warning($message, [
            'action' => $action,
            'exception' => $e::class,
            'message' => $sanitizedException,
        ]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $message,
            'legal_site',
            null,
            ['exception' => $e::class],
            'error'
        );

        return $this->buildLegalFailureResult(
            $message . ' Bitte Logs prüfen.',
            $action,
            $sanitizedException !== '' ? ['Ursache: ' . $sanitizedException] : [],
            ['exception' => $e::class, 'message' => $sanitizedException]
        );
    }

    private function buildLegalFailureResult(string $message, string $errorCode, array $details = [], array $errorData = []): array
    {
        $context = [
            'source' => '/admin/legal-sites',
            'title' => 'Legal Sites',
        ];

        $normalizedDetails = array_values(array_filter(array_map(
            static fn (mixed $detail): string => trim((string) $detail),
            $details
        ), static fn (string $detail): bool => $detail !== ''));

        return [
            'success' => false,
            'error' => $message,
            'details' => $normalizedDetails,
            'error_details' => [
                'code' => $errorCode,
                'data' => $errorData,
                'context' => $context,
            ],
            'report_payload' => ErrorReportService::buildReportPayloadFromWpError(
                new \CMS\WP_Error($errorCode, $message, $errorData),
                $context
            ),
        ];
    }

    private function canAccess(): bool
    {
        return class_exists(Auth::class)
            && Auth::instance()->isAdmin()
            && Auth::instance()->hasCapability('manage_settings');
    }

    /** @return list<string> */
    private function getAssignmentKeys(): array
    {
        return ['imprint_page_id', 'privacy_page_id', 'terms_page_id', 'revocation_page_id', 'accessibility_page_id'];
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function loadSettingsMap(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, static fn ($key): bool => is_string($key) && $key !== '')));
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ($placeholders)",
            $keys
        ) ?: [];

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row->option_name] = (string)$row->option_value;
        }

        return $settings;
    }

    /** @return array<int, true> */
    private function getPublishedPageIds(): array
    {
        $rows = $this->db->get_results(
            "SELECT id FROM {$this->prefix}pages WHERE status = 'published'"
        ) ?: [];

        $pageIds = [];
        foreach ($rows as $row) {
            $pageIds[(int)($row->id ?? 0)] = true;
        }

        unset($pageIds[0]);

        return $pageIds;
    }

    private function sanitizeLegalContent(mixed $value): string
    {
        $value = (string)$value;
        $value = $this->truncate($value, self::MAX_LEGAL_HTML_LENGTH);

        return sanitize_html(strip_tags($value, '<p><a><strong><em><ul><ol><li><br><h2><h3><h4>'), 'default');
    }

    private function sanitizeErrorContext(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return $this->truncate($value, self::MAX_ERROR_CONTEXT_LENGTH);
    }

    /** @param array<string, string> $incoming
     *  @param array<string, string> $existing
     *  @return list<string>
     */
    private function collectChangedSettingKeys(array $incoming, array $existing): array
    {
        $changedKeys = [];

        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $existing) || (string) $existing[$key] !== (string) $value) {
                $changedKeys[] = (string) $key;
            }
        }

        return $changedKeys;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || $value === '') {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}
