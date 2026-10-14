# 365CMS – Projektdokumentation | Abschnitt: Admin – Theme-Verwaltung & Theme-Marktplatz

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Routen:** `/admin/themes`, `/admin/theme-marketplace` | **Capability:** `manage_settings` | **CSRF-Aktionen:** `admin_themes`, `admin_theme_marketplace`

## English (summary)

- `/admin/themes` (`CMS/admin/themes.php` → `ThemesModule`) lists installed themes from `CMS/themes/`, activates (`activate`) and deletes (`delete`) them. The active theme and the last remaining theme cannot be deleted.
- `/admin/theme-marketplace` (`CMS/admin/theme-marketplace.php` → `ThemeMarketplaceModule`) reads a remote catalog (default index `https://365cms.de/marketplace/themes/index.json`, setting `theme_registry_url`; relative paths resolve against `theme_marketplace_url`, default `https://365cms.de/marketplace/themes`), caches it for 15 minutes and installs ZIP packages (`install`) from an allow-listed set of hosts, requiring a valid SHA-256 checksum. The menu entry is hidden when `marketplace_enabled = 0`.

## Deutsch

### Theme-Verwaltung (`/admin/themes`)

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/themes.php` |
| Modul | `CMS/admin/modules/themes/ThemesModule.php` |
| View | `CMS/admin/views/themes/list.php` |
| Core | `CMS/core/ThemeManager.php` (`getAvailableThemes()`, `switchTheme()`, `healthCheckTheme()`, `deleteTheme()`) |

- **Anzeige:** Name, Version, Autor, Beschreibung, Screenshot und Status jedes Themes aus `theme.json`/`style.css`.
- **Aktivieren (`activate`):** `ThemeManager::switchTheme()` führt vorher einen Health-Check aus (`style.css` vorhanden, `index.php` oder `functions.php` vorhanden, alle PHP-Dateien syntaktisch gültig). Schlägt er fehl, bleibt das bisherige Theme aktiv.
- **Löschen (`delete`):** entfernt das Theme-Verzeichnis. Nicht möglich für das **aktive** Theme und das **letzte** verfügbare Theme.
- Customizer-Werte (`cms_theme_customizations`) bleiben beim Wechsel pro Theme erhalten.

### Theme-Marktplatz (`/admin/theme-marketplace`)

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/theme-marketplace.php` |
| Modul | `CMS/admin/modules/themes/ThemeMarketplaceModule.php` |
| View | `CMS/admin/views/themes/marketplace.php` |
| Installation | `CMS/core/Services/UpdateService.php` (Download, Prüfsumme, Entpacken) |

**Katalogquelle:** Einstellung `theme_registry_url` (*Einstellungen → Allgemein → Marketplace & Updates*), Standard `https://365cms.de/marketplace/themes/index.json`; relative Manifest-, Paket- und Screenshot-Pfade werden gegen `theme_marketplace_url` (Standard `https://365cms.de/marketplace/themes`) aufgelöst. Der Katalog (max. 1 MB, Manifeste max. 512 KB) wird 15 Minuten in `theme_marketplace_catalog_cache` zwischengespeichert.

**Erlaubte Hosts** für Katalog und Pakete: `365cms.de`, `www.365cms.de`, `365network.de`, `www.365network.de`, `github.com`, `api.github.com`, `codeload.github.com`, `objects.githubusercontent.com`, `raw.githubusercontent.com`.

**Manifest-Felder** (Auszug): `slug`, `name`, `description`, `version`, `author`, `download_url`/`package_url`, `sha256`/`checksum_sha256`, `package_size`, `requires_cms`/`min_cms_version`, `requires_php`/`min_php`, `tested_up_to`, `screenshot`, `homepage_url`, `docs_url`, `changelog_url`, `is_paid`, `price_amount`, `price_currency`, `purchase_url`.

**Installation (`action=install`, Feld `theme`):**

1. Theme muss im Katalog vorhanden und noch nicht installiert sein.
2. Paket nur als `.zip`, max. 100 MB; Archiv max. 2000 Einträge und 50 MB entpackt (Schutz vor ZIP-Bomben und Pfad-Traversal).
3. Eine gültige SHA-256-Prüfsumme (`sha256`/`checksum_sha256`) ist **Pflicht** – ohne sie bricht `UpdateService::downloadAndInstallUpdate()` ab (`updates.install.integrity_hash_missing`); das Prüfergebnis wird angezeigt.
4. Zielverzeichnis `CMS/themes/<slug>/` darf noch nicht existieren.
5. Kostenpflichtige Themes (`is_paid`) werden mit Preis und Kauf-Link angezeigt statt installiert.

Danach erscheint das Theme unter `/admin/themes` und kann aktiviert werden.

### Marketplace deaktivieren

*Einstellungen → Allgemein → Marketplace aktivieren* (`marketplace_enabled = 0`) blendet die Marketplace-Menüpunkte für Themes und Plugins aus.

### Verwandte Dokumente

[../plugins/MARKETPLACE.md](../plugins/MARKETPLACE.md) · [../../workflow/MARKETPLACE-WORKFLOW.md](../../workflow/MARKETPLACE-WORKFLOW.md) · [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md)
