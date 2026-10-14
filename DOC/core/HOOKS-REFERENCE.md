# 365CMS – Projektdokumentation | Abschnitt: Core – Hook-Referenz

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

`CMS\Hooks` (`CMS/core/Hooks.php`) is a WordPress-like action/filter registry. Callbacks are stored per tag and priority (lower runs first). WordPress-style wrappers (`add_action`, `do_action`, `add_filter`, `apply_filters`) live in `CMS/includes/functions/wordpress-compat.php`. `before_footer` and `body_end` fire at most once per request. This document lists every hook fired by the core, the admin, the member area and the shipped theme in 3.5.0, with its arguments.

## Deutsch

### API

| Methode | Zweck |
|---|---|
| `Hooks::addAction(string $tag, callable $cb, int $priority = 10)` | Action registrieren |
| `Hooks::doAction(string $tag, ...$args)` | Action auslösen |
| `Hooks::didAction(string $tag): int` | wie oft im aktuellen Request ausgelöst |
| `Hooks::hasAction(string $tag, ?callable $cb = null, ?int $priority = null)` | Registrierung prüfen |
| `Hooks::removeAction(string $tag, callable $cb, int $priority = 10)` | entfernen (gleiche Priorität angeben) |
| `Hooks::addFilter(string $tag, callable $cb, int $priority = 10)` | Filter registrieren |
| `Hooks::applyFilters(string $tag, mixed $value, ...$args): mixed` | Wert durch die Filterkette reichen |
| `Hooks::removeFilter(string $tag, callable $cb, int $priority = 10)` | entfernen |

Verhalten: Callbacks werden je Tag nach Priorität sortiert und in Registrierungsreihenfolge ausgeführt; Filter geben den (veränderten) Wert zurück. Ausnahmen werden nicht von `Hooks` abgefangen – Callbacks sollten selbst fehlertolerant sein.

### Lebenszyklus & Routing

| Hook | Typ | Argumente | Ausgelöst in |
|---|---|---|---|
| `plugin_loaded` | Action | `$slug` | `PluginManager::loadPlugins()` je Plugin |
| `plugins_loaded` | Action | – | nach dem Laden aller Plugins |
| `theme_loaded` | Action | `$themeSlug` | `ThemeManager` |
| `cms_init` | Action | – | Ende `Bootstrap::initializeCore()` |
| `cms_init_<modus>` | Action | – | `cms_init_web`, `cms_init_admin`, `cms_init_api`, `cms_init_cli` |
| `cms_before_route` | Action | – | `Bootstrap::run()` |
| `register_routes` | Action | `Router $router` | vor dem Dispatch – eigene Routen mit `$router->addRoute()` |
| `cms_after_route` | Action | – | nach dem Dispatch |
| `cms_public_form_persistent_token` (`Router::PUBLIC_FORM_TOKEN_FILTER`) | Filter | `$current (null), $routingUri, $method` | globaler CSRF-Guard für öffentliche POSTs – Rückgabe `['action' => …, 'token' => …]` meldet ein Formular mit sessiongebundenem Persistent-Token an (ab 3.4.15, z. B. PHINIT-Favoriten) |
| `cms_content_request_context` | Filter | `$context, $uri` | Sprachkontext der Anfrage |
| `cms_content_supported_locales` | Filter | `$locales` | unterstützte Inhaltssprachen (Standard `de`, `en`) |

### Theme-Ausgabe

| Hook | Typ | Argumente | Hinweis |
|---|---|---|---|
| `template_name` | Filter | `$template` | Template-Namen umleiten |
| `before_render` / `after_render` | Action | `$template` | um jedes Template |
| `before_header` / `after_header` | Action | – | um `header.php` |
| `head` | Action | – | im `<head>` (Theme `header.php`, Member-Header) – CSS, Meta, Skripte mit Nonce |
| `page_title` | Filter | `$siteTitle` | Dokumenttitel im Theme |
| `before_footer` / `after_footer` | Action | – | um `footer.php` (`before_footer` einmal je Request) |
| `body_end` | Action | – | vor `</body>` (einmal je Request) |
| `content_render` | Filter | `$html, $type, $id` | gerenderter Seiten-/Beitragsinhalt (`Router::prepareRenderableContent()`) |
| `register_menu_locations` | Filter | `$locations` | Menüpositionen ergänzen |
| `site_favicon_default` | Filter | `$relativePath` | Standard-Favicon |
| `local_font_slugs` | Filter | `$slugs` | lokal ausgelieferte Schriften |
| `cms_csp_prepare` | Action | – | vor dem Senden der CSP – zusätzliche Quellen mit `Security::allowCspSources()` |

### Inhalte (Admin)

| Hook | Typ | Argumente |
|---|---|---|
| `cms_prepare_page_save_payload` | Filter | `$payload, $post, $id, $userId` |
| `cms_after_page_save` | Action | `$id, $payload, $post` |
| `cms_after_page_copy_de_to_en` | Action | `$id, $payload, $userId` |
| `page_deleted` | Action | `$id` |
| `cms_prepare_post_save_payload` | Filter | `$payload, $post, $id, $userId` |
| `cms_after_post_save` | Action | `$id, $payload, $post` |
| `cms_after_post_copy_de_to_en` | Action | `$id, $data, $userId` |
| `post_deleted` | Action | `$id` |
| `cms_prepare_hub_settings_payload` | Filter | `$settings, $post, $id` |
| `cms_prepare_hub_cards_payload` | Filter | `$cards, $post, $id` |
| `cms_after_hub_save` | Action | `$id, $settings, $cards, $post` |

### Lokalisierung, Hub-Sites, Tabellen

| Hook | Typ | Argumente |
|---|---|---|
| `cms_localized_<typ>_payload` | Filter | `$payload, $locale` (z. B. `cms_localized_page_payload`) |
| `cms_localized_content_payload` | Filter | `$settings, 'hub_settings', $locale, $context` |
| `cms_localized_hub_settings` | Filter | `$settings, $locale, $context` |
| `cms_localized_hub_cards` | Filter | `$cards, $locale, $context` |
| `cms_hub_template_profile` | Filter | `$profile, $templateKey, $locale, $table` |
| `site_table_interactive_config` | Filter | `$config, $settings, $rowCount` |

### Suche, SEO, Landingpage

| Hook | Typ | Argumente | Hinweis |
|---|---|---|---|
| `search_results` | Filter | `$results, $query, $limit[, $context]` | Plugin-Treffer für `/search` (`$context` = `type`, `locale`, `source`); optionales Feld `date` für die Sortierung |
| `search_register_indices` | Action | `SearchService $service` | eigene TNTSearch-Indizes |
| `cms_sitemap_entries` | Filter | `[]` | zusätzliche Sitemap-URLs (Plugin-Seiten) |
| `landing_page_plugins` | Filter | `[]` | Landingpage-Bausteine (`name`, `description`, `version`, `targets`) |

### Plugins, Admin, Benutzer, Abos

| Hook | Typ | Argumente |
|---|---|---|
| `cms_admin_menu` | Action | – (Menüs mit `add_menu_page()`/`add_submenu_page()` registrieren) |
| `admin_head` / `admin_body_end` | Action | – (Admin-Layout) |
| `plugin_activated`, `plugin_deactivated`, `plugin_before_delete`, `plugin_deleted` | Action | `$slug` |
| `plugin_installed` | Action | – |
| `user_registered` | Action | `$userId` |
| `subscription_assigned` | Action | `$userId, $planId` |
| `dsgvo_export_data` | Action | `$userId, $email` (Auskunft abgeschlossen) |
| `dsgvo_delete_data` | Action | `$userId, $email` (unmittelbar vor der endgültigen Kontolöschung – Plugins löschen hier ihre Daten) |
| `performance_cache_purged` | Action | `'all', $report` |
| `performance_cdn_purge_requested` | Action | `$payload` (CDN-Purge durch Plugin) |
| `theme_customizer_save` | Action | `$themeSlug, $tab, $sections, $context` |

### Member-Bereich

| Hook | Typ | Argumente |
|---|---|---|
| `member_head` | Action | `$pageKey, $user` |
| `body_start` | Action | – |
| `member_body_end` | Action | `$pageKey` |
| `member_menu_items` | Filter | `$items` |
| `member_dashboard_widgets` | Filter | `$widgets, $user, $settings` |
| `member_dashboard_init` | Action | `PluginDashboardRegistry $registry` |
| `member_plugin_section_head` | Action | `$section, $user, $params` |

### Cron

| Hook | Typ | Argumente |
|---|---|---|
| `cms_cron_mail_queue` | Action | `$context` |
| `cms_cron_hourly` | Action | – |
| `cms_cron_daily` | Action | – |
| `cms_cron_feeds` / `cms_cron_<name>` | Action | – (über `cron.php --task=…`) |

### Beispiele

```php
use CMS\Hooks;

// Eigene Route
Hooks::addAction('register_routes', static function (\CMS\Router $router): void {
    $router->addRoute('GET', '/status-seite', static function (): void {
        \CMS\ThemeManager::instance()->render('page', ['page' => ['title' => 'Status', 'content' => '<p>OK</p>']]);
    });
});

// Inhalt nachbearbeiten
Hooks::addFilter('content_render', static function (string $html, string $type, int $id): string {
    return $type === 'post' ? $html . '<p class="hinweis">Beitrag #' . $id . '</p>' : $html;
}, 20);

// Plugin-Treffer in der Suche
Hooks::addFilter('search_results', static function (array $results, string $query): array {
    $results[] = ['title' => 'Treffer', 'url' => '/mein-plugin/1', 'excerpt' => '…', 'type' => 'mein-plugin', 'date' => '2026-09-30'];
    return $results;
});
```

### Verwandte Dokumente

[ARCHITECTURE.md](ARCHITECTURE.md) · [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md) · [../theme/THEME-DEVELOPMENT.md](../theme/THEME-DEVELOPMENT.md)
