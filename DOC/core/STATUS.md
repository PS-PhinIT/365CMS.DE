# 365CMS – Projektdokumentation | Abschnitt: Core – Implementierungsstatus

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | **Schema:** v23 | **Status:** Stable

## English (summary)

Snapshot of the runtime state verified against the source code on 2026-10-02: version and schema constants, feature areas and their maturity, the changes recorded since the 3.4.00 release (bundled in release 3.5.0), and a list of known gaps and inconsistencies found while updating the documentation.

## Deutsch

### Versionsstand

| Merkmal | Wert | Quelle |
|---|---|---|
| Core-Version | `3.5.4`, Release `2026-10-23`, Status `stable` | `CMS/core/Version.php`, `CMS/update.json` |
| Schema | `v23` | `SchemaManager::SCHEMA_VERSION`, `MigrationManager::SCHEMA_VERSION` |
| PHP | ≥ 8.4.1 | `CMS_MIN_PHP_VERSION` in `CMS/config.php` und `CMS\Version::MIN_PHP` (es zählt das Maximum, `Version::minimumPhp()`), `min_php` in `update.json` |
| Datenbank | MySQL ≥ 5.7 / MariaDB (empfohlen MySQL 8.0+ / MariaDB 10.6+) | Update-Preflight |
| Standard-Theme | `cms-default` 1.0.9 („Meridian CMS Default“) | `CMS/themes/cms-default/theme.json` |
| Mitgeliefertes Plugin | `cms-importer` 3.0.3 | `CMS/plugins/cms-importer/cms-importer.php` |
| Changelog | Zwischenstände `3.4.01`–`3.4.25`, Release `3.5.0` (14.10.2026), `3.5.1` und Release `3.5.2` (17.10.2026), Release `3.5.3` und `3.5.4` (23.10.2026) | `Changelog.md` – 3.5.0 bündelt alle Änderungen seit `3.4.00`, 3.5.2 enthält 3.5.1 |

### Änderungen seit 3.4.00 (Release 3.5.0, laut Changelog)

| Version | Schwerpunkt |
|---|---|
| 3.4.13 | Offene Audit-Punkte und Laufzeittests: Rate-Limits bei abweichender MySQL-Zeitzone, Kontaktformular-POST, Sprachspalten `posts` bei Neuinstallation, Options-Cache `OptionStore`, Schema v23 (Index `posts(status, published_at)`), JWT-API opt-in, Doku-Sync-UI, WP-Asset-Funktionen, CSRF für Plugin-Member-Bereiche, Kontakt-Rate-Limit, Google Fonts standardmäßig aus, toter Code entfernt |
| 3.4.12 | Audit 2026-10-03: Wartungsmodus, `/order`, `/health`, Config-Speichern, Upload-/Proxy-/Registrierungs-Härtung, Theme-Abfragen, Doku-Links – siehe [../checks/README.md](../checks/README.md) |
| 3.4.11 | PageSpeed: DOMPurify per `defer`, OPcache-Deploy-Prüfung gedrosselt |
| 3.4.10 | Editor.js: Zitate/Media-Text im Purifier, klassenbasiertes CSS |
| 3.4.09 | Dokumentation vollständig gegen 3.4-Code abgeglichen |
| 3.4.08 | SEO: Template-Variablen in gespeicherten Meta-Titeln/-Beschreibungen werden aufgelöst |
| 3.4.07 | Admin-Sidebar: Plugin-Menüs nach Schema „Familie \| Name“, umbrechende Titel, Tooltip |
| 3.4.06 | Suche: nur Treffer mit allen Begriffen, sichtbarer Text statt Editor.js-JSON, strikte Typ-Filter, Sortierung nach Relevanz/Datum, Plugin-Treffer mit `date` |
| 3.4.05 | Theme: Logout per POST mit CSRF-Token, mobiles Menü bis 720 px |
| 3.4.04 | SEO: externe Links nicht mehr pauschal `nofollow`, Schema-Typen auf ausgegebene Typen begrenzt, Plugin-Seiten in der Sitemap (`cms_sitemap_entries`), Plugin-Treffer in `/search`, TNTSearch-Verbindungsfix |
| 3.4.03 | Theme: Tag-/Kategorie-Archive ohne HTTP 500, OPcache-Warmup bricht Admin-Seiten nicht mehr ab, Plugin-Unterseiten erhalten `$_GET['page']` |
| 3.4.02 | OPcache-Warmup nach Deploy ohne „Cannot redeclare class“ |
| 3.4.01 | Rollen & Rechte: Rechte-Matrix ohne doppelte Einträge |
| ältere | siehe [../../Changelog.md](../../Changelog.md) |

### Funktionsbereiche

| Bereich | Status | Dokumentation |
|---|---|---|
| Seiten/Beiträge (DE/EN, Revisionen, Planung) | produktiv | [../admin/pages-posts/](../admin/pages-posts/README.md) |
| Editor.js / SunEditor | produktiv | [../assets/editorjs/README.md](../assets/editorjs/README.md) |
| Medien (Bibliothek, WebP, Thumbnails) | produktiv | [../admin/media/MEDIA.md](../admin/media/MEDIA.md) |
| Benutzer, Rollen, Gruppen | produktiv | [../admin/users-groups/](../admin/users-groups/README.md) |
| Anmeldung (Passwort, TOTP, Passkeys, LDAP) | produktiv | [SECURITY.md](SECURITY.md) |
| Mitgliederbereich | produktiv | [../member/README.md](../member/README.md) |
| Aboverwaltung | produktiv, ohne Zahlungs-Gateway | [../admin/subscription/](../admin/subscription/README.md) |
| SEO-Suite, Sitemaps, Weiterleitungen | produktiv | [../admin/seo/](../admin/seo/README.md) |
| Rechtstexte, Cookie-Consent, DSGVO-Anfragen | produktiv | [../admin/legal/](../admin/legal/README.md) |
| Firewall, Anti-Spam, Audit | produktiv | [../admin/security/](../admin/security/README.md) |
| KI-Dienste | produktiv (nur Admin, keine Auto-Veröffentlichung) | [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md) |
| Backups, Updates, Monitoring, Cron | produktiv | [../admin/system-settings/](../admin/system-settings/README.md) |
| JSON-API | lesend + Admin-Listen, Session-basiert | [API-REFERENCE.md](API-REFERENCE.md) |

### Bekannte Lücken und Inkonsistenzen (Stand 2026-10-03)

Nachgeprüft im Audit vom 2026-10-03 ([../checks/README.md](../checks/README.md)). Behobene Punkte bleiben zur Nachvollziehbarkeit stehen.

| # | Befund | Auswirkung | Fundstelle | Status |
|---|---|---|---|---|
| 1 | Speichern unter `/admin/settings` erzeugte `config/app.php` aus einer Vorlage neu | manuell gesetzte Konstanten gingen verloren | `SettingsModule::saveSettings()` | ✅ 3.4.12 – nur verwaltete Konstanten werden ersetzt |
| 2 | Option `maintenance_mode` wurde nicht ausgewertet | Wartungsmodus ohne Wirkung | `SettingsModule`, Router | ✅ 3.4.12 – 503-Seite in `Router::dispatch()` |
| 3 | Route `/order` erwartete fehlendes `CMS/member/order_public.php` | 404 | `PublicRouter::renderOrder()` | ✅ 3.4.12 – Weiterleitung auf `/orders.php` |
| 4 | Sicherheits-Audit bewertete PHP ≥ 8.2 als „ok“ | Abweichung zur Mindestanforderung 8.4 | `SecurityAuditModule` | ✅ 3.4.12 – nutzt `CMS_MIN_PHP_VERSION` |
| 5 | `checkLimit('storage')` suchte `limit_storage`; Free-Fallback unsortiert | Speicherlimit nicht prüfbar | `SubscriptionManager` | ✅ 3.4.12 |
| 6 | `DesignSettingsModule` und `views/themes/settings.php` wurden nicht eingebunden | toter Code | `CMS/admin/modules/themes/` | ✅ 3.4.13 – entfernt |
| 7 | Dokumentations-Sync-Klassen waren nicht an `/admin/documentation` angebunden | kein Abgleich per Oberfläche | `DocumentationSync*` | ✅ 3.4.13 – Karte „Doku-Sync“ |
| 8 | Sammelaktion `delete` löschte wie `hard_delete` | kein weiches Löschen | `UserService::bulkAction()` | ✅ 3.4.12 – `delete` = Soft-Delete |
| 9 | Health-Endpunkt ohne Core-Route | Prüfung schlug fehl | `SystemInfoModule` | ✅ 3.4.12 – `GET /health` (Standardpfad) |
| 10 | `JwtService` war nicht in API-Routen verdrahtet | API nur mit Session | `ApiRouter`, `Api` | ✅ 3.4.13 – Bearer-Anmeldung opt-in über `JWT_SECRET` |
| 11 | Beispiel-Manifeste unter `CMS/marketplace/` mit veralteten Versionen | Anzeige veraltet | `CMS/marketplace/*/index.json` | ✅ 3.4.12 |
| 12 | Member-Upload sendete Token `member_media_action`, `/api/upload` prüft `media_action` | erster Upload scheiterte (403) | `CMS/member/media.php` | ✅ 3.4.12 |
| 13 | Plugin-Member-Bereiche (`post_callback`) ohne zentrale CSRF-Prüfung | Plugins mussten CSRF selbst prüfen | `PluginDashboardRegistry` | ✅ 3.4.13 – Herkunftsprüfung, optional Core-Token (`'csrf' => 'core'`) |
| 14 | `wp_enqueue_*`/`wp_dequeue_*` waren leere Stubs | WP-nahe Plugins luden keine Assets | `includes/functions/wordpress-compat.php` | ✅ 3.4.13 – Asset-Registry |
| 16 | DB-Rate-Limits zählten nie, wenn MySQL-Zeitzone ≠ PHP-Zeitzone | Login-/Reset-/API-Schutz wirkungslos | `Security::checkDbRateLimit()` | ✅ 3.4.13 – Sitzungszeitzone in `Database` |
| 17 | `POST /contact` ohne Route | Kontaktformular immer 404 | `ThemeRouter` | ✅ 3.4.13 |
| 18 | `posts.title_en/content_en/excerpt_en` nur über `/admin/posts` ergänzt | frische Installation: `/blog` 500 | `SchemaManager`, `PostsModule` | ✅ 3.4.13 – Schema v23 |
| 15 | `Version::CURRENT` blieb `3.4.00` trotz ausgelieferter Fixes | Installationen nicht unterscheidbar | `CMS/core/Version.php` | ✅ 3.5.0 – `Version::CURRENT = 3.5.0` (Audit FUN-12) |

### Grundsatz dieser Dokumentation

Dokumentiert wird nur, was im ausgelieferten Code nachvollziehbar ist. Pfade, Klassen und Routen in `DOC/` wurden am 2026-10-02 gegen `CMS/` abgeglichen und im Audit vom 2026-10-03 erneut geprüft.

### Verwandte Dokumente

[README.md](README.md) · [../README.md](../README.md) · [../checks/README.md](../checks/README.md) · [../../Changelog.md](../../Changelog.md)
