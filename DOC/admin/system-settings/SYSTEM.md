# 365CMS – Projektdokumentation | Abschnitt: Admin – Allgemeine Einstellungen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/settings` (Tabs `general`, `content`) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_settings`

## English (summary)

`/admin/settings` (`CMS/admin/settings.php` → `CMS/admin/modules/settings/SettingsModule.php` → `CMS/admin/views/settings/general.php`) holds the site-wide configuration. Tab `general` covers website identity, localisation, mail hints, member/registration hints, marketplace and update endpoints, maintenance mode and advanced options; tab `content` is described in [../pages-posts/SETTINGS.md](../pages-posts/SETTINGS.md). Actions: `save`, `run_site_url_migration`, `repair_imported_slugs`.

**Important:** `save` writes most values to `cms_settings` **and regenerates `CMS/config/app.php`** (site name, URL, admin e-mail, DB credentials and keys are carried over). Custom constants such as `LDAP_*`, `JWT_*`, `SMTP_*` or `CMS_HTTPS_REDIRECT_STRATEGY` that were edited directly in `config/app.php` are reset to their defaults. Back up `config/app.php` before saving and re-apply such values afterwards (`CMS/index.php` loads `config/app.php` first, so they cannot be moved to `config.php`).

## Deutsch

### Tab „Allgemein“

| Bereich | Felder (Option) | Hinweise |
|---|---|---|
| Website | Name (`site_name`), Beschreibung (`site_description`), URL (`site_url`), Logo (`site_logo`), Favicon (`site_favicon`), Admin-E-Mail (`admin_email`) | URL muss gültig sein; Logo/Favicon als Medienreferenz |
| Lokalisierung | Sprache (`language`: de, en, fr, es, it, nl, pl, pt), Zeitzone (`timezone`, z. B. Europe/Berlin, Europe/Vienna, Europe/Zurich, UTC …), Datums-/Zeitformat (`date_format`, `time_format`) | |
| Mail-System | Hinweis und Link auf `/admin/mail-settings` | eigentliche Konfiguration siehe [MAIL.md](MAIL.md) |
| Inhalte | Beiträge pro Seite (`posts_per_page`, 1–100), Kommentare aktiv (`comments_enabled`) | Benutzer-/Auth-Optionen liegen unter `/admin/user-settings` |
| Marketplace & Updates | Marketplace aktiv (`marketplace_enabled`), Marketplace-Übersicht (`marketplace_public_url`), Plugins-Index (`plugin_registry_url`), Themes-Index (`theme_registry_url`), Plugins-Basis (`plugin_marketplace_base_url`), Themes-Basis (`theme_marketplace_url`), Public Einreichung (`marketplace_submit_url`), CMS-Update-Feed (`core_update_url`) | Standard: `https://365cms.de/marketplace-public`, `…/marketplace/plugins/index.json`, `…/marketplace/themes/index.json`, `…/marketplace/plugins`, `…/marketplace/themes`, `…/marketplace-submit`, `…/marketplace/core/365cms/update.json` (zentral in `MarketplaceEndpoints`) |
| Wartungsmodus | `maintenance_mode`, `maintenance_message` | Seit 3.5.0 aktiv: Besucher erhalten HTTP 503 mit der Nachricht (erlaubt `<p><strong><em><br>`), API-Aufrufe JSON. Erreichbar bleiben eingeloggte Admins, `/admin/*`, Login-/Passwort-/MFA-Routen und `/health` |
| Erweitert | Google-Analytics-Altfeld (`google_analytics`), `robots_txt` | Tracking besser über [../seo/ANALYTICS.md](../seo/ANALYTICS.md) |

### Site-URL-Migration (`run_site_url_migration`)

Nach einem Domainumzug ersetzt die Aktion die alte Domain (`migrate_from_site_url`) durch die neue in allen relevanten Tabellen und Spalten, u. a. `settings`, `user_meta`, Inhalte/Auszüge/Beitragsbilder von `pages` und `posts`, `landing_sections`, `plugins`, `plugin_meta`, `theme_customizations`, `site_tables`, `redirect_rules`, `messages`, `comments`, Logs sowie `mail_queue`. Vorher **unbedingt ein Backup** anlegen ([BACKUP.md](BACKUP.md)).

Die angezeigte „aktive Runtime-URL“ ist der tatsächlich verwendete Wert aus `SITE_URL`.

### Speichern und `config/app.php`

`SettingsModule::saveSettings()`:

1. validiert alle Felder (Allowlists für Sprache, Zeitzone, Editor, Status; URL-Prüfungen für Marketplace-Endpunkte),
2. speichert Optionen in `cms_settings` (`SETTINGS_KEYS`),
3. erzeugt `CMS/config/app.php` aus der Vorlage neu, übernimmt dabei DB-Zugang, `AUTH_KEY`/`SECURE_AUTH_KEY`/`NONCE_KEY`, `CMS_DEBUG`, `SITE_NAME`, `SITE_URL`, `ADMIN_EMAIL` und prüft die Datei vor dem Schreiben auf gültige PHP-Syntax.

> **Achtung:** Die Vorlage setzt `LDAP_*`, `JWT_*`, `SMTP_*`, HTTPS-/HSTS-Konstanten (`CMS_HTTPS_REDIRECT_STRATEGY`, `CMS_HSTS_*`) und die Zeitzone (`Europe/Berlin`) auf Standardwerte zurück. Vor dem Speichern `config/app.php` sichern und eigene Werte danach erneut eintragen. Ein Auslagern nach `CMS/config.php` hilft nicht, weil `CMS/index.php` `config/app.php` zuerst lädt. Mail-Zugangsdaten gehören ohnehin in `/admin/mail-settings` (verschlüsselt in der Datenbank).

### Weitere Systemseiten

| Seite | Dokument |
|---|---|
| Mail & Azure OAuth2 | [MAIL.md](MAIL.md) |
| Module | [MODULES.md](MODULES.md) |
| Backup & Restore | [BACKUP.md](BACKUP.md) |
| Updates | [UPDATES.md](UPDATES.md) |
| Dokumentation | siehe unten |

### Dokumentation (`/admin/documentation`)

`CMS/admin/documentation.php` → `DocumentationModule` zeigt die Markdown- und CSV-Dateien aus dem Ordner `DOC/` neben dem `CMS/`-Verzeichnis als navigierbaren Katalog an (`?doc=<pfad>`; nur `.md`/`.csv`, keine Pfad-Traversal, max. 256 KB je Datei). Relative Links zwischen Dokumenten werden umgeschrieben. Lesend, Capability `manage_settings` oder `manage_system`. `/admin/support` leitet auf diese Seite um.

**Doku-Sync (seit 3.5.0):** Benutzer mit `manage_system` sehen links die Karte „Doku-Sync“ mit Status und – falls möglich – dem Button „/DOC jetzt synchronisieren“ (Aktion `sync_docs`, CSRF `admin_documentation`, Bestätigungsdialog). `DocumentationSyncService` wählt den Modus:

| Modus | Voraussetzung | Konfiguration (`config/app.php`, optional) |
|---|---|---|
| Git | `.git` im Repository-Root, `git` und `exec()` verfügbar | `CMS_DOCS_SYNC_REMOTE` (Standard `origin`), `CMS_DOCS_SYNC_BRANCH` (Standard `main`) |
| GitHub-ZIP | ZIP-Extension, HTTPS über den zentralen HTTP-Client | **Pflicht:** `CMS_DOCS_SYNC_BUNDLE_SHA256` (SHA-256 des freigegebenen Bundles) und `CMS_DOCS_SYNC_BUNDLE_FILES` (Dateianzahl); optional `CMS_DOCS_SYNC_ZIP_URL` |

Ohne freigegebenes Bundle zeigt die Karte „ZIP-Sync nicht konfiguriert“. Der Sync ersetzt ausschließlich den Ordner `DOC/` und läuft mit Datei-Lock; lokale Änderungen in `DOC/` gehen verloren.

### Verwandte Dokumente

[README.md](README.md) · [../pages-posts/SETTINGS.md](../pages-posts/SETTINGS.md) · [../../INSTALLATION.md](../../INSTALLATION.md)
