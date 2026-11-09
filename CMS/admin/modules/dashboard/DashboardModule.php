<?php
declare(strict_types=1);

/**
 * Dashboard Module – Business-Logik für die Admin-Startseite
 *
 * Lädt Statistiken aus DashboardService und bereitet Daten
 * für die View auf.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Services\CoreModuleService;
use CMS\Services\DashboardService;
use CMS\Database;
use CMS\Auth;
use CMS\AuditLogger;

class DashboardModule
{
    private const DASHBOARD_SECTION_DEFINITIONS = [
        'work_overview' => [
            'label' => 'Zentrale Arbeitsübersicht',
            'description' => 'KPI-Karten und wichtigste Schnellzugriffe.',
            'required' => true,
        ],
        'favorites_recent' => [
            'label' => 'Favoriten & zuletzt genutzt',
            'description' => 'Persönliche Schnellzugriffe und zuletzt genutzte Admin-Ziele.',
            'required' => false,
        ],
        'attention' => [
            'label' => 'Nächste Aufmerksamkeit',
            'description' => 'Offene Prioritäten und kontextuelle Warnungen.',
            'required' => false,
        ],
        'system_status' => [
            'label' => 'Systemstatus',
            'description' => 'PHP, CMS, Datenbank und Speicherindikatoren.',
            'required' => false,
        ],
        'security_performance' => [
            'label' => 'Sicherheit & Performance',
            'description' => 'Security- und Performance-Kurzsignale.',
            'required' => false,
        ],
        'recent_orders' => [
            'label' => 'Neueste Bestellungen',
            'description' => 'Aktuelle Bestellungen bei aktivem Abo-/Order-Modul.',
            'required' => false,
        ],
        'recent_activity' => [
            'label' => 'Letzte Aktivitäten',
            'description' => 'Jüngste Audit-Ereignisse aus dem System.',
            'required' => false,
        ],
    ];

    private const WORK_OVERVIEW_WIDGET_DEFINITIONS = [
        'users_total' => [
            'label' => 'Benutzer',
            'description' => 'Gesamtzahl, Aktivität und frische Registrierungen.',
        ],
        'pages_total' => [
            'label' => 'Seiten',
            'description' => 'Seitenbestand inklusive Redaktionsstatus.',
        ],
        'posts_total' => [
            'label' => 'Beiträge',
            'description' => 'Beitragsvolumen, Veröffentlichung und Planung.',
        ],
        'media_total' => [
            'label' => 'Medien',
            'description' => 'Dateibestand, Speicherverbrauch und Dateitypen.',
        ],
        'orders_revenue' => [
            'label' => 'Umsatz (30T)',
            'description' => 'Umsatz- und Bestellstatus bei aktivem Order-Modul.',
        ],
        'user_growth' => [
            'label' => 'Nutzerwachstum',
            'description' => 'Neue Konten und Entwicklung über 30 Tage.',
        ],
        'content_pipeline' => [
            'label' => 'Redaktions-Pipeline',
            'description' => 'Entwürfe, geplante Inhalte und private Inhalte mit Handlungsbedarf.',
        ],
        'comment_queue' => [
            'label' => 'Kommentar-Moderation',
            'description' => 'Offene Kommentare mit direktem Sprung in die Moderation.',
        ],
        'sessions_live' => [
            'label' => 'Aktive Sessions',
            'description' => 'Live-Aktivität und heutige Nutzung im Überblick.',
        ],
        'security_snapshot' => [
            'label' => 'Security Snapshot',
            'description' => 'Score, HTTPS-Status und auffällige Login-Signale.',
        ],
        'system_stack' => [
            'label' => 'System-Stack',
            'description' => 'CMS-, PHP- und Datenbank-Kontext für schnelle Checks.',
        ],
    ];

    private const FAVORITE_SHORTCUT_DEFINITIONS = [
        'new_page' => [
            'label' => 'Neue Seite',
            'description' => 'Schnell in die Seitenerstellung springen.',
            'icon' => 'file-plus',
            'url' => '/admin/pages?action=new',
        ],
        'new_post' => [
            'label' => 'Neuer Beitrag',
            'description' => 'Direkt einen neuen Beitrag anlegen.',
            'icon' => 'pencil-plus',
            'url' => '/admin/posts?action=new',
        ],
        'comments' => [
            'label' => 'Kommentare',
            'description' => 'Moderation und offene Kommentare prüfen.',
            'icon' => 'message-circle',
            'url' => '/admin/comments',
        ],
        'media' => [
            'label' => 'Medien',
            'description' => 'Medienbibliothek und Uploads öffnen.',
            'icon' => 'photo',
            'url' => '/admin/media',
        ],
        'featured_media' => [
            'label' => 'Beitrags & Site Medien',
            'description' => 'Verwendete Featured Images direkt verwalten.',
            'icon' => 'photo',
            'url' => '/admin/media?tab=featured',
        ],
        'users' => [
            'label' => 'Benutzer',
            'description' => 'Benutzerverwaltung und Rollen aufrufen.',
            'icon' => 'users',
            'url' => '/admin/users',
        ],
        'analytics' => [
            'label' => 'Analytics',
            'description' => 'Nutzung, Traffic und Kennzahlen ansehen.',
            'icon' => 'activity',
            'url' => '/admin/analytics',
        ],
        'security_audit' => [
            'label' => 'Security Audit',
            'description' => 'Sicherheitsstatus und Audit-Hinweise prüfen.',
            'icon' => 'shield-check',
            'url' => '/admin/security-audit',
        ],
        'updates' => [
            'label' => 'Updates',
            'description' => 'Core-, Theme- und Plugin-Updates prüfen.',
            'icon' => 'settings',
            'url' => '/admin/updates',
        ],
        'cms_logs' => [
            'label' => 'CMS Logs',
            'description' => 'Diagnose- und Betriebsprotokolle öffnen.',
            'icon' => 'alert-triangle',
            'url' => '/admin/logs',
        ],
        'settings' => [
            'label' => 'Einstellungen',
            'description' => 'Zentrale Core-Einstellungen verwalten.',
            'icon' => 'settings',
            'url' => '/admin/settings',
        ],
    ];

    private const DEFAULT_FAVORITE_SHORTCUTS = [
        'new_page',
        'new_post',
        'comments',
        'media',
        'users',
        'updates',
    ];

    /** Ersatzziele für Admin-Seiten, deren Core-Modul deaktiviert ist. */
    private const DISABLED_ADMIN_PAGE_FALLBACKS = [
        '/admin/security-audit' => '/admin/logs/security-audit',
    ];

    private const ROLE_TEMPLATE_DEFINITIONS = [
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Breite Steuerungsansicht mit Betriebs-, Sicherheits- und Aktivitätsfokus.',
            'visible_sections' => [
                'work_overview',
                'favorites_recent',
                'attention',
                'system_status',
                'security_performance',
                'recent_orders',
                'recent_activity',
            ],
            'visible_work_overview_widgets' => [
                'content_pipeline',
                'comment_queue',
                'users_total',
                'pages_total',
                'posts_total',
                'media_total',
                'orders_revenue',
                'sessions_live',
                'user_growth',
                'security_snapshot',
                'system_stack',
            ],
            'work_overview_widget_order' => [
                'content_pipeline',
                'comment_queue',
                'users_total',
                'pages_total',
                'posts_total',
                'media_total',
                'orders_revenue',
                'sessions_live',
                'user_growth',
                'security_snapshot',
                'system_stack',
            ],
            'favorite_shortcuts' => [
                'new_page',
                'new_post',
                'comments',
                'media',
                'users',
                'analytics',
                'security_audit',
                'updates',
            ],
            'favorite_shortcut_order' => [
                'new_page',
                'new_post',
                'comments',
                'media',
                'users',
                'analytics',
                'security_audit',
                'updates',
                'cms_logs',
                'settings',
                'featured_media',
            ],
        ],
        'editor' => [
            'label' => 'Redaktion',
            'description' => 'Redaktionelle Standardansicht mit Fokus auf Content, Moderation und Medien.',
            'visible_sections' => [
                'work_overview',
                'favorites_recent',
                'attention',
                'recent_activity',
            ],
            'visible_work_overview_widgets' => [
                'content_pipeline',
                'comment_queue',
                'pages_total',
                'posts_total',
                'media_total',
                'sessions_live',
                'user_growth',
            ],
            'work_overview_widget_order' => [
                'content_pipeline',
                'comment_queue',
                'pages_total',
                'posts_total',
                'media_total',
                'sessions_live',
                'user_growth',
            ],
            'favorite_shortcuts' => [
                'new_page',
                'new_post',
                'comments',
                'media',
                'analytics',
            ],
            'favorite_shortcut_order' => [
                'new_page',
                'new_post',
                'comments',
                'media',
                'analytics',
                'featured_media',
            ],
        ],
        'author' => [
            'label' => 'Autor',
            'description' => 'Schlankere Arbeitsansicht für Content-Erstellung, Uploads und Moderation.',
            'visible_sections' => [
                'work_overview',
                'favorites_recent',
                'attention',
            ],
            'visible_work_overview_widgets' => [
                'content_pipeline',
                'posts_total',
                'media_total',
                'comment_queue',
            ],
            'work_overview_widget_order' => [
                'content_pipeline',
                'posts_total',
                'media_total',
                'comment_queue',
            ],
            'favorite_shortcuts' => [
                'new_post',
                'media',
                'comments',
            ],
            'favorite_shortcut_order' => [
                'new_post',
                'media',
                'comments',
                'featured_media',
            ],
        ],
        'member' => [
            'label' => 'Mitglied',
            'description' => 'Minimaler Standard mit Fokus auf die wichtigsten Content- und Medienpfade.',
            'visible_sections' => [
                'work_overview',
                'favorites_recent',
            ],
            'visible_work_overview_widgets' => [
                'posts_total',
                'media_total',
            ],
            'work_overview_widget_order' => [
                'posts_total',
                'media_total',
            ],
            'favorite_shortcuts' => [
                'media',
            ],
            'favorite_shortcut_order' => [
                'media',
            ],
        ],
    ];

    private DashboardService $service;
    private Database $db;
    private string $prefix;

    public function __construct()
    {
        $this->service = DashboardService::getInstance();
        $this->db      = Database::instance();
        $this->prefix  = $this->db->getPrefix();
    }

    /**
     * Alle Dashboard-Daten laden
     */
    public function getData(): array
    {
        $stats = $this->service->getAllStats();
        $meta = is_array($stats['meta'] ?? null) ? $stats['meta'] : [];
        $subscriptionEnabled = $this->isSubscriptionSystemEnabled();
        $subscriptionOrdersEnabled = $this->isSubscriptionOrdersEnabled();
        $widgetDefinitions = $this->getWorkOverviewWidgetDefinitions($subscriptionOrdersEnabled);
        $favoriteShortcutDefinitions = $this->getFavoriteShortcutDefinitions();
        $system = $stats['system'] ?? [];
        $security = $stats['security'] ?? [];
        $performance = $stats['performance'] ?? [];
        $orders = $stats['orders'] ?? [];
        $sessions = $stats['sessions'] ?? [];
        $users = $stats['users'] ?? [];
        $pages = $stats['pages'] ?? [];
        $posts = $stats['posts'] ?? [];
        $media = $stats['media'] ?? [];
        $pendingComments = $this->getPendingCommentsCount();
        $preferences = $this->getDashboardPreferences($subscriptionOrdersEnabled, $widgetDefinitions, $favoriteShortcutDefinitions);
        $workOverviewWidgetOrder = is_array($preferences['work_overview_widget_order'] ?? null)
            ? $this->normalizeOrderedPreferenceKeys($preferences['work_overview_widget_order'], $widgetDefinitions)
            : array_keys($widgetDefinitions);
        $favoriteShortcutOrder = is_array($preferences['favorite_shortcut_order'] ?? null)
            ? $this->normalizeOrderedPreferenceKeys($preferences['favorite_shortcut_order'], $favoriteShortcutDefinitions)
            : array_keys($favoriteShortcutDefinitions);
        $widgetDefinitions = $this->sortDefinitionMapByOrder($widgetDefinitions, $workOverviewWidgetOrder);
        $favoriteShortcutDefinitions = $this->sortDefinitionMapByOrder($favoriteShortcutDefinitions, $favoriteShortcutOrder);
        return [
            'welcome'       => $this->getWelcomeData($system),
            'kpis'          => $this->buildKpis($stats, $subscriptionOrdersEnabled),
            'work_overview_widgets' => $this->buildWorkOverviewWidgets($stats, $pendingComments, $subscriptionOrdersEnabled, $workOverviewWidgetOrder),
            'dashboard_sections' => $this->getDashboardSections($subscriptionOrdersEnabled),
            'dashboard_work_overview_widget_definitions' => $widgetDefinitions,
            'favorite_shortcut_definitions' => $favoriteShortcutDefinitions,
            'favorite_shortcuts' => $this->buildFavoriteShortcuts(
                is_array($preferences['favorite_shortcuts'] ?? null) ? $preferences['favorite_shortcuts'] : [],
                $favoriteShortcutDefinitions
            ),
            'dashboard_preferences' => $preferences,
            'activity'      => $this->getRecentActivity(),
            'quickLinks'    => $this->getQuickLinks(),
            'alerts'        => $this->filterAdminLinks(array_merge($this->buildDashboardHealthAlerts($meta), $this->getAlerts($stats, $pendingComments)), false),
            'attention'     => $this->filterAdminLinks($this->service->getAttentionItems($stats), false),
            'subscription_enabled' => $subscriptionEnabled,
            'recent_orders' => $subscriptionOrdersEnabled ? $this->service->getRecentOrders() : [],
            'orders'        => $orders,
            'sessions'      => $sessions,
            'system'        => $system,
            'security'      => $security,
            'performance'   => $performance,
            'meta'          => $meta,
            'highlights'    => [
                [
                    'label' => 'Neue Benutzer heute',
                    'value' => (string) ($users['new_today'] ?? 0),
                    'hint' => (string) (($users['new_this_week'] ?? 0) . ' in den letzten 7 Tagen'),
                    'icon' => 'users',
                    'url' => '/admin/users',
                ],
                [
                    'label' => 'Entwürfe & private Seiten',
                    'value' => (string) (($pages['drafts'] ?? 0) + ($pages['private'] ?? 0)),
                    'hint' => (string) (($pages['published'] ?? 0) . ' Seiten sind veröffentlicht'),
                    'icon' => 'file-text',
                    'url' => '/admin/pages',
                ],
                [
                    'label' => 'Geplante & private Beiträge',
                    'value' => (string) (($posts['scheduled'] ?? 0) + ($posts['private'] ?? 0)),
                    'hint' => (string) (($posts['published'] ?? 0) . ' Beiträge sind öffentlich sichtbar'),
                    'icon' => 'article',
                    'url' => '/admin/posts',
                ],
                [
                    'label' => 'Uploads gesamt',
                    'value' => (string) ($media['total_files'] ?? 0),
                    'hint' => (string) ($media['total_size_formatted'] ?? $this->formatBytes((int) ($media['total_size'] ?? 0))),
                    'icon' => 'photo',
                    'url' => '/admin/media',
                ],
                ...($subscriptionOrdersEnabled ? [[
                    'label' => 'Bestellungen offen',
                    'value' => (string) ($orders['pending'] ?? 0),
                    'hint' => (string) ($orders['month_revenue_formatted'] ?? '0,00 EUR') . ' Umsatz in 30 Tagen',
                    'icon' => 'shopping-cart',
                    'url' => '/admin/orders',
                ]] : []),
            ],
        ];
    }

    public function handleAction(array $post): array
    {
        $action = trim((string) ($post['action'] ?? ''));

        return match ($action) {
            'save_dashboard_preferences' => $this->saveDashboardPreferences($post),
            'reset_dashboard_preferences' => $this->resetDashboardPreferences(),
            default => ['success' => false, 'error' => 'Unbekannte Dashboard-Aktion.'],
        };
    }

    private function getDashboardSections(bool $subscriptionOrdersEnabled): array
    {
        $sections = self::DASHBOARD_SECTION_DEFINITIONS;
        if (!$subscriptionOrdersEnabled) {
            unset($sections['recent_orders']);
        }

        return $sections;
    }

    private function getFavoriteShortcutDefinitions(): array
    {
        return $this->filterAdminLinks(self::FAVORITE_SHORTCUT_DEFINITIONS, true);
    }

    /**
     * Liefert das Linkziel oder '' für Seiten deaktivierter Core-Module (deren Entry-Points leiten sonst auf / um).
     */
    private function resolveAdminLinkTarget(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (!str_starts_with($path, '/admin/')) {
            return $url;
        }

        $pageSlug = trim(substr($path, strlen('/admin/')), '/');
        if ($pageSlug === '' || str_contains($pageSlug, '/')
            || CoreModuleService::getInstance()->isAdminPageEnabled($pageSlug)) {
            return $url;
        }

        return self::DISABLED_ADMIN_PAGE_FALLBACKS[$path] ?? '';
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    private function filterAdminLinks(array $items, bool $dropUnavailable): array
    {
        $filtered = [];
        foreach ($items as $key => $item) {
            if (is_array($item) && isset($item['url'])) {
                $item['url'] = $this->resolveAdminLinkTarget((string) $item['url']);
                if ($item['url'] === '' && $dropUnavailable) {
                    continue;
                }
            }

            $filtered[$key] = $item;
        }

        return array_is_list($items) ? array_values($filtered) : $filtered;
    }

    private function getWorkOverviewWidgetDefinitions(bool $subscriptionOrdersEnabled): array
    {
        $widgets = self::WORK_OVERVIEW_WIDGET_DEFINITIONS;
        if (!$subscriptionOrdersEnabled) {
            unset($widgets['orders_revenue']);
        }

        return $widgets;
    }

    private function parseOrderedPreferenceInput(mixed $value): array
    {
        $candidates = is_array($value) ? $value : explode(',', (string) $value);
        $normalized = [];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return $normalized;
    }

    private function normalizeOrderedPreferenceKeys(array $orderedKeys, array $availableItems): array
    {
        $availableKeys = array_keys($availableItems);
        $normalized = [];

        foreach ($orderedKeys as $key) {
            $key = trim((string) $key);
            if ($key === '' || !in_array($key, $availableKeys, true) || in_array($key, $normalized, true)) {
                continue;
            }

            $normalized[] = $key;
        }

        foreach ($availableKeys as $key) {
            if (!in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }

    private function sortDefinitionMapByOrder(array $definitions, array $orderedKeys): array
    {
        $sorted = [];

        foreach ($this->normalizeOrderedPreferenceKeys($orderedKeys, $definitions) as $key) {
            if (array_key_exists($key, $definitions)) {
                $sorted[$key] = $definitions[$key];
            }
        }

        return $sorted;
    }

    private function getDashboardPreferences(bool $subscriptionOrdersEnabled, array $workOverviewWidgetDefinitions, array $favoriteShortcutDefinitions): array
    {
        $sections = $this->getDashboardSections($subscriptionOrdersEnabled);
        $roleTemplate = $this->getRoleTemplateContext($subscriptionOrdersEnabled, $workOverviewWidgetDefinitions, $favoriteShortcutDefinitions);
        $defaultPreferences = is_array($roleTemplate['preferences'] ?? null) ? $roleTemplate['preferences'] : [];
        $defaultVisible = is_array($defaultPreferences['visible_sections'] ?? null) ? $defaultPreferences['visible_sections'] : array_keys($sections);
        $defaultVisibleWorkOverviewWidgets = is_array($defaultPreferences['visible_work_overview_widgets'] ?? null)
            ? $defaultPreferences['visible_work_overview_widgets']
            : array_keys($workOverviewWidgetDefinitions);
        $defaultWorkOverviewWidgetOrder = is_array($defaultPreferences['work_overview_widget_order'] ?? null)
            ? $defaultPreferences['work_overview_widget_order']
            : array_keys($workOverviewWidgetDefinitions);
        $defaultFavoriteShortcutOrder = is_array($defaultPreferences['favorite_shortcut_order'] ?? null)
            ? $defaultPreferences['favorite_shortcut_order']
            : array_keys($favoriteShortcutDefinitions);
        $defaultFavoriteShortcuts = is_array($defaultPreferences['favorite_shortcuts'] ?? null)
            ? $defaultPreferences['favorite_shortcuts']
            : $this->normalizeFavoriteShortcuts(self::DEFAULT_FAVORITE_SHORTCUTS, $favoriteShortcutDefinitions);
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return array_merge([
                'visible_sections' => $defaultVisible,
                'visible_work_overview_widgets' => $defaultVisibleWorkOverviewWidgets,
                'work_overview_widget_order' => $defaultWorkOverviewWidgetOrder,
                'favorite_shortcuts' => $defaultFavoriteShortcuts,
                'favorite_shortcut_order' => $defaultFavoriteShortcutOrder,
            ], [
                'uses_role_template' => true,
                'has_saved_preferences' => false,
                'role_template' => $this->buildRoleTemplateViewData($roleTemplate),
            ]);
        }

        try {
            $optionValue = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ?",
                [$this->getDashboardPreferencesOptionName($userId)]
            );
        } catch (\Throwable) {
            return array_merge([
                'visible_sections' => $defaultVisible,
                'visible_work_overview_widgets' => $defaultVisibleWorkOverviewWidgets,
                'work_overview_widget_order' => $defaultWorkOverviewWidgetOrder,
                'favorite_shortcuts' => $defaultFavoriteShortcuts,
                'favorite_shortcut_order' => $defaultFavoriteShortcutOrder,
            ], [
                'uses_role_template' => true,
                'has_saved_preferences' => false,
                'role_template' => $this->buildRoleTemplateViewData($roleTemplate),
            ]);
        }

        $decoded = is_string($optionValue) ? json_decode($optionValue, true) : null;
        if (!is_array($decoded)) {
            return array_merge([
                'visible_sections' => $defaultVisible,
                'visible_work_overview_widgets' => $defaultVisibleWorkOverviewWidgets,
                'work_overview_widget_order' => $defaultWorkOverviewWidgetOrder,
                'favorite_shortcuts' => $defaultFavoriteShortcuts,
                'favorite_shortcut_order' => $defaultFavoriteShortcutOrder,
            ], [
                'uses_role_template' => true,
                'has_saved_preferences' => false,
                'role_template' => $this->buildRoleTemplateViewData($roleTemplate),
            ]);
        }

        $visible = is_array($decoded['visible_sections'] ?? null) ? $decoded['visible_sections'] : $defaultVisible;
        $workOverviewWidgetOrder = is_array($decoded['work_overview_widget_order'] ?? null)
            ? $this->normalizeOrderedPreferenceKeys($decoded['work_overview_widget_order'], $workOverviewWidgetDefinitions)
            : $defaultWorkOverviewWidgetOrder;
        $hasStoredWorkOverviewWidgets = array_key_exists('visible_work_overview_widgets', $decoded);
        $visibleWorkOverviewWidgets = $hasStoredWorkOverviewWidgets && is_array($decoded['visible_work_overview_widgets'] ?? null)
            ? $this->normalizeVisibleWorkOverviewWidgets($decoded['visible_work_overview_widgets'], $workOverviewWidgetDefinitions, $workOverviewWidgetOrder)
            : $defaultVisibleWorkOverviewWidgets;
        $favoriteShortcutOrder = is_array($decoded['favorite_shortcut_order'] ?? null)
            ? $this->normalizeOrderedPreferenceKeys($decoded['favorite_shortcut_order'], $favoriteShortcutDefinitions)
            : $defaultFavoriteShortcutOrder;
        $favoriteShortcuts = is_array($decoded['favorite_shortcuts'] ?? null)
            ? $this->normalizeFavoriteShortcuts($decoded['favorite_shortcuts'], $favoriteShortcutDefinitions, $favoriteShortcutOrder)
            : $defaultFavoriteShortcuts;

        return [
            'visible_sections' => $this->normalizeVisibleDashboardSections($visible, $sections),
            'visible_work_overview_widgets' => $visibleWorkOverviewWidgets,
            'work_overview_widget_order' => $workOverviewWidgetOrder,
            'favorite_shortcuts' => $favoriteShortcuts,
            'favorite_shortcut_order' => $favoriteShortcutOrder,
            'uses_role_template' => false,
            'has_saved_preferences' => true,
            'role_template' => $this->buildRoleTemplateViewData($roleTemplate),
        ];
    }

    private function saveDashboardPreferences(array $post): array
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Dashboard-Einstellungen können ohne gültigen Benutzer nicht gespeichert werden.'];
        }

        $sections = $this->getDashboardSections($this->isSubscriptionOrdersEnabled());
        $workOverviewWidgetDefinitions = $this->getWorkOverviewWidgetDefinitions($this->isSubscriptionOrdersEnabled());
        $favoriteShortcutDefinitions = $this->getFavoriteShortcutDefinitions();
        $selectedSections = is_array($post['dashboard_sections'] ?? null) ? $post['dashboard_sections'] : [];
        $selectedWorkOverviewWidgets = is_array($post['work_overview_widgets'] ?? null) ? $post['work_overview_widgets'] : [];
        $selectedWorkOverviewWidgetOrder = $this->parseOrderedPreferenceInput($post['work_overview_widget_order'] ?? '');
        $selectedFavoriteShortcuts = is_array($post['favorite_shortcuts'] ?? null) ? $post['favorite_shortcuts'] : [];
        $selectedFavoriteShortcutOrder = $this->parseOrderedPreferenceInput($post['favorite_shortcut_order'] ?? '');
        $visibleSections = $this->normalizeVisibleDashboardSections($selectedSections, $sections);
        $workOverviewWidgetOrder = $this->normalizeOrderedPreferenceKeys($selectedWorkOverviewWidgetOrder, $workOverviewWidgetDefinitions);
        $favoriteShortcutOrder = $this->normalizeOrderedPreferenceKeys($selectedFavoriteShortcutOrder, $favoriteShortcutDefinitions);
        $visibleWorkOverviewWidgets = $this->normalizeVisibleWorkOverviewWidgets($selectedWorkOverviewWidgets, $workOverviewWidgetDefinitions, $workOverviewWidgetOrder);
        $favoriteShortcuts = $this->normalizeFavoriteShortcuts($selectedFavoriteShortcuts, $favoriteShortcutDefinitions, $favoriteShortcutOrder);
        $payload = [
            'visible_sections' => $visibleSections,
            'visible_work_overview_widgets' => $visibleWorkOverviewWidgets,
            'work_overview_widget_order' => $workOverviewWidgetOrder,
            'favorite_shortcuts' => $favoriteShortcuts,
            'favorite_shortcut_order' => $favoriteShortcutOrder,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $optionName = $this->getDashboardPreferencesOptionName($userId);
        $optionValue = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($optionValue)) {
            return ['success' => false, 'error' => 'Dashboard-Einstellungen konnten nicht serialisiert werden.'];
        }

        try {
            $exists = (int) ($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?", [$optionName]) ?? 0);
            $success = $exists > 0
                ? $this->db->update('settings', ['option_value' => $optionValue, 'autoload' => 0], ['option_name' => $optionName])
                : $this->db->insert('settings', ['option_name' => $optionName, 'option_value' => $optionValue, 'autoload' => 0]) !== false;
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Dashboard-Einstellungen konnten nicht gespeichert werden.'];
        }

        if (!$success) {
            return ['success' => false, 'error' => 'Dashboard-Einstellungen konnten nicht gespeichert werden.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'dashboard.preferences.save',
            'Admin-Dashboard-Personalisierung gespeichert',
            'dashboard',
            $userId,
            [
                'visible_sections' => $visibleSections,
                'visible_work_overview_widgets' => $visibleWorkOverviewWidgets,
                'work_overview_widget_order' => $workOverviewWidgetOrder,
                'favorite_shortcuts' => $favoriteShortcuts,
                'favorite_shortcut_order' => $favoriteShortcutOrder,
            ],
            'info'
        );

        return ['success' => true, 'message' => 'Dashboard-Ansicht gespeichert.'];
    }

    private function resetDashboardPreferences(): array
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Dashboard-Einstellungen können ohne gültigen Benutzer nicht zurückgesetzt werden.'];
        }

        $optionName = $this->getDashboardPreferencesOptionName($userId);

        try {
            $exists = (int) ($this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
                [$optionName]
            ) ?? 0);
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Dashboard-Vorlage konnte nicht wiederhergestellt werden.'];
        }

        if ($exists < 1) {
            return ['success' => true, 'message' => 'Die Rollen-Vorlage ist bereits aktiv.'];
        }

        try {
            $this->db->execute(
                "DELETE FROM {$this->prefix}settings WHERE option_name = ?",
                [$optionName]
            );
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Dashboard-Vorlage konnte nicht wiederhergestellt werden.'];
        }

        $role = $this->getCurrentUserRole();
        $resolvedTemplate = $this->resolveRoleTemplateKey($role);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'dashboard.preferences.reset',
            'Admin-Dashboard-Personalisierung auf Rollen-Vorlage zurückgesetzt',
            'dashboard',
            $userId,
            [
                'role' => $role !== '' ? $role : 'admin',
                'role_template' => $resolvedTemplate['key'] ?? 'admin',
                'exact_match' => !empty($resolvedTemplate['exact_match']),
            ],
            'info'
        );

        return ['success' => true, 'message' => 'Persönliche Dashboard-Ansicht auf Rollen-Vorlage zurückgesetzt.'];
    }

    private function normalizeVisibleDashboardSections(array $selectedSections, array $availableSections): array
    {
        $availableKeys = array_keys($availableSections);
        $visible = [];

        foreach ($selectedSections as $section) {
            $section = trim((string) $section);
            if ($section !== '' && in_array($section, $availableKeys, true) && !in_array($section, $visible, true)) {
                $visible[] = $section;
            }
        }

        foreach ($availableSections as $sectionKey => $definition) {
            if (!empty($definition['required']) && !in_array($sectionKey, $visible, true)) {
                $visible[] = $sectionKey;
            }
        }

        return $visible !== [] ? $visible : $availableKeys;
    }

    private function normalizeVisibleWorkOverviewWidgets(array $selectedWidgets, array $availableWidgets, array $preferredOrder = []): array
    {
        $availableKeys = array_keys($availableWidgets);
        $selected = [];

        foreach ($selectedWidgets as $widgetKey) {
            $widgetKey = trim((string) $widgetKey);
            if ($widgetKey !== '' && in_array($widgetKey, $availableKeys, true) && !in_array($widgetKey, $selected, true)) {
                $selected[] = $widgetKey;
            }
        }

        $orderedKeys = $preferredOrder !== []
            ? $this->normalizeOrderedPreferenceKeys($preferredOrder, $availableWidgets)
            : $availableKeys;
        $visible = [];
        foreach ($orderedKeys as $widgetKey) {
            if (in_array($widgetKey, $selected, true)) {
                $visible[] = $widgetKey;
            }
        }

        return $visible;
    }

    private function normalizeFavoriteShortcuts(array $selectedShortcuts, array $availableShortcuts, array $preferredOrder = []): array
    {
        $availableKeys = array_keys($availableShortcuts);
        $selected = [];

        foreach ($selectedShortcuts as $shortcutKey) {
            $shortcutKey = trim((string) $shortcutKey);
            if ($shortcutKey !== '' && in_array($shortcutKey, $availableKeys, true) && !in_array($shortcutKey, $selected, true)) {
                $selected[] = $shortcutKey;
            }
        }

        $orderedKeys = $preferredOrder !== []
            ? $this->normalizeOrderedPreferenceKeys($preferredOrder, $availableShortcuts)
            : $availableKeys;
        $favorites = [];
        foreach ($orderedKeys as $shortcutKey) {
            if (in_array($shortcutKey, $selected, true)) {
                $favorites[] = $shortcutKey;
            }
        }

        return array_slice($favorites, 0, 8);
    }

    private function buildFavoriteShortcuts(array $favoriteShortcutKeys, array $definitions): array
    {
        $shortcuts = [];

        foreach ($favoriteShortcutKeys as $shortcutKey) {
            $shortcutKey = (string) $shortcutKey;
            if (!isset($definitions[$shortcutKey]) || !is_array($definitions[$shortcutKey])) {
                continue;
            }

            $definition = $definitions[$shortcutKey];
            $shortcuts[$shortcutKey] = [
                'label' => (string) ($definition['label'] ?? $shortcutKey),
                'description' => (string) ($definition['description'] ?? ''),
                'icon' => (string) ($definition['icon'] ?? 'settings'),
                'url' => (string) ($definition['url'] ?? '/admin'),
            ];
        }

        return $shortcuts;
    }

    private function getDashboardPreferencesOptionName(int $userId): string
    {
        return 'admin_dashboard_preferences_user_' . max(0, $userId);
    }

    private function getCurrentUserId(): int
    {
        $user = Auth::instance()->currentUser();

        return (int) ($user->id ?? $_SESSION['user_id'] ?? 0);
    }

    private function getCurrentUserRole(): string
    {
        $user = Auth::instance()->currentUser();

        return $this->normalizeRoleSlug((string) ($user->role ?? $_SESSION['user_role'] ?? ''));
    }

    private function getCurrentUserRoleLabel(string $role): string
    {
        $role = $this->normalizeRoleSlug($role);
        if ($role === '') {
            return 'Administrator';
        }

        if (function_exists('get_role')) {
            $roleObject = get_role($role);
            $displayName = trim((string) ($roleObject->display_name ?? ''));
            if ($displayName !== '') {
                return $displayName;
            }
        }

        return $this->humanizeRoleSlug($role);
    }

    private function normalizeRoleSlug(string $role): string
    {
        if (function_exists('cms_normalize_role_slug')) {
            return (string) cms_normalize_role_slug($role);
        }

        $role = strtolower(trim($role));
        if ($role === 'administrator') {
            $role = 'admin';
        }

        $role = preg_replace('/[^a-z0-9_-]+/', '-', $role) ?? '';

        return trim($role, '-_');
    }

    private function humanizeRoleSlug(string $role): string
    {
        if (function_exists('cms_humanize_role_slug')) {
            return (string) cms_humanize_role_slug($role);
        }

        $role = str_replace(['_', '-', '.'], ' ', strtolower($role));

        return ucwords(trim($role));
    }

    private function resolveRoleTemplateKey(string $role): array
    {
        $role = $this->normalizeRoleSlug($role);
        if ($role !== '' && isset(self::ROLE_TEMPLATE_DEFINITIONS[$role])) {
            return ['key' => $role, 'exact_match' => true];
        }

        $capabilities = function_exists('cms_load_role_capabilities') && $role !== ''
            ? cms_load_role_capabilities($role)
            : [];

        $hasAnyCapability = static function (array $availableCapabilities, array $needles): bool {
            foreach ($needles as $needle) {
                if (!empty($availableCapabilities[$needle])) {
                    return true;
                }
            }

            return false;
        };

        if ($hasAnyCapability($capabilities, ['manage_settings', 'manage_system', 'manage_users'])) {
            return ['key' => 'admin', 'exact_match' => false];
        }

        if ($hasAnyCapability($capabilities, ['manage_pages', 'edit_all_posts', 'comments.moderate'])) {
            return ['key' => 'editor', 'exact_match' => false];
        }

        if ($hasAnyCapability($capabilities, ['posts.create', 'edit_own_posts', 'pages.create'])) {
            return ['key' => 'author', 'exact_match' => false];
        }

        return ['key' => 'member', 'exact_match' => false];
    }

    private function getRoleTemplateContext(bool $subscriptionOrdersEnabled, array $workOverviewWidgetDefinitions, array $favoriteShortcutDefinitions): array
    {
        $sections = $this->getDashboardSections($subscriptionOrdersEnabled);
        $role = $this->getCurrentUserRole();
        $resolvedTemplate = $this->resolveRoleTemplateKey($role);
        $templateKey = (string) ($resolvedTemplate['key'] ?? 'admin');
        $definition = self::ROLE_TEMPLATE_DEFINITIONS[$templateKey] ?? self::ROLE_TEMPLATE_DEFINITIONS['admin'];

        $workOverviewWidgetOrder = $this->normalizeOrderedPreferenceKeys(
            is_array($definition['work_overview_widget_order'] ?? null)
                ? $definition['work_overview_widget_order']
                : (is_array($definition['visible_work_overview_widgets'] ?? null) ? $definition['visible_work_overview_widgets'] : array_keys($workOverviewWidgetDefinitions)),
            $workOverviewWidgetDefinitions
        );
        $visibleWorkOverviewWidgets = $this->normalizeVisibleWorkOverviewWidgets(
            is_array($definition['visible_work_overview_widgets'] ?? null)
                ? $definition['visible_work_overview_widgets']
                : $workOverviewWidgetOrder,
            $workOverviewWidgetDefinitions,
            $workOverviewWidgetOrder
        );
        if ($visibleWorkOverviewWidgets === []) {
            $visibleWorkOverviewWidgets = array_slice($workOverviewWidgetOrder, 0, min(4, count($workOverviewWidgetOrder)));
        }

        $favoriteShortcutOrder = $this->normalizeOrderedPreferenceKeys(
            is_array($definition['favorite_shortcut_order'] ?? null)
                ? $definition['favorite_shortcut_order']
                : (is_array($definition['favorite_shortcuts'] ?? null) ? $definition['favorite_shortcuts'] : array_keys($favoriteShortcutDefinitions)),
            $favoriteShortcutDefinitions
        );
        $favoriteShortcuts = $this->normalizeFavoriteShortcuts(
            is_array($definition['favorite_shortcuts'] ?? null)
                ? $definition['favorite_shortcuts']
                : self::DEFAULT_FAVORITE_SHORTCUTS,
            $favoriteShortcutDefinitions,
            $favoriteShortcutOrder
        );
        if ($favoriteShortcuts === []) {
            $favoriteShortcuts = $this->normalizeFavoriteShortcuts(self::DEFAULT_FAVORITE_SHORTCUTS, $favoriteShortcutDefinitions, $favoriteShortcutOrder);
        }

        return [
            'key' => $templateKey,
            'label' => (string) ($definition['label'] ?? $this->getCurrentUserRoleLabel($role)),
            'description' => (string) ($definition['description'] ?? ''),
            'role' => $role !== '' ? $role : 'admin',
            'role_label' => $this->getCurrentUserRoleLabel($role),
            'exact_match' => !empty($resolvedTemplate['exact_match']),
            'preferences' => [
                'visible_sections' => $this->normalizeVisibleDashboardSections(
                    is_array($definition['visible_sections'] ?? null) ? $definition['visible_sections'] : array_keys($sections),
                    $sections
                ),
                'visible_work_overview_widgets' => $visibleWorkOverviewWidgets,
                'work_overview_widget_order' => $workOverviewWidgetOrder,
                'favorite_shortcuts' => $favoriteShortcuts,
                'favorite_shortcut_order' => $favoriteShortcutOrder,
            ],
        ];
    }

    private function buildRoleTemplateViewData(array $roleTemplate): array
    {
        return [
            'key' => (string) ($roleTemplate['key'] ?? 'admin'),
            'label' => (string) ($roleTemplate['label'] ?? 'Administrator'),
            'description' => (string) ($roleTemplate['description'] ?? ''),
            'role' => (string) ($roleTemplate['role'] ?? 'admin'),
            'role_label' => (string) ($roleTemplate['role_label'] ?? 'Administrator'),
            'exact_match' => !empty($roleTemplate['exact_match']),
        ];
    }

    private function buildDashboardHealthAlerts(array $meta): array
    {
        $degradedSections = array_values(array_filter(
            is_array($meta['degraded_sections'] ?? null) ? $meta['degraded_sections'] : [],
            static fn (mixed $section): bool => is_string($section) && trim($section) !== ''
        ));

        if ($degradedSections === []) {
            return [];
        }

        $sectionLabels = [
            'users' => 'Benutzer',
            'pages' => 'Seiten',
            'posts' => 'Beiträge',
            'media' => 'Medien',
            'sessions' => 'Sessions',
            'security' => 'Sicherheit',
            'performance' => 'Performance',
            'system' => 'Systemstatus',
            'orders' => 'Bestellungen',
        ];

        $labels = array_map(
            static fn (string $section): string => $sectionLabels[$section] ?? ucfirst($section),
            $degradedSections
        );

        return [[
            'type' => 'warning',
            'message' => 'Einige Dashboard-Bereiche laufen aktuell im Fallback-Modus: ' . implode(', ', $labels) . '. Details stehen in den CMS Logs.',
            'url' => '/admin/logs',
        ]];
    }

    private function buildWorkOverviewWidgets(array $stats, int $pendingComments, bool $subscriptionOrdersEnabled, array $widgetOrder = []): array
    {
        $users = is_array($stats['users'] ?? null) ? $stats['users'] : [];
        $pages = is_array($stats['pages'] ?? null) ? $stats['pages'] : [];
        $posts = is_array($stats['posts'] ?? null) ? $stats['posts'] : [];
        $media = is_array($stats['media'] ?? null) ? $stats['media'] : [];
        $orders = is_array($stats['orders'] ?? null) ? $stats['orders'] : [];
        $sessions = is_array($stats['sessions'] ?? null) ? $stats['sessions'] : [];
        $security = is_array($stats['security'] ?? null) ? $stats['security'] : [];
        $system = is_array($stats['system'] ?? null) ? $stats['system'] : [];

        $redactionLoad = (int) (($pages['drafts'] ?? 0) + ($pages['private'] ?? 0) + ($posts['drafts'] ?? 0) + ($posts['private'] ?? 0) + ($posts['scheduled'] ?? 0));
        $widgets = [
            'users_total' => [
                'label' => 'Benutzer',
                'value' => (string) ($users['total'] ?? 0),
                'hint' => (string) (($users['active_today'] ?? 0) . ' heute aktiv'),
                'icon' => 'users',
                'url' => '/admin/users',
                'footer_label' => 'Benutzer öffnen →',
                'details' => [
                    (string) (($users['new_today'] ?? 0) . ' neu heute'),
                    (string) (($users['new_this_week'] ?? 0) . ' neu in 7 Tagen'),
                ],
            ],
            'pages_total' => [
                'label' => 'Seiten',
                'value' => (string) ($pages['total'] ?? 0),
                'hint' => (string) (($pages['published'] ?? 0) . ' veröffentlicht'),
                'icon' => 'file-text',
                'url' => '/admin/pages',
                'footer_label' => 'Seiten öffnen →',
                'details' => [
                    (string) (($pages['drafts'] ?? 0) . ' Entwürfe'),
                    (string) (($pages['private'] ?? 0) . ' privat'),
                ],
            ],
            'posts_total' => [
                'label' => 'Beiträge',
                'value' => (string) ($posts['total'] ?? 0),
                'hint' => (string) (($posts['published'] ?? 0) . ' sichtbar · ' . ($posts['scheduled'] ?? 0) . ' geplant'),
                'icon' => 'article',
                'url' => '/admin/posts',
                'footer_label' => 'Beiträge öffnen →',
                'details' => [
                    (string) (($posts['drafts'] ?? 0) . ' Entwürfe'),
                    (string) (($posts['private'] ?? 0) . ' privat'),
                ],
            ],
            'media_total' => [
                'label' => 'Medien',
                'value' => (string) ($media['total_files'] ?? 0),
                'hint' => (string) ($media['total_size_formatted'] ?? $this->formatBytes((int) ($media['total_size'] ?? 0))),
                'icon' => 'photo',
                'url' => '/admin/media',
                'footer_label' => 'Medien öffnen →',
                'details' => [
                    (string) (($media['types']['images'] ?? 0) . ' Bilder'),
                    (string) (($media['types']['documents'] ?? 0) . ' Dokumente'),
                ],
            ],
            'user_growth' => [
                'label' => 'Nutzerwachstum',
                'value' => (string) ($users['new_this_month'] ?? 0),
                'hint' => 'neue Konten in 30 Tagen',
                'icon' => 'users',
                'url' => '/admin/users',
                'footer_label' => 'Wachstum prüfen →',
                'details' => [
                    (string) (($users['new_today'] ?? 0) . ' neu heute'),
                    (string) (($users['growth_rate'] ?? 0) . '% Anteil am Bestand'),
                ],
            ],
            'content_pipeline' => [
                'label' => 'Redaktions-Pipeline',
                'value' => (string) $redactionLoad,
                'hint' => 'Inhalte warten auf Review oder Veröffentlichung',
                'icon' => 'activity',
                'url' => '/admin/posts',
                'footer_label' => 'Pipeline öffnen →',
                'details' => [
                    (string) ((($pages['drafts'] ?? 0) + ($pages['private'] ?? 0)) . ' Seiten mit Handlungsbedarf'),
                    (string) ((($posts['drafts'] ?? 0) + ($posts['scheduled'] ?? 0) + ($posts['private'] ?? 0)) . ' Beiträge mit Handlungsbedarf'),
                ],
            ],
            'comment_queue' => [
                'label' => 'Kommentar-Moderation',
                'value' => (string) $pendingComments,
                'hint' => $pendingComments > 0 ? 'Kommentare warten auf Freigabe' : 'Aktuell keine offenen Kommentare',
                'icon' => 'message-circle',
                'url' => '/admin/comments',
                'footer_label' => 'Moderation öffnen →',
                'details' => [
                    $pendingComments > 0 ? 'Direkt in die Kommentarmoderation springen' : 'Kommentarbereich bleibt erreichbar',
                ],
            ],
            'sessions_live' => [
                'label' => 'Aktive Sessions',
                'value' => (string) ($sessions['active_now'] ?? 0),
                'hint' => (string) (($sessions['today'] ?? 0) . ' Sessions heute'),
                'icon' => 'activity',
                'url' => '/admin/analytics',
                'footer_label' => 'Nutzung ansehen →',
                'details' => [
                    (string) (($sessions['avg_duration'] ?? 0) . ' Minuten Ø-Dauer'),
                    (string) (($sessions['total'] ?? 0) . ' Sessions gesamt'),
                ],
            ],
            'security_snapshot' => [
                'label' => 'Security Snapshot',
                'value' => (string) (($security['security_score'] ?? 0) . '/100'),
                'hint' => !empty($security['https_enabled']) ? 'HTTPS aktiv' : 'HTTPS prüfen',
                'icon' => 'shield-check',
                'url' => '/admin/security-audit',
                'footer_label' => 'Security prüfen →',
                'details' => [
                    (string) (($security['failed_logins_24h'] ?? 0) . ' Fehlversuche / 24h'),
                    'Status: ' . (string) ($security['status'] ?? 'unbekannt'),
                ],
            ],
            'system_stack' => [
                'label' => 'System-Stack',
                'value' => 'CMS ' . (string) ($system['cms_version'] ?? '–'),
                'hint' => 'PHP ' . (string) ($system['php_version'] ?? '–') . ' · MySQL ' . (string) ($system['mysql_version'] ?? '–'),
                'icon' => 'server',
                'url' => '/admin/settings',
                'footer_label' => 'Systemdetails →',
                'details' => [
                    'Upload-Limit: ' . (string) ($system['upload_max_filesize'] ?? '–'),
                    'Post-Limit: ' . (string) ($system['post_max_size'] ?? '–'),
                ],
            ],
        ];

        if ($subscriptionOrdersEnabled) {
            $widgets['orders_revenue'] = [
                'label' => 'Umsatz (30T)',
                'value' => (string) ($orders['month_revenue_formatted'] ?? '0,00 EUR'),
                'hint' => (string) (($orders['pending'] ?? 0) . ' Bestellungen offen'),
                'icon' => 'shopping-cart',
                'url' => '/admin/orders',
                'footer_label' => 'Bestellungen öffnen →',
                'details' => [
                    (string) (($orders['total'] ?? 0) . ' Bestellungen gesamt'),
                    'Letzte 30 Tage im Blick',
                ],
            ];
        }

        $orderedWidgets = [];
        $orderedWidgetKeys = $this->normalizeOrderedPreferenceKeys($widgetOrder, $this->getWorkOverviewWidgetDefinitions($subscriptionOrdersEnabled));
        foreach ($orderedWidgetKeys as $widgetKey) {
            if (isset($widgets[$widgetKey])) {
                $orderedWidgets[$widgetKey] = $widgets[$widgetKey];
            }
        }

        return $this->filterAdminLinks($orderedWidgets, false);
    }

    private function getPendingCommentsCount(): int
    {
        try {
            return (int) ($this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}comments WHERE status = 'pending'"
            ) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function isSubscriptionSystemEnabled(): bool
    {
        return CoreModuleService::getInstance()->isModuleEnabled('subscriptions');
    }

    private function isSubscriptionOrdersEnabled(): bool
    {
        return CoreModuleService::getInstance()->isModuleEnabled('subscription_admin_orders');
    }

    /**
     * Kopfbereich für die Admin-Startseite.
     */
    private function getWelcomeData(array $system): array
    {
        $hour = (int) date('G');
        $greeting = match (true) {
            $hour < 11 => 'Guten Morgen',
            $hour < 17 => 'Guten Tag',
            default => 'Guten Abend',
        };

        $displayName = 'im Admin';
        if (!empty($_SESSION['user_display_name'])) {
            $displayName = (string) $_SESSION['user_display_name'];
        } elseif (!empty($_SESSION['username'])) {
            $displayName = (string) $_SESSION['username'];
        }

        return [
            'greeting' => $greeting,
            'display_name' => $displayName,
            'date_label' => date('d.m.Y'),
            'time_label' => date('H:i'),
            'server_time' => (string) ($system['server_time'] ?? date('Y-m-d H:i:s')),
        ];
    }

    /**
     * KPI-Karten aufbereiten
     */
    private function buildKpis(array $stats, bool $subscriptionEnabled): array
    {
        $users = $stats['users'] ?? [];
        $pages = $stats['pages'] ?? [];
        $posts = $stats['posts'] ?? [];
        $media = $stats['media'] ?? [];
        $orders = $stats['orders'] ?? [];

        $kpis = [
            [
                'label'  => 'Benutzer',
                'value'  => $users['total'] ?? 0,
                'sub'    => ($users['active_today'] ?? 0) . ' heute aktiv',
                'color'  => 'blue',
                'icon'   => 'users',
                'url'    => '/admin/users',
            ],
            [
                'label'  => 'Seiten',
                'value'  => $pages['total'] ?? 0,
                'sub'    => ($pages['published'] ?? 0) . ' veröffentlicht',
                'color'  => 'green',
                'icon'   => 'file-text',
                'url'    => '/admin/pages',
            ],
            [
                'label'  => 'Beiträge',
                'value'  => $posts['total'] ?? 0,
                'sub'    => ($posts['published'] ?? 0) . ' sichtbar · ' . ($posts['scheduled'] ?? 0) . ' geplant',
                'color'  => 'azure',
                'icon'   => 'article',
                'url'    => '/admin/posts',
            ],
            [
                'label'  => 'Medien',
                'value'  => $media['total_files'] ?? 0,
                'sub'    => $this->formatBytes($media['total_size'] ?? 0),
                'color'  => 'purple',
                'icon'   => 'photo',
                'url'    => '/admin/media',
            ],
        ];

        if ($subscriptionEnabled) {
            $kpis[] = [
                'label'  => 'Umsatz (30T)',
                'value'  => $orders['month_revenue_formatted'] ?? '0,00 EUR',
                'sub'    => ($orders['pending'] ?? 0) . ' ausstehend',
                'color'  => 'yellow',
                'icon'   => 'currency-euro',
                'url'    => '/admin/orders',
            ];
        }

        return $kpis;
    }

    /**
     * Letzte Aktivitäten laden
     */
    private function getRecentActivity(): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT action, description AS details, user_id, created_at
                 FROM {$this->prefix}audit_log
                 ORDER BY created_at DESC
                 LIMIT 8"
            );
            return $rows ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Schnellzugriffe
     */
    private function getQuickLinks(): array
    {
        return [
            ['label' => 'Neue Seite',       'url' => '/admin/pages?action=new',  'icon' => 'file-plus',    'color' => 'blue'],
            ['label' => 'Neuer Beitrag',     'url' => '/admin/posts?action=new',  'icon' => 'pencil-plus',  'color' => 'green'],
            ['label' => 'Medien hochladen',  'url' => '/admin/media',             'icon' => 'upload',       'color' => 'purple'],
            ['label' => 'Einstellungen',     'url' => '/admin/settings',          'icon' => 'settings',     'color' => 'orange'],
        ];
    }

    /**
     * Aufmerksamkeits-Hinweise
     */
    private function getAlerts(array $stats, ?int $pendingComments = null): array
    {
        $alerts = [];

        // Offene Kommentare
        $pendingComments ??= $this->getPendingCommentsCount();
        if ($pendingComments > 0) {
            $alerts[] = [
                'type'    => 'warning',
                'message' => $pendingComments . ' Kommentar(e) warten auf Freigabe',
                'url'     => '/admin/comments',
            ];
        }

        // Security-Warnungen
        $security = $stats['security'] ?? [];
        if (!empty($security['failed_logins_24h']) && $security['failed_logins_24h'] > 10) {
            $alerts[] = [
                'type'    => 'danger',
                'message' => $security['failed_logins_24h'] . ' fehlgeschlagene Logins in den letzten 24 Stunden',
                'url'     => '/admin/security-audit',
            ];
        }

        return $alerts;
    }

    /**
     * Bytes formatieren
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
