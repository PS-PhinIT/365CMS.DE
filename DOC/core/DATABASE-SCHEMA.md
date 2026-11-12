# 365CMS – Projektdokumentation | Abschnitt: Core – Datenbank & Schema

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Schema:** `v25` | **Status:** Stable

## English (summary)

365CMS uses MySQL ≥ 5.7 or a current MariaDB (InnoDB, `utf8mb4`; the update preflight checks the server version ≥ 5.7) through the PDO wrapper `CMS\Database` (native prepared statements, `ATTR_EMULATE_PREPARES = false`). All tables carry the prefix `DB_PREFIX` (default `cms_`). The base schema (46 tables) is defined in `CMS\SchemaManager::getSchemaQueries()`; `CMS\MigrationManager::run()` applies idempotent `CREATE`/`ALTER` migrations once per schema version (`SCHEMA_VERSION = 'v25'`, flag file `CMS/cache/db_schema_v25.flag`). Release 4.0.0 brings two schema steps: `v24` adds revision columns to `cms_post_revisions` for the post editor's revision history, `v25` adds the tables `cms_admin_menu_layouts` (custom admin sidebar layouts) and `cms_batch_jobs` (long-running admin tasks processed in steps). Further tables are created on demand by the services and admin modules that own them. Settings use the key/value table `cms_settings`.

## Deutsch

### Datenbankschicht (`CMS\Database`)

| Methode | Zweck |
|---|---|
| `Database::instance()` | Singleton, Verbindung aus `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET` |
| `prepare($sql)`, `execute($sql, $params)` | parametrisierte Abfragen (Typen werden explizit gebunden) |
| `query($sql)` | nur für vertrauenswürdige SQL ohne Parameter |
| `get_row()`, `get_var()`, `get_results()`, `get_col()` | WordPress-ähnliche Lesehelfer (Objekte) |
| `insert($table, $data)`, `update($table, $data, $where)`, `delete($table, $where)` | Schreibhelfer ohne Präfix im Tabellennamen; Bezeichner werden validiert |
| `insert_id()`, `affected_rows()` | Ergebnisse |
| `getPrefix()` / `prefix($table)` | Präfix bzw. vollständiger Tabellenname |
| `tableExists()`, `columnExists()` | Schema-Prüfungen |
| `repairTables()` | Schema-Flag löschen, Tabellen anlegen, Spalten migrieren |
| `getPdo()` | direkter PDO-Zugriff für Sonderfälle |

Fehler werden ohne Zugangsdaten protokolliert; im Debug-Modus erfasst `Debug::query()` Telemetrie.

### Schema-Verwaltung

| Klasse | Aufgabe |
|---|---|
| `SchemaManager` | Basisschema (`createTables()`), Standard-Admin bei Erstinstallation, Laufzeit-Ergänzungen (`password_resets`, `site_tables`, `ai_quota_usage`, `orders`, Spalte `audit_log.severity`) |
| `MigrationManager` | Versionsprüfung gegen `db_schema_version`, `ALTER`-Migrationen (u. a. EN-Spalten für Seiten/Beiträge), einmal je Version |
| `DatabaseUpdateRunner` | manueller Lauf aus `/admin/updates` bzw. `update.php`, setzt `installed_cms_version`, `installed_cms_schema_version`, `db_schema_version` |

### Basistabellen (`SchemaManager`)

**Benutzer & Zugang**

| Tabelle | Spalten |
|---|---|
| `users` | id, username, email, password, display_name, role, status, created_at, updated_at, last_login |
| `user_meta` | id, user_id → users (CASCADE), meta_key, meta_value – sicherheitsrelevante Schlüssel siehe unten |
| `roles` | id, name, display_name, description, capabilities, member_dashboard_access, sort_order, … |
| `sessions` | id (SHA-256 der Session-ID, nie die ID selbst), user_id, ip_address, user_agent, payload, last_activity, expires_at – ab 3.4.20 von `Auth` bei Anmeldung angelegt, höchstens alle 5 Minuten aktualisiert und bei Abmeldung/Passwortwechsel entfernt |
| `passkey_credentials` | id, user_id, credential_id, public_key, sign_count, aaguid, attestation_fmt, name, created_at, last_used_at |
| `password_resets` | id, email, token, expires_at, created_at |
| `login_attempts`, `failed_logins` | Anmeldeversuche (Rate-Limit, Sicherheitsalarme). `action` VARCHAR(30), `username` VARCHAR(60): `Security::normalizeRateLimitAction()`/`normalizeRateLimitIdentifier()` kürzen bzw. hashen längere Werte (ab 3.4.20). Seit 4.0.0 zusätzlich die Aktionen `login_account` (Verzögerung je Konto), `register_existing`, `email_change`, `email_change_confirm` und `email_change_notice` |
| `blocked_ips` | id, ip_address, reason, expires_at, permanent, … |
| `user_groups` | id, name, slug, description, role_id, plan_id, is_active, … |
| `user_group_members` | id, user_id, group_id, joined_at |

**Inhalte**

| Tabelle | Spalten |
|---|---|
| `pages` | id, slug, slug_en, title (+ title_en), content (+ content_en), excerpt, status, hide_title, show_title_toc, featured_image, meta_title, meta_description, author_id, category_id, created_at, updated_at, published_at, content_updated_at, page_template (VARCHAR 80), page_meta_json (TEXT; ab 3.4.21, Neuinstallation per `SchemaManager`, Bestand per `PageManager::ensureColumns()`) |
| `page_revisions` | id, page_id, title, title_en, slug, slug_en, content, content_en, excerpt, status, author_id, content_updated_at, page_template (VARCHAR 80), page_meta_json (TEXT), created_at |
| `posts` | id, title (+ title_en), slug, slug_en, content (+ content_en), excerpt (+ excerpt_en), featured_image, status, author_id, author_display_name, author_display_url, post_template, post_meta_json, category_id, tags, views, allow_comments, meta_title, meta_description, created_at, updated_at, published_at, content_updated_at |
| `post_revisions` | Snapshot je Speichern (Titel, Slugs, Inhalte DE/EN, Status, Kategorie, Tags, Autor-Anzeige, Datumswerte); seit v24 zusätzlich `revision_type` (`manual`, `autosave`, `restore`, `baseline`, Bestandszeilen `legacy`), `created_by`, `featured_image`, `meta_title`, `meta_description` und `fingerprint` (SHA-1 des Inhaltsstands gegen doppelte Revisionen). `PostRevisionService` behält je Beitrag die letzten 30 |
| `post_categories` | id, name, slug, slug_en, description, parent_id, sort_order, replacement_category_id, created_at |
| `post_tags` | id, name, slug, slug_en, description, post_count, created_at |
| `post_tag_rel`, `post_category_rel` | Zuordnungen (CASCADE auf `posts`) |
| `comments` | id, post_id, user_id, author, author_email, author_ip, content, status (`pending/approved/spam/trash`), post_date, modified_at, edited_at, edited_by (Bearbeitung durch Moderation) |
| `landing_sections` | id, type, data (JSON), sort_order |
| `site_tables` | id, table_name, table_slug, description, columns_json, rows_json, settings_json (auch Hub-Sites) |
| `media` | id, filename, filepath, filetype, filesize, title, alt_text, caption, uploaded_by, uploaded_at |
| `custom_fonts` | id, name, slug, format, file_path, css_path, source (`upload`/`google-fonts-local`) |

**Mitglieder & Kommunikation**

| Tabelle | Spalten |
|---|---|
| `messages` | id, sender_id, recipient_id, subject, body, is_read, read_at, parent_id, deleted_by_sender, deleted_by_recipient, created_at |
| `notifications` | id, user_id, type, title, message, url, is_read, read_at, created_at |
| `favorites` | id, user_id, post_id, created_at |

**Abos & Bestellungen**

| Tabelle | Spalten |
|---|---|
| `subscription_plans` | Preise, Limits (`limit_*`), Plugin-Freigaben (`plugin_*`), Features (`feature_*`), is_active, sort_order |
| `user_subscriptions` | user_id, plan_id, status, billing_cycle, start_date, end_date, next_billing_date, cancelled_at |
| `subscription_usage` | user_id, resource_type, current_count, last_updated |
| `orders` | order_number, user_id, plan_id, Beträge, currency, status, payment_method, billing_cycle, Adressdaten, contact_data |

**System, Plugins, Themes**

| Tabelle | Spalten |
|---|---|
| `settings` | id, option_name (eindeutig), option_value, autoload – gelesen über den Request-Cache `CMS\Services\OptionStore` (alle `autoload = 1` in einer Abfrage); seit v23 ohne den redundanten Index `idx_key` |
| `plugins`, `plugin_meta` | Plugin-Registry und Metadaten (aktive Plugins zusätzlich in Option `active_plugins`) |
| `theme_customizations` | theme_slug, setting_category, setting_key, setting_value, user_id |
| `cache` | cache_key, cache_value, expires_at |
| `activity_log` | user_id, action, entity_type, entity_id, description, ip_address, user_agent, metadata |
| `audit_log` | user_id, category, action, entity_type, entity_id, description, ip_address, user_agent, metadata, severity |
| `security_log` | action, ip_address, request_uri, user_agent, rule_matched, user_id, extra |
| `page_views` | page_id, page_slug, page_title, user_id, session_id, ip_address (anonymisiert), user_agent, referrer, visited_at |
| `core_web_vitals` | page_path, device_type, connection, viewport, ttfb_ms, lcp_ms, inp_ms, cls, recorded_at |
| `mail_log` | recipient, subject, status, transport, provider, message_id, error_message, meta, source |
| `mail_queue` | recipient, subject, body, headers, status, attempts, max_attempts, available_at, sent_at, locked_at, attachment_*, error_category, last_error |
| `ai_quota_usage` | scope_name, period_key, user_id, provider_id, request_count, character_count |
| `admin_menu_layouts` | id, site_key (eindeutig; `''` = global, sonst Site-Schlüssel aus *Einstellungen › MultiSites*), layout_json (MEDIUMTEXT, nur validierte Layouts; `NULL` = zurückgesetzt), version (steigt bei jedem Speichern und Zurücksetzen), updated_by, created_at, updated_at – seit v25, eigene Sidebar-Layouts ([../admin/ADMIN-MENU.md](../admin/ADMIN-MENU.md)) |
| `batch_jobs` | id, job_type, scope_key, status (`pending`, `running`, `done`, `failed`, `cancelled`), total, processed, failed, state_json (Cursor und Optionen), errors_json, message, lock_token, locked_until, created_by, created_at, updated_at, finished_at – seit v25, lange Admin-Aufgaben in Schritten (`BatchJobService`) |

### Schema v25 (4.0.0)

| Änderung | Zweck |
|---|---|
| neue Tabelle `admin_menu_layouts` (Spalten siehe oben, `UNIQUE KEY uniq_admin_menu_layouts_site (site_key)`) | eigene Layouts der Admin-Seitenleiste (Profil „Eigenes Layout“): eine Zeile global und höchstens eine je Site. Zurücksetzen leert `layout_json` und erhöht den Versionszähler, die Zeile bleibt erhalten. So wiederholt sich keine Version, und der Menü-Cache (Version im Schlüssel) liefert kein veraltetes Layout |
| `posts`, `pages`: Spalten `previous_status` VARCHAR(20), `trashed_at` DATETIME, `trashed_by` INT UNSIGNED (alle NULL) und Index `idx_trashed_at`; `site_tables`: `trashed_at`, `trashed_by` und `idx_trashed_at` | Papierkorb ([CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md#papierkorb-trashprovider)): Status vor dem Verschieben (für die Wiederherstellung), Zeitpunkt (Frist für das automatische Leeren) und Benutzer. Hub-Sites haben keine Status-Spalte, bei ihnen bedeutet `trashed_at IS NOT NULL` „im Papierkorb“ |
| neue Tabelle `batch_jobs` (Spalten siehe oben, Indizes `idx_batch_jobs_lookup (job_type, scope_key, status)` und `idx_batch_jobs_updated (updated_at)`) | lange Admin-Aufgaben in Schritten (z. B. Suchindex-Neuaufbau, Thumbnails, Import): je Typ und Scope höchstens ein aktiver Auftrag, jeder Schritt unter einer Sperre mit Ablaufzeit (120 s). Aufträge lassen sich nach dem Neuladen der Seite oder per Cron fortsetzen; abgeschlossene Aufträge werden nach 30 Tagen entfernt |
| Spalten `planned_at` DATETIME NULL in `posts` und `pages` | Redaktionskalender: „Geplantes Datum“ für Entwürfe; rein redaktionell, veröffentlicht nichts |
| `page_revisions`: Spalte `created_by` INT UNSIGNED NULL | Benutzer, der die Seitenrevision ausgelöst hat (Übersicht *Letzte Änderungen*); ältere Zeilen bleiben `NULL` |
| Tabellen der Core-Funktionen, Liste `SchemaManager::FEATURE_TABLES` (siehe unten) | Formulare, Downloads, Feed-Aggregator, Übersetzungsstatus, Suchbegriff-Statistik und Medien-Papierkorb; dieselbe Definition für Neuinstallation, Update und Laufzeit-Absicherung |

#### Tabellen aus `FEATURE_TABLES` (v25)

| Tabelle | Spalten | Zweck |
|---|---|---|
| `form_forms` | id, form_key (eindeutig), title, status, is_builtin, fields_json, settings_json, legacy_id, previous_status, trashed_at, trashed_by, created_by, created_at, updated_at | Formulare mit Feldern und Einstellungen ([FORMS.md](../admin/pages-posts/FORMS.md)) |
| `form_submissions` | id, form_id, status (`new`, `read`, `done`, `trash`), previous_status, trashed_at, trashed_by, user_id, sender_name, sender_email, subject, data_json, meta_json, locale, source_url, consent_at, read_at, done_at, legacy_id, created_at, updated_at | Eingänge – ohne IP-Adresse und User-Agent |
| `form_submission_files` | id, submission_id, field_name, original_name, stored_name, mime_type, size_bytes, created_at | hochgeladene Dateien (privater Speicher `forms/uploads/`) |
| `downloads_items` | id, title, slug, summary, description, category_id, cover_media, file_type, author_name, license, requirements, external_links, is_signed, signature_info, access_level, access_roles, access_plans, status, previous_status, trashed_at, trashed_by, is_featured, sort_order, seo_*, download_count, download_count_base, legacy_id, created_by, updated_by, published_at, created_at, updated_at | Downloads ([DOWNLOADS.md](../admin/media/DOWNLOADS.md)) |
| `downloads_versions` | id, download_id, version, status, current_flag, file_area, file_path, file_name, file_ext, mime_type, file_size, sha256, external_url, release_date, changelog, download_count, legacy_id, legacy_path, created_by, created_at | Versionen und Dateien je Download |
| `downloads_terms`, `downloads_item_terms` | id, term_type, name, slug, description, icon, status, sort_order, legacy_id, created_at · download_id, term_id | Kategorien und Schlagwörter |
| `downloads_property_values` | download_id, field_key, value | eigene Eigenschaften |
| `downloads_usage` | download_id, content_type, content_id | Verwendung in Inhalten (Editor-Block) |
| `downloads_events`, `downloads_stats_daily` | id, download_id, version_id, source_type, source_id, dedupe_hash, aggregated, created_at · stat_date, download_id, version_id, source_type, source_id, hits | Abrufe ohne Personendaten, täglich verdichtet (Löschregel `downloads.events`) |
| `downloads_rate_limits` | key_hash, request_count, window_started_at | Grenzwert für Abrufe |
| `feeds_categories`, `feeds_sources`, `feeds_items` | Bereiche (Name/Beschreibung DE/EN, Layout) · Quellen (URL, Intervall, Fehlerzähler) · Artikel (guid, Titel, Link, Inhalt, Bild, Datum, hervorgehoben/ausgeblendet) | Feed-Aggregator ([FEEDS.md](../admin/system-settings/FEEDS.md)) |
| `feeds_digests`, `feeds_subscriptions` | E-Mail-Digests (Empfänger, Bereiche, Rhythmus) · Mitglieder-Abos (Quellen, Rhythmus, Sprache) | Versand von Zusammenfassungen |
| `feeds_fetch_queue` | id, source_id, status, trigger_type, attempts, items_new, error, claim_token, created_at, started_at, processed_at | Abruf-Warteschlange des Cron |
| `translation_sync` | content_type, content_id, locale (Primärschlüssel), source_hash, target_hash, method, synced_by, synced_at | Übersetzungsstatus ([TRANSLATIONS.md](../admin/pages-posts/TRANSLATIONS.md)) |
| `search_queries` | id, term, locale, result_count, created_at | Suchbegriffe der Frontend-Suche – ohne IP, Benutzer, Session; täglich verdichtet und gelöscht |
| `search_term_daily` | day, locale, term, searches, zero_hits, result_sum | Tageswerte der Suchbegriffe (Löschregel `search_terms`) |
| `trash_media` | id, original_path, storage_name, title, filetype, filesize, payload_json, trashed_at, trashed_by | Medien im Papierkorb (Datei im privaten Speicher `trash/media/`) |

Geprüft (12.11.2026, MariaDB 10.11): Neuinstallation auf leerer Datenbank und Update von v24 (Feature-Tabellen, `batch_jobs`, `admin_menu_layouts`, `planned_at` und `page_revisions.created_by` entfernt, Version auf v24) ergeben dieselben 68 Tabellen und Spalten; die Version steht danach auf v25.

Die Definitionen stehen nur in `SchemaManager::getSchemaQueries()` und werden an drei Stellen verwendet: bei der Neuinstallation (Installer und `createTables()`), beim Update (`MigrationManager::run()`, Einträge v25) und als Laufzeitprüfung in `AdminMenu\LayoutRepository` bzw. `BatchJobService` (`Database::runSchemaOnce()`, falls die Migration noch aussteht). Alle Wege nutzen `CREATE TABLE IF NOT EXISTS` und verändern keine bestehenden Daten. `TESTS/admin-menu/run.php` (Teil 7) prüft für beide Tabellen, dass alle drei Wege dieselbe Definition nutzen. Die Papierkorb-Spalten stehen in den `CREATE TABLE`-Anweisungen, als `ALTER TABLE` in den v25-Migrationen und in der Laufzeitprüfung `SchemaManager::ensureTrashColumns()`, die Spalten und Index auch bei Installationen nachträgt, die v25 schon erreicht hatten. Das gewählte Profil steht in der Option `admin_menu_profile`.

### Schema v24 (4.0.0)

| Änderung | Zweck |
|---|---|
| `post_revisions`: Spalte `revision_type` VARCHAR(20) NOT NULL DEFAULT `'legacy'` | Herkunft der Revision (`manual` = Speichern, `autosave` = Autosave höchstens alle 10 Minuten, `restore` = Wiederherstellung, `baseline` = „Ausgangsstand“, der vor der ersten erfassten Änderung bzw. vor einer Wiederherstellung gesichert wird); vorhandene Zeilen werden als `legacy` („Ältere Version“) gekennzeichnet |
| `post_revisions`: Spalte `created_by` INT UNSIGNED NULL | Benutzer, der die Revision ausgelöst hat (Anzeige „Autor“ im Revisions-Panel); `author_id` bleibt der Beitragsautor |
| `post_revisions`: Spalten `featured_image` VARCHAR(500), `meta_title` VARCHAR(255), `meta_description` TEXT (alle NULL) | Vergleich und Wiederherstellung von Beitragsbild und Meta-Feldern; `NULL` = in älteren Revisionen nicht erfasst, die Wiederherstellung behält dann den aktuellen Wert |
| `post_revisions`: Spalte `fingerprint` CHAR(40) NULL | SHA-1 über Titel, Slugs, Inhalte, Auszüge, Beitragsbild, Meta-Felder, Kategorie, Tags und Autor-Anzeige (ohne Status und Datumswerte); identische Stände werden nicht doppelt gespeichert |

Die Migration ergänzt nur Spalten (keine Daten werden umgeschrieben oder gelöscht) und läuft idempotent über `MigrationManager::run()` bzw. *Admin → Updates*; Neuinstallationen erhalten die Spalten über `SchemaManager`. Die übrigen Daten der neuen Admin-Oberfläche liegen in bestehenden Tabellen: Benutzer-Präferenzen in `cms_user_meta` (`admin_ui_prefs`), Plugin-Zuweisung, Site-Liste und Menüprofil in `cms_settings` (`admin_plugin_site_assignments`, `admin_sites`, `admin_menu_profile`); nur die eigenen Sidebar-Layouts haben mit v25 eine eigene Tabelle.

### Schema v23 (3.4.13)

| Änderung | Zweck |
|---|---|
| `posts`: Index `idx_status_published (status, published_at)` | Blog-, Archiv- und Feed-Listen filtern auf `status` und sortieren nach `published_at` |
| `posts`: Spalten `title_en`, `content_en`, `excerpt_en` im Basisschema | wurden bisher nur von `PostsModule::ensureColumns()` ergänzt; frische Installationen lieferten bis dahin 500 auf `/blog` |
| `settings`: Index `idx_key` entfernt | doppelte `UNIQUE(option_name)` und kostete nur Schreibzeit |

Alle Änderungen laufen idempotent über `MigrationManager::run()`.

**Zeitzone (seit 3.4.13):** `CMS\Database` setzt pro Verbindung `SET time_zone` auf den PHP-Offset (`date('P')`), damit `NOW()`/`CURRENT_TIMESTAMP` zu den PHP-seitigen Zeitvergleichen passen.

### Bedarfsweise angelegte Tabellen

| Tabelle | Angelegt von | Zweck |
|---|---|---|
| `seo_meta` | `SEO\SeoMetaRepository` | SEO-Felder je Seite/Beitrag |
| `seo_dashboard_trends` | `SeoTrendService` | SEO-Kennzahlen-Verlauf |
| `redirect_rules`, `not_found_logs` | `RedirectService` | Weiterleitungen, 404-Protokoll |
| `firewall_rules` | `SecurityRuntimeService` | Firewall-Regeln |
| `spam_blacklist` | `AntispamModule` | Anti-Spam-Blacklist |
| `cookie_categories`, `cookie_services` | `CookieManagerModule` | Consent-Kategorien und Dienste |
| `privacy_requests` | `MemberService`, `PrivacyRequestsModule`, `DeletionRequestsModule` | DSGVO-Anfragen |
| `role_permissions` | `RolesModule`, `includes/functions/roles.php` | Capability-Overrides je Rolle |
| `menus`, `menu_items` | `MenuEditorModule` | Navigationsmenüs |
| `error_reports` | `ErrorReportService` | Fehlerberichte aus dem Admin |
| `feature_usage` | `FeatureUsageService` | Nutzung von Admin-Funktionen |
| `monitoring_trends` | `MonitoringTrendService` | Antwortzeit-, Speicher-, Cron-Verlauf |
| `import_log`, `import_meta`, `import_items` | Plugin `cms-importer` | WordPress-Import |

### Sicherheitsrelevante Schlüssel in `cms_user_meta`

| Schlüssel | Inhalt |
|---|---|
| `mfa_enabled`, `mfa_secret`, `mfa_pending_secret` | TOTP-Status und -Secrets; die Secrets seit 4.0.0 verschlüsselt (`enc:v2:…`, Kontext Benutzer + Feld), Klartext aus älteren Versionen wird beim Lesen bzw. beim Start migriert |
| `auth_token_version` | Zähler für „Überall abmelden“ (seit 4.0.0): fließt in den Sitzungs-Fingerabdruck und den `pwf`-Claim der API-Tokens ein |
| `email_verification_token`, `email_verification_expires`, `email_verified_at` | Bestätigung neuer Konten (Token als SHA-256-Hash) bzw. Zeitpunkt der letzten Bestätigung |
| `pending_email`, `pending_email_token`, `pending_email_expires` | ausstehende E-Mail-Änderung (seit 4.0.0; Token als SHA-256-Hash, 48 Stunden gültig) |

Nicht sicherheitsrelevant, aber ebenfalls je Benutzer: `admin_ui_prefs` (seit 4.0.0, `AdminUiPreferenceService`) speichert als JSON den eingeklappten Zustand der Seitenleiste (`sidebar_collapsed`), manuell auf- oder zugeklappte Gruppen und Plugin-Einträge (`groups`, höchstens 200 Schlüssel) und den gewählten Site-Kontext (`site`).

### Wichtige Optionen in `cms_settings`

| Option | Inhalt |
|---|---|
| `db_schema_version`, `installed_cms_version`, `installed_cms_schema_version` | Versionsstände |
| `active_plugins` | JSON-Liste aktiver Plugin-Slugs |
| `active_theme` | aktives Theme |
| `admin_plugin_site_assignments` | Plugin-Zuweisung je Site (seit 4.0.0): JSON `{"<plugin-slug>": ["<site-key>", …]}`; Plugins ohne Eintrag gelten für alle Sites ([../admin/plugins/PLUGINS.md](../admin/plugins/PLUGINS.md#plugin-zuweisung)) |
| `admin_menu_profile` | Profil der Admin-Seitenleiste (seit 4.0.0): `classic` (Standard), `structured` oder `custom`; gesetzt im Installer oder unter *Einstellungen › Admin-Menü* ([../admin/ADMIN-MENU.md](../admin/ADMIN-MENU.md)) |
| `admin_sites` | Site-Liste der Admin-Oberfläche (seit 4.0.0, *Einstellungen › MultiSites*): JSON-Liste mit `key`, `label`, `url`, `accent`, `accent_soft`, `accent_strong`, `hosts`; ohne Option gilt die Haupt-URL der Installation (`SITE_URL`) als einzige Site ([../admin/system-settings/MULTISITES.md](../admin/system-settings/MULTISITES.md)) |
| `site_name`, `site_url`, `language`, … | allgemeine Einstellungen ([../admin/system-settings/SYSTEM.md](../admin/system-settings/SYSTEM.md)) |
| `seo_*`, `perf_*`, `firewall_*`, `antispam_*`, `cookie_*`, `member_*`, `legal_*`, `cms_loginpage_*` | Einstellungen der jeweiligen Bereiche |
| Gruppen über `SettingsService` (`mail`, `core_modules`, `ai.*`, `cron`, …) | gruppierte, teils verschlüsselte Werte (`json:`; Secrets seit 4.0.0 `enc:v2:<alg>:<Schlüssel-ID>:<Daten>` über `CryptoService`, Altwerte `enc:` werden automatisch neu verschlüsselt). Die Gruppe `cron` enthält seit 4.0.0 außerdem `last_run_at`, `last_run_status` (`ok`/`failed`), `last_run_task` und `last_error` des letzten Laufs (Systemhinweise im Admin) |

### Hinweise für Entwickler

- Tabellennamen immer über `getPrefix()` bilden, nie hart codieren.
- Eigene Plugin-Tabellen idempotent mit `CREATE TABLE IF NOT EXISTS` beim Aktivieren anlegen und bei `dsgvo_delete_data` personenbezogene Daten löschen.
- Für Spaltenergänzungen `columnExists()` prüfen und `ALTER TABLE … ADD COLUMN` nur bei Bedarf ausführen.
- Keine Werte in SQL interpolieren – Platzhalter `?` verwenden.

### Verwandte Dokumente

[ARCHITECTURE.md](ARCHITECTURE.md) · [CORE-CLASSES.md](CORE-CLASSES.md) · [../admin/system-settings/UPDATES.md](../admin/system-settings/UPDATES.md)
