# 365CMS – Projektdokumentation | Abschnitt: Core – Datenbank & Schema

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Schema:** `v23` | **Status:** Stable

## English (summary)

365CMS uses MySQL ≥ 5.7 or a current MariaDB (InnoDB, `utf8mb4`; the update preflight checks the server version ≥ 5.7) through the PDO wrapper `CMS\Database` (native prepared statements, `ATTR_EMULATE_PREPARES = false`). All tables carry the prefix `DB_PREFIX` (default `cms_`). The base schema (45 tables) is defined in `CMS\SchemaManager::getSchemaQueries()`; `CMS\MigrationManager::run()` applies idempotent `CREATE`/`ALTER` migrations once per schema version (`SCHEMA_VERSION = 'v23'`, flag file `CMS/cache/db_schema_v23.flag`). Further tables are created on demand by the services and admin modules that own them. Settings use the key/value table `cms_settings`.

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
| `user_meta` | id, user_id → users (CASCADE), meta_key, meta_value |
| `roles` | id, name, display_name, description, capabilities, member_dashboard_access, sort_order, … |
| `sessions` | id (SHA-256 der Session-ID, nie die ID selbst), user_id, ip_address, user_agent, payload, last_activity, expires_at – ab 3.4.20 von `Auth` bei Anmeldung angelegt, höchstens alle 5 Minuten aktualisiert und bei Abmeldung/Passwortwechsel entfernt |
| `passkey_credentials` | id, user_id, credential_id, public_key, sign_count, aaguid, attestation_fmt, name, created_at, last_used_at |
| `password_resets` | id, email, token, expires_at, created_at |
| `login_attempts`, `failed_logins` | Anmeldeversuche (Rate-Limit, Sicherheitsalarme). `action` VARCHAR(30), `username` VARCHAR(60): `Security::normalizeRateLimitAction()`/`normalizeRateLimitIdentifier()` kürzen bzw. hashen längere Werte (ab 3.4.20) |
| `blocked_ips` | id, ip_address, reason, expires_at, permanent, … |
| `user_groups` | id, name, slug, description, role_id, plan_id, is_active, … |
| `user_group_members` | id, user_id, group_id, joined_at |

**Inhalte**

| Tabelle | Spalten |
|---|---|
| `pages` | id, slug, slug_en, title (+ title_en), content (+ content_en), excerpt, status, hide_title, show_title_toc, featured_image, meta_title, meta_description, author_id, category_id, created_at, updated_at, published_at, content_updated_at, page_template (VARCHAR 80), page_meta_json (TEXT; ab 3.4.21, Neuinstallation per `SchemaManager`, Bestand per `PageManager::ensureColumns()`) |
| `page_revisions` | id, page_id, title, title_en, slug, slug_en, content, content_en, excerpt, status, author_id, content_updated_at, page_template (VARCHAR 80), page_meta_json (TEXT), created_at |
| `posts` | id, title (+ title_en), slug, slug_en, content (+ content_en), excerpt (+ excerpt_en), featured_image, status, author_id, author_display_name, author_display_url, post_template, post_meta_json, category_id, tags, views, allow_comments, meta_title, meta_description, created_at, updated_at, published_at, content_updated_at |
| `post_revisions` | Snapshot je Speichern (Titel, Slugs, Inhalte DE/EN, Status, Kategorie, Tags, Autor-Anzeige, Datumswerte) |
| `post_categories` | id, name, slug, slug_en, description, parent_id, sort_order, replacement_category_id, created_at |
| `post_tags` | id, name, slug, slug_en, description, post_count, created_at |
| `post_tag_rel`, `post_category_rel` | Zuordnungen (CASCADE auf `posts`) |
| `comments` | id, post_id, user_id, author, author_email, author_ip, content, status (`pending/approved/spam/trash`), post_date, modified_at |
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

### Wichtige Optionen in `cms_settings`

| Option | Inhalt |
|---|---|
| `db_schema_version`, `installed_cms_version`, `installed_cms_schema_version` | Versionsstände |
| `active_plugins` | JSON-Liste aktiver Plugin-Slugs |
| `active_theme` | aktives Theme |
| `site_name`, `site_url`, `language`, … | allgemeine Einstellungen ([../admin/system-settings/SYSTEM.md](../admin/system-settings/SYSTEM.md)) |
| `seo_*`, `perf_*`, `firewall_*`, `antispam_*`, `cookie_*`, `member_*`, `legal_*`, `cms_loginpage_*` | Einstellungen der jeweiligen Bereiche |
| Gruppen über `SettingsService` (`mail`, `core_modules`, `ai.*`, `cron`, …) | gruppierte, teils verschlüsselte Werte (`enc:`/`json:`) |

### Hinweise für Entwickler

- Tabellennamen immer über `getPrefix()` bilden, nie hart codieren.
- Eigene Plugin-Tabellen idempotent mit `CREATE TABLE IF NOT EXISTS` beim Aktivieren anlegen und bei `dsgvo_delete_data` personenbezogene Daten löschen.
- Für Spaltenergänzungen `columnExists()` prüfen und `ALTER TABLE … ADD COLUMN` nur bei Bedarf ausführen.
- Keine Werte in SQL interpolieren – Platzhalter `?` verwenden.

### Verwandte Dokumente

[ARCHITECTURE.md](ARCHITECTURE.md) · [CORE-CLASSES.md](CORE-CLASSES.md) · [../admin/system-settings/UPDATES.md](../admin/system-settings/UPDATES.md)
