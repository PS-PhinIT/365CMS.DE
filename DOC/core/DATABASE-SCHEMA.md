# 365CMS Projektdokumentation | Core: Datenbank & Schema

> **Stand:** 2026-11-19 | **Version:** 4.1.8 (veröffentlicht), 4.2.0 in Entwicklung | **Schema:** `v27` (Entwicklungsstand; die veröffentlichten Releases 4.1.x nutzen `v26`) | **Status:** Stable bis v26

## English (summary)

365CMS uses MySQL ≥ 5.7 or a current MariaDB (InnoDB, `utf8mb4`; the update preflight checks the server version ≥ 5.7) through the PDO wrapper `CMS\Database` (native prepared statements, `ATTR_EMULATE_PREPARES = false`). All tables carry the prefix `DB_PREFIX` (default `cms_`). This document describes **schema `v27`**, the development state of release 4.2.0 (the published 4.1.x releases use `v26`). The base schema (118 tables) is defined in `CMS\SchemaManager::getSchemaQueries()`: 76 tables up to `v25`, 14 new in `v26` and 28 new in `v27`. `CMS\MigrationManager::run()` applies idempotent `CREATE`/`ALTER` migrations once per schema version. Schema history: `v24` adds revision columns to `cms_post_revisions`; `v25` adds `cms_admin_menu_layouts`, `cms_batch_jobs` and the 30 feature tables of `SchemaManager::FEATURE_TABLES` (forms, downloads, feed aggregator, knowledge base, newsletter, translation status, search statistics, media trash); **`v26`** adds the fourteen tables of `SchemaManager::FEATURE_TABLES_V26`, `cms_password_history`, `cms_user_identities`, `cms_media_usage`, `cms_content_workflow_events`, `cms_content_preview_links`, `cms_reusable_blocks`, `cms_reusable_block_usage`, `cms_reusable_block_revisions`, `cms_backup_offsite_uploads`, `cms_webhook_endpoints`, `cms_webhook_deliveries`, `cms_payment_events`, `cms_invoice_sequences`, `cms_invoices`, and the columns `cms_users.password_changed_at`, `cms_passkey_credentials.user_verified`, `expires_at`/`review_due_at`/`expiry_notified_at`/`review_notified_at` on `cms_posts`, `cms_pages` and `cms_kb_entries`, plus payment columns on `cms_orders` and `cms_user_subscriptions`. **`v27`** adds the 28 tables `cms_community_*` of the Community module (`SchemaManager::FEATURE_TABLES_V27`) and, as the only change to an existing table, the two columns `cms_notifications.group_key` and `event_count` with the index `idx_user_group` (bundling of notifications per topic; existing rows keep no key and a count of 1; fresh install, update and runtime check use the same column definition including the comment, `group_key` is `utf8mb4_unicode_ci` on every path; development installations that reached `v27` with the code of PR #1 are aligned by the runtime check) and the one-time permission step `capabilities_v27` (capabilities `community.*`, system role `moderator`; marker option `capabilities_v27`, never overwrites a stored permission). Existing installations receive all of it through the runtime check `SchemaManager::ensureFeatureSchema()`. Further tables are created on demand by the services and admin modules that own them. Settings use the key/value table `cms_settings`.

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
| `users` | id, username, email, password, display_name, role, status, created_at, updated_at, last_login, `password_changed_at` (seit v26) |
| `user_meta` | id, user_id → users (CASCADE), meta_key, meta_value: sicherheitsrelevante Schlüssel siehe unten |
| `roles` | id, name, display_name, description, capabilities, member_dashboard_access, sort_order, … |
| `sessions` | id (SHA-256 der Session-ID, nie die ID selbst), user_id, ip_address, user_agent, payload, last_activity, expires_at: ab 3.4.20 von `Auth` bei Anmeldung angelegt, höchstens alle 5 Minuten aktualisiert und bei Abmeldung/Passwortwechsel entfernt |
| `passkey_credentials` | id, user_id, credential_id, public_key, sign_count, aaguid, attestation_fmt, name, `user_verified` (seit v26), created_at, last_used_at |
| `password_resets` | id, email, token, expires_at, created_at |
| `login_attempts`, `failed_logins` | Anmeldeversuche (Rate-Limit, Sicherheitsalarme). `action` VARCHAR(30), `username` VARCHAR(60): `Security::normalizeRateLimitAction()`/`normalizeRateLimitIdentifier()` kürzen bzw. hashen längere Werte (ab 3.4.20). Seit 4.0.0 zusätzlich die Aktionen `login_account` (Verzögerung je Konto), `register_existing`, `email_change`, `email_change_confirm` und `email_change_notice` |
| `blocked_ips` | id, ip_address, reason, expires_at, permanent, … |
| `user_groups` | id, name, slug, description, role_id, plan_id, is_active, … |
| `user_group_members` | id, user_id, group_id, joined_at |

**Inhalte**

| Tabelle | Spalten |
|---|---|
| `pages` | id, slug, slug_en, title (+ title_en), content (+ content_en), excerpt, status, hide_title, show_title_toc, featured_image, meta_title, meta_description, author_id, category_id, created_at, updated_at, published_at, content_updated_at, page_template (VARCHAR 80), page_meta_json (TEXT; ab 3.4.21, Neuinstallation per `SchemaManager`, Bestand per `PageManager::ensureColumns()`), seit v25 `previous_status`, `trashed_*`, `planned_at`, seit v26 `expires_at`, `review_due_at`, `expiry_notified_at`, `review_notified_at`; `status` kennt seit 4.1.0 auch `pending` |
| `page_revisions` | id, page_id, title, title_en, slug, slug_en, content, content_en, excerpt, status, author_id, content_updated_at, page_template (VARCHAR 80), page_meta_json (TEXT), created_at |
| `posts` | id, title (+ title_en), slug, slug_en, content (+ content_en), excerpt (+ excerpt_en), featured_image, status, author_id, author_display_name, author_display_url, post_template, post_meta_json, category_id, tags, views, allow_comments, meta_title, meta_description, created_at, updated_at, published_at, content_updated_at, seit v25 `previous_status`, `trashed_*`, `planned_at`, seit v26 `expires_at`, `review_due_at`, `expiry_notified_at`, `review_notified_at`; `status` kennt seit 4.1.0 auch `pending` |
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
| `notifications` | id, user_id, type, title, message, url, group_key (v27), event_count (v27), is_read, read_at, created_at |
| `favorites` | id, user_id, post_id, created_at |

**Abos & Bestellungen**

| Tabelle | Spalten |
|---|---|
| `subscription_plans` | Preise, Limits (`limit_*`), Plugin-Freigaben (`plugin_*`), Features (`feature_*`), is_active, sort_order |
| `user_subscriptions` | user_id, plan_id, status, billing_cycle, start_date, end_date, next_billing_date, cancelled_at, seit v26 `provider`, `provider_subscription_id`, `current_period_end` |
| `subscription_usage` | user_id, resource_type, current_count, last_updated |
| `orders` | order_number, user_id, plan_id, Beträge, currency, status, payment_method, billing_cycle, Adressdaten, contact_data, seit v26 `provider`, `provider_checkout_id`, `paid_at` |

**System, Plugins, Themes**

| Tabelle | Spalten |
|---|---|
| `settings` | id, option_name (eindeutig), option_value, autoload: gelesen über den Request-Cache `CMS\Services\OptionStore` (alle `autoload = 1` in einer Abfrage); seit v23 ohne den redundanten Index `idx_key` |
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
| `admin_menu_layouts` | id, site_key (eindeutig; `''` = global, sonst Site-Schlüssel aus *Einstellungen › MultiSites*), layout_json (MEDIUMTEXT, nur validierte Layouts; `NULL` = zurückgesetzt), version (steigt bei jedem Speichern und Zurücksetzen), updated_by, created_at, updated_at: seit v25, eigene Sidebar-Layouts ([../admin/ADMIN-MENU.md](../admin/ADMIN-MENU.md)) |
| `batch_jobs` | id, job_type, scope_key, status (`pending`, `running`, `done`, `failed`, `cancelled`), total, processed, failed, state_json (Cursor und Optionen), errors_json, message, lock_token, locked_until, created_by, created_at, updated_at, finished_at: seit v25, lange Admin-Aufgaben in Schritten (`BatchJobService`) |

### Schema v25

| Änderung | Zweck |
|---|---|
| neue Tabelle `admin_menu_layouts` (Spalten siehe oben, `UNIQUE KEY uniq_admin_menu_layouts_site (site_key)`) | eigene Layouts der Admin-Seitenleiste (Profil „Eigenes Layout“): eine Zeile global und höchstens eine je Site. Zurücksetzen leert `layout_json` und erhöht den Versionszähler, die Zeile bleibt erhalten. So wiederholt sich keine Version, und der Menü-Cache (Version im Schlüssel) liefert kein veraltetes Layout |
| `posts`, `pages`: Spalten `previous_status` VARCHAR(20), `trashed_at` DATETIME, `trashed_by` INT UNSIGNED (alle NULL) und Index `idx_trashed_at`; `site_tables`: `trashed_at`, `trashed_by` und `idx_trashed_at` | Papierkorb ([CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md#papierkorb-trashprovider)): Status vor dem Verschieben (für die Wiederherstellung), Zeitpunkt (Frist für das automatische Leeren) und Benutzer. Hub-Sites haben keine Status-Spalte, bei ihnen bedeutet `trashed_at IS NOT NULL` „im Papierkorb“ |
| neue Tabelle `batch_jobs` (Spalten siehe oben, Indizes `idx_batch_jobs_lookup (job_type, scope_key, status)` und `idx_batch_jobs_updated (updated_at)`) | lange Admin-Aufgaben in Schritten (z. B. Suchindex-Neuaufbau, Thumbnails, Import): je Typ und Scope höchstens ein aktiver Auftrag, jeder Schritt unter einer Sperre mit Ablaufzeit (120 s). Aufträge lassen sich nach dem Neuladen der Seite oder per Cron fortsetzen; abgeschlossene Aufträge werden nach 30 Tagen entfernt |
| Spalten `planned_at` DATETIME NULL in `posts` und `pages` | Redaktionskalender: „Geplantes Datum“ für Entwürfe; rein redaktionell, veröffentlicht nichts |
| `page_revisions`: Spalte `created_by` INT UNSIGNED NULL | Benutzer, der die Seitenrevision ausgelöst hat (Übersicht *Letzte Änderungen*); ältere Zeilen bleiben `NULL` |
| Tabellen der Core-Funktionen, Liste `SchemaManager::FEATURE_TABLES` (siehe unten) | Formulare, Downloads, Feed-Aggregator, Knowledgebase, Newsletter, Übersetzungsstatus, Suchbegriff-Statistik und Medien-Papierkorb; dieselbe Definition für Neuinstallation, Update und Laufzeit-Absicherung |

#### Tabellen aus `FEATURE_TABLES` (v25)

| Tabelle | Spalten | Zweck |
|---|---|---|
| `form_forms` | id, form_key (eindeutig), title, status, is_builtin, fields_json, settings_json, legacy_id, previous_status, trashed_at, trashed_by, created_by, created_at, updated_at | Formulare mit Feldern und Einstellungen ([FORMS.md](../admin/pages-posts/FORMS.md)) |
| `form_submissions` | id, form_id, status (`new`, `read`, `done`, `trash`), previous_status, trashed_at, trashed_by, user_id, sender_name, sender_email, subject, data_json, meta_json, locale, source_url, consent_at, read_at, done_at, legacy_id, created_at, updated_at | Eingänge: ohne IP-Adresse und User-Agent |
| `form_submission_files` | id, submission_id, field_name, original_name, stored_name, mime_type, size_bytes, created_at | hochgeladene Dateien (privater Speicher `forms/uploads/`) |
| `downloads_items` | id, title, slug, summary, description, category_id, cover_media, file_type, author_name, license, requirements, external_links, is_signed, signature_info, access_level, access_roles, access_plans, status, previous_status, trashed_at, trashed_by, is_featured, sort_order, seo_*, download_count, download_count_base, legacy_id, created_by, updated_by, published_at, created_at, updated_at | Downloads ([DOWNLOADS.md](../admin/media/DOWNLOADS.md)) |
| `downloads_versions` | id, download_id, version, status, current_flag, file_area, file_path, file_name, file_ext, mime_type, file_size, sha256, external_url, release_date, changelog, download_count, legacy_id, legacy_path, created_by, created_at | Versionen und Dateien je Download |
| `downloads_terms`, `downloads_item_terms` | id, term_type, name, slug, description, icon, status, sort_order, legacy_id, created_at · download_id, term_id | Kategorien und Schlagwörter |
| `downloads_property_values` | download_id, field_key, value | eigene Eigenschaften |
| `downloads_usage` | download_id, content_type, content_id | Verwendung in Inhalten (Editor-Block) |
| `downloads_events`, `downloads_stats_daily` | id, download_id, version_id, source_type, source_id, dedupe_hash, aggregated, created_at · stat_date, download_id, version_id, source_type, source_id, hits | Abrufe ohne Personendaten, täglich verdichtet (Löschregel `downloads.events`) |
| `downloads_rate_limits` | key_hash, request_count, window_started_at | Grenzwert für Abrufe |
| `kb_entries` | id, title, keyword, slug, excerpt, content, content_details, content_benefits, content_practice, content_notes, content_license, content_conclusion, content_links, tooltip_text, synonyms, category, priority, is_active, is_case_sensitive, is_whole_word, max_links_per_page, trashed_at, trashed_by, seit v26 expires_at, review_due_at, expiry_notified_at, review_notified_at, created_at, updated_at | Knowledgebase-Einträge ([KNOWLEDGEBASE.md](../admin/pages-posts/KNOWLEDGEBASE.md)); Tabellen wie im früheren Plugin, Schema-Version in Option `cms_knowledgebase_version` |
| `kb_categories`, `kb_settings` | Kategorien (name, slug, sort_order) · Einstellungen (setting_key, setting_value) | Knowledgebase |
| `newsletter_subscribers` | id, email, first_name, last_name, status, source, segment_slug, locale, user_id, optin_token, optin_sent_at, consent_at, consent_text, confirmed_at, unsubscribed_at, last_sent_at, created_at, updated_at | Newsletter-Abonnenten ([NEWSLETTER.md](../admin/pages-posts/NEWSLETTER.md)); Schema-Version in Option `cms_newsletter_version` |
| `newsletter_templates`, `newsletter_campaigns` | Vorlagen (name, subject, content_html/_text, status) · Kampagnen (template_id, subject, preview_text, content_html/_text, segment_slug, locale, status, is_public, scheduled_at, started_at, sent_at, recipient/sent/failed_count, created_by) | Newsletter |
| `newsletter_sends`, `newsletter_settings` | Versand je Empfänger (campaign_id + subscriber_id eindeutig, status queued/sent/failed/skipped, sent_at, last_error) · Einstellungen | Newsletter |
| `feeds_categories`, `feeds_sources`, `feeds_items` | Bereiche (Name/Beschreibung DE/EN, Layout) · Quellen (URL, Intervall, Fehlerzähler) · Artikel (guid, Titel, Link, Inhalt, Bild, Datum, hervorgehoben/ausgeblendet) | Feed-Aggregator ([FEEDS.md](../admin/system-settings/FEEDS.md)) |
| `feeds_digests`, `feeds_subscriptions` | E-Mail-Digests (Empfänger, Bereiche, Rhythmus) · Mitglieder-Abos (Quellen, Rhythmus, Sprache) | Versand von Zusammenfassungen |
| `feeds_fetch_queue` | id, source_id, status, trigger_type, attempts, items_new, error, claim_token, created_at, started_at, processed_at | Abruf-Warteschlange des Cron |
| `translation_sync` | content_type, content_id, locale (Primärschlüssel), source_hash, target_hash, method, synced_by, synced_at | Übersetzungsstatus ([TRANSLATIONS.md](../admin/pages-posts/TRANSLATIONS.md)) |
| `search_queries` | id, term, locale, result_count, created_at | Suchbegriffe der Frontend-Suche: ohne IP, Benutzer, Session; täglich verdichtet und gelöscht |
| `search_term_daily` | day, locale, term, searches, zero_hits, result_sum | Tageswerte der Suchbegriffe (Löschregel `search_terms`) |
| `trash_media` | id, original_path, storage_name, title, filetype, filesize, payload_json, trashed_at, trashed_by | Medien im Papierkorb (Datei im privaten Speicher `trash/media/`) |


Die Definitionen stehen nur in `SchemaManager::getSchemaQueries()` und werden an drei Stellen verwendet: bei der Neuinstallation (Installer und `createTables()`), beim Update (`MigrationManager::run()`, Einträge v25) und als Laufzeitprüfung in `AdminMenu\LayoutRepository` bzw. `BatchJobService` (`Database::runSchemaOnce()`, falls die Migration noch aussteht). Alle Wege nutzen `CREATE TABLE IF NOT EXISTS` und verändern keine bestehenden Daten. `TESTS/admin-menu/run.php` (Teil 7) prüft für beide Tabellen, dass alle drei Wege dieselbe Definition nutzen. Die Papierkorb-Spalten stehen in den `CREATE TABLE`-Anweisungen, als `ALTER TABLE` in den v25-Migrationen und in der Laufzeitprüfung `SchemaManager::ensureTrashColumns()`, die Spalten und Index auch bei Installationen nachträgt, die v25 schon erreicht hatten. Das gewählte Profil steht in der Option `admin_menu_profile`.

### Schema v27

#### Tabellen aus `FEATURE_TABLES_V27` (Community-Modul)

Spezifikation und Umsetzungsstand: [workflow/specs/community/DATA-MODEL.md](../workflow/specs/community/DATA-MODEL.md), vollständige Spalten-, Typ- und Indexlisten in [workflow/specs/community/HANDOFF-PR2-PR5.md](../workflow/specs/community/HANDOFF-PR2-PR5.md). Gesamtstand: **118 Tabellendefinitionen** in `SchemaManager::getSchemaQueries()` (90 bis `v26`, 28 neu in `v27`). Neuinstallationen erhalten sie über `getSchemaQueries()`, Updates über den `MigrationManager` (Schritt `v27`) und zusätzlich über die Laufzeitprüfung `SchemaManager::ensureFeatureSchema()` (idempotent, `CREATE TABLE IF NOT EXISTS`); `DatabaseUpdateRunner::missingSchemaElements()` prüft sie mit. `SchemaManager::SCHEMA_VERSION` und `MigrationManager::SCHEMA_VERSION` stehen auf `v27`.

**Einzige Änderung an einer Bestandstabelle:** `notifications` erhält `group_key VARCHAR(120) NULL` und `event_count INT UNSIGNED NOT NULL DEFAULT 1` sowie den Index `idx_user_group (user_id, group_key, is_read)`; die Spalten stehen in `getSchemaQueries()`, im `MigrationManager`, in `ensureFeatureSchema()` und in `SchemaManager::REQUIRED_COLUMNS`. Der `NotificationDispatcher` (PR #2) bündelt damit gleichartige Hinweise („3 neue Antworten in …“); Hinweise ohne `group_key` verhalten sich wie bisher. Alle Wege nutzen dieselbe Spaltendefinition samt Kommentar; `group_key` erhält im `ALTER` ausdrücklich `utf8mb4_unicode_ci` (Tabellen aus 4.0.0 tragen die Standardkollation des Servers: MariaDB und MySQL 5.7 `utf8mb4_general_ci`, MySQL 8 `utf8mb4_0900_ai_ci`). Entwicklungsinstallationen, die `v27` mit dem Code von PR #1 erreicht haben (Spalten ohne Kommentar, `group_key` mit der Kollation der Tabelle), gleicht `ensureFeatureSchema()` per `MODIFY COLUMN` an. Das Datenbank-Update prüft Kommentar und Kollation (`SchemaManager::REQUIRED_COLUMN_DEFINITIONS`) und den Index samt Spalten (`SchemaManager::REQUIRED_INDEXES`, dort auch die beiden nach PR #1 ergänzten Indizes an `community_topics`).

Eigenschaften aller 28 Tabellen: InnoDB, `utf8mb4_unicode_ci`, **keine Fremdschlüssel** (Konsistenz über die Dienste), Zeiten als `DATETIME` in UTC, Strukturwerte als `LONGTEXT` in Spalten `*_json`, **keine `NOT NULL`-Spalte ohne Default** (Strict Mode; Textspalten sind `NULL`-fähig), keine Klar-IP (nur `community_posts.ip_hash`, ein HMAC), keine `legacy_*`-Spalten (keine Datenübernahme aus `cms-forum`). Einstellungen liegen in `settings` (Gruppe `community`), nicht in einer eigenen Tabelle.

| Tabelle | Spalten | Zweck |
|---|---|---|
| `community_categories` | id, title, title_en, slug, slug_en, description, description_en, sort_order, is_visible, created_at, updated_at | Oberste Gliederung der Startseite |
| `community_forums` | id, category_id, parent_id, depth, path, title, title_en, slug, slug_en, description, description_en, icon, color, type, link_url, status, require_approval, slow_mode_seconds, auto_close_days, inherit_permissions, sort_order, topic_count, post_count, last_post_id, last_post_at, last_topic_id, last_poster_id, version, created_at, updated_at | Foren und Unterforen als Baum (`parent_id`, `depth`, `path` = `/3/17/` für Unterbaum-Abfragen) |
| `community_forum_permissions` | id, forum_id, subject_type, subject_key, permissions_json, updated_at | Rechte-Matrix je Forum und Subjekt |
| `community_forum_moderators` | forum_id, subject_type, subject_id, include_children | Forum-Moderatoren: `subject_type` `user` oder `group`, `include_children = 1` gilt auch für Unterforen |
| `community_topics` | id, forum_id, user_id, title, slug, status, moved_to_topic_id, moved_expires_at, is_pinned, pinned_until, is_announcement, is_locked, locked_until, slow_mode_seconds, prefix, first_post_id, solution_post_id, solved_at, solved_by, has_poll, has_attachments, reply_count, view_count, reaction_count, last_post_id, last_post_at, last_poster_id, version, trashed_at, trashed_by, previous_status, created_at, updated_at | `status`: `published` · `pending` · `hidden` · `deleted` · `trash` · `moved` (Verweis: `moved_to_topic_id`, `moved_expires_at`) |
| `community_posts` | id, topic_id, forum_id, user_id, is_first_post, status, status_reason, format, body, body_html, rendered_version, quote_of_post_id, edit_count, edited_at, edited_by, edit_reason, reaction_count, ip_hash, created_at, updated_at | `status`: `published` · `pending` · `hidden` · `deleted` · `rejected` (`status_reason` für den Autor sichtbar) |
| `community_post_revisions` | id, post_id, title, body, format, edited_by, edit_reason, created_at | Bearbeitungsverlauf; `title` nur beim ersten Beitrag |
| `community_attachments` | id, post_id, user_id, storage_path, original_name, mime_type, extension, file_size, sha256, width, height, has_preview, download_count, upload_token, created_at | Anhänge; Datei im privaten Speicher (`PrivateStorageService`, Bereich `community`), `storage_path` relativ |
| `community_drafts` | user_id, target_type, target_id, title, body, updated_at | Server-Entwürfe, ein Entwurf je Ziel (`target_type` `forum` = neues Thema, `topic` = Antwort) |
| `community_reactions` | post_id, user_id, reaction, created_at | Eine Reaktion je Mitglied und Beitrag (`reaction` = Schlüssel aus der Admin-Auswahl, Standard `like`) |
| `community_subscriptions` | user_id, target_type, target_id, mode, notified_at, created_at | `target_type` `topic` oder `forum`; `mode`: `instant` · `daily` · `weekly` · `none` (nur In-App) |
| `community_read_states` | user_id, topic_id, last_read_post_id, updated_at | Letzter gelesener Beitrag je Mitglied und Thema (`last_read_post_id` nur steigend) |
| `community_forum_read_marks` | user_id, forum_id, marked_at | „Alles bis hier gelesen“ je Forum; `forum_id = 0` gilt global |
| `community_polls` | id, topic_id, question, max_choices, results_visibility, allow_change, is_public_voters, ends_at, voter_count, created_at | Umfragen (Oberfläche ab P2): eine je Thema |
| `community_poll_options` | id, poll_id, label, vote_count, sort_order | Antwortoptionen (`vote_count` in derselben Transaktion wie die Stimme) |
| `community_poll_votes` | poll_id, option_id, user_id, created_at | Stimmen; bei Mehrfachauswahl eine Zeile je Option |
| `community_ignores` | user_id, ignored_user_id, created_at | Ignorieren-Liste (P2): Beiträge ignorierter Mitglieder eingeklappt, keine Hinweise von ihnen; Moderation ausgenommen |
| `community_user_stats` | user_id, topic_count, post_count, reactions_received, reactions_given, solutions_count, reputation, trust_level, trust_level_locked, topics_read, days_visited, rank_id, custom_title, signature, signature_html, settings_json, last_post_at, last_seen_at, updated_at | Zähler, Vertrauensstufe (`trust_level` 0–4, `trust_level_locked` = von der Moderation festgesetzt), Rang, Signatur, Einstellungen (`settings_json`: Auto-Abo, Online-Sichtbarkeit, Editor) |
| `community_ranks` | id, title, title_en, min_posts, color, icon, sort_order | Ränge nach Beitragszahl (`min_posts`) oder manuell (`min_posts` NULL) |
| `community_badges` | id, slug, title, title_en, description, description_en, icon, tier, rule_json, is_active | Abzeichen (Oberfläche ab P3); `rule_json` NULL = manuell vergeben |
| `community_user_badges` | user_id, badge_id, granted_by, reason, granted_at | Vergebene Abzeichen |
| `community_reports` | id, target_type, target_id, forum_id, reporter_id, reason, message, status, resolution_note, notify_reporter, handled_by, handled_at, created_at | `target_type`: `post` · `topic` · `user` |
| `community_warnings` | id, user_id, issued_by, post_id, reason, points, expires_at, revoked_at, revoked_by, created_at | Verwarnungen (Oberfläche ab P2): 1–10 Punkte, Ablauf (Standard 90 Tage), widerrufbar |
| `community_bans` | id, user_id, scope, reason, internal_note, issued_by, starts_at, ends_at, lifted_at, lifted_by, created_at | Community-Sperren: `scope` `write` (nur lesen) oder `full` (kein Zugriff); `ends_at` NULL = dauerhaft (`community.ban`) |
| `community_moderation_log` | id, actor_id, action, target_type, target_id, forum_id, reason, details_json, created_at | Moderationsprotokoll ohne IP-Adressen: `action` z. B. `topic.lock`, `topic.move`, `post.hide`, `user.ban`, `report.resolve`; `details_json` z. B. `{"from_forum":3,"to_forum":7}` |
| `community_stats_daily` | day, forum_id, topics, posts, active_members, new_members, solved, median_first_reply_minutes | Tagesverdichtung (Oberfläche ab P2); `forum_id = 0` = gesamt |
| `community_redirects` | source_type, source_key, target_type, target_id, created_at | Weiterleitungen nach Zusammenführen oder Umbenennen: `source_type` `topic` oder `forum`, `source_key` = alte ID bzw. alter Slug |
| `community_notify_queue` | id, kind, topic_id, post_id, actor_id, detail, phase, cursor_user_id, attempts, steps, lease_token, locked_until, created_at, updated_at | Offene Zustellaufträge der Benachrichtigungen (PR #2b): ein Auftrag je Ereignis, Cursor und Lease des Cron-Laufs, erledigte Aufträge werden gelöscht; Index `idx_community_notify_queue_kind` für die Stufen der Zustellung. Bestandsinstallationen v27 erhalten die Tabelle über `ensureFeatureSchema()` |

#### Rechte und Rolle (`capabilities_v27`)

Sechs neue Capabilities (`community.view`, `community.participate`, `community.moderate`, `community.ban`, `community.manage`, `community.view_private_data`) und die Systemrolle `moderator`; Katalog in `RolesModule::CAPABILITIES['community']`, Namen und Standards in `CMS\Services\Community\Support\CommunityCapabilities`. Der Update-Schritt trägt die Standardrechte einmalig in `role_permissions` ein (Marker: Option `capabilities_v27` in `settings`), überschreibt nie eine vorhandene Zeile, legt `role_permissions` bei Bedarf selbst an, trägt nur Katalog-Rechte `community.*` ein (die Grundrechte der Rolle `moderator` kommen aus den Code-Standards) und läuft bei Neuinstallation über `ensureFeatureSchema()`, beim Update über `MigrationManager::run()`. Ein fehlender Marker erscheint im Datenbank-Update als `option:capabilities_v27` und wird dort wiederholt. Details: [workflow/specs/community/HANDOFF-PR2-PR5.md](../workflow/specs/community/HANDOFF-PR2-PR5.md) §4.

### Schema v26

#### Tabellen aus `FEATURE_TABLES_V26`

Spezifikation: [workflow/specs/v4.1/SCHEMA-v26.md](../workflow/specs/v4.1/SCHEMA-v26.md). Gesamtstand der veröffentlichten Releases 4.1.x: **90 Tabellendefinitionen** in `SchemaManager::getSchemaQueries()` (76 bis `v25`, 14 neu in `v26`, darunter `invoice_sequences` und `invoices`). Neuinstallationen erhalten sie über `getSchemaQueries()`, Updates über den `MigrationManager` (Schritt `v26`) und zusätzlich über die Laufzeitprüfung `SchemaManager::ensureFeatureSchema()` (idempotent, `CREATE TABLE IF NOT EXISTS`). Die Konstanten `SchemaManager::SCHEMA_VERSION` und `MigrationManager::SCHEMA_VERSION` stehen auf `v26`.

| Tabelle / Spalte | Spalten | Zweck |
|---|---|---|
| `password_history` | id, user_id, password_hash, created_at | frühere bcrypt-Hashes für die Wiederverwendungssperre der Passwort-Richtlinie |
| `user_identities` | id, user_id, provider, tenant_id, subject, email_at_link, provisioned, linked_at, last_login_at | SSO-Bindungen (Entra ID): eindeutig über provider + tenant_id + subject (`oid`), nie über Benutzername/E-Mail; keine Tokens |
| `media_usage` | media_path, content_type, content_id, context, updated_at | Verwendungsnachweis für Medien (Bereich 2b): eine Zeile je Originaldatei, Inhalt und Kontext (`content`, `content_en`, `featured`, `gallery`, `attachment`, `setting`); Primärschlüssel mit Präfix `media_path(191)` |
| `content_workflow_events` | id, content_type, content_id, locale, action, from_status, to_status, user_id, comment, created_at | Freigabe-Verlauf (Bereich 3a): `submitted`, `approved`, `returned`, `withdrawn`, `comment`, `preview_created`, `preview_revoked`; Index je Inhalt und Zeit |
| `content_preview_links` | id, content_type, content_id, token_hash, expires_at, created_by, created_at | Vorschau-Links `/preview/<token>` für Entwürfe und eingereichte Inhalte: nur der SHA-256-Hash des Tokens (eindeutig), Ablauf 7 Tage, widerrufbar; abgelaufene Einträge entfernt `cms_cron_daily` |
| `reusable_blocks` | id, title, content, content_en, status, created_by, updated_by, created_at, updated_at | EditorJS Block Vorlagen (Bereich 3b): Editor.js-JSON je Sprache; eingebunden über den Block „Vorlagen“ (`reusableBlock`, Daten nur `{ "id" }`) |
| `reusable_block_usage` | block_id, content_type, content_id | Wo ist eine Vorlage eingebunden (`post`, `page`, `reusable_block`)? Für „Verwendet in“, Löschwarnung und das Aktualisieren verknüpfter Inhalte |
| `reusable_block_revisions` | id, block_id, title, content, content_en, created_by, created_at | Verlauf je Vorlage, die letzten 20 Fassungen |
| `backup_offsite_uploads` | id, backup_name, target, object_prefix, status, bytes, files, attempts, last_error, uploaded_at, created_at, updated_at | Offsite-Status je Backup (S3-kompatibel) |
| `webhook_endpoints` | id, name, url, events_json, secret_encrypted, is_active, consecutive_failures, disabled_reason, created_by, created_at, updated_at | Empfänger ausgehender Webhooks; Geheimnis über `CryptoService` |
| `webhook_deliveries` | id, endpoint_id, delivery_uuid, event, payload_json, status, attempts, next_attempt_at, response_status, last_error, created_at, delivered_at | Zustell-Warteschlange mit Wiederholungen |
| `payment_events` | id, provider, provider_event_id, type, order_id, status, payload_hash, error, received_at, processed_at | Eingehende Ereignisse der Zahlungsanbieter (Idempotenz), eindeutig je (provider, provider_event_id) |
| `orders`, `user_subscriptions`: Zahlungsspalten | `orders.provider`, `provider_checkout_id`, `paid_at` (Index `idx_provider_checkout`) · `user_subscriptions.provider`, `provider_subscription_id`, `current_period_end` (Index `idx_provider_sub`) | Zuordnung zu Stripe/Mollie und Laufzeitende bei Verlängerungen |
| `invoice_sequences` | sequence_key, next_number, updated_at | Rechnungs-Nummernkreise (nie rückwärts) |
| `invoices` | id, invoice_number, sequence_key, sequence_number, type, order_id, user_id, cancels_invoice_id, issued_at, service_period_start, service_period_end, currency, net_total, tax_total, gross_total, tax_mode, snapshot_json, pdf_path, pdf_sha256, created_by, restricted_at, created_at | Rechnungen/Storno-Rechnungen, unveränderlich, 10 Jahre |
| `posts`, `pages`, `kb_entries`: `expires_at`, `review_due_at`, `expiry_notified_at`, `review_notified_at` (DATETIME NULL), Indizes `idx_expires (status, expires_at)`, `idx_review_due` (Wissensdatenbank: `idx_kb_expires (is_active, expires_at)`, `idx_kb_review_due`) | Ablauf- und Prüfdaten (Bereich 3c, `ContentExpiryService`); die `*_notified_at`-Spalten verhindern Mehrfachmeldungen |
| `posts.status`, `pages.status` | neuer Wert `pending` | „Zur Prüfung“ (Vier-Augen-Prinzip): kein Schemawechsel (`VARCHAR(20)`); nie öffentlich, Frontend-Abfragen bleiben bei `status = 'published'` |
| `users.password_changed_at` | DATETIME NULL | Passwort-Alter für das optionale Höchstalter; Bestand `NULL` (rechnet ab `created_at`) |
| `passkey_credentials.user_verified` | TINYINT(1) NULL | UV-Flag der Registrierung; zählt beim MFA-Zwang nur mit `1` |

### Schema v24

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
| `auth_token_version` | Zähler für „Überall abmelden“: fließt in den Sitzungs-Fingerabdruck und den `pwf`-Claim der API-Tokens ein |
| `email_verification_token`, `email_verification_expires`, `email_verified_at` | Bestätigung neuer Konten (Token als SHA-256-Hash) bzw. Zeitpunkt der letzten Bestätigung |
| `pending_email`, `pending_email_token`, `pending_email_expires` | ausstehende E-Mail-Änderung (seit 4.0.0; Token als SHA-256-Hash, 48 Stunden gültig) |

Nicht sicherheitsrelevant, aber ebenfalls je Benutzer: `admin_ui_prefs` (`AdminUiPreferenceService`) speichert als JSON den eingeklappten Zustand der Seitenleiste (`sidebar_collapsed`), manuell auf- oder zugeklappte Gruppen und Plugin-Einträge (`groups`, höchstens 200 Schlüssel) und den gewählten Site-Kontext (`site`).

### Wichtige Optionen in `cms_settings`

| Option | Inhalt |
|---|---|
| `db_schema_version`, `installed_cms_version`, `installed_cms_schema_version` | Versionsstände |
| `active_plugins` | JSON-Liste aktiver Plugin-Slugs |
| `active_theme` | aktives Theme |
| `admin_plugin_site_assignments` | Plugin-Zuweisung je Site: JSON `{"<plugin-slug>": ["<site-key>", …]}`; Plugins ohne Eintrag gelten für alle Sites ([../admin/plugins/PLUGINS.md](../admin/plugins/PLUGINS.md#plugin-zuweisung)) |
| `admin_menu_profile` | Profil der Admin-Seitenleiste: `classic` (Standard), `structured` oder `custom`; gesetzt im Installer oder unter *Einstellungen › Admin-Menü* ([../admin/ADMIN-MENU.md](../admin/ADMIN-MENU.md)) |
| `admin_sites` | Site-Liste der Admin-Oberfläche (*Einstellungen › MultiSites*): JSON-Liste mit `key`, `label`, `url`, `accent`, `accent_soft`, `accent_strong`, `hosts`; ohne Option gilt die Haupt-URL der Installation (`SITE_URL`) als einzige Site ([../admin/system-settings/MULTISITES.md](../admin/system-settings/MULTISITES.md)) |
| `site_name`, `site_url`, `language`, … | allgemeine Einstellungen ([../admin/system-settings/SYSTEM.md](../admin/system-settings/SYSTEM.md)) |
| `seo_*`, `perf_*`, `firewall_*`, `antispam_*`, `cookie_*`, `member_*`, `legal_*`, `cms_loginpage_*` | Einstellungen der jeweiligen Bereiche |
| Gruppen über `SettingsService` (`mail`, `core_modules`, `ai.*`, `cron`, …) | gruppierte, teils verschlüsselte Werte (`json:`; Secrets seit 4.0.0 `enc:v2:<alg>:<Schlüssel-ID>:<Daten>` über `CryptoService`, Altwerte `enc:` werden automatisch neu verschlüsselt). Die Gruppe `cron` enthält seit 4.0.0 außerdem `last_run_at`, `last_run_status` (`ok`/`failed`), `last_run_task` und `last_error` des letzten Laufs (Systemhinweise im Admin) |

### Hinweise für Entwickler

- Tabellennamen immer über `getPrefix()` bilden, nie hart codieren.
- Eigene Plugin-Tabellen idempotent mit `CREATE TABLE IF NOT EXISTS` beim Aktivieren anlegen und bei `dsgvo_delete_data` personenbezogene Daten löschen.
- Für Spaltenergänzungen `columnExists()` prüfen und `ALTER TABLE … ADD COLUMN` nur bei Bedarf ausführen.
- Keine Werte in SQL interpolieren: Platzhalter `?` verwenden.

### Verwandte Dokumente

[ARCHITECTURE.md](ARCHITECTURE.md) · [CORE-CLASSES.md](CORE-CLASSES.md) · [../admin/system-settings/UPDATES.md](../admin/system-settings/UPDATES.md)
