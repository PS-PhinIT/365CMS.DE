# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugin-Marketplace

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/plugin-marketplace` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_plugin_mp`

## English (summary)

The plugin marketplace (`CMS/admin/plugin-marketplace.php` → `CMS/admin/modules/plugins/PluginMarketplaceModule.php` → `CMS/admin/views/plugins/marketplace.php`) reads the plugin registry (setting `plugin_registry_url`, default `https://365cms.de/marketplace/plugins/index.json`), caches it for 15 minutes and installs ZIP packages via `UpdateService::downloadAndInstallUpdate()` (action `install`). Only allow-listed hosts are accepted and a valid SHA-256 checksum is mandatory. The menu entry is hidden when `marketplace_enabled = 0`.

## Deutsch

### Katalog

- Quelle: `plugin_registry_url` (*Einstellungen → Allgemein → Marketplace & Updates*).
- Grenzen: Registry max. 1 MB, einzelne Manifeste max. 512 KB, Textfelder max. 500 Zeichen.
- Cache: 15 Minuten in `plugin_marketplace_registry_cache` (+ `_meta`).
- Erlaubte Hosts: `365cms.de`, `www.365cms.de`, `365network.de`, `www.365network.de`, `github.com`, `api.github.com`, `codeload.github.com`, `objects.githubusercontent.com`, `raw.githubusercontent.com`.
- Anzeige je Plugin: Name, Beschreibung, Version, Autor, Kompatibilität (`requires_cms`/`min_cms_version`, `requires_php`/`min_php`, `tested_up_to`), Status (installiert/aktualisierbar), Preis bzw. Kauf-Link bei kostenpflichtigen Plugins.

### Installation (`action=install`, Feld `slug`)

1. Plugin muss im Katalog stehen und darf noch nicht installiert sein.
2. Download nur von erlaubten Hosts, Paket nur `.zip`, max. 100 MB.
3. SHA-256-Prüfsumme ist Pflicht und wird gegen das Paket geprüft.
4. Entpacken mit Grenzen (2000 Einträge, 50 MB entpackt) und Pfadprüfung.
5. Ziel `CMS/plugins/<slug>/`; danach unter `/admin/plugins` aktivieren.

### Eigene Registry betreiben

Eine Registry ist eine JSON-Datei mit dem Schlüssel `plugins` (Liste). Beispiel (vgl. `CMS/marketplace/plugins/index.json`):

```json
{
  "plugins": [
    {
      "slug": "mein-plugin",
      "name": "Mein Plugin",
      "description": "Kurzbeschreibung",
      "version": "1.0.0",
      "author": "Firma",
      "requires_cms": "3.4.00",
      "requires_php": "8.4",
      "is_paid": false,
      "download_url": "https://365cms.de/marketplace/plugins/mein-plugin-1.0.0.zip",
      "sha256": "<64 Hex-Zeichen>",
      "update_url": "https://365cms.de/marketplace/plugins/mein-plugin/update.json"
    }
  ]
}
```

Die Download-URL muss auf einem erlaubten Host liegen. Details zum Paketformat: [../../plugins/PLUGIN-MARKETPLACE.md](../../plugins/PLUGIN-MARKETPLACE.md).

### Verwandte Dokumente

[PLUGINS.md](PLUGINS.md) · [UPDATES.md](UPDATES.md) · [../themes-design/MARKETPLACE.md](../themes-design/MARKETPLACE.md) · [../../workflow/MARKETPLACE-WORKFLOW.md](../../workflow/MARKETPLACE-WORKFLOW.md)
