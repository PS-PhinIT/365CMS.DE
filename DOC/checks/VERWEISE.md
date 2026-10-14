# 365CMS – Projektdokumentation | Abschnitt: Audit 2026-10-03 – Falsche Verweise

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | Übersicht: [README.md](README.md)

## English (summary)

References were checked mechanically: static `require`/`include` targets, asset URLs, `CMS\…` class names, admin links against the router, Markdown links and `CMS/…` paths named in `DOC/`. No missing include or class was found. Fixed: one dead admin link, outdated local marketplace manifests, 15 dead Markdown links and stale version statements.

## Deutsch

### Prüfungen ohne Befund

| Prüfung | Umfang | Ergebnis |
|---|---|---|
| `require`/`include` mit festem Pfad (`__DIR__`, `ABSPATH`, `CORE_PATH`) | alle PHP-Dateien außerhalb `vendor/`/`assets/` | alle Ziele vorhanden (zwei Treffer in `InstallerService` sind Teil einer Heredoc-Vorlage für `CMS/config.php`) |
| Klassenreferenzen `CMS\…` | 139 Namen | alle deklariert (inkl. `CMS\WP_Error` im Namespace-Block, Kandidatenlisten in `admin/pages.php`/`posts.php`) |
| Asset-URLs (`cms_asset_url()`, `ASSETS_URL`, `'/assets/…'`) | PHP + JS | vorhanden; einzige Ausnahme `css/local-fonts.css` ist per `file_exists()` abgesichert und wird vom Font-Manager erzeugt |
| Admin-Links `/admin/<seite>` | 115 URLs aus Views, Modulen und JS | Ziel-Dateien vorhanden – Ausnahme REF-01 |

### Behobene Befunde

| ID | Verweis | Problem | Korrektur |
|---|---|---|---|
| REF-01 | `CMS/admin/views/subscriptions/settings.php` → `/admin/subscriptions/packages` | Route `/admin/:page` kennt nur ein Segment → 404 | `/admin/packages` |
| REF-02 | `CMS/marketplace/plugins/index.json`, `CMS/marketplace/themes/index.json` | `cms-importer` 1.6.0 / `requires_cms` 0.26.0, `cms-default` 1.0.3 / 0.20.0 | `cms-importer` 3.0.3 / 3.0.0, `cms-default` 1.0.9 / 3.4.00 (wie `update.json` der Pakete) |
| REF-03 | `Changelog.md` → `Changelog_old.md` | Datei nicht mehr im Repository | Link entfernt, Hinweis auf Git-Historie |
| REF-03 | `DOC/INSTALLATION.md` → `CMS/README.md` | Datei existiert nicht; DB-Versionsangaben widersprachen dem Root-README | Text auf den tatsächlichen Stand gebracht |
| REF-03 | `DOC/assets/ASSET.md` → `assets/images/README.md`, `FILELIST.md`, `ai/…`, `admin/system-settings/…` | Pfade relativ zu `DOC/` statt zu `DOC/assets/` | `images/README.md`, `../FILELIST.md`, `../ai/…`, `../admin/…` |
| REF-03 | `DOC/assets/ASSETS_NEW.md` → `ai/AI-SERVICES.md`, `admin/system-settings/AI-SERVICES.md` | falsche Ebene | `../ai/…`, `../admin/…` |
| REF-03 | `DOC/assets/README.md` → `../ASSETS_NEW.md`, `../ASSETS_OwnAssets.md` | Dateien liegen in `DOC/assets/` | `ASSETS_NEW.md`, `ASSETS_OwnAssets.md` |
| REF-03 | `DOC/assets/mailer/README.md`, `DOC/assets/msgraph/README.md` → `../../ASSETS_OwnAssets.md` | eine Ebene zu hoch | `../ASSETS_OwnAssets.md` |
| REF-03 | `DOC/assets/msgraph/README.md` → `../VENDOR-NETWORK-PATHS.md` | Dokument wurde in 3.5.0 entfernt | Link entfernt, Hinweis umformuliert |
| REF-03 | `DOC/FILELIST.md` Abschnitt 1.2 | nannte `ASSETS/`, `TESTS/`, `tools/` als Repository-Ordner | als „nicht Teil des Repositorys“ gekennzeichnet |
| REF-04 | `README.md`, `DOC/core/STATUS.md`, Kopf von `Changelog.md` | nannten Changelog-Stand 3.5.0 | auf 3.5.0 aktualisiert |
| REF-04 | `DOC/core/STATUS.md`, `DOC/admin/subscription/ORDERS.md` | beschrieben `/order` als 404 | Weiterleitung dokumentiert |

### Bewusst nicht korrigiert

In `DOC/` genannte, aber nicht vorhandene `CMS/…`-Pfade, die **absichtlich** nicht existieren: entfernte Grid.js-Dateien (die Historien-READMEs unter `DOC/assets/gridjs/` & Co. wurden in 3.5.0 gelöscht), Roadmap-Dateien in `DOC/assets/ASSETS_OwnAssets.md`/`ASSETS_NEW.md`, das zur Laufzeit erzeugte `CMS/config/media-settings.json`, der optionale lokale Marketplace-Index `CMS/index.json` und das Beispiel-Plugin `cms-forum` im Workflow-Fahrplan.

### Mit 3.5.0 behoben

| ID | Verweis | Korrektur |
|---|---|---|
| REF-05 | `CMS/themes/cms-default/admin/customizer/helpers.php` → `admin/partials/admin-menu.php` | ungenutzte Funktion `cms_default_theme_customizer_get_admin_menu_paths()` entfernt (FUN-11) |

### Verwandte Dokumente

[README.md](README.md) · [../INDEX.md](../INDEX.md) · [../FILELIST.md](../FILELIST.md)
