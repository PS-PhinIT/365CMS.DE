<?php
declare(strict_types=1);

/**
 * Member Dashboard Module – Konfiguration des Member-Bereichs
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Database;
use CMS\AuditLogger;
use CMS\Logger;
use CMS\Security;

class MemberDashboardModule
{
    private const CSRF_ACTION = 'admin_member_dashboard';
    private const READ_CAPABILITIES = ['manage_settings', 'manage_users'];
    private const SECTION_CAPABILITIES = [
        'general' => 'manage_settings',
        'widgets' => 'manage_settings',
        'profile-fields' => 'manage_users',
        'design' => 'manage_settings',
        'frontend-modules' => 'manage_settings',
        'notifications' => 'manage_settings',
        'onboarding' => 'manage_settings',
        'plugin-widgets' => 'manage_settings',
    ];
    private Database $db;
    private string $prefix;
    private Logger $logger;

    private const SETTINGS_KEYS = [
        'member_dashboard_enabled',
        'member_registration_enabled',
        'member_email_verification',
        'member_welcome_message',
        'member_default_role',
        'member_dashboard_widgets',
        'member_profile_fields',
        'member_required_profile_fields',
        'member_custom_profile_fields',
        'member_subscription_visible',
        'member_dashboard_columns',
        'member_dashboard_section_order',
        'member_dashboard_custom_widget_order',
        'member_dashboard_logo',
        'member_dashboard_greeting',
        'member_dashboard_welcome_text',
        'member_dashboard_footer_text',
        'member_dashboard_show_welcome',
        'member_widget_1_title',
        'member_widget_1_content',
        'member_widget_1_icon',
        'member_widget_2_title',
        'member_widget_2_content',
        'member_widget_2_icon',
        'member_widget_3_title',
        'member_widget_3_content',
        'member_widget_3_icon',
        'member_widget_4_title',
        'member_widget_4_content',
        'member_widget_4_icon',
        'member_dashboard_color_primary',
        'member_dashboard_color_accent',
        'member_dashboard_color_bg',
        'member_dashboard_color_card_bg',
        'member_dashboard_color_text',
        'member_dashboard_color_border',
        'member_dashboard_show_quickstart',
        'member_dashboard_show_stats',
        'member_dashboard_show_custom_widgets',
        'member_dashboard_show_plugin_widgets',
        'member_dashboard_show_notifications_panel',
        'member_dashboard_show_onboarding_panel',
        'member_dashboard_notification_center_enabled',
        'member_dashboard_notification_email_enabled',
        'member_dashboard_notification_digest_frequency',
        'member_dashboard_notification_sender_name',
        'member_dashboard_notification_empty_text',
        'member_dashboard_notification_types',
        'member_dashboard_onboarding_enabled',
        'member_dashboard_onboarding_title',
        'member_dashboard_onboarding_intro',
        'member_dashboard_onboarding_steps',
        'member_dashboard_onboarding_cta_label',
        'member_dashboard_onboarding_cta_url',
        'member_dashboard_onboarding_require_profile_completion',
        'member_dashboard_plugin_order',
    ];

    private const SECTION_ORDER_OPTIONS = [
        'stats,widgets,plugins' => 'Statistiken → Widgets → Plugins',
        'stats,plugins,widgets' => 'Statistiken → Plugins → Widgets',
        'widgets,stats,plugins' => 'Widgets → Statistiken → Plugins',
        'plugins,stats,widgets' => 'Plugins → Statistiken → Widgets',
        'quick_start,stats,widgets,plugins' => 'Schnellstart → Statistiken → Widgets → Plugins',
        'quick_start,stats,plugins,widgets' => 'Schnellstart → Statistiken → Plugins → Widgets',
    ];

    private const NOTIFICATION_TYPES = [
        'system' => 'Systemmeldungen',
        'messages' => 'Direktnachrichten',
        'billing' => 'Abo & Rechnungen',
        'security' => 'Sicherheitswarnungen',
        'community' => 'Community & Aktivitäten',
    ];

    private const DIGEST_FREQUENCIES = [
        'instant' => 'Sofort',
        'daily' => 'Täglich',
        'weekly' => 'Wöchentlich',
    ];

    private const DEFAULT_PROFILE_FIELDS = ['first_name', 'last_name', 'username', 'birth_date', 'email', 'website', 'social'];
    private const REQUIRED_PROFILE_FIELDS = ['username', 'email'];

    private const PROFILE_FIELDS = [
        'first_name' => [
            'label' => 'Vorname',
            'description' => 'Basisfeld für persönliche Ansprache.',
            'recommended' => true,
            'type' => 'text',
        ],
        'last_name' => [
            'label' => 'Nachname',
            'description' => 'Für vollständige Profile und Verzeichnisse sinnvoll.',
            'recommended' => true,
            'type' => 'text',
        ],
        'username' => [
            'label' => 'Benutzername',
            'description' => 'Eindeutiger Login- und Profilname. Immer Pflichtfeld.',
            'recommended' => true,
            'required' => true,
            'locked' => true,
            'type' => 'text',
        ],
        'birth_date' => [
            'label' => 'Geburtsdatum',
            'description' => 'Optionales Datumsfeld für Mitgliedsprofile.',
            'recommended' => false,
            'type' => 'date',
        ],
        'email' => [
            'label' => 'Mailadresse',
            'description' => 'Eindeutige Kontakt- und Login-Adresse. Immer Pflichtfeld.',
            'recommended' => true,
            'required' => true,
            'locked' => true,
            'type' => 'email',
        ],
        'website' => [
            'label' => 'Website',
            'description' => 'Externe Website oder Portfolio verlinken.',
            'recommended' => false,
            'type' => 'url',
        ],
        'social' => [
            'label' => 'Social-Media-Links',
            'description' => 'Zeigt zusätzliche Social-Profile im Member-Bereich.',
            'recommended' => false,
            'type' => 'url',
        ],
        'bio' => [
            'label' => 'Biografie',
            'description' => 'Kurzbeschreibung für Mitgliederprofil oder Netzwerkseiten. Nutzt den Block-Editor inkl. Formatierung.',
            'recommended' => false,
            'type' => 'wysiwyg',
        ],
        'phone' => [
            'label' => 'Telefon',
            'description' => 'Nur aktivieren, wenn Kontaktdaten im Portal sichtbar sein sollen.',
            'recommended' => false,
            'type' => 'text',
        ],
        'company' => [
            'label' => 'Firma',
            'description' => 'Hilfreich für Branchen-, Speaker- oder Expertenprofile.',
            'recommended' => false,
            'type' => 'text',
        ],
        'position' => [
            'label' => 'Position',
            'description' => 'Berufsbezeichnung oder Rolle im Unternehmen.',
            'recommended' => false,
            'type' => 'text',
        ],
        'location' => [
            'label' => 'Standort',
            'description' => 'Ort oder Region für Community- und Netzwerkfunktionen.',
            'recommended' => false,
            'type' => 'text',
        ],
        'avatar' => [
            'label' => 'Profilbild',
            'description' => 'Wichtig für persönliche Darstellung und Wiedererkennung.',
            'recommended' => false,
            'type' => 'url',
        ],
    ];

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->logger = Logger::instance()->withChannel('admin.member-dashboard');
    }

    /**
     * Einstellungen laden
     */
    public function getData(): array
    {
        if (!$this->canRead()) {
            return $this->emptyData();
        }

        $settingsMap = $this->loadSettingsMap();
        $settings = $this->getSettings($settingsMap);
        $stats    = $this->getMemberStats();
        $widgets  = $this->getAvailableWidgets();
        $profileFields = $this->getProfileFieldDefinitions($settings['custom_profile_fields'] ?? []);
        $pluginWidgets = $this->getPluginWidgets($settingsMap);

        return [
            'settings'  => $settings,
            'stats'     => $stats,
            'widgets'   => $widgets,
            'profileFields' => $profileFields,
            'roles'     => $this->getAvailableRoles(),
            'sectionOrderOptions' => self::SECTION_ORDER_OPTIONS,
            'notificationTypes' => self::NOTIFICATION_TYPES,
            'digestFrequencies' => self::DIGEST_FREQUENCIES,
            'pluginWidgets' => $pluginWidgets,
            'overview'  => [
                'enabledWidgets'      => count($settings['widgets'] ?? []),
                'enabledProfileFields'=> count($settings['profile_fields'] ?? []),
                'customWidgetCount'   => count(array_filter($settings['custom_widgets'] ?? [], static function (array $widget): bool {
                    return trim((string)($widget['title'] ?? '')) !== '' || trim((string)($widget['content'] ?? '')) !== '';
                })),
                'registrationEnabled' => !empty($settings['registration_enabled']),
                'verificationEnabled' => !empty($settings['email_verification']),
                'subscriptionVisible' => !empty($settings['subscription_visible']),
                'pluginWidgetCount'   => count($pluginWidgets),
            ],
            'dashboardPreview' => $this->buildDashboardPreviewData($settings, $settingsMap, $pluginWidgets),
        ];
    }

    /**
     * Frontend-/Runtime-Einstellungen für den Member-Bereich laden.
     *
     * Dieser Pfad darf nicht an Admin-Read-Capabilities hängen, da die
     * gespeicherten Member-Dashboard-Settings auch für normale Mitglieder im
     * öffentlichen `/member/...`-Bereich benötigt werden.
     *
     * @return array<string, mixed>
     */
    public function getRuntimeSettings(): array
    {
        return $this->getSettings($this->loadSettingsMap());
    }

    public function getSectionData(string $section): array
    {
        if (!$this->canRead()) {
            return $this->emptySectionData($section);
        }

        $settingsMap = $this->loadSettingsMap();
        $settings = $this->getSettings($settingsMap);
        $pluginWidgets = in_array($section, ['overview', 'plugin-widgets'], true)
            ? $this->getPluginWidgets($settingsMap)
            : [];

        return match ($section) {
            'overview' => [
                'settings' => $settings,
                'stats' => $this->getMemberStats(),
                'widgets' => $this->getAvailableWidgets(),
                'profileFields' => $this->getProfileFieldDefinitions($settings['custom_profile_fields'] ?? []),
                'pluginWidgets' => $pluginWidgets,
                'overview' => $this->buildOverviewData($settings, $settingsMap, $pluginWidgets),
                'dashboardPreview' => $this->buildDashboardPreviewData($settings, $settingsMap, $pluginWidgets),
            ],
            'general', 'design', 'frontend-modules' => [
                'settings' => $settings,
            ],
            'onboarding' => [
                'settings' => $settings,
                'onboardingAnalytics' => $this->buildOnboardingAnalytics($settings),
            ],
            'widgets' => [
                'settings' => $settings,
                'widgets' => $this->getAvailableWidgets(),
                'sectionOrderOptions' => self::SECTION_ORDER_OPTIONS,
            ],
            'profile-fields' => [
                'settings' => $settings,
                'profileFields' => $this->getProfileFieldDefinitions($settings['custom_profile_fields'] ?? []),
                'profileFieldCompatibility' => $this->buildProfileFieldCompatibility($settings),
            ],
            'notifications' => [
                'settings' => $settings,
                'notificationTypes' => self::NOTIFICATION_TYPES,
                'digestFrequencies' => self::DIGEST_FREQUENCIES,
            ],
            'plugin-widgets' => [
                'settings' => $settings,
                'pluginWidgets' => $pluginWidgets,
            ],
            default => $this->getData(),
        };
    }

    /**
     * Einstellungen speichern
     */
    public function saveSettings(array $post): array
    {
        $section = (string)($post['settings_section'] ?? 'general');

        return $this->saveSection($section, $post);
    }

    public function saveSection(string $section, array $post): array
    {
        $section = $this->normalizeSection($section);
        if ($section === null) {
            return ['success' => false, 'error' => 'Unbekannter Einstellungsbereich.'];
        }

        if (!$this->assertCsrf($post)) {
            return ['success' => false, 'error' => 'Sicherheitstoken ungültig.'];
        }

        if (!$this->canWriteSection($section)) {
            return ['success' => false, 'error' => 'Sie dürfen diesen Einstellungsbereich nicht bearbeiten.'];
        }

        $result = match ($section) {
            'general'        => $this->saveGeneralSettings($post),
            'widgets'        => $this->saveWidgetSettings($post),
            'profile-fields' => $this->saveProfileSettings($post),
            'design'         => $this->saveDesignSettings($post),
            'frontend-modules' => $this->saveFrontendModules($post),
            'notifications'  => $this->saveNotificationSettings($post),
            'onboarding'     => $this->saveOnboardingSettings($post),
            'plugin-widgets' => $this->savePluginWidgetSettings($post),
            default          => ['success' => false, 'error' => 'Unbekannter Einstellungsbereich.'],
        };

        if (!empty($result['success'])) {
            $this->auditSectionSave($section);
        }

        return $result;
    }

    private function saveGeneralSettings(array $post): array
    {
        try {
            $values = [
                'member_dashboard_enabled'    => !empty($post['dashboard_enabled']) ? '1' : '0',
                'member_welcome_message'      => strip_tags($post['welcome_message'] ?? '', '<p><a><strong><em><br>'),
                'member_dashboard_greeting'   => $this->sanitizeTextSetting((string)($post['dashboard_greeting'] ?? 'Guten Tag, {name}!'), 120),
                'member_dashboard_welcome_text' => strip_tags((string)($post['dashboard_welcome_text'] ?? ''), '<p><a><strong><em><br><ul><ol><li>'),
                'member_dashboard_show_welcome' => !empty($post['show_welcome']) ? '1' : '0',
                'member_dashboard_logo'       => $this->normalizeAssetReference((string)($post['dashboard_logo'] ?? '')),
                'member_dashboard_footer_text' => $this->sanitizeTextSetting((string)($post['dashboard_footer_text'] ?? ''), 200),
            ];

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Allgemeine Member-Dashboard-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.general.save_failed', 'Allgemeine Member-Dashboard-Einstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    private function saveWidgetSettings(array $post): array
    {
        try {
            $availableWidgets = array_keys($this->getAvailableWidgets());
            $widgetOrder = $this->normalizeOrderedValues(
                explode(',', (string)($post['widget_order'] ?? '')),
                array_map('strval', $availableWidgets)
            );

            $selectedWidgetLookup = array_map('strval', array_keys(array_filter($post['widgets'] ?? [])));
            $selectedWidgets = array_values(array_filter(
                $widgetOrder,
                static fn(string $widgetKey): bool => in_array($widgetKey, $selectedWidgetLookup, true)
            ));

            $customWidgetOrder = $this->normalizeOrderedValues(
                explode(',', (string)($post['custom_widget_order'] ?? '')),
                $this->getDefaultCustomWidgetOrder()
            );

            $columns = (int)($post['dashboard_columns'] ?? 3);
            if ($columns < 1 || $columns > 4) {
                $columns = 3;
            }

            $sectionOrder = (string)($post['section_order'] ?? 'stats,widgets,plugins');
            if (!isset(self::SECTION_ORDER_OPTIONS[$sectionOrder])) {
                $sectionOrder = 'stats,widgets,plugins';
            }

            $values = [
                'member_dashboard_widgets'      => json_encode($selectedWidgets, JSON_UNESCAPED_UNICODE),
                'member_dashboard_columns'      => (string)$columns,
                'member_dashboard_section_order'=> $sectionOrder,
                'member_dashboard_custom_widget_order' => json_encode($customWidgetOrder, JSON_UNESCAPED_UNICODE),
            ];

            for ($i = 1; $i <= 4; $i++) {
                $values["member_widget_{$i}_title"] = $this->sanitizeTextSetting((string)($post['custom_widgets'][$i]['title'] ?? ''), 80);
                $values["member_widget_{$i}_icon"] = $this->sanitizeTextSetting((string)($post['custom_widgets'][$i]['icon'] ?? ''), 16);
                $values["member_widget_{$i}_content"] = strip_tags((string)($post['custom_widgets'][$i]['content'] ?? ''), '<p><a><strong><em><br><ul><ol><li>');
            }

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Widget- und Layout-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.widgets.save_failed', 'Widget- und Layout-Einstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    private function saveProfileSettings(array $post): array
    {
        try {
            $customProfileFields = $this->normalizeCustomProfileFields($post['custom_profile_fields'] ?? []);
            $profileFieldDefinitions = $this->getProfileFieldDefinitions($customProfileFields);
            $selectedFields = array_values(array_intersect(
                array_keys($profileFieldDefinitions),
                array_keys(array_filter($post['profile_fields'] ?? []))
            ));
            foreach ($customProfileFields as $customField) {
                if (!empty($customField['enabled'])) {
                    $selectedFields[] = (string)$customField['key'];
                }
            }
            $selectedFields = array_values(array_unique(array_merge($selectedFields, self::REQUIRED_PROFILE_FIELDS)));

            $requiredFields = array_values(array_intersect(
                $selectedFields,
                array_keys(array_filter($post['required_profile_fields'] ?? []))
            ));
            foreach ($customProfileFields as $customField) {
                if (!empty($customField['required']) && in_array((string)$customField['key'], $selectedFields, true)) {
                    $requiredFields[] = (string)$customField['key'];
                }
            }
            $requiredFields = array_values(array_unique(array_merge($requiredFields, self::REQUIRED_PROFILE_FIELDS)));

            $values = [
                'member_profile_fields'       => json_encode($selectedFields, JSON_UNESCAPED_UNICODE),
                'member_required_profile_fields' => json_encode($requiredFields, JSON_UNESCAPED_UNICODE),
                'member_custom_profile_fields' => json_encode($customProfileFields, JSON_UNESCAPED_UNICODE),
                'member_subscription_visible' => !empty($post['subscription_visible']) ? '1' : '0',
            ];

            $retriggerOnboarding = !empty($post['profile_fields_retrigger_onboarding']);
            if ($retriggerOnboarding) {
                $values['member_dashboard_onboarding_enabled'] = '1';
                $values['member_dashboard_show_onboarding_panel'] = '1';
                $values['member_dashboard_onboarding_require_profile_completion'] = '1';
            }

            $this->persistSettings($values);

            return [
                'success' => true,
                'message' => $retriggerOnboarding
                    ? 'Profil-Felder gespeichert und Onboarding-Hinweis für unvollständige Profile aktiviert.'
                    : 'Profil-Felder und Navigationssichtbarkeit gespeichert.',
            ];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.profile.save_failed', 'Profil-Felder konnten nicht gespeichert werden.', $e);
        }
    }

    private function saveDesignSettings(array $post): array
    {
        try {
            $values = [
                'member_dashboard_color_primary' => $this->sanitizeColor((string)($post['color_primary'] ?? '#6366f1'), '#6366f1'),
                'member_dashboard_color_accent' => $this->sanitizeColor((string)($post['color_accent'] ?? '#8b5cf6'), '#8b5cf6'),
                'member_dashboard_color_bg' => $this->sanitizeColor((string)($post['color_bg'] ?? '#f1f5f9'), '#f1f5f9'),
                'member_dashboard_color_card_bg' => $this->sanitizeColor((string)($post['color_card_bg'] ?? '#ffffff'), '#ffffff'),
                'member_dashboard_color_text' => $this->sanitizeColor((string)($post['color_text'] ?? '#1e293b'), '#1e293b'),
                'member_dashboard_color_border' => $this->sanitizeColor((string)($post['color_border'] ?? '#e2e8f0'), '#e2e8f0'),
            ];

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Design- und Farbvorgaben gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.design.save_failed', 'Design- und Farbvorgaben konnten nicht gespeichert werden.', $e);
        }
    }

    private function saveFrontendModules(array $post): array
    {
        try {
            $values = [
                'member_dashboard_show_quickstart' => !empty($post['show_quickstart']) ? '1' : '0',
                'member_dashboard_show_stats' => !empty($post['show_stats']) ? '1' : '0',
                'member_dashboard_show_custom_widgets' => !empty($post['show_custom_widgets']) ? '1' : '0',
                'member_dashboard_show_plugin_widgets' => !empty($post['show_plugin_widgets']) ? '1' : '0',
                'member_dashboard_show_notifications_panel' => !empty($post['show_notifications_panel']) ? '1' : '0',
                'member_dashboard_show_onboarding_panel' => !empty($post['show_onboarding_panel']) ? '1' : '0',
            ];

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Frontend-Module gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.frontend_modules.save_failed', 'Frontend-Module konnten nicht gespeichert werden.', $e);
        }
    }

    private function saveNotificationSettings(array $post): array
    {
        try {
            $selectedTypes = array_values(array_intersect(
                array_keys(self::NOTIFICATION_TYPES),
                array_keys(array_filter($post['notification_types'] ?? []))
            ));

            $frequency = (string)($post['notification_digest_frequency'] ?? 'daily');
            if (!isset(self::DIGEST_FREQUENCIES[$frequency])) {
                $frequency = 'daily';
            }

            $values = [
                'member_dashboard_notification_center_enabled' => !empty($post['notification_center_enabled']) ? '1' : '0',
                'member_dashboard_notification_email_enabled' => !empty($post['notification_email_enabled']) ? '1' : '0',
                'member_dashboard_notification_digest_frequency' => $frequency,
                'member_dashboard_notification_sender_name' => $this->sanitizeTextSetting((string)($post['notification_sender_name'] ?? '365CMS Member Hub'), 120),
                'member_dashboard_notification_empty_text' => $this->sanitizeTextSetting((string)($post['notification_empty_text'] ?? 'Aktuell gibt es keine neuen Meldungen.'), 255),
                'member_dashboard_notification_types' => json_encode($selectedTypes, JSON_UNESCAPED_UNICODE),
            ];

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Benachrichtigungseinstellungen gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.notifications.save_failed', 'Benachrichtigungseinstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    private function saveOnboardingSettings(array $post): array
    {
        try {
            $steps = preg_split('/\r\n|\r|\n/', (string)($post['onboarding_steps'] ?? '')) ?: [];
            $steps = array_values(array_filter(array_map(fn(string $step): string => $this->sanitizeTextSetting($step, 160), $steps)));

            $values = [
                'member_dashboard_onboarding_enabled' => !empty($post['onboarding_enabled']) ? '1' : '0',
                'member_dashboard_onboarding_title' => $this->sanitizeTextSetting((string)($post['onboarding_title'] ?? 'Dein nächster Schritt'), 120),
                'member_dashboard_onboarding_intro' => trim(strip_tags((string)($post['onboarding_intro'] ?? ''))),
                'member_dashboard_onboarding_steps' => json_encode($steps, JSON_UNESCAPED_UNICODE),
                'member_dashboard_onboarding_cta_label' => $this->sanitizeTextSetting((string)($post['onboarding_cta_label'] ?? 'Profil vervollständigen'), 80),
                'member_dashboard_onboarding_cta_url' => $this->normalizeActionUrl((string)($post['onboarding_cta_url'] ?? '/member/profile'), '/member/profile'),
                'member_dashboard_onboarding_require_profile_completion' => !empty($post['onboarding_require_profile_completion']) ? '1' : '0',
            ];

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Onboarding-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.onboarding.save_failed', 'Onboarding-Einstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    private function savePluginWidgetSettings(array $post): array
    {
        try {
            $pluginWidgets = $this->getPluginWidgets();
            $allowedPlugins = array_map(static fn(array $widget): string => (string)$widget['plugin'], $pluginWidgets);

            $order = array_values(array_filter(array_map(
                static fn(string $value): string => trim($value),
                explode(',', (string)($post['plugin_widget_order'] ?? ''))
            ), static fn(string $value): bool => in_array($value, $allowedPlugins, true)));

            $values = [
                'member_dashboard_plugin_order' => json_encode($order, JSON_UNESCAPED_UNICODE),
            ];

            foreach ($pluginWidgets as $widget) {
                $pluginSlug = (string) ($widget['plugin'] ?? '');
                if ($pluginSlug === '') {
                    continue;
                }

                if (!empty($widget['supports_frontend_widget'])) {
                    $values['member_dashboard_plugin_' . $pluginSlug] = !empty($post['plugin_visible'][$pluginSlug]) ? '1' : '0';
                }

                $meta = [
                    'title' => $this->sanitizeTextSetting((string) ($post['plugin_meta'][$pluginSlug]['title'] ?? ($widget['label'] ?? '')), 120),
                    'description' => $this->sanitizeTextSetting((string) ($post['plugin_meta'][$pluginSlug]['description'] ?? ($widget['description'] ?? '')), 255),
                    'icon' => $this->sanitizeTextSetting((string) ($post['plugin_meta'][$pluginSlug]['icon'] ?? ($widget['icon'] ?? '🔌')), 16),
                    'color' => $this->sanitizeColor((string) ($post['plugin_meta'][$pluginSlug]['color'] ?? ($widget['color'] ?? '#4f46e5')), (string) ($widget['color'] ?? '#4f46e5')),
                ];

                $values['member_dashboard_widget_meta_' . $pluginSlug] = json_encode($meta, JSON_UNESCAPED_UNICODE);
            }

            $this->persistSettings($values);

            return ['success' => true, 'message' => 'Plugin-Widgets gespeichert.'];
        } catch (\Throwable $e) {
			return $this->failResult('member.dashboard.plugin_widgets.save_failed', 'Plugin-Widgets konnten nicht gespeichert werden.', $e);
        }
    }

    /**
     * Einstellungen laden
     */
    private function getSettings(array $settings = []): array
    {
        if ($settings === []) {
            $settings = $this->loadSettingsMap();
        }

        $customProfileFields = $this->normalizeCustomProfileFields(
            \CMS\Json::decodeArray($settings['member_custom_profile_fields'] ?? null, [])
        );
        $profileFieldDefinitions = $this->getProfileFieldDefinitions($customProfileFields);
        $selectedProfileFields = array_values(array_intersect(
            array_keys($profileFieldDefinitions),
            array_map('strval', \CMS\Json::decodeArray($settings['member_profile_fields'] ?? null, self::DEFAULT_PROFILE_FIELDS))
        ));
        if ($selectedProfileFields === []) {
            $selectedProfileFields = self::DEFAULT_PROFILE_FIELDS;
        }
        $selectedProfileFields = array_values(array_unique(array_merge($selectedProfileFields, self::REQUIRED_PROFILE_FIELDS)));
        $requiredProfileFields = array_values(array_intersect(
            $selectedProfileFields,
            array_map('strval', \CMS\Json::decodeArray($settings['member_required_profile_fields'] ?? null, self::REQUIRED_PROFILE_FIELDS))
        ));
        $requiredProfileFields = array_values(array_unique(array_merge($requiredProfileFields, self::REQUIRED_PROFILE_FIELDS)));

        return [
            'dashboard_enabled'    => ($settings['member_dashboard_enabled'] ?? '1') === '1',
            'registration_enabled' => ($settings['member_registration_enabled'] ?? '1') === '1',
            'email_verification'   => ($settings['member_email_verification'] ?? '0') === '1',
            'welcome_message'      => $settings['member_welcome_message'] ?? '',
            'default_role'         => $settings['member_default_role'] ?? 'member',
            // Nie gespeichert = alle Kern-Widgets sichtbar (Stand vor 4.0.0); danach gilt die gespeicherte Allowlist.
            'widgets'              => array_key_exists('member_dashboard_widgets', $settings)
                ? array_values(array_intersect(
                    array_map('strval', \CMS\Json::decodeArray($settings['member_dashboard_widgets'], [])),
                    array_keys($this->getAvailableWidgets())
                ))
                : array_keys($this->getAvailableWidgets()),
            'profile_fields'       => $selectedProfileFields,
            'required_profile_fields' => $requiredProfileFields,
            'custom_profile_fields' => $customProfileFields,
            'profile_field_definitions' => $profileFieldDefinitions,
            'dashboard_columns'    => max(1, min(4, (int)($settings['member_dashboard_columns'] ?? 3))),
            'section_order'        => isset(self::SECTION_ORDER_OPTIONS[(string)($settings['member_dashboard_section_order'] ?? '')])
                ? (string)$settings['member_dashboard_section_order']
                : 'quick_start,stats,widgets,plugins',
            'custom_widget_order'  => $this->normalizeOrderedValues(
                \CMS\Json::decodeArray($settings['member_dashboard_custom_widget_order'] ?? null, $this->getDefaultCustomWidgetOrder()),
                $this->getDefaultCustomWidgetOrder()
            ),
            'dashboard_logo'       => $settings['member_dashboard_logo'] ?? '',
            'dashboard_greeting'   => $settings['member_dashboard_greeting'] ?? 'Guten Tag, {name}!',
            'dashboard_welcome_text' => $settings['member_dashboard_welcome_text'] ?? '',
            'dashboard_footer_text' => $settings['member_dashboard_footer_text'] ?? '',
            'show_welcome'         => ($settings['member_dashboard_show_welcome'] ?? '1') === '1',
            'subscription_visible' => ($settings['member_subscription_visible'] ?? '0') === '1',
            'custom_widgets'       => $this->mapCustomWidgets($settings),
            'design'               => [
                'primary' => $settings['member_dashboard_color_primary'] ?? '#6366f1',
                'accent' => $settings['member_dashboard_color_accent'] ?? '#8b5cf6',
                'bg' => $settings['member_dashboard_color_bg'] ?? '#f1f5f9',
                'card_bg' => $settings['member_dashboard_color_card_bg'] ?? '#ffffff',
                'text' => $settings['member_dashboard_color_text'] ?? '#1e293b',
                'border' => $settings['member_dashboard_color_border'] ?? '#e2e8f0',
            ],
            'frontend_modules'     => [
                'show_quickstart' => ($settings['member_dashboard_show_quickstart'] ?? '1') === '1',
                'show_stats' => ($settings['member_dashboard_show_stats'] ?? '1') === '1',
                'show_custom_widgets' => ($settings['member_dashboard_show_custom_widgets'] ?? '1') === '1',
                'show_plugin_widgets' => ($settings['member_dashboard_show_plugin_widgets'] ?? '1') === '1',
                'show_notifications_panel' => ($settings['member_dashboard_show_notifications_panel'] ?? '1') === '1',
                'show_onboarding_panel' => ($settings['member_dashboard_show_onboarding_panel'] ?? '1') === '1',
            ],
            'notifications'        => [
                'center_enabled' => ($settings['member_dashboard_notification_center_enabled'] ?? '1') === '1',
                'email_enabled' => ($settings['member_dashboard_notification_email_enabled'] ?? '0') === '1',
                'digest_frequency' => $settings['member_dashboard_notification_digest_frequency'] ?? 'daily',
                'sender_name' => $settings['member_dashboard_notification_sender_name'] ?? '365CMS Member Hub',
                'empty_text' => $settings['member_dashboard_notification_empty_text'] ?? 'Aktuell gibt es keine neuen Meldungen.',
                'types' => \CMS\Json::decodeArray($settings['member_dashboard_notification_types'] ?? null, ['system', 'messages']),
            ],
            'onboarding'           => [
                'enabled' => ($settings['member_dashboard_onboarding_enabled'] ?? '1') === '1',
                'title' => $settings['member_dashboard_onboarding_title'] ?? 'So startest du optimal',
                'intro' => $settings['member_dashboard_onboarding_intro'] ?? 'Begleite neue Mitglieder mit einer klaren Checkliste und gezielten nächsten Schritten.',
                'steps' => \CMS\Json::decodeArray($settings['member_dashboard_onboarding_steps'] ?? null, [
                    'Profil vervollständigen',
                    'Profilbild hochladen',
                    'Passwort & Sicherheit prüfen',
                    'Erste Bereiche im Member-Dashboard entdecken',
                ]),
                'cta_label' => $settings['member_dashboard_onboarding_cta_label'] ?? 'Jetzt starten',
                'cta_url' => $settings['member_dashboard_onboarding_cta_url'] ?? '/member/profile',
                'require_profile_completion' => ($settings['member_dashboard_onboarding_require_profile_completion'] ?? '0') === '1',
            ],
            'plugin_widget_order'  => \CMS\Json::decodeArray($settings['member_dashboard_plugin_order'] ?? null, []),
        ];
    }

    /**
     * Member-Statistiken
     */
    private function getMemberStats(): array
    {
        try {
            $row = $this->db->get_row(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week
                 FROM {$this->prefix}users"
            );

            return [
                'total'    => (int)($row->total ?? 0),
                'active'   => (int)($row->active ?? 0),
                'thisWeek' => (int)($row->this_week ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['total' => 0, 'active' => 0, 'thisWeek' => 0];
        }
    }

    /**
     * Verfügbare Dashboard-Widgets
     */
    private function getAvailableWidgets(): array
    {
        return [
            'profile' => [
                'label'       => 'Profil-Übersicht',
                'description' => 'Profil-Fortschritt, Kontostatus und Sicherheitsstatus (2FA, letzte Passwortänderung).',
                'recommended' => true,
            ],
            'activity' => [
                'label'       => 'Letzte Aktivitäten',
                'description' => 'Die jüngsten Einträge aus dem Aktivitätsprotokoll des Mitglieds.',
                'recommended' => true,
            ],
            'messages' => [
                'label'       => 'Nachrichten',
                'description' => 'Anzahl ungelesener Nachrichten mit Link zum Posteingang.',
                'recommended' => false,
            ],
            'bookmarks' => [
                'label'       => 'Lesezeichen',
                'description' => 'Die zuletzt gemerkten Beiträge (Favoriten) des Mitglieds.',
                'recommended' => false,
            ],
            'notifications' => [
                'label'       => 'Benachrichtigungen',
                'description' => 'Die letzten Benachrichtigungen mit Link zur Benachrichtigungsseite.',
                'recommended' => true,
            ],
            'quick_links' => [
                'label'       => 'Schnellzugriffe',
                'description' => 'Bereich „Schnellzugriff“ mit Links zu Profil, Sicherheit und Dateien.',
                'recommended' => true,
            ],
            'statistics' => [
                'label'       => 'Statistiken',
                'description' => 'Kennzahlen-Karten: Logins, Sicherheitswert, ungelesene Nachrichten, aktive Sitzungen.',
                'recommended' => false,
            ],
        ];
    }

    private function getProfileFieldDefinitions(array $customProfileFields = []): array
    {
        $definitions = self::PROFILE_FIELDS;

        foreach ($this->normalizeCustomProfileFields($customProfileFields) as $field) {
            $key = (string)($field['key'] ?? '');
            if ($key === '' || isset($definitions[$key])) {
                continue;
            }

            $definitions[$key] = [
                'label' => (string)($field['label'] ?? $key),
                'description' => (string)($field['description'] ?? 'Zusätzliches Profilfeld.'),
                'recommended' => false,
                'type' => (string)($field['type'] ?? 'text'),
                'required' => !empty($field['required']),
                'custom' => true,
            ];
        }

        return $definitions;
    }

    /**
     * @param mixed $rawFields
    * @return array<int,array{key:string,label:string,type:string,required:bool,enabled:bool,description:string}>
     */
    private function normalizeCustomProfileFields(mixed $rawFields): array
    {
        $rows = is_array($rawFields) ? $rawFields : [];
        $fields = [];
        $usedKeys = array_fill_keys(array_keys(self::PROFILE_FIELDS), true);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = $this->sanitizeTextSetting((string)($row['label'] ?? ''), 80);
            $rawKey = strtolower(trim((string)($row['key'] ?? '')));
            if ($rawKey === '' && $label !== '') {
                $rawKey = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
            }

            $key = strtolower((string)preg_replace('/[^a-z0-9_]/', '_', $rawKey));
            $key = trim((string)preg_replace('/_+/', '_', $key), '_');
            if ($key === '' || $label === '') {
                continue;
            }
            if (!str_starts_with($key, 'custom_')) {
                $key = 'custom_' . $key;
            }
            $key = substr($key, 0, 64);
            if (isset($usedKeys[$key])) {
                continue;
            }

            $type = (string)($row['type'] ?? 'text');
            if (!in_array($type, ['text', 'textarea', 'url', 'date'], true)) {
                $type = 'text';
            }

            $usedKeys[$key] = true;
            $fields[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($row['required']),
                'enabled' => !empty($row['enabled']),
                'description' => $this->sanitizeTextSetting((string)($row['description'] ?? 'Zusätzliches Profilfeld.'), 160),
            ];
        }

        return $fields;
    }

    /**
     * Baut eine read-only Kompatibilitätsvorschau für Profilfeld-Änderungen.
     *
     * Die Analyse bleibt bewusst begrenzt: Counts laufen aggregiert über aktive Konten,
     * Beispielnutzer werden limitiert ausgegeben. Fehler in optionalen Meta-Tabellen
     * dürfen die Admin-Seite nicht blockieren.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function buildProfileFieldCompatibility(array $settings): array
    {
        $profileFieldDefinitions = $this->getProfileFieldDefinitions($settings['custom_profile_fields'] ?? []);
        $selectedFields = array_values(array_intersect(
            array_keys($profileFieldDefinitions),
            array_map('strval', (array)($settings['profile_fields'] ?? []))
        ));

        $result = [
            'available' => true,
            'active_user_count' => 0,
            'current_fields' => $selectedFields,
            'current_incomplete_count' => 0,
            'current_incomplete_samples' => [],
            'onboarding_require_profile_completion' => !empty($settings['onboarding']['require_profile_completion']),
            'onboarding_enabled' => !empty($settings['onboarding']['enabled']),
            'onboarding_panel_visible' => !empty($settings['frontend_modules']['show_onboarding_panel']),
            'fields' => [],
        ];

        foreach ($profileFieldDefinitions as $fieldKey => $definition) {
            $result['fields'][$fieldKey] = [
                'key' => $fieldKey,
                'label' => (string)($definition['label'] ?? $fieldKey),
                'missing_count' => 0,
                'missing_samples' => [],
                'currently_selected' => in_array($fieldKey, $selectedFields, true),
            ];
        }

        try {
            $result['active_user_count'] = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}users WHERE status = 'active'"
            );

            if ($result['active_user_count'] < 1) {
                return $result;
            }

            foreach (array_keys($profileFieldDefinitions) as $fieldKey) {
                $fieldSummary = $this->getProfileFieldMissingSummary((string)$fieldKey);
                $result['fields'][$fieldKey]['missing_count'] = $fieldSummary['missing_count'];
                $result['fields'][$fieldKey]['missing_samples'] = $fieldSummary['missing_samples'];
            }

            if ($selectedFields !== []) {
                $currentSummary = $this->getProfileSelectionIncompleteSummary($selectedFields);
                $result['current_incomplete_count'] = $currentSummary['incomplete_count'];
                $result['current_incomplete_samples'] = $currentSummary['incomplete_samples'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Profilfeld-Kompatibilitätsvorschau konnte nicht vollständig geladen werden.', [
                'action' => 'member.dashboard.profile.compatibility_failed',
                'exception_class' => $e::class,
            ]);

            $result['available'] = false;
            $result['message'] = 'Die Kompatibilitätsvorschau konnte nicht vollständig geladen werden. Die Profilfeld-Speicherung bleibt verfügbar.';
        }

        return $result;
    }

    /** @return array{missing_count:int,missing_samples:array<int,array{id:int,label:string,email:string}>} */
    private function getProfileFieldMissingSummary(string $fieldKey): array
    {
        $fieldKey = $this->normalizeProfileFieldKey($fieldKey);
        if ($fieldKey === '') {
            return ['missing_count' => 0, 'missing_samples' => []];
        }

        try {
            if (in_array($fieldKey, ['username', 'email'], true)) {
                $missingCount = (int)$this->db->get_var(
                    "SELECT COUNT(*)
                       FROM {$this->prefix}users u
                      WHERE u.status = 'active'
                        AND (u.{$fieldKey} IS NULL OR TRIM(u.{$fieldKey}) = '')"
                );

                return [
                    'missing_count' => $missingCount,
                    'missing_samples' => $this->getProfileFieldMissingSamples([$fieldKey], 8),
                ];
            }

            $missingCount = (int)$this->db->get_var(
                "SELECT COUNT(*)
                   FROM {$this->prefix}users u
                   LEFT JOIN {$this->prefix}user_meta um
                          ON um.user_id = u.id AND um.meta_key = ?
                  WHERE u.status = 'active'
                    AND (um.meta_value IS NULL OR TRIM(um.meta_value) = '')",
                [$fieldKey]
            );

            return [
                'missing_count' => $missingCount,
                'missing_samples' => $this->getProfileFieldMissingSamples([$fieldKey], 8),
            ];
        } catch (\Throwable) {
            return ['missing_count' => 0, 'missing_samples' => []];
        }
    }

    /** @param array<int,string> $selectedFields */
    private function getProfileSelectionIncompleteSummary(array $selectedFields): array
    {
        $selectedFields = array_values(array_filter(array_map([$this, 'normalizeProfileFieldKey'], $selectedFields)));
        if ($selectedFields === []) {
            return ['incomplete_count' => 0, 'incomplete_samples' => []];
        }

        $missingClauses = [];
        $params = [];
        foreach ($selectedFields as $fieldKey) {
                        if (in_array($fieldKey, ['username', 'email'], true)) {
                                $missingClauses[] = "(u.{$fieldKey} IS NULL OR TRIM(u.{$fieldKey}) = '')";
                                continue;
                        }

                        $missingClauses[] = "NOT EXISTS (
                                SELECT 1
                                    FROM {$this->prefix}user_meta um
                                 WHERE um.user_id = u.id
                                     AND um.meta_key = ?
                                     AND TRIM(um.meta_value) <> ''
                        )";
                        $params[] = $fieldKey;
        }

        try {
            $incompleteCount = (int)$this->db->get_var(
                "SELECT COUNT(*)
                   FROM {$this->prefix}users u
                  WHERE u.status = 'active'
                    AND (" . implode(' OR ', $missingClauses) . ')',
                $params
            );

            return [
                'incomplete_count' => $incompleteCount,
                'incomplete_samples' => $this->getProfileFieldMissingSamples($selectedFields, 8),
            ];
        } catch (\Throwable) {
            return ['incomplete_count' => 0, 'incomplete_samples' => []];
        }
    }

    /**
     * @param array<int,string> $fieldKeys
     * @return array<int,array{id:int,label:string,email:string}>
     */
    private function getProfileFieldMissingSamples(array $fieldKeys, int $limit): array
    {
        $fieldKeys = array_values(array_filter(array_map([$this, 'normalizeProfileFieldKey'], $fieldKeys)));
        if ($fieldKeys === []) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $missingClauses = [];
        $params = [];
        foreach ($fieldKeys as $fieldKey) {
                        if (in_array($fieldKey, ['username', 'email'], true)) {
                                $missingClauses[] = "(u.{$fieldKey} IS NULL OR TRIM(u.{$fieldKey}) = '')";
                                continue;
                        }

                        $missingClauses[] = "NOT EXISTS (
                                SELECT 1
                                    FROM {$this->prefix}user_meta um
                                 WHERE um.user_id = u.id
                                     AND um.meta_key = ?
                                     AND TRIM(um.meta_value) <> ''
                        )";
                        $params[] = $fieldKey;
        }

        try {
            $rows = $this->db->get_results(
                "SELECT u.id, u.username, u.display_name, u.email
                   FROM {$this->prefix}users u
                  WHERE u.status = 'active'
                    AND (" . implode(' OR ', $missingClauses) . ")
                  ORDER BY u.username ASC
                  LIMIT {$limit}",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $samples = [];
        foreach ($rows as $row) {
            $label = $this->sanitizeTextSetting((string)($row->display_name ?? ''), 120);
            if ($label === '') {
                $label = $this->sanitizeTextSetting((string)($row->username ?? ''), 120);
            }

            $samples[] = [
                'id' => (int)($row->id ?? 0),
                'label' => $label !== '' ? $label : 'Benutzer #' . (int)($row->id ?? 0),
                'email' => $this->sanitizeTextSetting((string)($row->email ?? ''), 190),
            ];
        }

        return $samples;
    }

    private function normalizeProfileFieldKey(string $fieldKey): string
    {
        $fieldKey = trim($fieldKey);

        $settings = $this->loadSettingsMap();
        $customFields = \CMS\Json::decodeArray($settings['member_custom_profile_fields'] ?? null, []);

        return isset($this->getProfileFieldDefinitions($customFields)[$fieldKey]) ? $fieldKey : '';
    }

    private function getAvailableRoles(): array
    {
        $roles = ['member', 'author'];

        try {
            $dbRoles = $this->db->get_results("SELECT DISTINCT role FROM {$this->prefix}users ORDER BY role ASC") ?: [];
            foreach ($dbRoles as $row) {
                $role = $this->sanitizeRole((string)($row->role ?? ''));
                if ($role !== '' && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        } catch (\Throwable $e) {
        }

        return $roles;
    }

    private function getPluginWidgets(array $settings = []): array
    {
        if (!class_exists('\\CMS\\Member\\PluginDashboardRegistry')) {
            return [];
        }

        try {
            $registry = \CMS\Member\PluginDashboardRegistry::instance();
            $registry->init();
            $metaMap = $this->getPluginWidgetMetaMap($settings);

            $widgets = [];
            $seenPlugins = [];
            foreach ($registry->getAll() as $section) {
                if (!empty($section['parent_slug'])) {
                    continue;
                }

                $config = is_array($section['dashboard_widget'] ?? null) ? $section['dashboard_widget'] : [];
                $plugin = (string)($section['plugin'] ?? $section['slug'] ?? '');
                if ($plugin === '') {
                    continue;
                }
                if (isset($seenPlugins[$plugin])) {
                    continue;
                }
                $seenPlugins[$plugin] = true;

                $meta = $metaMap[$plugin] ?? [];
                $supportsFrontendWidget = ($section['dashboard_widget'] ?? null) !== false;

                $widgets[] = [
                    'plugin' => $this->sanitizeTextSetting($plugin, 120),
                    'slug' => $this->sanitizeTextSetting((string)($section['slug'] ?? $plugin), 120),
                    'label' => $this->sanitizeTextSetting((string)($meta['title'] ?? $config['title'] ?? $section['label'] ?? $plugin), 120),
                    'description' => $this->sanitizeTextSetting((string)($meta['description'] ?? $config['description'] ?? ''), 255),
                    'icon' => $this->sanitizeTextSetting((string)($meta['icon'] ?? $config['icon'] ?? $section['icon'] ?? '🔌'), 16),
                    'color' => $this->sanitizeColor((string)($meta['color'] ?? $config['color'] ?? '#4f46e5'), '#4f46e5'),
                    'priority' => (int)($section['priority'] ?? 50),
                    'supports_frontend_widget' => $supportsFrontendWidget,
                    'admin_note' => !$supportsFrontendWidget
                        ? 'Theme-/Plugin-spezifischer Bereich ohne generische 365CMS-Dashboard-Kachel.'
                        : '',
                ];
            }

            return $widgets;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function persistSettings(array $values): void
    {
        $existingSettings = $this->loadSettingsMap(array_keys($values));

        foreach ($values as $key => $value) {
            $this->upsertSetting((string)$key, (string)$value, array_key_exists((string)$key, $existingSettings));
        }
    }

    private function upsertSetting(string $key, string $value, bool $exists = false): void
    {
        if ($exists) {
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

    private function mapCustomWidgets(array $settings): array
    {
        $widgets = [];

        for ($i = 1; $i <= 4; $i++) {
            $widgets[$i] = [
                'title'   => (string)($settings["member_widget_{$i}_title"] ?? ''),
                'content' => (string)($settings["member_widget_{$i}_content"] ?? ''),
                'icon'    => (string)($settings["member_widget_{$i}_icon"] ?? ''),
            ];
        }

        return $widgets;
    }

    /**
     * @return array<int,string>
     */
    private function getDefaultCustomWidgetOrder(): array
    {
        return ['1', '2', '3', '4'];
    }

    /**
     * @param array<int|string,mixed> $requestedValues
     * @param array<int,string> $allowedValues
     * @return array<int,string>
     */
    private function normalizeOrderedValues(array $requestedValues, array $allowedValues): array
    {
        $allowedLookup = [];
        foreach ($allowedValues as $allowedValue) {
            $allowedLookup[(string)$allowedValue] = true;
        }

        $normalized = [];
        $seen = [];

        foreach ($requestedValues as $requestedValue) {
            $requestedValue = trim((string)$requestedValue);
            if ($requestedValue === '' || !isset($allowedLookup[$requestedValue]) || isset($seen[$requestedValue])) {
                continue;
            }

            $seen[$requestedValue] = true;
            $normalized[] = $requestedValue;
        }

        foreach ($allowedValues as $allowedValue) {
            $allowedValue = (string)$allowedValue;
            if (isset($seen[$allowedValue])) {
                continue;
            }

            $seen[$allowedValue] = true;
            $normalized[] = $allowedValue;
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $pluginWidgets
     * @param array<int|string,mixed> $storedOrder
     * @return array<int,array<string,mixed>>
     */
    private function sortPluginWidgetsByStoredOrder(array $pluginWidgets, array $storedOrder): array
    {
        if ($pluginWidgets === []) {
            return [];
        }

        $allowedPlugins = [];
        foreach ($pluginWidgets as $widget) {
            $pluginSlug = trim((string)($widget['plugin'] ?? ''));
            if ($pluginSlug !== '') {
                $allowedPlugins[] = $pluginSlug;
            }
        }

        $normalizedOrder = $this->normalizeOrderedValues($storedOrder, $allowedPlugins);
        $positions = array_flip($normalizedOrder);

        usort($pluginWidgets, static function (array $left, array $right) use ($positions): int {
            $leftKey = (string)($left['plugin'] ?? '');
            $rightKey = (string)($right['plugin'] ?? '');
            $leftPosition = isset($positions[$leftKey]) ? (int)$positions[$leftKey] : 999;
            $rightPosition = isset($positions[$rightKey]) ? (int)$positions[$rightKey] : 999;

            if ($leftPosition === $rightPosition) {
                return strcmp($leftKey, $rightKey);
            }

            return $leftPosition <=> $rightPosition;
        });

        return $pluginWidgets;
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, array<string, string>>
     */
    private function getPluginWidgetMetaMap(array $settings = []): array
    {
        if ($settings === []) {
            $settings = $this->loadSettingsMap();
        }

        $meta = [];

        foreach ($settings as $key => $value) {
            if (!str_starts_with((string)$key, 'member_dashboard_widget_meta_')) {
                continue;
            }

            $pluginSlug = substr((string)$key, strlen('member_dashboard_widget_meta_'));
            if (!is_string($pluginSlug) || $pluginSlug === '') {
                continue;
            }

            $decoded = \CMS\Json::decodeArray((string)$value, []);
            if (is_array($decoded)) {
                $meta[$pluginSlug] = $decoded;
            }
        }

        return $meta;
    }

    /**
     * @param list<string>|null $keys
     * @return array<string, string>
     */
    private function loadSettingsMap(?array $keys = null): array
    {
        try {
            if (is_array($keys) && $keys !== []) {
                $keys = array_values(array_filter(array_map('strval', $keys), static fn(string $key): bool => $key !== ''));
                if ($keys === []) {
                    return [];
                }

                $placeholders = implode(',', array_fill(0, count($keys), '?'));
                $rows = $this->db->get_results(
                    "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
                    $keys
                ) ?: [];
            } else {
                $rows = $this->db->get_results(
                    "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name LIKE 'member_%'"
                ) ?: [];
            }

            $settings = [];
            foreach ($rows as $row) {
                $optionName = (string)($row->option_name ?? '');
                if ($optionName === '') {
                    continue;
                }

                $settings[$optionName] = (string)($row->option_value ?? '');
            }

            return $settings;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canRead(): bool
    {
        if (!class_exists(Auth::class) || !Auth::isAdmin()) {
            return false;
        }

        return $this->hasAnyCapability(self::READ_CAPABILITIES);
    }

    private function canWriteSection(string $section): bool
    {
        if (!class_exists(Auth::class) || !Auth::isAdmin()) {
            return false;
        }

        $capability = self::SECTION_CAPABILITIES[$section] ?? 'manage_settings';

        return Auth::instance()->hasCapability($capability);
    }

    private function hasAnyCapability(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (Auth::instance()->hasCapability((string)$capability)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSection(string $section): ?string
    {
        $section = trim($section);

        return array_key_exists($section, self::SECTION_CAPABILITIES) ? $section : null;
    }

    private function assertCsrf(array $post): bool
    {
        if (!empty($post['_csrf_verified'])) {
            return true;
        }

        $token = (string)($post['csrf_token'] ?? '');

        return class_exists(Security::class)
            && Security::instance()->verifyToken($token, self::CSRF_ACTION);
    }

    private function auditSectionSave(string $section): void
    {
        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'member.dashboard.' . str_replace('-', '_', $section) . '.saved',
            'Member-Dashboard-Einstellungen gespeichert.',
            'member_dashboard',
            null,
            [
                'section' => $section,
                'capability' => self::SECTION_CAPABILITIES[$section] ?? 'manage_settings',
            ],
            'info'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyData(): array
    {
        return [
            'settings' => [],
            'stats' => ['total' => 0, 'active' => 0, 'thisWeek' => 0],
            'widgets' => [],
            'profileFields' => [],
            'roles' => [],
            'sectionOrderOptions' => self::SECTION_ORDER_OPTIONS,
            'notificationTypes' => self::NOTIFICATION_TYPES,
            'digestFrequencies' => self::DIGEST_FREQUENCIES,
            'pluginWidgets' => [],
            'overview' => [
                'enabledWidgets' => 0,
                'enabledProfileFields' => 0,
                'customWidgetCount' => 0,
                'registrationEnabled' => false,
                'verificationEnabled' => false,
                'subscriptionVisible' => false,
                'pluginWidgetCount' => 0,
            ],
            'dashboardPreview' => $this->emptyDashboardPreviewData(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDashboardPreviewData(): array
    {
        return [
            'dashboard_enabled' => false,
            'show_welcome' => false,
            'greeting' => 'Guten Tag, {name}!',
            'welcome_text' => '',
            'design' => [],
            'frontend_modules' => [
                'show_quickstart' => false,
                'show_stats' => false,
                'show_custom_widgets' => false,
                'show_plugin_widgets' => false,
                'show_notifications_panel' => false,
                'show_onboarding_panel' => false,
            ],
            'section_order' => ['stats', 'widgets', 'plugins'],
            'core_widgets' => [],
            'custom_widgets' => [],
            'plugin_widgets' => [],
            'profile_fields' => [],
            'notifications' => [
                'center_enabled' => false,
                'empty_text' => 'Aktuell gibt es keine neuen Meldungen.',
            ],
            'onboarding' => [
                'enabled' => false,
                'title' => 'So startest du optimal',
                'intro' => '',
                'steps' => [],
                'cta_label' => 'Jetzt starten',
                'cta_url' => '/member/profile',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySectionData(string $section): array
    {
        return match ($section) {
            'overview' => [
                'settings' => [],
                'stats' => ['total' => 0, 'active' => 0, 'thisWeek' => 0],
                'widgets' => [],
                'profileFields' => [],
                'pluginWidgets' => [],
                'overview' => [
                    'enabledWidgets' => 0,
                    'enabledProfileFields' => 0,
                    'customWidgetCount' => 0,
                    'registrationEnabled' => false,
                    'verificationEnabled' => false,
                    'subscriptionVisible' => false,
                    'pluginWidgetCount' => 0,
                ],
                'dashboardPreview' => $this->emptyDashboardPreviewData(),
            ],
            'general', 'design', 'frontend-modules' => [
                'settings' => [],
            ],
            'onboarding' => [
                'settings' => [],
                'onboardingAnalytics' => $this->emptyOnboardingAnalytics(),
            ],
            'widgets' => [
                'settings' => [],
                'widgets' => [],
                'sectionOrderOptions' => self::SECTION_ORDER_OPTIONS,
            ],
            'profile-fields' => [
                'settings' => [],
                'profileFields' => [],
            ],
            'notifications' => [
                'settings' => [],
                'notificationTypes' => self::NOTIFICATION_TYPES,
                'digestFrequencies' => self::DIGEST_FREQUENCIES,
            ],
            'plugin-widgets' => [
                'settings' => [],
                'pluginWidgets' => [],
            ],
            default => $this->emptyData(),
        };
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $settingsMap
     * @return array<string, mixed>
     */
    private function buildOverviewData(array $settings, array $settingsMap, ?array $pluginWidgets = null): array
    {
        $pluginWidgets ??= $this->getPluginWidgets($settingsMap);

        return [
            'enabledWidgets' => count($settings['widgets'] ?? []),
            'enabledProfileFields' => count($settings['profile_fields'] ?? []),
            'customWidgetCount' => count(array_filter($settings['custom_widgets'] ?? [], static function (array $widget): bool {
                return trim((string)($widget['title'] ?? '')) !== '' || trim((string)($widget['content'] ?? '')) !== '';
            })),
            'registrationEnabled' => !empty($settings['registration_enabled']),
            'verificationEnabled' => !empty($settings['email_verification']),
            'subscriptionVisible' => !empty($settings['subscription_visible']),
            'pluginWidgetCount' => count($pluginWidgets),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $settingsMap
     * @return array<string, mixed>
     */
    private function buildDashboardPreviewData(array $settings, array $settingsMap, ?array $pluginWidgets = null): array
    {
        $frontendModules = is_array($settings['frontend_modules'] ?? null) ? $settings['frontend_modules'] : [];
        $availableWidgets = $this->getAvailableWidgets();
        $profileFieldDefinitions = $this->getProfileFieldDefinitions($settings['custom_profile_fields'] ?? []);
        $pluginWidgets ??= $this->getPluginWidgets($settingsMap);
        $pluginWidgets = $this->sortPluginWidgetsByStoredOrder($pluginWidgets, (array)($settings['plugin_widget_order'] ?? []));
        $customWidgetOrder = $this->normalizeOrderedValues(
            (array)($settings['custom_widget_order'] ?? []),
            $this->getDefaultCustomWidgetOrder()
        );

        $enabledCoreWidgets = [];
        foreach ((array)($settings['widgets'] ?? []) as $widgetKey) {
            $widgetKey = (string)$widgetKey;
            if (!isset($availableWidgets[$widgetKey])) {
                continue;
            }

            $enabledCoreWidgets[] = [
                'key' => $widgetKey,
                'label' => (string)($availableWidgets[$widgetKey]['label'] ?? $widgetKey),
                'description' => (string)($availableWidgets[$widgetKey]['description'] ?? ''),
            ];
        }

        $customWidgets = [];
        foreach ($customWidgetOrder as $widgetPosition) {
            $widget = $settings['custom_widgets'][(int)$widgetPosition] ?? null;
            if (!is_array($widget)) {
                continue;
            }

            $title = $this->sanitizeTextSetting((string)($widget['title'] ?? ''), 120);
            $content = $this->sanitizeTextSetting((string)($widget['content'] ?? ''), 240);
            if ($title === '' && $content === '') {
                continue;
            }

            $customWidgets[] = [
                'title' => $title !== '' ? $title : 'Info-Widget',
                'content' => $content,
                'icon' => $this->sanitizeTextSetting((string)($widget['icon'] ?? '✨'), 16) ?: '✨',
            ];
        }

        $visiblePluginWidgets = [];
        foreach ($pluginWidgets as $widget) {
            if (empty($widget['supports_frontend_widget'])) {
                continue;
            }

            $pluginSlug = $this->sanitizeTextSetting((string)($widget['plugin'] ?? ''), 120);
            if ($pluginSlug === '') {
                continue;
            }

            if (($settingsMap['member_dashboard_plugin_' . $pluginSlug] ?? '1') !== '1') {
                continue;
            }

            $visiblePluginWidgets[] = [
                'plugin' => $pluginSlug,
                'label' => (string)($widget['label'] ?? $pluginSlug),
                'description' => (string)($widget['description'] ?? ''),
                'icon' => (string)($widget['icon'] ?? '🔌'),
                'color' => $this->sanitizeColor((string)($widget['color'] ?? '#4f46e5'), '#4f46e5'),
            ];
        }

        $profileFields = [];
        foreach ((array)($settings['profile_fields'] ?? []) as $fieldKey) {
            $fieldKey = (string)$fieldKey;
            if (!isset($profileFieldDefinitions[$fieldKey])) {
                continue;
            }

            $profileFields[] = [
                'key' => $fieldKey,
                'label' => (string)($profileFieldDefinitions[$fieldKey]['label'] ?? $fieldKey),
            ];
        }

        $sectionOrder = [];
        $allowedSections = ['quick_start', 'stats', 'widgets', 'plugins'];
        foreach (explode(',', (string)($settings['section_order'] ?? 'stats,widgets,plugins')) as $section) {
            $section = trim($section);
            if ($section !== '' && in_array($section, $allowedSections, true) && !in_array($section, $sectionOrder, true)) {
                $sectionOrder[] = $section;
            }
        }

        if ($sectionOrder === []) {
            $sectionOrder = ['stats', 'widgets', 'plugins'];
        }

        $notifications = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];
        $onboarding = is_array($settings['onboarding'] ?? null) ? $settings['onboarding'] : [];

        return [
            'dashboard_enabled' => !empty($settings['dashboard_enabled']),
            'show_welcome' => !empty($settings['show_welcome']),
            'greeting' => (string)($settings['dashboard_greeting'] ?? 'Guten Tag, {name}!'),
            'welcome_text' => (string)($settings['dashboard_welcome_text'] ?? 'Hier findest du alle wichtigen Funktionen rund um Profil, Sicherheit, Dateien und Kommunikation.'),
            'design' => is_array($settings['design'] ?? null) ? $settings['design'] : [],
            'frontend_modules' => [
                'show_quickstart' => !array_key_exists('show_quickstart', $frontendModules) || !empty($frontendModules['show_quickstart']),
                'show_stats' => !array_key_exists('show_stats', $frontendModules) || !empty($frontendModules['show_stats']),
                'show_custom_widgets' => !array_key_exists('show_custom_widgets', $frontendModules) || !empty($frontendModules['show_custom_widgets']),
                'show_plugin_widgets' => !array_key_exists('show_plugin_widgets', $frontendModules) || !empty($frontendModules['show_plugin_widgets']),
                'show_notifications_panel' => !array_key_exists('show_notifications_panel', $frontendModules) || !empty($frontendModules['show_notifications_panel']),
                'show_onboarding_panel' => !array_key_exists('show_onboarding_panel', $frontendModules) || !empty($frontendModules['show_onboarding_panel']),
            ],
            'section_order' => $sectionOrder,
            'core_widgets' => $enabledCoreWidgets,
            'custom_widgets' => $customWidgets,
            'plugin_widgets' => $visiblePluginWidgets,
            'profile_fields' => $profileFields,
            'notifications' => [
                'center_enabled' => !array_key_exists('center_enabled', $notifications) || !empty($notifications['center_enabled']),
                'empty_text' => trim((string)($notifications['empty_text'] ?? 'Aktuell gibt es keine neuen Meldungen.')),
            ],
            'onboarding' => [
                'enabled' => !array_key_exists('enabled', $onboarding) || !empty($onboarding['enabled']),
                'title' => (string)($onboarding['title'] ?? 'So startest du optimal'),
                'intro' => (string)($onboarding['intro'] ?? ''),
                'steps' => array_values(array_filter(array_map('strval', (array)($onboarding['steps'] ?? [])))),
                'cta_label' => (string)($onboarding['cta_label'] ?? 'Jetzt starten'),
                'cta_url' => $this->normalizeActionUrl((string)($onboarding['cta_url'] ?? '/member/profile'), '/member/profile'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function buildOnboardingAnalytics(array $settings): array
    {
        $analytics = $this->emptyOnboardingAnalytics();

        $profileFieldDefinitions = $this->getProfileFieldDefinitions($settings['custom_profile_fields'] ?? []);
        $selectedProfileFields = array_values(array_intersect(
            array_keys($profileFieldDefinitions),
            array_map('strval', (array)($settings['profile_fields'] ?? []))
        ));

        $onboarding = is_array($settings['onboarding'] ?? null) ? $settings['onboarding'] : [];
        $analytics['profile_fields_considered'] = count($selectedProfileFields);
        $analytics['steps_configured'] = count(array_values(array_filter(array_map('strval', (array)($onboarding['steps'] ?? [])))));
        $analytics['require_profile_completion'] = !empty($onboarding['require_profile_completion']);

        try {
            $activeUsers = $this->db->get_results(
                "SELECT id FROM {$this->prefix}users WHERE status = 'active'"
            ) ?: [];

            $activeUserIds = [];
            foreach ($activeUsers as $userRow) {
                $userId = (int)($userRow->id ?? 0);
                if ($userId > 0) {
                    $activeUserIds[] = $userId;
                }
            }

            $analytics['total_active_accounts'] = count($activeUserIds);
            if ($activeUserIds === []) {
                $analytics['basis_note'] = 'Noch keine aktiven Konten vorhanden.';
                return $analytics;
            }

            $profileCompletionByUser = [];
            if ($selectedProfileFields !== []) {
                $placeholders = implode(',', array_fill(0, count($selectedProfileFields), '?'));
                $profileRows = $this->db->get_results(
                    "SELECT um.user_id, um.meta_key, um.meta_value
                     FROM {$this->prefix}user_meta um
                     INNER JOIN {$this->prefix}users u ON u.id = um.user_id
                     WHERE u.status = 'active' AND um.meta_key IN ({$placeholders})",
                    $selectedProfileFields
                ) ?: [];

                foreach ($profileRows as $profileRow) {
                    $userId = (int)($profileRow->user_id ?? 0);
                    $metaKey = (string)($profileRow->meta_key ?? '');
                    $metaValue = trim((string)($profileRow->meta_value ?? ''));
                    if ($userId <= 0 || $metaKey === '' || $metaValue === '') {
                        continue;
                    }

                    if (!isset($profileCompletionByUser[$userId])) {
                        $profileCompletionByUser[$userId] = [];
                    }

                    $profileCompletionByUser[$userId][$metaKey] = true;
                }
            }

            $mfaUsers = [];
            try {
                $mfaRows = $this->db->get_results(
                    "SELECT DISTINCT um.user_id
                     FROM {$this->prefix}user_meta um
                     INNER JOIN {$this->prefix}users u ON u.id = um.user_id
                     WHERE u.status = 'active'
                       AND um.meta_key IN ('mfa_enabled', '2fa_enabled')
                       AND um.meta_value = '1'"
                ) ?: [];

                foreach ($mfaRows as $mfaRow) {
                    $userId = (int)($mfaRow->user_id ?? 0);
                    if ($userId > 0) {
                        $mfaUsers[$userId] = true;
                    }
                }
            } catch (\Throwable) {
                $mfaUsers = [];
            }

            $passkeyUsers = [];
            try {
                $passkeyRows = $this->db->get_results(
                    "SELECT DISTINCT pc.user_id
                     FROM {$this->prefix}passkey_credentials pc
                     INNER JOIN {$this->prefix}users u ON u.id = pc.user_id
                     WHERE u.status = 'active'"
                ) ?: [];

                foreach ($passkeyRows as $passkeyRow) {
                    $userId = (int)($passkeyRow->user_id ?? 0);
                    if ($userId > 0) {
                        $passkeyUsers[$userId] = true;
                    }
                }
            } catch (\Throwable) {
                $passkeyUsers = [];
            }

            $recentlyActiveUsers = [];
            try {
                $recentActivityRows = $this->db->get_results(
                    "SELECT DISTINCT al.user_id
                     FROM {$this->prefix}activity_log al
                     INNER JOIN {$this->prefix}users u ON u.id = al.user_id
                     WHERE u.status = 'active'
                       AND al.action = 'login'
                       AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                ) ?: [];

                foreach ($recentActivityRows as $activityRow) {
                    $userId = (int)($activityRow->user_id ?? 0);
                    if ($userId > 0) {
                        $recentlyActiveUsers[$userId] = true;
                    }
                }
            } catch (\Throwable) {
                $analytics['has_recent_login_signal'] = false;
                $recentlyActiveUsers = [];
            }

            $profileCompletedAccounts = 0;
            foreach ($activeUserIds as $userId) {
                if ($selectedProfileFields === []) {
                    continue;
                }

                $filledFields = $profileCompletionByUser[$userId] ?? [];
                $isComplete = true;
                foreach ($selectedProfileFields as $profileFieldKey) {
                    if (empty($filledFields[$profileFieldKey])) {
                        $isComplete = false;
                        break;
                    }
                }

                if ($isComplete) {
                    $profileCompletedAccounts++;
                }
            }

            $analytics['profile_completed_accounts'] = $profileCompletedAccounts;
            $analytics['profile_incomplete_accounts'] = max(0, count($activeUserIds) - $profileCompletedAccounts);
            $analytics['mfa_enabled_accounts'] = count($mfaUsers);
            $analytics['passkey_ready_accounts'] = count($passkeyUsers);
            $analytics['security_ready_accounts'] = count(array_unique(array_merge(array_keys($mfaUsers), array_keys($passkeyUsers))));
            $analytics['recently_active_accounts'] = count($recentlyActiveUsers);

            if ($selectedProfileFields !== []) {
                $analytics['completion_mode'] = 'profile';
                $analytics['completion_accounts'] = $profileCompletedAccounts;
                $analytics['completion_rate'] = (int)round(($profileCompletedAccounts / count($activeUserIds)) * 100);
                $analytics['profile_completion_rate'] = $analytics['completion_rate'];
                $analytics['basis_note'] = 'Die Abschlussrate basiert auf vollständig ausgefüllten aktuell aktivierten Profilfeldern und bleibt rein aggregiert/read-only.';
            } else {
                $analytics['completion_mode'] = 'activity_fallback';
                $analytics['completion_accounts'] = count($recentlyActiveUsers);
                $analytics['completion_rate'] = (int)round((count($recentlyActiveUsers) / count($activeUserIds)) * 100);
                $analytics['profile_completion_rate'] = 0;
                $analytics['basis_note'] = 'Aktuell sind keine zusätzlichen Profilfelder aktiv. Als defensiver Fallback zeigt die Abschlusskarte daher die aktive Nutzung in den letzten 30 Tagen statt einer Profilquote.';
            }

            $analytics['security_ready_rate'] = (int)round(($analytics['security_ready_accounts'] / count($activeUserIds)) * 100);
            $analytics['recently_active_rate'] = (int)round(($analytics['recently_active_accounts'] / count($activeUserIds)) * 100);

            return $analytics;
        } catch (\Throwable $e) {
            $this->logger->warning('Onboarding-Analytics konnten nicht vollständig aufgebaut werden.', [
                'action' => 'member.dashboard.onboarding.analytics_failed',
                'exception_class' => $e::class,
            ]);

            return $analytics;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyOnboardingAnalytics(): array
    {
        return [
            'total_active_accounts' => 0,
            'steps_configured' => 0,
            'profile_fields_considered' => 0,
            'require_profile_completion' => false,
            'completion_mode' => 'profile',
            'completion_accounts' => 0,
            'completion_rate' => 0,
            'profile_completed_accounts' => 0,
            'profile_incomplete_accounts' => 0,
            'profile_completion_rate' => 0,
            'mfa_enabled_accounts' => 0,
            'passkey_ready_accounts' => 0,
            'security_ready_accounts' => 0,
            'security_ready_rate' => 0,
            'recently_active_accounts' => 0,
            'recently_active_rate' => 0,
            'has_recent_login_signal' => true,
            'basis_note' => 'Die Kennzahlen werden read-only aus bestehenden Signalen abgeleitet.',
        ];
    }

    private function sanitizeRole(string $role): string
    {
        $role = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($role)) ?? '';

        return $role !== '' ? strtolower($role) : 'member';
    }

    private function sanitizeTextSetting(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));

        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }

    private function sanitizeColor(string $value, string $fallback): string
    {
        $value = trim($value);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
            return strtolower($value);
        }

        return $fallback;
    }

    private function normalizeAssetReference(string $value): string
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

    private function normalizeActionUrl(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        if (str_starts_with($value, '/')) {
            return '/' . ltrim($value, '/');
        }

        $sanitized = trim((string)filter_var($value, FILTER_SANITIZE_URL));
        if ($sanitized === '' || filter_var($sanitized, FILTER_VALIDATE_URL) === false) {
            return $fallback;
        }

        $scheme = strtolower((string)parse_url($sanitized, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $sanitized : $fallback;
    }

    private function failResult(string $action, string $message, \Throwable $e): array
    {
        $this->logger->warning($message, [
            'action' => $action,
            'exception_class' => $e::class,
        ]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $message,
            'member_dashboard',
            null,
            ['exception_class' => $e::class],
            'error'
        );

        return ['success' => false, 'error' => $message . ' Bitte Logs prüfen.'];
    }
}
