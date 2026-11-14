# 365CMS – Projektdokumentation | Abschnitt: Core – Hook-Referenz

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

`CMS\Hooks` (`CMS/core/Hooks.php`) is a WordPress-like action/filter registry. Callbacks are stored per tag and priority (lower runs first). WordPress-style wrappers (`add_action`, `do_action`, `add_filter`, `apply_filters`) live in `CMS/includes/functions/wordpress-compat.php`. `before_footer` and `body_end` fire at most once per request. This document lists every hook fired by the core, the admin, the member area and the shipped theme in 4.0.0, with its arguments. New in 4.0.0: account hooks for e-mail verification and confirmed e-mail changes (`user_email_verified`, `user_email_change_requested`, `user_email_changed`, `guest_orders_assigned`), `user_sessions_revoked`, the `cms_data_retention_days` filter and `cms_register_content_type_providers` (content type providers for trash, export/import and search).

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
| `cms_admin_menu_preferred_parents` | Filter | `$parents` (Menü-Slug ⇒ [Profil ⇒ Gruppen-ID]); **ohne Wirkung** seit „Aktive Plugins“ (nur Kompatibilität), ergänzt `cms_admin_menu_set_preferred_parent()` (seit 4.0.0, [ADMIN-MENU.md](../admin/ADMIN-MENU.md#4-plugin-menüs-im-abschnitt-aktive-plugins)) |
| `admin_head` / `admin_body_end` | Action | – (Admin-Layout) |
| `plugin_activated`, `plugin_deactivated`, `plugin_before_delete`, `plugin_deleted` | Action | `$slug` |
| `plugin_installed` | Action | – |
| `user_registered` | Action | `$userId` |
| `user_email_verified` | Action | `$userId` (Adresse per Link aus `/cms-verify-email` bestätigt; seit 4.0.0) |
| `user_email_change_requested` | Action | `$userId, $newEmail, $initiator` (`'member'`/`'admin'`; Bestätigungslink an die neue Adresse verschickt, nicht wenn die Adresse einem anderen Konto gehört; seit 4.0.0) |
| `user_email_changed` | Action | `$userId, $previousEmail, $newEmail` (nach Bestätigung über `/cms-confirm-email-change` oder Admin-Änderung ohne Bestätigung; seit 4.0.0) |
| `guest_orders_assigned` | Action | `$userId, $count` (Gastbestellungen der bestätigten Adresse dem Konto zugeordnet; seit 4.0.0) |
| `user_sessions_revoked` | Action | `$userId, $initiator` (`'member'`/`'admin'`; alle Sitzungen und API-Tokens beendet, `Auth::revokeAllSessions()`; seit 4.0.0) |
| `cms_password_changed` | Action | `$userId, $source` (`'register'`, `'member'`, `'admin_create'`, `'admin_update'`, `'reset'`; nach jedem gespeicherten Passwort, `PasswordPolicyService::recordChange()`; seit 4.1.0) |
| `cms_sso_login` | Action | `$userId, $provider` (`'entra'`), `$claims` (`tid`, `oid`, `name`, `email`, `roles`, `groups`, `amr`; nie Tokens); nach jeder SSO-Anmeldung mit fertiger Sitzung (seit 4.1.0) |
| `cms_sso_user_provisioned` | Action | `$userId, $provider, $claims`; Konto bei der ersten SSO-Anmeldung angelegt (seit 4.1.0) |
| `user_role_changed` | Action | `$userId, $fromRole, $toRole, $source` (`'sso'` = Rollen-Mapping der Entra-Anmeldung; seit 4.1.0) |
| `cms_mfa_enforcement_required` | Filter | `$required` (bool), `$user`; kann den MFA-Zwang für ein Konto zusätzlich einschalten, für eine gewählte Rolle nicht aufheben (seit 4.1.0) |
| `cms_password_policy` | Filter | `$policy` (`min_length`, `require_uppercase`/`_lowercase`/`_digit`/`_special`, `max_age_days`, `history`); Ergebnis kann die Einstellungen nur verschärfen, nie abschwächen (seit 4.1.0) |
| `subscription_assigned` | Action | `$userId, $planId` |
| `dsgvo_export_data` | Action | `$userId, $email` (Auskunft abgeschlossen) |
| `dsgvo_delete_data` | Action | `$userId, $email` (unmittelbar vor der endgültigen Kontolöschung – Plugins löschen hier ihre Daten) |
| `performance_cache_purged` | Action | `'all', $report` |
| `performance_cdn_purge_requested` | Action | `$payload` (CDN-Purge durch Plugin) |
| `cms_data_retention_days` | Filter | `$days` – Tage je Bereich (`page_views`, `core_web_vitals`, `not_found_logs`, `log_files`, `activity_log`, `audit_log`); 0 = nicht löschen, sonst mindestens 7 Tage (Protokolle 90), höchstens 3650 (`DataRetentionService::getRetentionDays()`) |
| `theme_customizer_save` | Action | `$themeSlug, $tab, $sections, $context` |
| `cms_register_content_type_providers` (`ContentTypeRegistry::REGISTER_ACTION`) | Action | `ContentTypeRegistry $registry` – Inhaltstyp-Provider für Papierkorb, Export/Import und Suchindex anmelden; einmal beim ersten Zugriff auf die Registry ausgelöst (seit 4.0.0, [CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md)) |

### Member-Bereich

| Hook | Typ | Argumente |
|---|---|---|
| `member_head` | Action | `$pageKey, $user` – der Newsletter lädt hier `css/member-newsletter.css` für `dashboard` und `plugin_newsletter` |
| `body_start` | Action | – |
| `member_body_end` | Action | `$pageKey` |
| `member_menu_items` | Filter | `$items` – Einträge dürfen `priority` und `parent_slug` tragen; das Ergebnis wird nach Kategorie (`core`, `content`, `account`, `plugins`, `admin`) und Priorität sortiert, Unterpunkte stehen in `children` (seit 4.0.0) |
| `member_dashboard_widgets` | Filter | `$widgets, $user, $settings` – Einträge dürfen `html` (fertig escaptes Markup, unverändert ausgegeben) und `anchor` (`[a-z0-9_-]`) tragen (seit 4.0.0) |
| `member_dashboard_init` | Action | `PluginDashboardRegistry $registry` |
| `member_plugin_section_head` | Action | `$section, $user, $params` |
| `cms_member_notification_created` | Action | `int $id, int $userId, string $type` – nach dem Anlegen durch `NotificationService::create()` (seit 4.0.0) |

**`PluginDashboardRegistry::register()` (seit 4.0.0):**

- `dashboard_widget.render_callback` (`fn(object $user, array $context): string`, Kontext `slug`, `plugin`, `action_url`, `csrf_field`, `return_to`, `anchor`) liefert fertig escaptes Markup statt der Zählerkachel; `getDashboardWidgets()` setzt dann `html` und `anchor`. Wirft der Callback oder liefert er `''`, erscheint die Kachel. `dashboard_widget.anchor` (string) ist die HTML-id.
- `post_callback(object $user, array $params)` läuft vor jeder Ausgabe und gibt `['type', 'message', 'redirect'?, 'payload'?]` oder einen lokalen Pfad zurück; der Core setzt daraus eine Flash-Meldung und leitet mit 303 weiter (Post/Redirect/Get). Ausgaben ohne Rückgabewert werden einmal im Rahmen angezeigt.
- `csrf`: Standard `'core'` (Token-Aktion `member_plugin_<slug>`, ein Token je Bereich und Seitenaufruf); `'plugin'` nur ausdrücklich, mit Hinweis im Debug-Log.
- Routenparameter `:action`/`:id` kommen als `$params` an `render_callback` und `post_callback`, nicht mehr über `$_GET`.

**Theme-Vorlagen (seit 4.0.0):**

| Datei im Theme | Ersetzt | Variablen |
|---|---|---|
| `member/widgets/newsletter.php` | `views/member/widgets/newsletter.php` | `$status` (aus `NewsletterMemberService::statusFor()`), `$csrfField`, `$actionUrl`, `$segments`, `$locales`, `$consentText`, `$notice`, `$returnTo` |
| `member/partials/plugin-not-found.php` | `member/partials/plugin-not-found.php` | `$pluginErrorStatus` (403/404) |
| `auth/showcase.php` | `views/auth/partials/showcase.php` | `$showcase` (aus `CmsAuthPageService::getShowcase()`, Rohwerte), `$pageType` (`login`/`register`), `$showcaseMode` (`cms`/`legacy`); im Modus `cms` lädt die Loginseite kein Theme-CSS, Stile daher als `<style nonce>` mitliefern |
| `login.php`, `register.php` (Modus `legacy`) | – | zusätzlich `$showcase` |

`theme.json` kann unter `login_page.colors` Farben (`#rrggbb`) für die Core-Loginseite vorschlagen (Schlüssel wie `CmsAuthPageService::COLOR_SETTING_KEYS`); übernommen werden sie nur per Schaltfläche unter `/admin/cms-loginpage`.

### Formulare, Downloads, Knowledgebase, Newsletter, Medien (seit 4.0.0)

| Hook | Typ | Argumente | Hinweis |
|---|---|---|---|
| `cms_form_submitted` | Action | `int $submissionId`, `array $form`, `array $data` | nach dem Speichern eines Formulareingangs (`FormFrontendController`); Fehler in Callbacks werden protokolliert und brechen die Absendung nicht ab |
| `contact_submitted` | Action | `int $submissionId`, `int $formId`, `array $meta` | Kompatibilität zum früheren Plugin cms-contact; neue Erweiterungen nutzen `cms_form_submitted` |
| `cms_after_download_save` | Action | `int $id`, `array $item` | nach dem Speichern eines Downloads |
| `cms_download_trashed` | Action | `int $id` | Download in den Papierkorb verschoben |
| `download_deleted` | Action | `int $id` | Download endgültig gelöscht (Name wie im früheren Plugin) |
| `cms_download_access` | Filter | `string $state`, `array $item`, `?object $user` | Zugriffsentscheidung; erlaubt sind nur `granted`, `login`, `role`, `membership` (sonst gilt der ursprüngliche Wert) |
| `cms_knowledgebase_entry_saved` | Action | `int $id` | Knowledgebase-Eintrag angelegt, gespeichert oder aus dem Papierkorb geholt |
| `cms_knowledgebase_entry_trashed` | Action | `int $id` | Knowledgebase-Eintrag in den Papierkorb gelegt |
| `cms_knowledgebase_entry_deleted` | Action | `int $id` | Knowledgebase-Eintrag endgültig gelöscht |
| `cms_newsletter_subscribed` | Action | `int $subscriberId` | Newsletter-Abonnent aktiv (bestätigt, angelegt oder reaktiviert) |
| `cms_newsletter_unsubscribed` | Action | `int $subscriberId` | Abonnent abgemeldet |
| `cms_newsletter_subscriber_deleted` | Action | `int $subscriberId` | Abonnent endgültig gelöscht |
| `cms_newsletter_campaign_started` | Action | `int $campaignId` | Kampagnenversand gestartet |
| `cms_newsletter_campaign_sent` | Action | `int $campaignId` | alle Mails einer Kampagne verarbeitet |
| `cms_member_export_data` | Filter | `array $export`, `int $userId`, `string $email` | Selbstauskunft im Mitgliederbereich ergänzen (Formulare: `form_submissions`, Newsletter: `newsletter`) |
| `cms_image_figcaption_credit` | Filter | `string $html`, `string $imageUrl`, `array $context` (`context` = image/gallery/carousel, `original_url`) | Credit-Zeile in Bildunterschriften; der Core hängt hier die Urheberangaben der Mediathek an |
| `cms_media_credit_line_enabled` | Filter | `bool $enabled`, `string $imageUrl`, `array $context` | Credit-Zeile je Bild ab- oder einschalten (z. B. im Theme) |
| `cms_after_site_search` | Action | `array $parsed`, `int $resultCount`, `string $locale`, `string $type` | nach einer Suche auf der Suchseite; speist die anonyme Suchbegriff-Statistik |

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

[ARCHITECTURE.md](ARCHITECTURE.md) · [CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md) · [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md) · [../theme/THEME-DEVELOPMENT.md](../theme/THEME-DEVELOPMENT.md)
