<?php
/**
 * Katalog aller Core-Admin-Seiten für das Admin-Menü.
 *
 * Eine Zeile je Seite: stabile ID, Route, Capabilities (eine von mehreren genügt), Seitendatei,
 * Seiten-Slug, Kategorie (Kachelseiten) und Badge. Einträge, deren Seitendatei noch fehlt
 * (vorgeplante Seiten aus Phase B und WP1–4), bleiben ausgeblendet, bis die Datei existiert.
 *
 * Die Capabilities sind Werte. Der statische Test `TESTS/admin-menu/run.php` gleicht sie mit
 * den Zugriffsprüfungen der Seitendateien ab (KLARSTELLUNGEN §7).
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class CoreCatalog
{
    public const CATEGORY_LABELS = [
        'general' => 'Allgemein',
        'security' => 'Sicherheit',
        'legal' => 'Recht & Datenschutz',
        'performance' => 'Performance',
        'media' => 'Medien',
        'ai' => 'KI',
        'maintenance' => 'Wartung',
        'logs' => 'Protokolle',
        'monitors' => 'Monitore',
        'system' => 'System',
        'help' => 'Hilfe',
        'members' => 'Mitgliederbereich',
        'other' => 'Weitere',
    ];

    /**
     * Bereiche der Liste „Verfügbare Einträge“ im Menü-Editor (Präfix der Eintrags-ID).
     * Dashboard und Updates gehören zu „Start“.
     */
    public const AREA_LABELS = [
        'start' => 'Start',
        'content' => 'Inhalte',
        'media' => 'Medien',
        'ai' => 'KI-Dienste',
        'design' => 'Design',
        'extend' => 'Erweitern',
        'seo' => 'SEO',
        'people' => 'Personen',
        'members' => 'Mitgliederbereich',
        'subscriptions' => 'Abos',
        'settings' => 'Einstellungen',
        'security' => 'Sicherheit',
        'legal' => 'Recht & Datenschutz',
        'performance' => 'Performance',
        'system' => 'Wartung & System',
        'logs' => 'Protokolle',
        'monitor' => 'Monitore',
        'other' => 'Weitere',
    ];

    private const START_IDS = ['dashboard', 'system.updates'];

    private const SETTINGS = ['manage_settings'];
    private const SETTINGS_OR_SYSTEM = ['manage_settings', 'manage_system'];

    /** @var list<AdminMenuItem>|null */
    private static ?array $items = null;

    /**
     * @return list<AdminMenuItem>
     */
    public static function items(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }

        $items = [];
        foreach (self::rows() as $row) {
            $category = (string) ($row['category'] ?? '');
            $items[] = new AdminMenuItem(
                id: $row['id'],
                label: $row['label'],
                route: $row['route'],
                icon: ['type' => 'tabler', 'name' => (string) ($row['icon'] ?? 'point')],
                capabilities: array_values((array) ($row['caps'] ?? [])),
                file: (string) ($row['file'] ?? ''),
                pageSlug: (string) ($row['page'] ?? ''),
                category: $category,
                badge: (string) ($row['badge'] ?? ''),
                source: 'core',
                locked: (bool) ($row['locked'] ?? false),
                type: (string) ($row['type'] ?? AdminMenuItem::TYPE_PAGE),
                requiresMarketplace: (bool) ($row['marketplace'] ?? false),
                tileLabel: (string) ($row['tile'] ?? ''),
                description: (string) ($row['desc'] ?? ''),
                categoryLabel: self::CATEGORY_LABELS[$category] ?? '',
            );
        }

        return self::$items = $items;
    }

    /**
     * Einträge, die im Editor weder ausgeblendet noch entfernt werden können.
     *
     * @return list<string>
     */
    public static function lockedIds(): array
    {
        $ids = [];
        foreach (self::items() as $item) {
            if ($item->locked) {
                $ids[] = $item->id;
            }
        }

        return $ids;
    }

    /**
     * Bereich eines Core-Eintrags für den Menü-Editor (Schlüssel aus AREA_LABELS).
     */
    public static function areaOf(string $id): string
    {
        if (in_array($id, self::START_IDS, true)) {
            return 'start';
        }
        $prefix = strstr($id, '.', true);

        return is_string($prefix) && isset(self::AREA_LABELS[$prefix]) ? $prefix : 'other';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return [
            // ── Start ───────────────────────────────────────────────────────────────
            ['id' => 'dashboard', 'label' => 'Dashboard', 'route' => '/admin', 'icon' => 'layout-dashboard', 'caps' => [], 'file' => 'admin/index.php', 'page' => 'dashboard', 'locked' => true],
            ['id' => 'system.updates', 'label' => 'Updates', 'route' => '/admin/updates', 'icon' => 'refresh', 'caps' => self::SETTINGS, 'file' => 'admin/updates.php', 'page' => 'updates', 'badge' => 'updates'],

            // ── Inhalte ─────────────────────────────────────────────────────────────
            ['id' => 'content.posts', 'label' => 'Beiträge', 'route' => '/admin/posts', 'icon' => 'article', 'caps' => ['edit_all_posts', 'edit_own_posts', 'posts.view'], 'file' => 'admin/posts.php', 'page' => 'posts'],
            ['id' => 'content.post-categories', 'label' => 'Kategorien', 'route' => '/admin/post-categories', 'icon' => 'category', 'caps' => ['edit_all_posts'], 'file' => 'admin/post-categories.php', 'page' => 'post-categories'],
            ['id' => 'content.post-tags', 'label' => 'Tags', 'route' => '/admin/post-tags', 'icon' => 'tags', 'caps' => ['edit_all_posts'], 'file' => 'admin/post-tags.php', 'page' => 'post-tags'],
            ['id' => 'content.pages', 'label' => 'Seiten', 'route' => '/admin/pages', 'icon' => 'file', 'caps' => ['manage_pages'], 'file' => 'admin/pages.php', 'page' => 'pages'],
            ['id' => 'content.hub-sites', 'label' => 'Hub-Sites', 'route' => '/admin/hub-sites', 'icon' => 'world', 'caps' => self::SETTINGS, 'file' => 'admin/hub-sites.php', 'page' => 'hub-sites'],
            ['id' => 'content.site-tables', 'label' => 'Tabellen', 'route' => '/admin/site-tables', 'icon' => 'table', 'caps' => self::SETTINGS, 'file' => 'admin/site-tables.php', 'page' => 'site-tables'],
            ['id' => 'content.toc', 'label' => 'Inhaltsverzeichnis', 'route' => '/admin/table-of-contents', 'icon' => 'list-numbers', 'caps' => self::SETTINGS, 'file' => 'admin/table-of-contents.php', 'page' => 'table-of-contents'],
            ['id' => 'content.comments', 'label' => 'Kommentare', 'route' => '/admin/comments', 'icon' => 'messages', 'caps' => ['comments.view'], 'file' => 'admin/comments.php', 'page' => 'comments', 'badge' => 'comments'],
            ['id' => 'content.forms', 'label' => 'Formulare', 'route' => '/admin/forms', 'icon' => 'forms', 'caps' => ['manage_forms'], 'file' => 'admin/forms.php', 'page' => 'forms'],
            ['id' => 'content.form-submissions', 'label' => 'Eingänge', 'route' => '/admin/form-submissions', 'icon' => 'inbox', 'caps' => ['view_form_submissions'], 'file' => 'admin/form-submissions.php', 'page' => 'form-submissions', 'badge' => 'form_submissions'],
            ['id' => 'content.trash', 'label' => 'Papierkorb', 'route' => '/admin/trash', 'icon' => 'trash', 'caps' => ['manage_trash'], 'file' => 'admin/trash.php', 'page' => 'trash'],
            ['id' => 'content.editorial-calendar', 'label' => 'Redaktionskalender', 'route' => '/admin/editorial-calendar', 'icon' => 'calendar-event', 'caps' => ['view_editorial_calendar'], 'file' => 'admin/editorial-calendar.php', 'page' => 'editorial-calendar'],
            ['id' => 'content.changes', 'label' => 'Letzte Änderungen', 'route' => '/admin/content-changes', 'icon' => 'history', 'caps' => ['edit_all_posts', 'manage_pages'], 'file' => 'admin/content-changes.php', 'page' => 'content-changes'],
            ['id' => 'content.translations', 'label' => 'Übersetzungen', 'route' => '/admin/translations', 'icon' => 'language', 'caps' => ['manage_translations'], 'file' => 'admin/translations.php', 'page' => 'translations'],

            // ── Medien ──────────────────────────────────────────────────────────────
            ['id' => 'media.library', 'label' => 'Mediathek', 'route' => '/admin/media', 'icon' => 'photo', 'caps' => ['manage_media'], 'file' => 'admin/media.php', 'page' => 'media'],
            ['id' => 'media.featured', 'label' => 'Beitrags- & Site-Medien', 'route' => '/admin/media?tab=featured', 'icon' => 'photo-star', 'caps' => ['manage_media'], 'file' => 'admin/media.php', 'page' => 'media-featured'],
            ['id' => 'media.categories', 'label' => 'Medienkategorien', 'route' => '/admin/media?tab=categories', 'icon' => 'folders', 'caps' => ['manage_media'], 'file' => 'admin/media.php', 'page' => 'media-categories'],
            ['id' => 'media.check', 'label' => 'Medien-Check', 'route' => '/admin/media?tab=check', 'icon' => 'photo-check', 'caps' => ['manage_media'], 'file' => 'admin/media.php', 'page' => 'media-check'],
            ['id' => 'media.downloads', 'label' => 'Downloads', 'route' => '/admin/downloads', 'icon' => 'download', 'caps' => ['manage_downloads', 'view_download_stats'], 'file' => 'admin/downloads.php', 'page' => 'downloads'],
            ['id' => 'media.tools', 'label' => 'Medien-Werkzeuge', 'route' => '/admin/media-tools', 'icon' => 'photo-cog', 'caps' => ['manage_media'], 'file' => 'admin/media-tools.php', 'page' => 'media-tools'],
            ['id' => 'media.settings', 'label' => 'Medien-Einstellungen', 'route' => '/admin/media?tab=settings', 'icon' => 'photo-edit', 'caps' => ['manage_media'], 'file' => 'admin/media.php', 'page' => 'media-settings', 'category' => 'media', 'desc' => 'Upload-Grenzen, Bildgrößen und erlaubte Dateitypen der Mediathek.'],

            // ── KI-Dienste (Modul ai_services) ──────────────────────────────────────
            ['id' => 'ai.dashboard', 'label' => 'KI-Dashboard', 'route' => '/admin/ai-services', 'icon' => 'sparkles', 'caps' => ['manage_settings', 'manage_system', 'manage_ai_services'], 'file' => 'admin/ai-services.php', 'page' => 'ai-services'],
            ['id' => 'ai.translation', 'label' => 'KI-Übersetzung', 'route' => '/admin/ai-translation', 'icon' => 'language', 'caps' => ['manage_settings', 'manage_system', 'manage_ai_services'], 'file' => 'admin/ai-translation.php', 'page' => 'ai-translation'],
            ['id' => 'ai.content-creator', 'label' => 'Inhaltsassistent', 'route' => '/admin/ai-content-creator', 'icon' => 'wand', 'caps' => ['manage_settings', 'manage_system', 'manage_ai_services'], 'file' => 'admin/ai-content-creator.php', 'page' => 'ai-content-creator'],
            ['id' => 'ai.seo-creator', 'label' => 'SEO-Assistent', 'route' => '/admin/ai-seo-creator', 'icon' => 'robot', 'caps' => ['manage_settings', 'manage_system', 'manage_ai_services'], 'file' => 'admin/ai-seo-creator.php', 'page' => 'ai-seo-creator'],
            ['id' => 'ai.settings', 'label' => 'KI-Einstellungen', 'route' => '/admin/ai-settings', 'icon' => 'adjustments-alt', 'caps' => ['manage_settings', 'manage_system', 'manage_ai_services'], 'file' => 'admin/ai-settings.php', 'page' => 'ai-settings', 'category' => 'ai', 'desc' => 'Anbieter, Modelle und Zugangsdaten der KI-Dienste.'],

            // ── Design ──────────────────────────────────────────────────────────────
            ['id' => 'design.themes', 'label' => 'Themes', 'route' => '/admin/themes', 'icon' => 'palette', 'caps' => self::SETTINGS, 'file' => 'admin/themes.php', 'page' => 'themes'],
            ['id' => 'design.theme-editor', 'label' => 'Theme-Editor', 'route' => '/admin/theme-editor', 'icon' => 'brush', 'caps' => self::SETTINGS, 'file' => 'admin/theme-editor.php', 'page' => 'theme-editor'],
            ['id' => 'design.theme-explorer', 'label' => 'Theme-Explorer', 'route' => '/admin/theme-explorer', 'icon' => 'folder-search', 'caps' => self::SETTINGS, 'file' => 'admin/theme-explorer.php', 'page' => 'theme-explorer'],
            ['id' => 'design.menus', 'label' => 'Menüs', 'route' => '/admin/menu-editor', 'icon' => 'menu-2', 'caps' => self::SETTINGS, 'file' => 'admin/menu-editor.php', 'page' => 'menu-editor'],
            ['id' => 'design.landing-page', 'label' => 'Landingpage', 'route' => '/admin/landing-page', 'icon' => 'layout-navbar', 'caps' => self::SETTINGS, 'file' => 'admin/landing-page.php', 'page' => 'landing-page'],
            ['id' => 'design.fonts', 'label' => 'Schriften', 'route' => '/admin/font-manager', 'icon' => 'typography', 'caps' => self::SETTINGS, 'file' => 'admin/font-manager.php', 'page' => 'font-manager'],
            ['id' => 'design.login-page', 'label' => 'Login-Seite', 'route' => '/admin/cms-loginpage', 'icon' => 'login', 'caps' => self::SETTINGS, 'file' => 'admin/cms-loginpage.php', 'page' => 'cms-loginpage'],
            ['id' => 'design.theme-marketplace', 'label' => 'Theme-Marktplatz', 'route' => '/admin/theme-marketplace', 'icon' => 'building-store', 'caps' => self::SETTINGS, 'file' => 'admin/theme-marketplace.php', 'page' => 'theme-marketplace', 'marketplace' => true],

            // ── Erweitern ───────────────────────────────────────────────────────────
            ['id' => 'extend.plugins', 'label' => 'Plugins verwalten', 'route' => '/admin/plugins', 'icon' => 'plug', 'caps' => self::SETTINGS, 'file' => 'admin/plugins.php', 'page' => 'plugins'],
            ['id' => 'extend.marketplace', 'label' => 'Plugin-Marktplatz', 'route' => '/admin/plugin-marketplace', 'icon' => 'building-store', 'caps' => self::SETTINGS, 'file' => 'admin/plugin-marketplace.php', 'page' => 'plugin-marketplace', 'marketplace' => true],
            ['id' => 'extend.plugin-assignment', 'label' => 'Plugin-Zuweisung', 'route' => '/admin/plugin-assignment', 'icon' => 'plug-connected', 'caps' => self::SETTINGS, 'file' => 'admin/plugin-assignment.php', 'page' => 'plugin-assignment'],
            ['id' => 'extend.modules', 'label' => 'Module', 'route' => '/admin/modules', 'icon' => 'apps', 'caps' => self::SETTINGS, 'file' => 'admin/modules.php', 'page' => 'modules'],

            // ── SEO (Modul seo) ─────────────────────────────────────────────────────
            ['id' => 'seo.dashboard', 'label' => 'SEO-Dashboard', 'route' => '/admin/seo-dashboard', 'icon' => 'chart-line', 'caps' => self::SETTINGS, 'file' => 'admin/seo-dashboard.php', 'page' => 'seo-dashboard'],
            ['id' => 'seo.analytics', 'label' => 'Analysen', 'route' => '/admin/analytics', 'icon' => 'chart-bar', 'caps' => ['manage_settings', 'view_analytics'], 'file' => 'admin/analytics.php', 'page' => 'analytics'],
            ['id' => 'seo.audit', 'label' => 'SEO-Prüfung', 'route' => '/admin/seo-audit', 'icon' => 'report-search', 'caps' => self::SETTINGS, 'file' => 'admin/seo-audit.php', 'page' => 'seo-audit'],
            ['id' => 'seo.meta', 'label' => 'Meta-Daten', 'route' => '/admin/seo-meta', 'icon' => 'file-description', 'caps' => self::SETTINGS, 'file' => 'admin/seo-meta.php', 'page' => 'seo-meta'],
            ['id' => 'seo.social', 'label' => 'Soziale Medien', 'route' => '/admin/seo-social', 'icon' => 'share', 'caps' => self::SETTINGS, 'file' => 'admin/seo-social.php', 'page' => 'seo-social'],
            ['id' => 'seo.schema', 'label' => 'Strukturierte Daten', 'route' => '/admin/seo-schema', 'icon' => 'code', 'caps' => self::SETTINGS, 'file' => 'admin/seo-schema.php', 'page' => 'seo-schema'],
            ['id' => 'seo.sitemap', 'label' => 'Sitemap & robots.txt', 'route' => '/admin/seo-sitemap', 'icon' => 'sitemap', 'caps' => self::SETTINGS, 'file' => 'admin/seo-sitemap.php', 'page' => 'seo-sitemap'],
            ['id' => 'seo.search-engines', 'label' => 'Suchmaschinen', 'route' => '/admin/seo-search-engines', 'icon' => 'brand-google', 'caps' => self::SETTINGS, 'file' => 'admin/seo-search-engines.php', 'page' => 'seo-search-engines'],
            ['id' => 'seo.technical', 'label' => 'Technisches SEO', 'route' => '/admin/seo-technical', 'icon' => 'settings-code', 'caps' => self::SETTINGS, 'file' => 'admin/seo-technical.php', 'page' => 'seo-technical'],
            ['id' => 'seo.redirects', 'label' => 'Weiterleitungen', 'route' => '/admin/redirect-manager', 'icon' => 'arrows-right-left', 'caps' => self::SETTINGS, 'file' => 'admin/redirect-manager.php', 'page' => 'redirect-manager'],
            ['id' => 'seo.not-found', 'label' => '404-Monitor', 'route' => '/admin/not-found-monitor', 'icon' => 'error-404', 'caps' => self::SETTINGS, 'file' => 'admin/not-found-monitor.php', 'page' => 'not-found-monitor'],

            // ── Personen ────────────────────────────────────────────────────────────
            ['id' => 'people.users', 'label' => 'Benutzer', 'route' => '/admin/users', 'icon' => 'users', 'caps' => ['manage_users'], 'file' => 'admin/users.php', 'page' => 'users', 'locked' => true],
            ['id' => 'people.groups', 'label' => 'Gruppen', 'route' => '/admin/groups', 'icon' => 'users-group', 'caps' => ['manage_users'], 'file' => 'admin/groups.php', 'page' => 'groups'],
            ['id' => 'people.roles', 'label' => 'Rollen & Rechte', 'route' => '/admin/roles', 'icon' => 'shield-lock', 'caps' => ['manage_users'], 'file' => 'admin/roles.php', 'page' => 'roles', 'locked' => true],
            ['id' => 'people.settings', 'label' => 'Benutzereinstellungen', 'route' => '/admin/user-settings', 'icon' => 'user-cog', 'caps' => ['manage_users'], 'file' => 'admin/user-settings.php', 'page' => 'user-settings'],
            ['id' => 'people.profile', 'label' => 'Mein Profil', 'route' => '/member/profile', 'icon' => 'user-circle', 'caps' => [], 'type' => AdminMenuItem::TYPE_LINK],

            // ── Mitgliederbereich (Modul member_dashboard) ──────────────────────────
            ['id' => 'members.overview', 'label' => 'Mitgliederbereich: Übersicht', 'tile' => 'Übersicht', 'route' => '/admin/member-dashboard', 'icon' => 'id-badge-2', 'caps' => ['manage_settings', 'manage_users'], 'file' => 'admin/member-dashboard.php', 'page' => 'member-dashboard', 'category' => 'members', 'desc' => 'Status und Kennzahlen des Mitgliederbereichs.'],
            ['id' => 'members.general', 'label' => 'Mitgliederbereich: Allgemein', 'tile' => 'Allgemein', 'route' => '/admin/member-dashboard-general', 'icon' => 'adjustments', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-general.php', 'page' => 'member-dashboard-general', 'category' => 'members', 'desc' => 'Zugang, Startseite und Grundeinstellungen des Mitgliederbereichs.'],
            ['id' => 'members.design', 'label' => 'Mitgliederbereich: Design & Farben', 'tile' => 'Design & Farben', 'route' => '/admin/member-dashboard-design', 'icon' => 'palette', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-design.php', 'page' => 'member-dashboard-design', 'category' => 'members', 'desc' => 'Farben und Erscheinungsbild des Mitgliederbereichs.'],
            ['id' => 'members.frontend-modules', 'label' => 'Mitgliederbereich: Frontend-Module', 'tile' => 'Frontend-Module', 'route' => '/admin/member-dashboard-frontend-modules', 'icon' => 'apps', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-frontend-modules.php', 'page' => 'member-dashboard-frontend-modules', 'category' => 'members', 'desc' => 'Module, die Mitglieder im Frontend nutzen können.'],
            ['id' => 'members.widgets', 'label' => 'Mitgliederbereich: Dashboard-Widgets', 'tile' => 'Dashboard-Widgets', 'route' => '/admin/member-dashboard-widgets', 'icon' => 'layout-grid', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-widgets.php', 'page' => 'member-dashboard-widgets', 'category' => 'members', 'desc' => 'Widgets auf dem Dashboard der Mitglieder.'],
            ['id' => 'members.plugin-widgets', 'label' => 'Mitgliederbereich: Plugin-Widgets', 'tile' => 'Plugin-Widgets', 'route' => '/admin/member-dashboard-plugin-widgets', 'icon' => 'puzzle', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-plugin-widgets.php', 'page' => 'member-dashboard-plugin-widgets', 'category' => 'members', 'desc' => 'Widgets, die Plugins für den Mitgliederbereich bereitstellen.'],
            ['id' => 'members.profile-fields', 'label' => 'Mitgliederbereich: Profil-Felder', 'tile' => 'Profil-Felder', 'route' => '/admin/member-dashboard-profile-fields', 'icon' => 'id', 'caps' => ['manage_users'], 'file' => 'admin/member-dashboard-profile-fields.php', 'page' => 'member-dashboard-profile-fields', 'category' => 'members', 'desc' => 'Felder im Profil der Mitglieder festlegen.'],
            ['id' => 'members.notifications', 'label' => 'Mitgliederbereich: Benachrichtigungen', 'tile' => 'Benachrichtigungen', 'route' => '/admin/member-dashboard-notifications', 'icon' => 'bell', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-notifications.php', 'page' => 'member-dashboard-notifications', 'category' => 'members', 'desc' => 'Benachrichtigungen an Mitglieder einstellen.'],
            ['id' => 'members.onboarding', 'label' => 'Mitgliederbereich: Onboarding', 'tile' => 'Onboarding', 'route' => '/admin/member-dashboard-onboarding', 'icon' => 'rocket', 'caps' => self::SETTINGS, 'file' => 'admin/member-dashboard-onboarding.php', 'page' => 'member-dashboard-onboarding', 'category' => 'members', 'desc' => 'Erste Schritte für neue Mitglieder.'],

            // ── Abos (Modul subscriptions) ──────────────────────────────────────────
            ['id' => 'subscriptions.packages', 'label' => 'Pakete', 'route' => '/admin/packages', 'icon' => 'package', 'caps' => self::SETTINGS, 'file' => 'admin/packages.php', 'page' => 'packages'],
            ['id' => 'subscriptions.orders', 'label' => 'Bestellungen', 'route' => '/admin/orders', 'icon' => 'receipt', 'caps' => self::SETTINGS, 'file' => 'admin/orders.php', 'page' => 'orders'],
            ['id' => 'subscriptions.settings', 'label' => 'Abo-Einstellungen', 'route' => '/admin/subscription-settings', 'icon' => 'settings-2', 'caps' => self::SETTINGS, 'file' => 'admin/subscription-settings.php', 'page' => 'subscription-settings'],

            // ── Einstellungen / Konfiguration ───────────────────────────────────────
            ['id' => 'settings.general', 'label' => 'Allgemeine Einstellungen', 'tile' => 'Allgemein', 'route' => '/admin/settings', 'icon' => 'settings', 'caps' => self::SETTINGS, 'file' => 'admin/settings.php', 'page' => 'settings', 'category' => 'general', 'desc' => 'Name, Adresse, Sprache und Grundeinstellungen der Website.'],
            ['id' => 'settings.content', 'label' => 'Inhalts-Einstellungen', 'tile' => 'Inhalte', 'route' => '/admin/settings?tab=content', 'icon' => 'file-settings', 'caps' => self::SETTINGS, 'file' => 'admin/settings.php', 'page' => 'content-settings', 'category' => 'general', 'desc' => 'Standards für Beiträge, Sites und den Editor.'],
            ['id' => 'settings.feeds', 'label' => 'Feeds', 'route' => '/admin/settings?tab=feeds', 'icon' => 'rss', 'caps' => ['manage_feeds'], 'file' => 'admin/settings-tabs/feeds.php', 'page' => 'settings-feeds', 'category' => 'general', 'desc' => 'Feed-Aggregator (Quellen, Bereiche, Digests, Abos, OPML) und RSS-Ausgang.'],
            ['id' => 'system.mail', 'label' => 'E-Mail & OAuth2', 'route' => '/admin/mail-settings', 'icon' => 'mail', 'caps' => self::SETTINGS_OR_SYSTEM, 'file' => 'admin/mail-settings.php', 'page' => 'mail-settings', 'category' => 'general', 'desc' => 'Versand über SMTP oder Microsoft 365 (Azure OAuth2), Absender und Testmail.'],
            ['id' => 'system.multisites', 'label' => 'MultiSites', 'route' => '/admin/multisites', 'icon' => 'world-www', 'caps' => self::SETTINGS, 'file' => 'admin/multisites.php', 'page' => 'multisites', 'category' => 'general', 'desc' => 'Sites mit Domain, Akzentfarbe und weiteren Hostnamen verwalten.'],
            ['id' => 'settings.admin-menu', 'label' => 'Admin-Menü', 'route' => '/admin/settings?tab=admin-menu', 'icon' => 'layout-sidebar', 'caps' => ['manage_admin_menu'], 'file' => 'admin/settings-tabs/admin-menu.php', 'page' => 'settings-admin-menu', 'category' => 'general', 'locked' => true, 'desc' => 'Profil der Seitenleiste wählen und ein eigenes Menü bearbeiten.'],
            ['id' => 'settings.security', 'label' => 'Login & Sperren', 'route' => '/admin/settings?tab=security', 'icon' => 'lock', 'caps' => self::SETTINGS, 'file' => 'admin/settings-tabs/security.php', 'page' => 'settings-security', 'category' => 'security', 'desc' => 'Anmeldeversuche und Sperrzeit für alle Login-Wege.'],
            ['id' => 'security.antispam', 'label' => 'Anti-Spam', 'route' => '/admin/antispam', 'icon' => 'shield-x', 'caps' => self::SETTINGS, 'file' => 'admin/antispam.php', 'page' => 'antispam', 'category' => 'security', 'desc' => 'Spam-Schutz für Kommentare, Formulare und Registrierungen.'],
            ['id' => 'security.firewall', 'label' => 'Firewall', 'route' => '/admin/firewall', 'icon' => 'wall', 'caps' => self::SETTINGS, 'file' => 'admin/firewall.php', 'page' => 'firewall', 'category' => 'security', 'desc' => 'Regeln für IP-Adressen, Länder und auffällige Anfragen.'],
            ['id' => 'legal.sites', 'label' => 'Rechtstexte', 'route' => '/admin/legal-sites', 'icon' => 'scale', 'caps' => self::SETTINGS, 'file' => 'admin/legal-sites.php', 'page' => 'legal-sites', 'category' => 'legal', 'desc' => 'Impressum, Datenschutzerklärung und weitere Rechtstexte.'],
            ['id' => 'legal.cookies', 'label' => 'Cookie-Manager', 'route' => '/admin/cookie-manager', 'icon' => 'cookie', 'caps' => self::SETTINGS, 'file' => 'admin/cookie-manager.php', 'page' => 'cookie-manager', 'category' => 'legal', 'desc' => 'Cookie-Banner, Kategorien und erkannte Dienste.'],
            ['id' => 'legal.data-requests', 'label' => 'Auskunft & Löschen', 'route' => '/admin/data-requests', 'icon' => 'user-search', 'caps' => self::SETTINGS, 'file' => 'admin/data-requests.php', 'page' => 'data-requests', 'category' => 'legal', 'badge' => 'privacy_requests', 'desc' => 'Auskunfts- und Löschanfragen nach DSGVO bearbeiten.'],
            ['id' => 'legal.retention', 'label' => 'Löschfristen', 'route' => '/admin/data-retention', 'icon' => 'clock-cog', 'caps' => self::SETTINGS, 'file' => 'admin/data-retention.php', 'page' => 'data-retention', 'category' => 'legal', 'desc' => 'Aufbewahrungsfristen, letzte Läufe und Probelauf im Überblick.'],
            ['id' => 'performance.overview', 'label' => 'Performance: Übersicht', 'tile' => 'Übersicht', 'route' => '/admin/performance', 'icon' => 'gauge', 'caps' => self::SETTINGS, 'file' => 'admin/performance.php', 'page' => 'performance', 'category' => 'performance', 'desc' => 'Kennzahlen zu Cache, Medien, Datenbank und Sessions.'],
            ['id' => 'performance.cache', 'label' => 'Cache', 'route' => '/admin/performance-cache', 'icon' => 'bolt', 'caps' => self::SETTINGS, 'file' => 'admin/performance-cache.php', 'page' => 'performance-cache', 'category' => 'performance', 'desc' => 'Seiten-, Objekt- und Asset-Cache prüfen und leeren.'],
            ['id' => 'performance.media', 'label' => 'Medien-Optimierung', 'route' => '/admin/performance-media', 'icon' => 'photo-down', 'caps' => self::SETTINGS, 'file' => 'admin/performance-media.php', 'page' => 'performance-media', 'category' => 'performance', 'desc' => 'Bilder komprimieren und moderne Formate erzeugen.'],
            ['id' => 'performance.database', 'label' => 'Datenbank', 'route' => '/admin/performance-database', 'icon' => 'database', 'caps' => self::SETTINGS, 'file' => 'admin/performance-database.php', 'page' => 'performance-database', 'category' => 'performance', 'desc' => 'Tabellen optimieren und Altdaten bereinigen.'],
            ['id' => 'performance.settings', 'label' => 'Performance-Einstellungen', 'tile' => 'Einstellungen', 'route' => '/admin/performance-settings', 'icon' => 'adjustments-horizontal', 'caps' => self::SETTINGS, 'file' => 'admin/performance-settings.php', 'page' => 'performance-settings', 'category' => 'performance', 'desc' => 'Caching, Komprimierung und Ladeverhalten einstellen.'],
            ['id' => 'performance.sessions', 'label' => 'Sessions', 'route' => '/admin/performance-sessions', 'icon' => 'clock-hour-4', 'caps' => self::SETTINGS, 'file' => 'admin/performance-sessions.php', 'page' => 'performance-sessions', 'category' => 'performance', 'desc' => 'Aktive Sitzungen einsehen und beenden.'],

            // ── Wartung / System ────────────────────────────────────────────────────
            ['id' => 'system.backups', 'label' => 'Backups', 'route' => '/admin/backups', 'icon' => 'database-export', 'caps' => self::SETTINGS_OR_SYSTEM, 'file' => 'admin/backups.php', 'page' => 'backups', 'category' => 'maintenance', 'desc' => 'Sicherungen erstellen, herunterladen und wiederherstellen.'],
            ['id' => 'system.content-exchange', 'label' => 'Import/Export', 'route' => '/admin/content-exchange', 'icon' => 'file-export', 'caps' => ['import_export'], 'file' => 'admin/content-exchange.php', 'page' => 'content-exchange', 'category' => 'maintenance', 'desc' => 'Inhalte als ZIP exportieren und in diese Installation importieren.'],
            ['id' => 'system.search-index', 'label' => 'Suchindex', 'route' => '/admin/search-index', 'icon' => 'zoom-code', 'caps' => ['manage_search_index'], 'file' => 'admin/search-index.php', 'page' => 'search-index', 'category' => 'maintenance', 'desc' => 'Suchindex neu aufbauen und Suchbegriffe auswerten.'],
            ['id' => 'system.info', 'label' => 'Systeminfo', 'route' => '/admin/info', 'icon' => 'info-circle', 'caps' => self::SETTINGS, 'file' => 'admin/info.php', 'page' => 'info', 'category' => 'system', 'desc' => 'PHP, Datenbank, Erweiterungen und Verzeichnisrechte.'],
            ['id' => 'system.diagnose', 'label' => 'Diagnose (Datenbank)', 'route' => '/admin/diagnose', 'icon' => 'stethoscope', 'caps' => self::SETTINGS, 'file' => 'admin/diagnose.php', 'page' => 'diagnose', 'category' => 'system', 'desc' => 'Datenbanktabellen und Schema prüfen.'],
            ['id' => 'security.audit', 'label' => 'Sicherheits-Audit', 'route' => '/admin/security-audit', 'icon' => 'shield-check', 'caps' => self::SETTINGS, 'file' => 'admin/security-audit.php', 'page' => 'security-audit', 'category' => 'security', 'desc' => 'Sicherheitsrelevante Einstellungen und Dateien prüfen.'],
            ['id' => 'system.documentation', 'label' => 'Dokumentation', 'route' => '/admin/documentation', 'icon' => 'book', 'caps' => self::SETTINGS_OR_SYSTEM, 'file' => 'admin/documentation.php', 'page' => 'documentation', 'category' => 'help', 'desc' => 'Handbuch und Entwicklerdokumentation des CMS.'],

            // ── Protokolle ──────────────────────────────────────────────────────────
            ['id' => 'logs.overview', 'label' => 'Protokolle: Übersicht', 'tile' => 'Übersicht', 'route' => '/admin/logs', 'icon' => 'clipboard-list', 'caps' => self::SETTINGS, 'file' => 'admin/logs.php', 'page' => 'logs-overview', 'category' => 'logs', 'badge' => 'logs', 'desc' => 'Alle Protokolle und ungelesenen Hinweise auf einen Blick.'],
            ['id' => 'logs.operational', 'label' => 'Operativer Log', 'route' => '/admin/logs/operational', 'icon' => 'file-text', 'caps' => self::SETTINGS, 'file' => 'admin/logs/operational.php', 'page' => 'logs-operational', 'category' => 'logs', 'desc' => 'Betriebsereignisse des CMS.'],
            ['id' => 'logs.security-audit', 'label' => 'Sicherheits-Audit-Log', 'route' => '/admin/logs/security-audit', 'icon' => 'fingerprint', 'caps' => self::SETTINGS, 'file' => 'admin/logs/security-audit.php', 'page' => 'logs-security-audit', 'category' => 'logs', 'desc' => 'Sicherheitsrelevante Aktionen mit Benutzer und Zeitpunkt.'],
            ['id' => 'logs.php-errors', 'label' => 'PHP-Fehlerlog', 'route' => '/admin/logs/php-errors', 'icon' => 'bug', 'caps' => self::SETTINGS, 'file' => 'admin/logs/php-errors.php', 'page' => 'logs-php-errors', 'category' => 'logs', 'desc' => 'PHP-Fehler und Warnungen des Servers.'],
            ['id' => 'logs.channels', 'label' => 'Kanal-Logs & Update-Historie', 'route' => '/admin/logs/channels', 'icon' => 'notes', 'caps' => self::SETTINGS, 'file' => 'admin/logs/channels.php', 'page' => 'logs-channels', 'category' => 'logs', 'desc' => 'Protokolle je Kanal sowie die Update-Historie.'],

            // ── Monitore ────────────────────────────────────────────────────────────
            ['id' => 'monitor.assets', 'label' => 'Asset-Monitor', 'tile' => 'Assets', 'route' => '/admin/monitor-assets', 'icon' => 'file-code', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-assets.php', 'page' => 'monitor-assets', 'category' => 'monitors', 'desc' => 'Größe und Auslieferung von CSS-, JS- und Bilddateien.'],
            ['id' => 'monitor.response-time', 'label' => 'Antwortzeit-Monitor', 'tile' => 'Antwortzeit', 'route' => '/admin/monitor-response-time', 'icon' => 'stopwatch', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-response-time.php', 'page' => 'monitor-response-time', 'category' => 'monitors', 'desc' => 'Antwortzeiten wichtiger Seiten messen.'],
            ['id' => 'monitor.cron-status', 'label' => 'Cron-Job-Status', 'route' => '/admin/monitor-cron-status', 'icon' => 'clock-play', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-cron-status.php', 'page' => 'monitor-cron-status', 'category' => 'monitors', 'desc' => 'Letzte Läufe und Status der Cron-Jobs.'],
            ['id' => 'monitor.disk-usage', 'label' => 'Speichernutzung', 'route' => '/admin/monitor-disk-usage', 'icon' => 'server', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-disk-usage.php', 'page' => 'monitor-disk-usage', 'category' => 'monitors', 'desc' => 'Belegter Speicherplatz nach Verzeichnissen.'],
            ['id' => 'monitor.scheduled-tasks', 'label' => 'Geplante Aufgaben', 'route' => '/admin/monitor-scheduled-tasks', 'icon' => 'calendar-time', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-scheduled-tasks.php', 'page' => 'monitor-scheduled-tasks', 'category' => 'monitors', 'desc' => 'Geplante Aufgaben mit nächster Ausführung.'],
            ['id' => 'monitor.health-check', 'label' => 'Systemprüfung', 'route' => '/admin/monitor-health-check', 'icon' => 'heartbeat', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-health-check.php', 'page' => 'monitor-health-check', 'category' => 'monitors', 'desc' => 'Systemprüfung mit Empfehlungen.'],
            ['id' => 'monitor.email-alerts', 'label' => 'E-Mail-Benachrichtigungen', 'route' => '/admin/monitor-email-alerts', 'icon' => 'mail-exclamation', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-email-alerts.php', 'page' => 'monitor-email-alerts', 'category' => 'monitors', 'desc' => 'E-Mail-Benachrichtigungen bei Störungen festlegen.'],
            ['id' => 'monitor.warnings', 'label' => 'Warnungen', 'route' => '/admin/monitor-warnings', 'icon' => 'alert-triangle', 'caps' => self::SETTINGS, 'file' => 'admin/monitor-warnings.php', 'page' => 'monitor-warnings', 'category' => 'monitors', 'desc' => 'Aktuelle Warnungen aus allen Prüfungen.'],
        ];
    }
}
