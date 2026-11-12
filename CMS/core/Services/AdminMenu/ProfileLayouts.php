<?php
/**
 * Deklarative Layouts der Profile „classic“ und „structured“.
 *
 * Die Bäume entsprechen DOC/workflow/specs/ADMIN-MENU-PROFILE.md („Finale Struktur (verbindlich)“).
 * Ein Layout ordnet nur an: Abschnitts-Trenner, direkte Einträge und Gruppen (Menü oder Kachelseite)
 * mit Eintrags-IDs aus der Registry. Routen, Capabilities und Badges kommen immer aus der Registry.
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileLayouts
{
    public const CLASSIC = 'classic';
    public const STRUCTURED = 'structured';
    public const CUSTOM = 'custom';

    public const PROFILES = [self::CLASSIC, self::STRUCTURED];
    public const ALL_PROFILES = [self::CLASSIC, self::STRUCTURED, self::CUSTOM];

    /** Bezeichnung und Kurzbeschreibung je Profil (Einstellungen → Admin-Menü und Installer). */
    public const LABELS = [
        self::CLASSIC => 'Klassisch',
        self::STRUCTURED => 'Strukturiert',
        self::CUSTOM => 'Eigenes Layout',
    ];
    public const DESCRIPTIONS = [
        self::CLASSIC => 'Vertraut wie bei WordPress: Inhalte und Arbeitsbereiche oben, Gestaltung und Verwaltung darunter. Einstellungen und Website-Zustand sind Kachelseiten.',
        self::STRUCTURED => 'Nach Aufgaben gegliedert: Inhalt, Medien, Redaktion, Struktur, Design und Personen. Konfiguration und Berichte sind Kachelseiten.',
        self::CUSTOM => 'Im Menü-Editor zusammengestellt: eigene Gruppen, Reihenfolge, Bezeichnungen, Icons und ausgeblendete Einträge.',
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @var array<string, array<string, string>> */
    private static array $groupOfCache = [];

    public static function isProfile(string $profile): bool
    {
        return in_array($profile, self::ALL_PROFILES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $profile): array
    {
        $profile = $profile === self::STRUCTURED ? self::STRUCTURED : self::CLASSIC;

        return self::$cache[$profile] ??= $profile === self::STRUCTURED ? self::structured() : self::classic();
    }

    /**
     * Gruppe, in der ein Eintrag im Basisprofil steht (für nicht platzierte Einträge eines eigenen Layouts).
     */
    public static function baseGroupOf(string $profile, string $itemId): ?string
    {
        $profile = $profile === self::STRUCTURED ? self::STRUCTURED : self::CLASSIC;
        if (!isset(self::$groupOfCache[$profile])) {
            $map = [];
            foreach ((array) (self::get($profile)['nodes'] ?? []) as $node) {
                if (($node['type'] ?? '') === 'group') {
                    foreach ((array) ($node['items'] ?? []) as $id) {
                        $map[(string) $id] = (string) $node['id'];
                    }
                }
            }
            self::$groupOfCache[$profile] = $map;
        }

        return self::$groupOfCache[$profile][$itemId] ?? null;
    }

    /**
     * Übersetzungsschlüssel für Beschriftungen eines Profils (Gruppen, Trenner, Eintrags-Überschreibungen).
     */
    public static function labelKey(string $profile, string $kind, string $id): string
    {
        return 'admin_menu.' . $profile . '.' . $kind . '.' . $id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function classic(): array
    {
        return [
            'format' => LayoutValidator::FORMAT,
            'version' => LayoutValidator::FORMAT_VERSION,
            'base' => self::CLASSIC,
            'fallback_group' => 'plugins',
            'root_group' => 'settings',
            'nodes' => [
                self::separator('sep.work', 'Arbeit'),
                self::item('dashboard'),
                self::item('system.updates'),
                self::group('posts', 'Beiträge', 'article', [
                    'content.posts', 'content.post-categories', 'content.post-tags', 'content.editorial-calendar',
                ]),
                self::group('media', 'Medien', 'photo', [
                    'media.library', 'media.featured', 'media.categories', 'media.check',
                    'media.downloads', 'media.tools', 'media.settings',
                ]),
                self::group('pages', 'Seiten', 'file', [
                    'content.pages', 'content.hub-sites', 'content.site-tables', 'content.toc', 'content.knowledgebase',
                ]),
                self::item('content.comments'),
                self::group('forms', 'Formulare', 'forms', ['content.forms', 'content.form-submissions']),
                self::group('ai', 'KI-Dienste', 'sparkles', [
                    'ai.dashboard', 'ai.translation', 'ai.content-creator', 'ai.seo-creator',
                ]),

                self::separator('sep.design', 'Gestaltung'),
                self::group('design', 'Design', 'palette', [
                    'design.themes', 'design.theme-editor', 'design.theme-explorer', 'design.menus',
                    'design.landing-page', 'design.fonts', 'design.login-page', 'design.theme-marketplace',
                ]),
                self::group('plugins', 'Plugins', 'plug', [
                    'extend.plugins', 'extend.marketplace', 'extend.plugin-assignment', 'extend.modules',
                ]),
                self::group('seo', 'SEO', 'chart-line', self::seoItems()),

                self::separator('sep.manage', 'Verwaltung'),
                self::group('users', 'Benutzer', 'users', [
                    'people.users', 'people.groups', 'people.roles', 'people.profile', 'people.settings',
                ]),
                self::group('members', 'Mitgliederbereich', 'id-badge-2', self::memberItems(), LayoutValidator::DISPLAY_TILES),
                self::group('subscriptions', 'Abos', 'credit-card', [
                    'subscriptions.packages', 'subscriptions.orders', 'subscriptions.settings',
                ]),
                self::group('tools', 'Werkzeuge', 'tool', [
                    'system.content-exchange', 'content.trash', 'content.changes', 'content.translations',
                    'system.search-index', 'seo.redirects', 'seo.not-found', 'system.backups',
                ]),
                self::group('settings', 'Einstellungen', 'settings', [
                    ...self::settingsGeneralItems(),
                    ...self::settingsSecurityItems(),
                    ...self::legalItems(),
                    ...self::performanceItems(),
                    'ai.settings',
                ], LayoutValidator::DISPLAY_TILES),
                self::group('health', 'Website-Zustand', 'heart-rate-monitor', self::reportItems(), LayoutValidator::DISPLAY_TILES),
            ],
            'labels' => [
                'content.posts' => 'Alle Beiträge',
                'content.pages' => 'Alle Seiten',
                'media.settings' => 'Einstellungen',
                'ai.translation' => 'Übersetzung',
                'people.settings' => 'Einstellungen',
                'subscriptions.settings' => 'Einstellungen',
            ],
            'icons' => [],
            'hidden' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function structured(): array
    {
        return [
            'format' => LayoutValidator::FORMAT,
            'version' => LayoutValidator::FORMAT_VERSION,
            'base' => self::STRUCTURED,
            'fallback_group' => 'extend',
            'root_group' => 'configuration',
            'nodes' => [
                self::item('dashboard'),
                self::item('system.updates'),
                self::group('content', 'Inhalt', 'file-text', [
                    'content.pages', 'content.posts', 'content.comments', 'content.knowledgebase',
                    'content.forms', 'content.form-submissions', 'content.trash',
                ]),
                self::group('media', 'Medien', 'photo', [
                    'media.library', 'media.featured', 'media.categories', 'media.check', 'media.downloads', 'media.tools',
                ]),
                self::group('editorial', 'Redaktion', 'calendar-event', [
                    'content.editorial-calendar', 'content.changes', 'content.translations',
                ]),
                self::group('ai', 'KI-Dienste', 'sparkles', [
                    'ai.dashboard', 'ai.translation', 'ai.content-creator', 'ai.seo-creator',
                ]),
                self::group('structure', 'Struktur', 'sitemap', [
                    'design.menus', 'content.post-categories', 'content.post-tags', 'content.hub-sites',
                    'content.site-tables', 'content.toc', 'seo.redirects', 'seo.not-found',
                ]),
                self::group('design', 'Design', 'palette', [
                    'design.themes', 'design.theme-editor', 'design.theme-explorer', 'design.landing-page',
                    'design.fonts', 'design.login-page', 'design.theme-marketplace',
                ]),
                self::group('seo', 'SEO & Marketing', 'chart-line', self::seoItems()),
                self::group('extend', 'Erweitern', 'puzzle', [
                    'extend.plugins', 'extend.marketplace', 'extend.plugin-assignment', 'extend.modules',
                ]),
                self::group('people', 'Personen', 'users', [
                    'people.users', 'people.groups', 'people.roles', 'people.settings', 'people.profile',
                ]),
                self::group('members', 'Mitgliederbereich', 'id-badge-2', self::memberItems(), LayoutValidator::DISPLAY_TILES),
                self::group('subscriptions', 'Abos', 'credit-card', [
                    'subscriptions.packages', 'subscriptions.orders', 'subscriptions.settings',
                ]),
                self::group('configuration', 'Konfiguration', 'settings', [
                    ...self::settingsGeneralItems(),
                    ...self::settingsSecurityItems(),
                    ...self::legalItems(),
                    ...self::performanceItems(),
                    'media.settings',
                    'ai.settings',
                    'system.backups', 'system.content-exchange', 'system.search-index',
                ], LayoutValidator::DISPLAY_TILES),
                self::group('reports', 'Berichte', 'report-analytics', self::reportItems(), LayoutValidator::DISPLAY_TILES),
            ],
            'labels' => [
                'ai.translation' => 'Übersetzung',
                'subscriptions.settings' => 'Einstellungen',
            ],
            'icons' => [],
            'hidden' => [],
        ];
    }

    /** @return list<string> */
    private static function seoItems(): array
    {
        return [
            'seo.dashboard', 'seo.analytics', 'seo.audit', 'seo.meta', 'seo.social',
            'seo.schema', 'seo.sitemap', 'seo.search-engines', 'seo.technical',
        ];
    }

    /** @return list<string> */
    private static function memberItems(): array
    {
        return [
            'members.overview', 'members.general', 'members.design', 'members.frontend-modules', 'members.widgets',
            'members.plugin-widgets', 'members.profile-fields', 'members.notifications', 'members.onboarding',
        ];
    }

    /** @return list<string> */
    private static function settingsGeneralItems(): array
    {
        return ['settings.general', 'settings.content', 'settings.feeds', 'system.mail', 'system.multisites', 'settings.admin-menu'];
    }

    /** @return list<string> */
    private static function settingsSecurityItems(): array
    {
        return ['settings.security', 'security.antispam', 'security.firewall'];
    }

    /** @return list<string> */
    private static function legalItems(): array
    {
        return ['legal.sites', 'legal.cookies', 'legal.data-requests', 'legal.retention'];
    }

    /** @return list<string> */
    private static function performanceItems(): array
    {
        return [
            'performance.overview', 'performance.cache', 'performance.media',
            'performance.database', 'performance.settings', 'performance.sessions',
        ];
    }

    /** @return list<string> */
    private static function reportItems(): array
    {
        return [
            'logs.overview', 'logs.operational', 'logs.security-audit', 'logs.php-errors', 'logs.channels',
            'monitor.assets', 'monitor.response-time', 'monitor.cron-status', 'monitor.disk-usage',
            'monitor.scheduled-tasks', 'monitor.health-check', 'monitor.email-alerts', 'monitor.warnings',
            'security.audit',
            'system.info', 'system.diagnose',
            'system.documentation',
        ];
    }

    /**
     * @return array{type:string,id:string,label:string}
     */
    private static function separator(string $id, string $label): array
    {
        return ['type' => 'separator', 'id' => $id, 'label' => $label];
    }

    /**
     * @return array{type:string,id:string}
     */
    private static function item(string $id): array
    {
        return ['type' => 'item', 'id' => $id];
    }

    /**
     * @param list<string> $items
     * @return array{type:string,id:string,label:string,icon:string,display:string,items:list<string>}
     */
    private static function group(string $id, string $label, string $icon, array $items, string $display = LayoutValidator::DISPLAY_MENU): array
    {
        return ['type' => 'group', 'id' => $id, 'label' => $label, 'icon' => $icon, 'display' => $display, 'items' => $items];
    }
}
