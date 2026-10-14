# 365CMS – Projektdokumentation | Abschnitt: Workflow – Marketplace
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Quellen:** `CMS/admin/plugin-marketplace.php`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/theme-marketplace.php`, `CMS/core/Services/UpdateService.php`, `CMS/marketplace/`

## English (summary)

Two roles meet in the marketplace: **operators** install packages from `/admin/plugin-marketplace` (and themes from the theme marketplace), **publishers** maintain the registry (`{"plugins":[…]}`) and ZIP packages. Automatic installation requires a catalog entry with an HTTPS download URL on an allowed host, a 64-hex SHA-256, a `.zip` archive ≤ 100 MiB and compatible CMS/PHP requirements. Installed plugins are never activated automatically. Everything else is a manual installation into `CMS/plugins/<slug>/`.

## Deutsch

### Betreiber: Plugin installieren

1. **Backup** erstellen (`/admin/backups`).
2. `/admin/plugin-marketplace` öffnen (Admin + `manage_settings`). Die Statusleiste zeigt die Quelle: `remote`, `cache`, veralteter Cache (Warnung), `local` oder `none`.
3. Karte prüfen: Version, Kategorie, CMS-/PHP-Anforderung, Paketgröße, Host, Prüfsumme, Preis.
4. **Installieren** (nur wenn „automatisch installierbar“). Ablauf: Slug normalisieren → Katalogeintrag → Bedingungen → `UpdateService::downloadAndInstallUpdate()` (Download, SHA-256, Entpacken in Staging, Austausch nach `CMS/plugins/<slug>/`).
5. Ergebnis lesen: „Plugin "<slug>" installiert. Aktiviere es unter Plugin-Verwaltung.“ inkl. „SHA-256 verifiziert: ja“.
6. `/admin/plugins` → **Aktivieren**. Dabei laufen Sicherheits-Scan und `<slug>_activate()`.
7. Funktion testen, Audit-Log (`/admin/cms-logs`) prüfen.

**Manuelle Installation** (kostenpflichtig, keine Prüfsumme, fremder Host …): Paket vom Anbieter beziehen, Prüfsumme selbst prüfen (`sha256sum paket.zip`), nach `CMS/plugins/<slug>/` entpacken (Ordnername = Slug = Bootstrap-Dateiname), dann wie Schritt 6.

### Betreiber: Theme installieren

Themes werden über den Theme-Marketplace bzw. `/admin/updates` (`install_theme`) mit derselben Integritätslogik installiert und unter *Themes* aktiviert. Details: [admin/themes-design/MARKETPLACE.md](../admin/themes-design/MARKETPLACE.md).

### Betreiber: Registry-Quelle ändern

*Einstellungen → Marketplace & Updates*: `marketplace_public_url`, `plugin_registry_url`, `theme_registry_url`, `plugin_marketplace_base_url`, `theme_marketplace_url`, `marketplace_submit_url`, `core_update_url` (Defaults in `CMS\Services\MarketplaceEndpoints`). Nur HTTPS-Adressen auf erlaubten Hosts (`365cms.de`, `www.365cms.de`, `365network.de`, `www.365network.de`, `github.com`, `api.github.com`, `codeload.github.com`, `objects.githubusercontent.com`, `raw.githubusercontent.com`) funktionieren. Der Cache gilt 15 Minuten.

### Anbieter: Plugin veröffentlichen

1. Plugin nach [plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md) bauen; Header mit `Version`, `Requires CMS`, `Requires PHP`.
2. ZIP erzeugen, dessen Wurzel genau den Ordner `<slug>/` mit `<slug>.php` enthält:

   ```bash
   cd build && zip -r hello-world-1.0.0.zip hello-world/
   sha256sum hello-world-1.0.0.zip
   stat -c %s hello-world-1.0.0.zip
   ```

3. ZIP auf einem erlaubten Host veröffentlichen (z. B. GitHub-Release).
4. Registry-Eintrag ergänzen (`slug`, `name`, `version`, `requires_cms`, `requires_php`, `download_url`, `sha256`, `package_size`, optional `docs_url`, `changelog_url`, `is_paid`, `purchase_url`) – Beispiel in [plugins/PLUGIN-MARKETPLACE.md](../plugins/PLUGIN-MARKETPLACE.md).
5. Grenzen einhalten: ZIP ≤ 100 MiB, ≤ 2.000 Einträge, ≤ 50 MiB entpackt, keine Pfade mit `..` oder absoluten Pfaden.
6. Testinstallation auf einer Staging-Instanz; danach Update-Pfad testen (`/admin/updates` vergleicht installierte mit Registry-Version).

### Fehlerbilder

| Meldung | Ursache |
|---|---|
| „Für die automatische Installation fehlt eine gültige SHA-256-Prüfsumme …“ | `sha256`/`checksum_sha256` fehlt oder ist nicht 64 Hex-Zeichen |
| „Download-URL liegt außerhalb der erlaubten Marketplace-Hosts.“ | Host nicht in der Allowlist oder kein HTTPS |
| „Paket überschreitet das Auto-Install-Limit …“ | `package_size` > 100 MiB |
| „Plugin ist bereits installiert.“ | Ordner `CMS/plugins/<slug>/` existiert |
| Quelle „none“ | Registry nicht erreichbar, kein Cache, kein lokales `CMS/index.json` |

## Verwandte Dokumente

- [plugins/PLUGIN-MARKETPLACE.md](../plugins/PLUGIN-MARKETPLACE.md) · [admin/plugins/MARKETPLACE.md](../admin/plugins/MARKETPLACE.md) · [admin/plugins/PLUGINS.md](../admin/plugins/PLUGINS.md)
- [UPDATE-DEPLOYMENT-WORKFLOW.md](UPDATE-DEPLOYMENT-WORKFLOW.md)
