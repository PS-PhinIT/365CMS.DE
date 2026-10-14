# 365CMS – Projektdokumentation | Abschnitt: Theme – Entwicklungsablauf

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Practical workflow for building or changing a theme: start from `cms-default` (copy and rename), develop locally against a 365CMS installation with `CMS_DEBUG = true` (CSP in report-only mode), keep everything in `CMS/themes/<slug>/`, bump the version in all four places, test the contract and publish the theme as a ZIP (optionally via the marketplace). Never edit the shipped theme in place on production – updates overwrite it.

## Deutsch

### 1. Startpunkt

1. `CMS/themes/cms-default/` nach `CMS/themes/mein-theme/` kopieren.
2. In `style.css` (`Theme Name`, `Version`, `Author`), `theme.json` (`name`, `slug`, `version`), `update.json` (`slug`, `name`, `version`, `min_cms_version: "3.4.00"`) und `functions.php` (Klassen- und Konstantennamen, z. B. `MERIDIAN_*` → eigenes Präfix) anpassen.
3. Unter `/admin/themes` aktivieren – der Health-Check prüft Pflichtdateien und PHP-Syntax.

### 2. Lokale Entwicklung

- `CMS_DEBUG = true` in `config/app.php`: ausführliche Fehler, Debug-Checkpoints, CSP nur als *Report-Only* (Verstöße erscheinen in der Browser-Konsole, ohne zu blockieren). Vor dem Livegang wieder `false`.
- Theme-Dateien direkt im Editor bearbeiten; der Admin-**Theme-Explorer** (`/admin/theme-explorer`) eignet sich für kleine Korrekturen.
- Customizer-Optionen in `admin/customizer/config.php` ergänzen, Ausgabe als CSS-Variablen ([DESIGN-SYSTEM.md](DESIGN-SYSTEM.md)).
- Nach Dateiänderungen bei aktivem OPcache ggf. `/admin/performance-cache` → OPcache leeren.

### 3. Prüfliste vor dem Release

- [ ] Alle Templates aus `theme.json` existieren; `index.php`-Fallback vorhanden.
- [ ] `head` im `<head>`, `body_end` vor `</body>`, `cms_csp_runtime_tags()` als erstes Skript.
- [ ] Keine Inline-Skripte ohne Nonce, keine `on*`-Attribute, keine externen Skripte ohne Freigabe und Consent.
- [ ] Ausgaben escaped; Formulare mit CSRF-Token (`form_guard`, `logout`, `comment_<id>`).
- [ ] Lokale Schriften und externer Consent werden respektiert.
- [ ] Startseite in beiden Modi (`posts`, `landing`), Suche, Archive (`/kategorie`, `/tag`), 404, EN-Pfade (`/en/…`) geprüft.
- [ ] Mobile Navigation (≤ 720 px) und Tastaturbedienung geprüft.
- [ ] Versionsnummer in `style.css`, `theme.json`, `update.json`, `functions.php` identisch; Changelog in `update.json` ergänzt.
- [ ] Sicherheits-Audit ohne neue Befunde zu Fremd-Assets.

### 4. Auslieferung

- ZIP mit dem Theme-Ordner auf oberster Ebene (`mein-theme/style.css` …), keine `.git`, `node_modules`, Zugangsdaten.
- SHA-256 der ZIP-Datei berechnen (`sha256sum mein-theme-1.0.0.zip`) und in Katalog/`update.json` eintragen.
- Bereitstellung über einen eigenen Theme-Katalog (Einstellung `theme_marketplace_url`) oder manuelles Hochladen per SFTP nach `CMS/themes/`.

### 5. Updates eigener Themes

Customizer-Werte bleiben beim Update erhalten (Datenbank). Dateiänderungen auf dem Server gehen verloren – Anpassungen gehören ins Repository des Themes.

### Verwandte Dokumente

[THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) · [COMPONENTS.md](COMPONENTS.md) · [../admin/themes-design/MARKETPLACE.md](../admin/themes-design/MARKETPLACE.md) · [../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md)
