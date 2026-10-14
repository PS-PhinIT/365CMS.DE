<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class CoreModuleService
{
    private const string SETTINGS_GROUP = 'core_modules';

    /**
     * @var array<string, array{
     *     label:string,
     *     description:string,
     *     category:string,
     *     category_label:string,
     *     order:int,
     *     default_enabled:bool,
    *     toggleable:bool,
     *     dependencies:array<int,string>,
     *     admin_pages:array<int,string>,
     *     admin_labels:array<int,string>,
     *     sidebar_group:?string,
     *     legacy_setting:?string
     * }>
     */
    private const array MODULES = [
        'ai_services' => [
            'label' => 'AI Services',
            'description' => 'Schaltet die zentrale AI-Services-Verwaltung als eigenen Admin-Hauptbereich samt Übersetzungs-, Content-, SEO- und Einstellungsseiten inklusive geschützter Editor.js-Übersetzungs- und SEO-Metadaten-Endpunkte ein oder aus.',
            'category' => 'automation',
            'category_label' => 'AI & Automatisierung',
            'order' => 10,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => ['ai-services', 'ai-translation', 'ai-content-creator', 'ai-seo-creator', 'ai-settings'],
            'admin_labels' => ['AI Dashboard', 'Übersetzung', 'Content Creator', 'SEO Creator', 'Einstellungen'],
            'sidebar_group' => 'ai_services',
            'legacy_setting' => null,
        ],
        'seo' => [
            'label' => 'SEO',
            'description' => 'Schaltet den kompletten SEO-Corebereich mit Dashboard, Analytics, Audit, Meta-/Schema-/Sitemap-Verwaltung sowie Redirect- und 404-Monitor zentral ein oder aus.',
            'category' => 'growth',
            'category_label' => 'Wachstum & Sichtbarkeit',
            'order' => 20,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => ['seo-dashboard', 'analytics', 'seo-audit', 'seo-meta', 'seo-social', 'seo-schema', 'seo-sitemap', 'seo-technical', 'redirect-manager', 'not-found-monitor'],
            'admin_labels' => ['SEO Dashboard', 'Analytics', 'SEO Audit', 'Meta-Daten', 'Social Media', 'Strukturierte Daten', 'Sitemap & robots.txt', 'Technisches SEO', 'Weiterleitungen', '404-Monitor'],
            'sidebar_group' => 'seo',
            'legacy_setting' => null,
        ],
        'security' => [
            'label' => 'Sicherheit',
            'description' => 'Schaltet die Sicherheitsverwaltung mit AntiSpam, Firewall und Security-Audit als kompletten Corebereich zentral ein oder aus.',
            'category' => 'compliance',
            'category_label' => 'Compliance & Schutz',
            'order' => 30,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => ['antispam', 'firewall', 'security-audit'],
            'admin_labels' => ['AntiSpam', 'Firewall', 'Security Audit'],
            'sidebar_group' => 'security',
            'legacy_setting' => null,
        ],
        'legal' => [
            'label' => 'Recht',
            'description' => 'Schaltet den Rechts-/Consent-Bereich mit Legal Sites, Cookie-Manager sowie Auskunfts- und Löschanfragen als kompletten Corebereich ein oder aus.',
            'category' => 'compliance',
            'category_label' => 'Compliance & Schutz',
            'order' => 40,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => ['legal-sites', 'cookie-manager', 'data-requests', 'privacy-requests', 'deletion-requests'],
            'admin_labels' => ['Legal Sites', 'Cookie-Manager', 'Auskunft & Löschen'],
            'sidebar_group' => 'legal',
            'legacy_setting' => null,
        ],
        'member_dashboard' => [
            'label' => 'Member Dashboard',
            'description' => 'Schaltet die Member-Dashboard-Konfiguration im Admin sowie den eigentlichen Member-Dashboard-Startbereich zentral als Core-Modul ein oder aus.',
            'category' => 'members',
            'category_label' => 'Mitgliederbereich',
            'order' => 20,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => [
                'member-dashboard',
                'member-dashboard-general',
                'member-dashboard-design',
                'member-dashboard-frontend-modules',
                'member-dashboard-widgets',
                'member-dashboard-plugin-widgets',
                'member-dashboard-profile-fields',
                'member-dashboard-notifications',
                'member-dashboard-onboarding',
            ],
            'admin_labels' => [
                'Member Dashboard',
                'Allgemein',
                'Design & Farben',
                'Frontend-Module',
                'Dashboard Widgets',
                'Plugin-Widgets',
                'Profil-Felder',
                'Benachrichtigungen',
                'Mitglieder-Onboarding',
            ],
            'sidebar_group' => 'member_dashboard',
            'legacy_setting' => 'member_dashboard_enabled',
        ],
        'performance' => [
            'label' => 'Performance',
            'description' => 'Schaltet den kompletten Performance-Bereich mit Übersicht, Cache, Medien, Datenbank, Sessions und Performance-Einstellungen zentral ein oder aus.',
            'category' => 'system_runtime',
            'category_label' => 'System & Runtime',
            'order' => 60,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => ['performance', 'performance-cache', 'performance-media', 'performance-database', 'performance-settings', 'performance-sessions'],
            'admin_labels' => ['Übersicht', 'Cache-Verwaltung', 'Medien-Optimierung', 'Datenbank-Wartung', 'Performance-Einstellungen', 'Session-Verwaltung'],
            'sidebar_group' => 'performance',
            'legacy_setting' => null,
        ],
        'subscriptions' => [
            'label' => 'Aboverwaltung Core',
            'description' => 'Schaltet die integrierte Aboverwaltung als Kernmodul ein oder aus. Wenn dieses Modul deaktiviert ist, verschwinden alle zugehörigen Admin-Bereiche und abhängigen Laufzeitfunktionen.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 110,
            'default_enabled' => true,
            'toggleable' => true,
            'dependencies' => [],
            'admin_pages' => [],
            'admin_labels' => [],
            'sidebar_group' => null,
            'legacy_setting' => 'subscription_enabled',
        ],
        'subscription_admin_packages' => [
            'label' => 'Pakete & Abo-Einstellungen',
            'description' => 'Blendet den Admin-Bereich für Paketdefinitionen, Preise, Trial-Einstellungen, Steuern und Rechnungsoptionen ein oder aus.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 120,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => ['packages'],
            'admin_labels' => ['Pakete & Abo-Einstellungen'],
            'sidebar_group' => 'subscriptions',
            'legacy_setting' => null,
        ],
        'subscription_admin_orders' => [
            'label' => 'Bestellungen & Zuweisung',
            'description' => 'Steuert den Admin-Bereich für Bestellungen, Statuspflege und manuelle Paketzuweisung.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 130,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => ['orders'],
            'admin_labels' => ['Bestellungen & Zuweisung'],
            'sidebar_group' => 'subscriptions',
            'legacy_setting' => null,
        ],
        'subscription_admin_settings' => [
            'label' => 'Abo-Einstellungen',
            'description' => 'Aktiviert die globale Einstellungsseite der Aboverwaltung im Adminbereich.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 140,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => ['subscription-settings'],
            'admin_labels' => ['Einstellungen'],
            'sidebar_group' => 'subscriptions',
            'legacy_setting' => null,
        ],
        'subscription_limits' => [
            'label' => 'Paketlimits & Zugriffsgates',
            'description' => 'Schaltet die Limit- und Zugriffsprüfung auf Basis der zugewiesenen Pakete systemweit an oder aus.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 150,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => [],
            'admin_labels' => [],
            'sidebar_group' => null,
            'legacy_setting' => 'subscription_limits_enabled',
        ],
        'subscription_member_area' => [
            'label' => 'Member-Abo-Bereich',
            'description' => 'Blendet den Bereich „Abo & Bestellungen“ im Member-Dashboard samt zugehöriger Seite aus oder ein.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 160,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => [],
            'admin_labels' => [],
            'sidebar_group' => null,
            'legacy_setting' => 'subscription_member_area_enabled',
        ],
        'subscription_ordering' => [
            'label' => 'Bestell- & Upgrade-Prozesse',
            'description' => 'Reservierter Kernschalter für Bestell- und Upgrade-Prozesse der Aboverwaltung.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 170,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => [],
            'admin_labels' => [],
            'sidebar_group' => null,
            'legacy_setting' => 'subscription_ordering_enabled',
        ],
        'subscription_public_pricing' => [
            'label' => 'Öffentliche Paketkommunikation',
            'description' => 'Reservierter Kernschalter für öffentliche Paket- und Pricing-Darstellung.',
            'category' => 'subscriptions',
            'category_label' => 'Aboverwaltung',
            'order' => 180,
            'default_enabled' => true,
            'toggleable' => false,
            'dependencies' => ['subscriptions'],
            'admin_pages' => [],
            'admin_labels' => [],
            'sidebar_group' => null,
            'legacy_setting' => 'subscription_public_pricing_enabled',
        ],
    ];

    private static ?self $instance = null;

    private SettingsService $settings;
    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->settings = SettingsService::getInstance();
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /** @return list<string> */
    public function getKnownModuleSlugs(): array
    {
        return array_keys(self::getToggleableDefinitions());
    }

    /** @return list<array<string, mixed>> */
    public static function getInstallerModules(): array
    {
        $modules = [];

        foreach (self::getToggleableDefinitions() as $slug => $definition) {
            $modules[] = [
                'slug' => $slug,
                'label' => (string) ($definition['label'] ?? $slug),
                'description' => (string) ($definition['description'] ?? ''),
                'category' => (string) ($definition['category'] ?? 'general'),
                'category_label' => (string) ($definition['category_label'] ?? 'Allgemein'),
                'default_enabled' => (bool) ($definition['default_enabled'] ?? true),
                'legacy_setting' => (string) ($definition['legacy_setting'] ?? ''),
            ];
        }

        return $modules;
    }

    /** @return array<string, mixed>|null */
    public function getModuleDefinition(string $slug): ?array
    {
        return self::MODULES[$slug] ?? null;
    }

    public function isModuleEnabled(string $slug, bool $resolveDependencies = true): bool
    {
        $definition = self::MODULES[$slug] ?? null;
        if ($definition === null) {
            return true;
        }

        $storedEnabled = $this->resolveStoredEnabledState($slug, $definition);

        if (!$storedEnabled || !$resolveDependencies) {
            return $storedEnabled;
        }

        foreach ((array) ($definition['dependencies'] ?? []) as $dependencySlug) {
            if (!$this->isModuleEnabled((string) $dependencySlug, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function getModulesForAdmin(): array
    {
        $modules = [];
        foreach (self::getToggleableDefinitions() as $slug => $definition) {
            $modules[] = $this->buildAdminModulePayload($slug, $definition);
        }

        return $modules;
    }

    /** @return list<array<string, mixed>> */
    public function getGroupedModules(): array
    {
        $groups = [];

        foreach ($this->getModulesForAdmin() as $module) {
            $category = (string) ($module['category'] ?? 'general');
            if (!isset($groups[$category])) {
                $groups[$category] = [
                    'slug' => $category,
                    'label' => (string) ($module['category_label'] ?? ucfirst($category)),
                    'modules' => [],
                    'enabled_count' => 0,
                    'total_count' => 0,
                ];
            }

            $groups[$category]['modules'][] = $module;
            $groups[$category]['total_count']++;
            if (!empty($module['effective_enabled'])) {
                $groups[$category]['enabled_count']++;
            }
        }

        return array_values($groups);
    }

    public function isAdminPageEnabled(string $pageSlug): bool
    {
        $normalizedSlug = trim($pageSlug);
        if ($normalizedSlug === '') {
            return true;
        }

        $matchedModuleSlugs = [];
        foreach (self::MODULES as $slug => $definition) {
            if (in_array($normalizedSlug, (array) ($definition['admin_pages'] ?? []), true)) {
                $matchedModuleSlugs[] = $slug;
            }
        }

        if ($matchedModuleSlugs === []) {
            return true;
        }

        foreach ($matchedModuleSlugs as $moduleSlug) {
            if ($this->isModuleEnabled($moduleSlug, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return array<int, array<string, mixed>>
     */
    public function filterSidebarChildren(string $sidebarGroup, array $children): array
    {
        $filtered = [];

        foreach ($children as $child) {
            $pageSlug = trim((string) ($child['slug'] ?? ''));
            if ($pageSlug === '' || $this->pageBelongsToSidebarGroup($pageSlug, $sidebarGroup) === false) {
                $filtered[] = $child;
                continue;
            }

            if ($this->isAdminPageEnabled($pageSlug)) {
                $filtered[] = $child;
            }
        }

        return array_values($filtered);
    }

    public function isSidebarGroupVisible(string $sidebarGroup, array $children): bool
    {
        return $this->filterSidebarChildren($sidebarGroup, $children) !== [];
    }

    /**
     * @param array<string, bool> $requestedStates
     * @return array{stored:array<string,bool>,effective:array<string,bool>}
     */
    public function updateModuleStates(array $requestedStates): array
    {
        $normalizedStates = [];
        $toggleableDefinitions = self::getToggleableDefinitions();

        foreach ($toggleableDefinitions as $slug => $definition) {
            $normalizedStates[$slug] = !empty($requestedStates[$slug]);
        }

        $this->settings->setMany(self::SETTINGS_GROUP, $normalizedStates);

        $effectiveStates = [];
        foreach ($toggleableDefinitions as $slug => $definition) {
            $effectiveStates[$slug] = $this->isModuleEnabled($slug, true);
        }

        foreach ($toggleableDefinitions as $slug => $definition) {
            $legacySetting = (string) ($definition['legacy_setting'] ?? '');
            if ($legacySetting === '') {
                continue;
            }

            $this->storeLegacySetting($legacySetting, !empty($effectiveStates[$slug]) ? '1' : '0');
        }

        return [
            'stored' => $normalizedStates,
            'effective' => $effectiveStates,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function getToggleableDefinitions(): array
    {
        $definitions = array_filter(
            self::MODULES,
            static fn (array $definition): bool => self::isToggleableDefinition($definition)
        );

        uasort($definitions, static function (array $left, array $right): int {
            return ((int) ($left['order'] ?? 999)) <=> ((int) ($right['order'] ?? 999));
        });

        return $definitions;
    }

    /** @param array<string, mixed> $definition */
    private static function isToggleableDefinition(array $definition): bool
    {
        return !array_key_exists('toggleable', $definition) || !empty($definition['toggleable']);
    }


    /** @param array<string, mixed> $definition */
    private function buildAdminModulePayload(string $slug, array $definition): array
    {
        $storedEnabled = $this->resolveStoredEnabledState($slug, $definition);
        $effectiveEnabled = $this->isModuleEnabled($slug, true);

        $dependencyLabels = [];
        foreach ((array) ($definition['dependencies'] ?? []) as $dependencySlug) {
            $dependencyDefinition = self::MODULES[(string) $dependencySlug] ?? null;
            if ($dependencyDefinition === null) {
                continue;
            }

            $dependencyLabels[] = (string) ($dependencyDefinition['label'] ?? $dependencySlug);
        }

        $statusReason = 'Aktiv.';
        if (!$storedEnabled) {
            $statusReason = 'Manuell deaktiviert.';
        } elseif (!$effectiveEnabled && $dependencyLabels !== []) {
            $statusReason = 'Blockiert durch abhängige Kernmodule: ' . implode(', ', $dependencyLabels) . '.';
        }

        return [
            'slug' => $slug,
            'label' => (string) ($definition['label'] ?? $slug),
            'description' => (string) ($definition['description'] ?? ''),
            'category' => (string) ($definition['category'] ?? 'general'),
            'category_label' => (string) ($definition['category_label'] ?? 'Allgemein'),
            'stored_enabled' => $storedEnabled,
            'effective_enabled' => $effectiveEnabled,
            'dependencies' => array_values((array) ($definition['dependencies'] ?? [])),
            'dependency_labels' => $dependencyLabels,
            'admin_pages' => array_values((array) ($definition['admin_pages'] ?? [])),
            'admin_labels' => array_values((array) ($definition['admin_labels'] ?? [])),
            'legacy_setting' => (string) ($definition['legacy_setting'] ?? ''),
            'status_reason' => $statusReason,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function resolveStoredEnabledState(string $slug, array $definition): bool
    {
        $default = (bool) ($definition['default_enabled'] ?? true);
        if (self::isToggleableDefinition($definition)) {
            $storedValue = $this->settings->get(self::SETTINGS_GROUP, $slug, null);
            if ($storedValue !== null) {
                return $this->normalizeStoredEnabledValue($storedValue, $default);
            }
        }

        $legacySetting = trim((string) ($definition['legacy_setting'] ?? ''));
        if ($legacySetting !== '') {
            return $this->readLegacyEnabledSetting($legacySetting, $default);
        }

        return $default;
    }

    private function readLegacyEnabledSetting(string $settingKey, bool $default): bool
    {
        $value = OptionStore::getInstance()->get($settingKey);

        if ($value === null) {
            return $default;
        }

        return $this->normalizeStoredEnabledValue($value, $default);
    }

    private function normalizeStoredEnabledValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function pageBelongsToSidebarGroup(string $pageSlug, string $sidebarGroup): bool
    {
        foreach (self::MODULES as $definition) {
            if (($definition['sidebar_group'] ?? null) !== $sidebarGroup) {
                continue;
            }

            if (in_array($pageSlug, (array) ($definition['admin_pages'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    private function storeLegacySetting(string $settingKey, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value)\n             VALUES (?, ?)\n             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [$settingKey, $value]
        );
    }
}
