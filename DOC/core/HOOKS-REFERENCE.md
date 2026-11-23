# 365CMS Projektdokumentation | Core: Hook-Referenz

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable

## English (summary)

`CMS\Hooks` (`CMS/core/Hooks.php`) is a WordPress-like action/filter registry. Callbacks are stored per tag and priority (lower runs first). WordPress-style wrappers (`add_action`, `do_action`, `add_filter`, `apply_filters`) live in `CMS/includes/functions/wordpress-compat.php`. `before_footer` and `body_end` fire at most once per request. This document lists every hook fired by the core, the admin, the member area and the shipped theme in 4.0.0, with its arguments. New in 4.0.0: account hooks for e-mail verification and confirmed e-mail changes (`user_email_verified`, `user_email_change_requested`, `user_email_changed`, `guest_orders_assigned`), `user_sessions_revoked`, the `cms_data_retention_days` filter and `cms_register_content_type_providers` (content type providers for trash, export/import and search). New in 4.2.0 (in development): the `cms_community_*` hooks of the community module (actions for the topic and post lifecycle, moderation, reports, bans, reactions and subscriptions; the filters `cms_community_can`, `cms_community_render_post`, `cms_community_spam_check` and `cms_community_notification_types`; see the section “Community (4.2.0)”), fired after the commit with IDs and status values only (exception: `cms_community_spam_check` receives title and text).

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
| `Hooks::getCallbacks(string $tag, string $type = 'filter'): array` | registrierte Callbacks eines Tags in Ausführungsreihenfolge (`'filter'` oder `'action'`), z. B. für Filterketten, die jeden Schritt einzeln prüfen (seit 4.2.0) |
| `Hooks::doActionIsolated(string $tag, ?callable $onError, ...$args)` | Action auslösen, jeder Verbraucher einzeln abgesichert: eine Ausnahme geht an `$onError` und hindert die übrigen nicht; zählt für `didAction()` wie `doAction()` (seit 4.2.0) |

Verhalten: Callbacks werden je Tag nach Priorität sortiert und in Registrierungsreihenfolge ausgeführt; Filter geben den (veränderten) Wert zurück. `doAction()` und `applyFilters()` fangen keine Ausnahmen ab (nur `doActionIsolated()`). Callbacks sollten selbst fehlertolerant sein.

### Lebenszyklus & Routing

| Hook | Typ | Argumente | Ausgelöst in |
|---|---|---|---|
| `plugin_loaded` | Action | `$slug` | `PluginManager::loadPlugins()` je Plugin |
| `plugins_loaded` | Action | – | nach dem Laden aller Plugins |
| `theme_loaded` | Action | `$themeSlug` | `ThemeManager` |
| `cms_init` | Action | – | Ende `Bootstrap::initializeCore()` |
| `cms_init_<modus>` | Action | – | `cms_init_web`, `cms_init_admin`, `cms_init_api`, `cms_init_cli` |
| `cms_before_route` | Action | – | `Bootstrap::run()` |
| `register_routes` | Action | `Router $router` | vor dem Dispatch: eigene Routen mit `$router->addRoute()` |
| `cms_after_route` | Action | – | nach dem Dispatch |
| `cms_public_form_persistent_token` (`Router::PUBLIC_FORM_TOKEN_FILTER`) | Filter | `$current (null), $routingUri, $method` | globaler CSRF-Guard für öffentliche POSTs: Rückgabe `['action' => …, 'token' => …]` meldet ein Formular mit sessiongebundenem Persistent-Token an (ab 3.4.15, z. B. PHINIT-Favoriten) |
| `cms_content_request_context` | Filter | `$context, $uri` | Sprachkontext der Anfrage |
| `cms_content_supported_locales` | Filter | `$locales` | unterstützte Inhaltssprachen (Standard `de`, `en`) |

### Theme-Ausgabe

| Hook | Typ | Argumente | Hinweis |
|---|---|---|---|
| `template_name` | Filter | `$template` | Template-Namen umleiten |
| `before_render` / `after_render` | Action | `$template` | um jedes Template |
| `before_header` / `after_header` | Action | – | um `header.php` |
| `head` | Action | – | im `<head>` (Theme `header.php`, Member-Header): CSS, Meta, Skripte mit Nonce |
| `page_title` | Filter | `$siteTitle` | Dokumenttitel im Theme |
| `before_footer` / `after_footer` | Action | – | um `footer.php` (`before_footer` einmal je Request) |
| `body_end` | Action | – | vor `</body>` (einmal je Request) |
| `content_render` | Filter | `$html, $type, $id` | gerenderter Seiten-/Beitragsinhalt (`Router::prepareRenderableContent()`) |
| `register_menu_locations` | Filter | `$locations` | Menüpositionen ergänzen |
| `site_favicon_default` | Filter | `$relativePath` | Standard-Favicon |
| `local_font_slugs` | Filter | `$slugs` | lokal ausgelieferte Schriften |
| `cms_csp_prepare` | Action | – | vor dem Senden der CSP: zusätzliche Quellen mit `Security::allowCspSources()` |
| `main_nav` | Action | `string $context` (`desktop` \| `mobile`) | Standard-Theme: Einträge an die Hauptnavigation anhängen (z. B. Wissensdatenbank) |

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
| `cms_content_submitted` | Action | `string $type, int $id, int $userId, string $comment`; Beitrag/Seite zur Prüfung eingereicht ([REVIEW.md](../admin/pages-posts/REVIEW.md)) |
| `cms_content_approved` | Action | `string $type, int $id, int $userId, string $comment`; eingereichter Inhalt freigegeben |
| `cms_reusable_block_saved` | Action | `int $blockId`; EditorJS Block Vorlage angelegt oder geändert ([BLOCK-TEMPLATES.md](../admin/pages-posts/BLOCK-TEMPLATES.md)) |
| `cms_reusable_block_deleted` | Action | `int $blockId`; Vorlage gelöscht |
| `cms_reusable_block_changed` | Action | `int $blockId, list<array{type,id}> $linked`; nach Änderung/Löschen, nachdem Cache geleert und die Speicher-Hooks der verknüpften Beiträge/Seiten ausgelöst wurden |
| `cms_content_expired` | Action | `string $type, int $id, string $action` (`draft`/`mark`); Inhalt abgelaufen (Cron stündlich) |
| `cms_content_review_due` | Action | `string $type, int $id`; Wiedervorlage fällig, einmal je Termin (Cron täglich) |
| `cms_content_returned` | Action | `string $type, int $id, int $userId, string $comment`; eingereichter Inhalt mit Begründung zurückgegeben |
| `cms_content_withdrawn` | Action | `string $type, int $id, int $userId, string $comment`; Einreichung zurückgezogen (seit 4.1.9; die Prüfenden werden benachrichtigt) |

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
| `cms_admin_menu` | Action |: (Menüs mit `add_menu_page()`/`add_submenu_page()` registrieren) |
| `cms_admin_menu_preferred_parents` | Filter | `$parents` (Menü-Slug ⇒ [Profil ⇒ Gruppen-ID]); **ohne Wirkung** seit „Aktive Plugins“ (nur Kompatibilität), ergänzt `cms_admin_menu_set_preferred_parent()` ([ADMIN-MENU.md](../admin/ADMIN-MENU.md#4-plugin-menüs-im-abschnitt-aktive-plugins)) |
| `admin_head` / `admin_body_end` | Action |: (Admin-Layout) |
| `plugin_activated`, `plugin_deactivated`, `plugin_before_delete`, `plugin_deleted` | Action | `$slug` |
| `plugin_installed` | Action | – |
| `user_registered` | Action | `$userId` |
| `user_email_verified` | Action | `$userId` (Adresse per Link aus `/cms-verify-email` bestätigt; seit 4.0.0) |
| `user_email_change_requested` | Action | `$userId, $newEmail, $initiator` (`'member'`/`'admin'`; Bestätigungslink an die neue Adresse verschickt, nicht wenn die Adresse einem anderen Konto gehört; seit 4.0.0) |
| `user_email_changed` | Action | `$userId, $previousEmail, $newEmail` (nach Bestätigung über `/cms-confirm-email-change` oder Admin-Änderung ohne Bestätigung; seit 4.0.0) |
| `guest_orders_assigned` | Action | `$userId, $count` (Gastbestellungen der bestätigten Adresse dem Konto zugeordnet; seit 4.0.0) |
| `user_sessions_revoked` | Action | `$userId, $initiator` (`'member'`/`'admin'`; alle Sitzungen und API-Tokens beendet, `Auth::revokeAllSessions()`; seit 4.0.0) |
| `cms_password_changed` | Action | `$userId, $source` (`'register'`, `'member'`, `'admin_create'`, `'admin_update'`, `'reset'`; nach jedem gespeicherten Passwort, `PasswordPolicyService::recordChange()`) |
| `cms_sso_login` | Action | `$userId, $provider` (`'entra'`), `$claims` (`tid`, `oid`, `name`, `email`, `roles`, `groups`, `amr`; nie Tokens); nach jeder SSO-Anmeldung mit fertiger Sitzung |
| `cms_sso_user_provisioned` | Action | `$userId, $provider, $claims`; Konto bei der ersten SSO-Anmeldung angelegt |
| `user_role_changed` | Action | `$userId, $fromRole, $toRole, $source`; nach jedem gespeicherten Rollenwechsel eines bestehenden Kontos, nie bei unveränderter Rolle (Groß-/Kleinschreibung zählt nicht) und nicht bei neuen Konten. `$source`: `'sso'` = Rollen-Mapping der Entra-Anmeldung (seit 4.1.0), `'admin_update'` = *Benutzer bearbeiten* (`UserService::updateUser()`), `'admin_bulk'` = Sammelaktion `change_role` (`UserService::bulkAction()`), `'role_renamed'` und `'role_deleted'` = Rolle umbenannt bzw. gelöscht (`RolesModule`, nach dem Commit; jedes Konto der Rolle meldet einzeln, `$toRole` ist der neue Name bzw. die Ersatzrolle); die vier Quellen außer `'sso'` seit 4.2.0. Ein Fehler eines Verbrauchers wird protokolliert (Kanal `user.service`) und macht die gespeicherte Änderung nicht rückgängig; die Quellen sind Konstanten `UserService::ROLE_SOURCE_*` |
| `cms_community_retention_days` | Filter | `array<string,int> $days` (`ip_hash`, `read_states`, `orphan_uploads`, `drafts`, `reports`, `moderation_log`, `warnings`, `bans`, `revisions`) | Löschfristen der Community in Tagen (4.2.0); 0 = nie, sonst 7 bis 3650; `orphan_uploads` nur 0 oder 1 (der Dienst räumt fest nach 24 Stunden). Die Community hört außerdem auf `dsgvo_export_data`, `dsgvo_delete_data`, `cms_user_deleted` (idempotent) und `cms_member_export_data` (Schlüssel `community`), auch bei abgeschaltetem Modul |
| `cms_captcha_providers` | Filter | `array<string,CaptchaProvider> $providers` (`pow`, `image`, `math`) | Weitere oder ersetzte Captcha-Verfahren des Core-Dienstes `Security\CaptchaService` (4.2.0, E7: nur lokale Verfahren). Einträge brauchen ein Objekt mit `CaptchaProvider` und eine Kennung `[a-z][a-z0-9_]{0,19}`; ungültige entfallen, `math` bleibt immer. Ein Verfahren, das nicht `available()` ist oder `requiresConsent()` meldet, wird nie automatisch gewählt (Rückfall auf die Rechenaufgabe). |
| `cms_forms_captcha_provider` | Filter | `string $provider` (Standard `pow`), `string $formKey` | Captcha-Verfahren eines Formulars der Antispam-Stufe „hoch“ (4.2.0): `pow`, `image` oder `math` (oder ein über `cms_captcha_providers` angemeldetes Verfahren); ein unbekanntes oder nicht verfügbares fällt im Dienst auf die Rechenaufgabe zurück. Die Aufgabe gilt nur im Kontext `forms.<Formularschlüssel>`. |
| `cms_community_captcha_required` | Filter | `bool $required, int $userId, string $action` (`create_topic`, `reply`) | Ob ein Mitglied beim Schreiben ein Captcha lösen muss (4.2.0). Darf nur verschärfen: `true` verlangt eines (auch bei Modus `off` und für die Moderation), `false` ändert nichts. Der Modus kommt aus der Einstellung `captcha_mode`. |
| `cms_role_renamed` | Action | `$oldSlug, $newSlug`; einmal nach dem Commit von `RolesModule::updateRole()`, nur bei tatsächlicher Umbenennung (4.2.0). Zusätzlich meldet `user_role_changed` jedes Konto. Verbraucher: `FeatureHooks\CommunityHooks` (schreibt die Rechte-Zeilen der Community um, auch bei abgeschaltetem Modul) |
| `cms_role_deleted` | Action | `$slug, $fallbackSlug`; einmal nach dem Commit von `RolesModule::deleteRole()` (4.2.0). Verbraucher: `FeatureHooks\CommunityHooks` (löscht die Rechte-Zeilen der Rolle) |
| `cms_mfa_enforcement_required` | Filter | `$required` (bool), `$user`; kann den MFA-Zwang für ein Konto zusätzlich einschalten, für eine gewählte Rolle nicht aufheben |
| `cms_password_policy` | Filter | `$policy` (`min_length`, `require_uppercase`/`_lowercase`/`_digit`/`_special`, `max_age_days`, `history`); Ergebnis kann die Einstellungen nur verschärfen, nie abschwächen |
| `subscription_assigned` | Action | `$userId, $planId` |
| `dsgvo_export_data` | Action | `$userId, $email` (Auskunft abgeschlossen) |
| `dsgvo_delete_data` | Action | `$userId, $email` (unmittelbar vor der endgültigen Kontolöschung: Plugins löschen hier ihre Daten) |
| `performance_cache_purged` | Action | `'all', $report` |
| `performance_cdn_purge_requested` | Action | `$payload` (CDN-Purge durch Plugin) |
| `cms_data_retention_days` | Filter | `$days`: Tage je Bereich (`page_views`, `core_web_vitals`, `not_found_logs`, `log_files`, `activity_log`, `audit_log`); 0 = nicht löschen, sonst mindestens 7 Tage (Protokolle 90), höchstens 3650 (`DataRetentionService::getRetentionDays()`) |
| `cms_mail_retention_days` | Filter | `array{mail_queue:int, mail_log:int} $days` | Löschfristen der Mail-Warteschlange (Standard 30 Tage) und des Mail-Protokolls (Standard 90 Tage) für `MailRetention`; 0 = nicht löschen, sonst 7 bis 3650 Tage |
| `cms_feature_usage_retention_days` | Filter | `int $days` | Löschfrist der Nutzungsstatistik (`FeatureUsageService`, Standard 180 Tage); 0 = nie, sonst 7 bis 3650 Tage |
| `theme_customizer_save` | Action | `$themeSlug, $tab, $sections, $context` |
| `cms_register_content_type_providers` (`ContentTypeRegistry::REGISTER_ACTION`) | Action | `ContentTypeRegistry $registry`: Inhaltstyp-Provider für Papierkorb, Export/Import und Suchindex anmelden; einmal beim ersten Zugriff auf die Registry ausgelöst ([CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md)) |

### Member-Bereich

| Hook | Typ | Argumente |
|---|---|---|
| `member_head` | Action | `$pageKey, $user`: der Newsletter lädt hier `css/member-newsletter.css` für `dashboard` und `plugin_newsletter` |
| `body_start` | Action | – |
| `member_body_end` | Action | `$pageKey` |
| `member_menu_items` | Filter | `$items`: Einträge dürfen `priority` und `parent_slug` tragen; das Ergebnis wird nach Kategorie (`core`, `content`, `account`, `plugins`, `admin`) und Priorität sortiert, Unterpunkte stehen in `children` |
| `member_dashboard_widgets` | Filter | `$widgets, $user, $settings`: Einträge dürfen `html` (fertig escaptes Markup, unverändert ausgegeben) und `anchor` (`[a-z0-9_-]`) tragen |
| `member_dashboard_init` | Action | `PluginDashboardRegistry $registry` |
| `member_plugin_section_head` | Action | `$section, $user, $params` |
| `cms_member_notification_created` | Action | `int $id, int $userId, string $type`: nach dem Anlegen durch `NotificationService::create()` |

**`PluginDashboardRegistry::register()`:**

- `dashboard_widget.render_callback` (`fn(object $user, array $context): string`, Kontext `slug`, `plugin`, `action_url`, `csrf_field`, `return_to`, `anchor`) liefert fertig escaptes Markup statt der Zählerkachel; `getDashboardWidgets()` setzt dann `html` und `anchor`. Wirft der Callback oder liefert er `''`, erscheint die Kachel. `dashboard_widget.anchor` (string) ist die HTML-id.
- `post_callback(object $user, array $params)` läuft vor jeder Ausgabe und gibt `['type', 'message', 'redirect'?, 'payload'?]` oder einen lokalen Pfad zurück; der Core setzt daraus eine Flash-Meldung und leitet mit 303 weiter (Post/Redirect/Get). Ausgaben ohne Rückgabewert werden einmal im Rahmen angezeigt.
- `csrf`: Standard `'core'` (Token-Aktion `member_plugin_<slug>`, ein Token je Bereich und Seitenaufruf); `'plugin'` nur ausdrücklich, mit Hinweis im Debug-Log.
- Routenparameter `:action`/`:id` kommen als `$params` an `render_callback` und `post_callback`, nicht mehr über `$_GET`.

**Theme-Vorlagen:**

| Datei im Theme | Ersetzt | Variablen |
|---|---|---|
| `member/widgets/newsletter.php` | `views/member/widgets/newsletter.php` | `$status` (aus `NewsletterMemberService::statusFor()`), `$csrfField`, `$actionUrl`, `$segments`, `$locales`, `$consentText`, `$notice`, `$returnTo` |
| `member/partials/plugin-not-found.php` | `member/partials/plugin-not-found.php` | `$pluginErrorStatus` (403/404) |
| `auth/showcase.php` | `views/auth/partials/showcase.php` | `$showcase` (aus `CmsAuthPageService::getShowcase()`, Rohwerte), `$pageType` (`login`/`register`), `$showcaseMode` (`cms`/`legacy`); im Modus `cms` lädt die Loginseite kein Theme-CSS, Stile daher als `<style nonce>` mitliefern |
| `login.php`, `register.php` (Modus `legacy`) | – | zusätzlich `$showcase` |

`theme.json` kann unter `login_page.colors` Farben (`#rrggbb`) für die Core-Loginseite vorschlagen (Schlüssel wie `CmsAuthPageService::COLOR_SETTING_KEYS`); übernommen werden sie nur per Schaltfläche unter `/admin/cms-loginpage`.

### Formulare, Downloads, Knowledgebase, Newsletter, Medien

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
| `cms_register_media_reference_extractors` | Action | `MediaUsageIndex $index` → `$index->register(MediaReferenceExtractor)`; weitere Inhaltsarten für den Verwendungsnachweis | Verwendungsindex |
| `cms_after_form_save` | Action | `int $formId`; nach Anlegen oder Ändern eines Formulars | Formulare |
| `cms_form_deleted` | Action | `int $formId`; nach endgültigem Löschen | Formulare |
| `cms_theme_customizer_saved` | Action | `string $theme, string $category, string $key`; nach jedem gespeicherten Theme-Wert | Theme |
| `cms_media_derivative_created` | Action | `string $path` (absolut), `string $format` (`'avif'`), `int $bytes`; nach jeder behaltenen AVIF-Fassung | Erzeugte Bildfassung |
| `cms_image_figcaption_credit` | Filter | `string $html`, `string $imageUrl`, `array $context` (`context` = image/gallery/carousel, `original_url`) | Credit-Zeile in Bildunterschriften; der Core hängt hier die Urheberangaben der Mediathek an |
| `cms_media_credit_line_enabled` | Filter | `bool $enabled`, `string $imageUrl`, `array $context` | Credit-Zeile je Bild ab- oder einschalten (z. B. im Theme) |
| `cms_after_site_search` | Action | `array $parsed`, `int $resultCount`, `string $locale`, `string $type` | nach einer Suche auf der Suchseite; speist die anonyme Suchbegriff-Statistik |

### Community (4.2.0)

Modul `community` (Kernmodul, standardmäßig aus). Die Namen stehen als Konstanten in `CMS\Services\Community\Support\Events`. Aktionen werden nach dem Commit über `Events::fire()` ausgelöst, nur bei einer Änderung; Systemaktionen tragen den Akteur `0`. Fehler in Callbacks werden protokolliert (Kanal `community`) und brechen den Vorgang nicht ab. Argumente sind IDs und Statuswerte, nie Inhalte. Ausnahme: der Filter `cms_community_spam_check` bekommt Titel und Text. Das Modul selbst hört auf `user_role_changed` und `user_email_verified` und verwirft dann den Rechte-Cache des Benutzers (`FeatureHooks\CommunityHooks`, nur bei aktivem Modul).

| Hook | Typ | Argumente | Hinweis |
|---|---|---|---|
| `cms_community_topic_created` | Action | `int $topicId`, `int $forumId`, `int $userId`, `string $status`, `int $firstPostId` | Thema samt erstem Beitrag gespeichert; `status` = `published` oder `pending` (Freigabepflicht). Auslöser: `TopicService::create` |
| `cms_community_topic_updated` | Action | `int $topicId`, `int $forumId`, `int $actorId`, `array $changed` | Thema geändert; `changed` nennt die geänderten Felder (z. B. `['title', 'slug']`). Auslöser: `PostService::edit` (Titel), `ModerationService` (schließen, öffnen, anheften, lösen, ankündigen, Ankündigung beenden, umbenennen) |
| `cms_community_topic_deleted` | Action | `int $topicId`, `int $forumId`, `int $actorId`, `string $mode` | Thema gelöscht; `mode` = `deleted` (weich), später `purged` (endgültig). Auslöser: `TopicService::delete` |
| `cms_community_post_created` | Action | `int $postId`, `int $topicId`, `int $userId`, `string $status` | Antwort gespeichert; **nicht** für den Themenstart (den meldet `cms_community_topic_created`); `status` = `published` oder `pending`. Auslöser: `PostService::reply` |
| `cms_community_post_updated` | Action | `int $postId`, `int $topicId`, `int $editorId`, `int $revisionId` | Beitrag bearbeitet; `revisionId` = die im Bearbeitungsverlauf gespeicherte Fassung, `0`: nur die Anhänge geändert. Auslöser: `PostService::edit` |
| `cms_community_post_deleted` | Action | `int $postId`, `int $topicId`, `int $actorId`, `string $mode` | Beitrag gelöscht (`deleted`, später `purged`); der erste Beitrag wird nur zusammen mit dem Thema gelöscht. Auslöser: `PostService::delete` |
| `cms_community_can` | Filter | `AccessDecision $decision`, `int $userId`, `string $action`, `array $context` | letzter Schritt von `CommunityAccess::can()` (nur bei erlaubter Entscheidung; `$context` kommt nur aus Dienst- oder Controller-Code). Jeder Filter läuft einzeln und bekommt die Entscheidung, die der vorige übrig gelassen hat. Er darf eine Erlaubnis nur ablehnen oder verschärfen (wartend statt veröffentlicht, Platzhalter statt voll), nie erweitern oder eine Ablehnung aufheben: die erste Ablehnung steht fest und beendet die Kette (Grund `filter_denied`, bei `read` und `search` `not_found`); eine Ausnahme im Filter gilt als Ablehnung (fail-closed). Ungültige Rückgaben und Erweiterungen werden ignoriert und protokolliert |
| `cms_community_post_approved` | Action | `int $postId`, `int $topicId`, `int $moderatorId` | wartende Antwort freigegeben; für den ersten Beitrag eines Themas feuert `cms_community_topic_approved`. Auslöser: `ModerationService::approvePost` |
| `cms_community_post_rejected` | Action | `int $postId`, `int $topicId`, `int $moderatorId`, `int $logId` | Beitrag abgelehnt; den Grund liefert der Protokolleintrag `logId` (`community_moderation_log`). **Vertragsänderung** gegenüber der Vorankündigung (`string $reason`), der Hook wurde vorher nie ausgelöst. Auslöser: `ModerationService::rejectPost` |
| `cms_community_post_hidden` | Action | `int $postId`, `int $topicId`, `int $actorId`, `int $logId` | Beitrag ausgeblendet; Akteur `0` = automatisch (Meldeschwelle). Auslöser: `ModerationService::hidePost`, `systemAction(hide)` |
| `cms_community_post_restored` | Action | `int $postId`, `int $topicId`, `int $actorId`, `string $fromStatus` | Beitrag wiederhergestellt; `fromStatus` = Status davor. Auslöser: `ModerationService::restorePost`, `systemAction(restore)` |
| `cms_community_post_moved` | Action | `int $postId`, `int $fromTopicId`, `int $toTopicId`, `int $actorId` | Beitrag in ein anderes Thema verschoben; je Beitrag, aufsteigend nach ID. Auslöser: `ModerationService::movePosts` |
| `cms_community_topic_approved` | Action | `int $topicId`, `int $forumId`, `int $moderatorId`, `int $firstPostId` | wartendes Thema samt erstem Beitrag freigegeben. Auslöser: `ModerationService::approveTopic` |
| `cms_community_topic_rejected` | Action | `int $topicId`, `int $forumId`, `int $moderatorId`, `int $logId` | Thema abgelehnt, danach `deleted`; Grund im Protokolleintrag `logId`. Auslöser: `ModerationService::rejectTopic` |
| `cms_community_topic_hidden` | Action | `int $topicId`, `int $forumId`, `int $actorId`, `int $logId` | Thema ausgeblendet; Akteur `0` = automatisch (Meldeschwelle). Auslöser: `ModerationService::hideTopic`, `systemAction(hide)` |
| `cms_community_topic_restored` | Action | `int $topicId`, `int $forumId`, `int $actorId`, `string $status` | Thema wiederhergestellt; `status` = `published` oder `pending`. Auslöser: `ModerationService::restoreTopic`, `systemAction(restore)` |
| `cms_community_topic_moved` | Action | `int $topicId`, `int $fromForumId`, `int $toForumId`, `int $actorId`, `int $stubTopicId` | Thema in ein anderes Forum verschoben; `stubTopicId` = Verweis-Thema im alten Forum, `0`: kein Verweis. Auslöser: `ModerationService::moveTopic` |
| `cms_community_solution_marked` | Action | `int $topicId`, `int $postId`, `int $actorId` | Antwort als Lösung markiert. Auslöser: `ModerationService::markSolution` |
| `cms_community_solution_unmarked` | Action | `int $topicId`, `int $postId`, `int $actorId` | Lösung aufgehoben, auch implizit beim Wechsel der Lösung, beim Ausblenden, Verschieben und `PostService::delete`. Auslöser: `ModerationService::unmarkSolution` und die genannten Abläufe |
| `cms_community_report_created` | Action | `int $reportId`, `string $targetType`, `int $targetId`, `int $reporterId` | Meldung angelegt; `targetType` = `post`, `topic` oder `user`; nicht beim Zusammenführen mit einer offenen Meldung. Auslöser: `ReportService::create` |
| `cms_community_report_resolved` | Action | `int $reportId`, `string $status`, `int $moderatorId` | Meldung erledigt, einmal je geschlossener Meldung; `status` = `valid`, `invalid` oder `resolved`. Die Rückmeldung an den Melder übernimmt der Listener des Moduls. Auslöser: `ReportService::resolve` |
| `cms_community_user_banned` | Action | `int $userId`, `int $banId`, `string $scope`, `int $actorId` | Community-Sperre verhängt; `scope` = `write` oder `full`; nicht bei einer gleichwertigen vorhandenen Sperre (`duplicate`). Dauer und Grund liest der Verbraucher über `BanRepository::find()`; den Sperrhinweis übernimmt der Listener des Moduls. Auslöser: `BanService::ban` |
| `cms_community_user_unbanned` | Action | `int $userId`, `int $banId`, `int $actorId` | Community-Sperre aufgehoben, nur bei einer Änderung. Auslöser: `BanService::lift` |
| `cms_community_reaction_changed` | Action | `int $postId`, `int $userId`, `string $reaction`, `string $previous` | Reaktion gesetzt, geändert oder entfernt; `''` = keine Reaktion (vorher bzw. nachher). Auslöser: `ReactionService::set`, `remove` |
| `cms_community_subscription_changed` | Action | `int $userId`, `string $targetType`, `int $targetId`, `string $mode` | Abo geändert; `mode` = `''`: abbestellt. Auslöser: `SubscriptionService::subscribe`, `unsubscribe`, `unsubscribeByToken`, Auto-Abo |
| `cms_community_render_post` | Filter | `string $html`, `int $postId` | gereinigtes HTML eines Beitrags zur Anzeigezeit, nach der Zitat-Neutralisierung; je Betrachter, nicht gespeichert und nicht erneut gereinigt: Filter müssen selbst escapen. Eine Rückgabe, die kein Text ist, wird verworfen; die Vorschau ruft den Filter mit `postId` = `0`. Auslöser: `RenderService::present`, `preview` |
| `cms_community_spam_check` | Filter | `string $verdict`, `int $userId`, `string $action`, `array $input` | **Filterausnahme**: `$input` enthält Titel und Text. Läuft vor dem Speichern außerhalb der Transaktion (Thema, Antwort, Bearbeitung), nur nach erlaubtem erstem `can()`. Darf nur verschärfen (`allow` → `review`); eine Ausnahme im Filter ergibt `review`. Auslöser: `ContentGuard::screen` |
| `cms_community_notification_types` | Filter | `array $types` | Liste der Benachrichtigungstypen `community.*` (Typ → `{mail: bool, switchable: bool}`); eingebaute Typen sind nicht entfern- oder änderbar, zusätzliche Typen erhalten `mail = false`, eine Ausnahme ergibt die eingebaute Liste. Auslöser: `NotificationDispatcher::types()` |

Vorgesehen, in 4.2.0 nicht ausgelöst (P2): Name, Konstante und Signatur stehen fest, damit spätere Dienste und Plugins sich darauf verlassen können.

| Hook | Typ | Argumente | Hinweis |
|---|---|---|---|
| `cms_community_topic_merged` | Action | `int $sourceTopicId`, `int $targetTopicId`, `int $actorId`, `int $logId` | Themen zusammengeführt (P2) |
| `cms_community_topic_split` | Action | `int $sourceTopicId`, `int $newTopicId`, `int $actorId`, `int $logId` | Thema geteilt (P2; die Aktion `topic.split` gibt es in `CommunityAccess` bereits) |
| `cms_community_user_warned` | Action | `int $userId`, `int $warningId`, `int $actorId` | Verwarnung ausgesprochen (P2) |

### Cron

| Hook | Typ | Argumente |
|---|---|---|
| `cms_cron_mail_queue` | Action | `$context` |
| `cms_cron_hourly` | Action | – |
| `cms_cron_daily` | Action | – |
| `cms_backup_created` | Action | `string $backupName`; nach jedem erfolgreichen vollen oder Datenbank-Backup |
| `cms_backup_offsite_uploaded` | Action | `string $backupName, int $bytes`; Offsite-Kopie erfolgreich |
| `cms_backup_offsite_failed` | Action | `string $backupName, string $error`; Offsite-Kopie fehlgeschlagen, Fehler ohne Zugangsdaten |
| `cms_content_status_changed` | Action | `string $type, int $id, string $from, string $to, int $userId`; jeder Statuswechsel von Beiträgen/Seiten beim Speichern, in der Freigabe und in Sammelaktionen |
| `cms_webhook_event` | Filter | `array<string,string> $events`; weitere Webhook-Ereignisse `bereich.aktion => Bezeichnung` |
| `cms_payment_succeeded` | Aktion | `int $orderId, string $provider, array $event`; Bestellung per Anbieter-Webhook bezahlt |
| `cms_payment_failed` | Aktion | `int $orderId` (bei fehlgeschlagener Abo-Verlängerung seit 4.1.9 die letzte Bestellung des Abos, nur ohne Vorlage 0), `string $provider, array $event` (bei Verlängerungen mit `renewal => true`, `subscription_row_id`) |
| `cms_subscription_renewed` | Aktion | `int $subscriptionRowId, int $userId, int $renewalOrderId, int $periodEnd` |
| `cms_subscription_ended` | Aktion | `int $subscriptionRowId, int $userId, string $provider`; Abo beim Anbieter beendet |
| `cms_payment_refunded` | Aktion | `int $orderId, string $provider, array $event`; Bestellung erstattet oder zurückgebucht (nach dem Beenden des zugehörigen Abos, `cms_subscription_ended` kommt vorher) |
| `cms_subscription_renewal_unbilled` | Aktion | `int $subscriptionRowId, int $userId, string $provider, array $event`; Verlängerung verbucht, aber keine Folgebestellung/Rechnung möglich (keine bezahlte oder erstattete Vorlagebestellung; seit 4.1.9) |
| `cms_subscription_cancelled_by_member` | Aktion | `int $subscriptionRowId, int $userId`; Mitglied hat gekündigt, Zugang bis Periodenende (seit 4.1.7) |
| `cms_invoice_created` | Aktion | `int $invoiceId, string $type, int $orderId, int $userId`; nach dem Erstellen einer Rechnung (`invoice`) bzw. Storno-Rechnung (`cancellation`), PDF liegt vor |
| `cms_user_deleted` | Aktion | `int $userId`; nach dem endgültigen Löschen eines Kontos (`UserService::deleteUser(…, true)`), z. B. Sperrvermerk für Rechnungen |
| `cms_webhook_endpoint_disabled` | Action | `int $endpointId, string $reason`; Empfänger automatisch deaktiviert (410 oder 20 Fehlschläge) |
| `cms_cron_feeds` / `cms_cron_<name>` | Action |: (über `cron.php --task=…`) |
| `cms_importer_content_reset` | Action | –; Plugin `cms-importer`: nach „Zurücksetzen“ (Beiträge/Seiten samt abhängiger Daten gelöscht, seit 4.1.9) |
| `cms_importer_image_budget_seconds` | Filter | `float $seconds`; Plugin `cms-importer`: Zeitbudget für das Laden entfernter Bilder je Importlauf (Standard 120 s, seit 4.1.6) |

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
