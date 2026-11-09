<?php
declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Navigationsmodell der Admin-Seitenleiste – reine Darstellungsschicht.
 *
 * Liest die Core-Menüpunkte sowie die bereits registrierten Plugin-Menüs
 * (Ergebnis von get_registered_admin_menus()), gruppiert sie, filtert Plugin-Menüs
 * nach der Plugin-Zuweisung der aktuellen Site und markiert den aktiven Eintrag.
 * Die Registrierung (Hooks, add_menu_page/add_submenu_page) wird weder ausgelöst
 * noch verändert – die Daten werden ausschließlich gelesen.
 */
final class AdminNavigationService
{
    public const DEFAULT_EXPANDED_GROUPS = 2;
    public const DEFAULT_PLUGIN_ICON = 'puzzle';
    public const PLUGIN_GROUP_ID = 'g:plugins';

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public static function normalizeActivePage(string $activePage): string
    {
        return match (trim($activePage)) {
            'theme-settings' => 'settings',
            'system-info' => 'info',
            'support' => 'documentation',
            'privacy-requests', 'deletion-requests' => 'data-requests',
            default => trim($activePage),
        };
    }

    /**
     * @param array<int|string, mixed> $registeredPluginMenus Unverändertes Ergebnis von get_registered_admin_menus()
     * @param array{site_url?:string,request_path?:string,site_key?:string,site_label?:string,group_states?:array<string,bool>,marketplace_enabled?:bool,logs_badge?:int,page_title?:string} $context
     * @return array{groups:list<array<string,mixed>>,breadcrumb:array{section:string,page:string},recent_label:string,plugins_total:int,plugins_hidden:int}
     */
    public function build(string $activePage, array $registeredPluginMenus, array $context = []): array
    {
        $activePage = self::normalizeActivePage($activePage);
        $siteUrl = rtrim((string) ($context['site_url'] ?? ''), '/');
        $requestPath = $this->normalizePath((string) ($context['request_path'] ?? ''));
        $groupStates = is_array($context['group_states'] ?? null) ? $context['group_states'] : [];
        $siteKey = (string) ($context['site_key'] ?? '');
        $siteLabel = (string) ($context['site_label'] ?? $siteKey);

        $coreGroups = $this->buildCoreGroups(
            $siteUrl,
            (bool) ($context['marketplace_enabled'] ?? true),
            max(0, (int) ($context['logs_badge'] ?? 0))
        );
        $adminGroup = array_pop($coreGroups);

        $pluginTotal = 0;
        $pluginHidden = 0;
        $pluginEntries = $this->buildPluginEntries($registeredPluginMenus, $siteUrl, $siteKey, $pluginTotal, $pluginHidden);

        $groups = $coreGroups;
        if ($pluginTotal > 0) {
            $groups[] = [
                'id' => self::PLUGIN_GROUP_ID,
                'label' => 'Plugins',
                'entries' => $pluginEntries,
                'hint' => $pluginEntries === [] ? [
                    'text' => sprintf('Für %s sind keine Plugins mit Menüeinträgen zugewiesen.', $siteLabel !== '' ? $siteLabel : 'diese Site'),
                    'url' => $siteUrl . PluginSiteAssignmentService::ADMIN_PATH,
                    'link' => 'Plugin-Zuweisung öffnen',
                ] : null,
            ];
        }
        $groups[] = $adminGroup;

        $groups = array_values(array_filter(
            $groups,
            static fn (array $group): bool => $group['entries'] !== [] || ($group['hint'] ?? null) !== null
        ));

        $pluginGroupIndex = null;
        foreach ($groups as $index => $group) {
            if ($group['id'] === self::PLUGIN_GROUP_ID) {
                $pluginGroupIndex = $index;
            }
        }

        // Aktiven Eintrag bestimmen: 1. Plugin-Seite per Pfad, 2. Core per Slug, 3. Plugin per Slug (Altverhalten).
        $found = false;
        if ($pluginGroupIndex !== null && $requestPath !== '') {
            $found = $this->markCurrent($groups[$pluginGroupIndex], static fn (array $item): bool => ($item['path'] ?? '') !== '' && $item['path'] === $requestPath);
        }
        if (!$found && $activePage !== '') {
            foreach ($groups as $index => $group) {
                if ($index !== $pluginGroupIndex && $this->markCurrent($groups[$index], static fn (array $item): bool => ($item['slug'] ?? null) === $activePage)) {
                    $found = true;
                    break;
                }
            }
        }
        if (!$found && $pluginGroupIndex !== null && $activePage !== '') {
            $this->markCurrent($groups[$pluginGroupIndex], static fn (array $item): bool => ($item['slug'] ?? null) === $activePage);
        }

        $breadcrumb = ['section' => 'Dashboard', 'page' => trim((string) ($context['page_title'] ?? '')) ?: 'Übersicht'];
        $recentLabel = '';

        foreach ($groups as $groupIndex => &$group) {
            $group['dom_id'] = $this->domId($group['id']);
            $group['active'] = false;

            foreach ($group['entries'] as &$entry) {
                $entry['dom_id'] = $this->domId($entry['id']);
                $entry['active'] = !empty($entry['current']);
                foreach ($entry['children'] as $child) {
                    if (!empty($child['current'])) {
                        $entry['active'] = true;
                        $breadcrumb = ['section' => $entry['label'], 'page' => $child['label']];
                        $recentLabel = $entry['label'] !== $child['label'] ? $entry['label'] . ' · ' . $child['label'] : $child['label'];
                    }
                }
                if (!empty($entry['current'])) {
                    $breadcrumb = ['section' => $group['label'], 'page' => $entry['label']];
                    $recentLabel = $entry['label'];
                }

                $storedEntry = $groupStates[$entry['id']] ?? null;
                $entry['expanded'] = $entry['children'] !== [] && ($entry['active'] || $storedEntry === true);
                $group['active'] = $group['active'] || $entry['active'];
            }
            unset($entry);

            $storedGroup = $groupStates[$group['id']] ?? null;
            $group['expanded'] = $group['active']
                || (is_bool($storedGroup) ? $storedGroup : $groupIndex < self::DEFAULT_EXPANDED_GROUPS);
        }
        unset($group);

        return [
            'groups' => $groups,
            'breadcrumb' => $breadcrumb,
            'recent_label' => $recentLabel,
            'plugins_total' => $pluginTotal,
            'plugins_hidden' => $pluginHidden,
        ];
    }

    /**
     * Markiert den ersten passenden Link (Eintrag oder Unterpunkt) einer Gruppe als aktuelle Seite.
     *
     * @param array<string, mixed> $group
     * @param callable(array<string, mixed>): bool $matches
     */
    private function markCurrent(array &$group, callable $matches): bool
    {
        foreach ($group['entries'] as &$entry) {
            foreach ($entry['children'] as &$child) {
                if ($matches($child)) {
                    $child['current'] = true;

                    return true;
                }
            }
            unset($child);

            if (($entry['url'] ?? null) !== null && $entry['children'] === [] && $matches($entry)) {
                $entry['current'] = true;

                return true;
            }
        }
        unset($entry);

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildCoreGroups(string $siteUrl, bool $marketplaceEnabled, int $logsBadge): array
    {
        $links = static function (array $rows) use ($siteUrl): array {
            $children = [];
            foreach ($rows as [$label, $slug, $path]) {
                $children[] = ['label' => $label, 'slug' => $slug, 'url' => $siteUrl . $path, 'current' => false];
            }

            return $children;
        };

        $themeRows = [
            ['Theme-Verwaltung', 'themes', '/admin/themes'],
            ['Theme-Editor', 'theme-editor', '/admin/theme-editor'],
            ['Theme-Explorer', 'theme-explorer', '/admin/theme-explorer'],
            ['Theme-Menü', 'menu-editor', '/admin/menu-editor'],
            ['Landingpage', 'landing-page', '/admin/landing-page'],
            ['Schriftverwaltung', 'font-manager', '/admin/font-manager'],
            ['CMS-Loginseite', 'cms-loginpage', '/admin/cms-loginpage'],
        ];
        if ($marketplaceEnabled) {
            $themeRows[] = ['Theme-Marktplatz', 'theme-marketplace', '/admin/theme-marketplace'];
        }

        $pluginAdminRows = [['Plugins verwalten', 'plugins', '/admin/plugins']];
        if ($marketplaceEnabled) {
            $pluginAdminRows[] = ['Marketplace', 'plugin-marketplace', '/admin/plugin-marketplace'];
        }
        $pluginAdminRows[] = ['Plugin-Zuweisung', 'plugin-assignment', PluginSiteAssignmentService::ADMIN_PATH];

        $groups = [
            ['id' => 'g:overview', 'label' => 'Übersicht', 'entries' => [
                $this->coreEntry('dashboard', 'Dashboard', 'layout-dashboard', [], ['slug' => 'dashboard', 'url' => $siteUrl . '/admin']),
                $this->coreEntry('ai-services', 'KI-Dienste', 'sparkles', $this->filterModule('ai_services', $links([
                    ['KI-Dashboard', 'ai-services', '/admin/ai-services'],
                    ['Übersetzung', 'ai-translation', '/admin/ai-translation'],
                    ['Inhaltsassistent', 'ai-content-creator', '/admin/ai-content-creator'],
                    ['SEO-Assistent', 'ai-seo-creator', '/admin/ai-seo-creator'],
                    ['Einstellungen', 'ai-settings', '/admin/ai-settings'],
                ]))),
            ]],
            ['id' => 'g:content', 'label' => 'Inhalte', 'entries' => [
                $this->coreEntry('pages-posts', 'Seiten & Beiträge', 'file-text', $links([
                    ['Seiten', 'pages', '/admin/pages'],
                    ['Beiträge', 'posts', '/admin/posts'],
                    ['Kategorien', 'post-categories', '/admin/post-categories'],
                    ['Tags', 'post-tags', '/admin/post-tags'],
                    ['Kommentare', 'comments', '/admin/comments'],
                    ['Inhaltsverzeichnis', 'table-of-contents', '/admin/table-of-contents'],
                    ['Hub-Sites', 'hub-sites', '/admin/hub-sites'],
                    ['Tabellen', 'site-tables', '/admin/site-tables'],
                    ['Einstellungen', 'content-settings', '/admin/settings?tab=content'],
                ])),
                $this->coreEntry('media', 'Medienverwaltung', 'photo', $links([
                    ['Medien', 'media', '/admin/media'],
                    ['Beitrags & Site Medien', 'media-featured', '/admin/media?tab=featured'],
                    ['Medien Check', 'media-check', '/admin/media?tab=check'],
                    ['Kategorien', 'media-categories', '/admin/media?tab=categories'],
                    ['Einstellungen', 'media-settings', '/admin/media?tab=settings'],
                ])),
            ]],
            ['id' => 'g:users', 'label' => 'Benutzer', 'entries' => [
                $this->coreEntry('users', 'Benutzer & Gruppen', 'users', $links([
                    ['Benutzer', 'users', '/admin/users'],
                    ['Gruppen', 'groups', '/admin/groups'],
                    ['Rollen & Rechte', 'roles', '/admin/roles'],
                    ['Einstellungen', 'user-settings', '/admin/user-settings'],
                ])),
                $this->coreEntry('member-dashboard', 'Mitglieder-Dashboard', 'id-badge-2', $this->filterModule('member_dashboard', $links([
                    ['Übersicht', 'member-dashboard', '/admin/member-dashboard'],
                    ['Allgemein', 'member-dashboard-general', '/admin/member-dashboard-general'],
                    ['Design & Farben', 'member-dashboard-design', '/admin/member-dashboard-design'],
                    ['Frontend-Module', 'member-dashboard-frontend-modules', '/admin/member-dashboard-frontend-modules'],
                    ['Dashboard Widgets', 'member-dashboard-widgets', '/admin/member-dashboard-widgets'],
                    ['Plugin-Widgets', 'member-dashboard-plugin-widgets', '/admin/member-dashboard-plugin-widgets'],
                    ['Profil-Felder', 'member-dashboard-profile-fields', '/admin/member-dashboard-profile-fields'],
                    ['Benachrichtigungen', 'member-dashboard-notifications', '/admin/member-dashboard-notifications'],
                    ['Mitglieder-Onboarding', 'member-dashboard-onboarding', '/admin/member-dashboard-onboarding'],
                ]))),
                $this->coreEntry('subscriptions', 'Aboverwaltung', 'credit-card', $this->filterModule('subscriptions', $links([
                    ['Pakete & Abo-Einstellungen', 'packages', '/admin/packages'],
                    ['Bestellungen & Zuweisung', 'orders', '/admin/orders'],
                    ['Einstellungen', 'subscription-settings', '/admin/subscription-settings'],
                ]))),
            ]],
            ['id' => 'g:design', 'label' => 'Gestaltung & Marketing', 'entries' => [
                $this->coreEntry('themes', 'Themes & Gestaltung', 'palette', $this->filterModule('themes', $links($themeRows))),
                $this->coreEntry('seo', 'SEO', 'chart-line', $this->filterModule('seo', $links([
                    ['SEO-Dashboard', 'seo-dashboard', '/admin/seo-dashboard'],
                    ['Analysen', 'analytics', '/admin/analytics'],
                    ['SEO-Prüfung', 'seo-audit', '/admin/seo-audit'],
                    ['Meta-Daten', 'seo-meta', '/admin/seo-meta'],
                    ['Soziale Medien', 'seo-social', '/admin/seo-social'],
                    ['Strukturierte Daten', 'seo-schema', '/admin/seo-schema'],
                    ['Sitemap & robots.txt', 'seo-sitemap', '/admin/seo-sitemap'],
                    ['Suchmaschinen', 'seo-search-engines', '/admin/seo-search-engines'],
                    ['Technisches SEO', 'seo-technical', '/admin/seo-technical'],
                    ['Weiterleitungen', 'redirect-manager', '/admin/redirect-manager'],
                    ['404-Monitor', 'not-found-monitor', '/admin/not-found-monitor'],
                ]))),
                $this->coreEntry('performance', 'Performance', 'gauge', $this->filterModule('performance', $links([
                    ['Übersicht', 'performance', '/admin/performance'],
                    ['Cache-Verwaltung', 'performance-cache', '/admin/performance-cache'],
                    ['Medien-Optimierung', 'performance-media', '/admin/performance-media'],
                    ['Datenbank-Wartung', 'performance-database', '/admin/performance-database'],
                    ['Performance-Einstellungen', 'performance-settings', '/admin/performance-settings'],
                    ['Session-Verwaltung', 'performance-sessions', '/admin/performance-sessions'],
                ]))),
            ]],
            ['id' => 'g:security', 'label' => 'Sicherheit & Recht', 'entries' => [
                $this->coreEntry('legal', 'Recht', 'scale', $this->filterModule('legal', $links([
                    ['Rechtsseiten', 'legal-sites', '/admin/legal-sites'],
                    ['Cookie-Manager', 'cookie-manager', '/admin/cookie-manager'],
                    ['Auskunft & Löschen', 'data-requests', '/admin/data-requests'],
                ]))),
                $this->coreEntry('security', 'Sicherheit', 'shield', $this->filterModule('security', $links([
                    ['Anti-Spam', 'antispam', '/admin/antispam'],
                    ['Firewall', 'firewall', '/admin/firewall'],
                    ['Audit', 'security-audit', '/admin/security-audit'],
                ]))),
                $this->coreEntry('logs-audit', 'Protokolle & Audit', 'clipboard-list', $links([
                    ['Übersicht', 'logs-overview', '/admin/logs'],
                    ['Operativer Log', 'logs-operational', '/admin/logs/operational'],
                    ['Sicherheits-Audit', 'logs-security-audit', '/admin/logs/security-audit'],
                    ['PHP-Fehlerlog', 'logs-php-errors', '/admin/logs/php-errors'],
                    ['Kanal-Logs & Update-Historie', 'logs-channels', '/admin/logs/channels'],
                ]), $logsBadge > 0 ? ['badge' => [
                    'label' => (string) $logsBadge,
                    'sr' => $logsBadge === 1 ? '1 ungelesener Hinweis' : $logsBadge . ' ungelesene Hinweise',
                ]] : []),
            ]],
            ['id' => 'g:admin', 'label' => 'Verwaltung', 'entries' => [
                $this->coreEntry('plugin-admin', 'Plugins', 'plug', $links($pluginAdminRows)),
                $this->coreEntry('system', 'System & Dokumentation', 'settings', $this->filterModule('system', $links([
                    ['Einstellungen', 'settings', '/admin/settings'],
                    ['Mail & Azure OAuth2', 'mail-settings', '/admin/mail-settings'],
                    ['Module', 'modules', '/admin/modules'],
                    ['Backup & Restore', 'backups', '/admin/backups'],
                    ['Updates', 'updates', '/admin/updates'],
                    ['Dokumentation', 'documentation', '/admin/documentation'],
                ]))),
                $this->coreEntry('diagnose', 'Diagnose', 'stethoscope', $links([
                    ['Übersicht', 'info', '/admin/info'],
                    ['Datenbank', 'diagnose', '/admin/diagnose'],
                    ['Assets', 'monitor-assets', '/admin/monitor-assets'],
                    ['Antwortzeit-Monitoring', 'monitor-response-time', '/admin/monitor-response-time'],
                    ['Cron-Job-Status', 'monitor-cron-status', '/admin/monitor-cron-status'],
                    ['Speichernutzung', 'monitor-disk-usage', '/admin/monitor-disk-usage'],
                    ['Geplante Aufgaben', 'monitor-scheduled-tasks', '/admin/monitor-scheduled-tasks'],
                    ['Systemprüfung', 'monitor-health-check', '/admin/monitor-health-check'],
                    ['E-Mail-Benachrichtigungen', 'monitor-email-alerts', '/admin/monitor-email-alerts'],
                ])),
            ]],
        ];

        foreach ($groups as &$group) {
            $group['hint'] = null;
            $group['entries'] = array_values(array_filter(
                $group['entries'],
                static fn (array $entry): bool => $entry['children'] !== [] || $entry['url'] !== null
            ));
        }
        unset($group);

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function coreEntry(string $key, string $label, string $icon, array $children, array $extra = []): array
    {
        return array_merge([
            'id' => 'e:' . $key,
            'label' => $label,
            'prefix' => '',
            'name' => $label,
            'slug' => null,
            'url' => null,
            'path' => '',
            'icon' => ['type' => 'tabler', 'name' => $icon],
            'children' => $children,
            'current' => false,
            'badge' => null,
            'plugin' => false,
        ], $extra);
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return list<array<string, mixed>>
     */
    private function filterModule(string $module, array $children): array
    {
        try {
            if (class_exists(CoreModuleService::class)) {
                return array_values(CoreModuleService::getInstance()->filterSidebarChildren($module, $children));
            }
        } catch (\Throwable) {
        }

        return $children;
    }

    /**
     * @param array<int|string, mixed> $menus
     * @return list<array<string, mixed>>
     */
    private function buildPluginEntries(array $menus, string $siteUrl, string $siteKey, int &$total, int &$hidden): array
    {
        $assignments = null;
        try {
            $assignments = PluginSiteAssignmentService::getInstance();
        } catch (\Throwable) {
            $assignments = null;
        }

        $entries = [];
        foreach ($menus as $menu) {
            if (!is_array($menu) || !empty($menu['hidden']) || empty($menu['menu_slug'])) {
                continue;
            }

            $total++;

            if ($assignments !== null && $siteKey !== '') {
                try {
                    $pluginSlug = $assignments->resolveMenuPluginSlug($menu);
                    if ($pluginSlug !== null && !$assignments->isAssigned($pluginSlug, $siteKey)) {
                        $hidden++;
                        continue;
                    }
                } catch (\Throwable) {
                    // Zuordnung nicht ermittelbar → Eintrag bleibt sichtbar.
                }
            }

            $menuSlug = (string) $menu['menu_slug'];
            $label = $this->plainLabel((string) ($menu['menu_title'] ?? ''), $menuSlug);
            $basePath = '/admin/plugins/' . rawurlencode($menuSlug) . '/';
            $mainPath = $basePath . rawurlencode($menuSlug);

            $children = [];
            $hasOverview = false;
            foreach ((array) ($menu['children'] ?? []) as $child) {
                if (!is_array($child) || empty($child['menu_slug'])) {
                    continue;
                }
                $childSlug = (string) $child['menu_slug'];
                $hasOverview = $hasOverview || $childSlug === $menuSlug;
                $childPath = $basePath . rawurlencode($childSlug);
                $children[] = [
                    'label' => $this->plainLabel((string) ($child['menu_title'] ?? ''), $childSlug),
                    'slug' => $childSlug,
                    'path' => $childPath,
                    'url' => $siteUrl . $childPath,
                    'current' => false,
                ];
            }

            if ($children !== [] && !$hasOverview) {
                array_unshift($children, [
                    'label' => 'Übersicht',
                    'slug' => $menuSlug,
                    'path' => $mainPath,
                    'url' => $siteUrl . $mainPath,
                    'current' => false,
                ]);
            }

            $parts = $this->splitPluginTitle($label);
            $entries[] = [
                'id' => 'p:' . $this->idFragment($menuSlug),
                'label' => $label,
                'prefix' => $parts['prefix'],
                'name' => $parts['name'],
                'slug' => $menuSlug,
                'url' => $children === [] ? $siteUrl . $mainPath : null,
                'path' => $children === [] ? $mainPath : '',
                'icon' => $this->pluginIcon((string) ($menu['icon_url'] ?? ''), $siteUrl),
                'children' => $children,
                'current' => false,
                'badge' => null,
                'plugin' => true,
            ];
        }

        usort($entries, function (array $left, array $right): int {
            $compare = strnatcasecmp($this->sortKey($left['label']), $this->sortKey($right['label']));

            return $compare !== 0 ? $compare : strnatcasecmp((string) $left['slug'], (string) $right['slug']);
        });

        return $entries;
    }

    /**
     * Plugin-Icon aus icon_url: Tabler-Klasse, Bild-URL (same origin), Inline-SVG (als data-URI-Bild)
     * oder kurzes Zeichen (Emoji). Alles andere → neutrales Standard-Icon.
     *
     * @return array{type:string,name?:string,src?:string,text?:string}
     */
    private function pluginIcon(string $icon, string $siteUrl): array
    {
        $icon = trim($icon);
        $default = ['type' => 'tabler', 'name' => self::DEFAULT_PLUGIN_ICON];
        if ($icon === '') {
            return $default;
        }

        if (preg_match('/^(?:ti\s+)?ti-([a-z0-9-]{1,60})$/', $icon, $match) === 1) {
            return ['type' => 'tabler', 'name' => $match[1]];
        }

        if (str_starts_with($icon, '<')) {
            if (preg_match('/^<svg[\s>]/i', $icon) === 1 && strlen($icon) <= 20000) {
                return ['type' => 'image', 'src' => 'data:image/svg+xml;base64,' . base64_encode($icon)];
            }
            if (preg_match('/class\s*=\s*["\'][^"\']*\bti-([a-z0-9-]{1,60})\b/i', $icon, $match) === 1) {
                return ['type' => 'tabler', 'name' => strtolower($match[1])];
            }

            return $default;
        }

        if (preg_match('~^data:image/(?:png|gif|webp|jpeg|svg\+xml);base64,[A-Za-z0-9+/=]+$~', $icon) === 1) {
            return ['type' => 'image', 'src' => $icon];
        }

        if ((str_starts_with($icon, '/') && !str_starts_with($icon, '//'))
            || ($siteUrl !== '' && str_starts_with($icon, $siteUrl . '/'))) {
            return ['type' => 'image', 'src' => $icon];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($icon, 'UTF-8') : strlen($icon);
        if ($length <= 4 && preg_match('/[\s<>"\'&]/', $icon) !== 1 && preg_match('~^(?:https?:)?//~i', $icon) !== 1) {
            return ['type' => 'text', 'text' => $icon];
        }

        return $default;
    }

    /**
     * @return array{prefix:string,name:string}
     */
    private function splitPluginTitle(string $title): array
    {
        if (preg_match('/^\s*([^|]{1,16}?)\s*\|\s*(\S.*)$/u', $title, $matches) === 1) {
            return ['prefix' => trim($matches[1]), 'name' => trim($matches[2])];
        }

        return ['prefix' => '', 'name' => $title];
    }

    private function plainLabel(string $label, string $fallback): string
    {
        $label = html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = trim((string) (preg_replace('/\s+/u', ' ', $label) ?? $label));

        return $label !== '' ? $label : $fallback;
    }

    private function sortKey(string $label): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    }

    private function idFragment(string $value): string
    {
        $fragment = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), '-');

        return substr($fragment !== '' ? $fragment : substr(sha1($value), 0, 12), 0, 100);
    }

    private function domId(string $id): string
    {
        return 'cms-nav-' . trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $id), '-');
    }

    private function normalizePath(string $path): string
    {
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $path = '/' . ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : $path;
    }
}
