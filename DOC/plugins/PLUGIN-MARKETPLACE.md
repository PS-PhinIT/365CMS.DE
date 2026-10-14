# 365CMS – Projektdokumentation | Abschnitt: Plugins – Marketplace
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Kurzbeschreibung:** User and technical reference for the 365CMS plugin marketplace, registry sources, catalog validation, automatic installation, integrity checks, and manual-install fallbacks.
> **Quellen:** `CMS/admin/plugin-marketplace.php`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/views/plugins/marketplace.php`, `CMS/core/Services/UpdateService.php`, `CMS/marketplace/plugins/index.json`

## English

### User-friendly guide

The marketplace is the protected admin page at `/admin/plugin-marketplace`. It shows catalog entries, installed state, compatibility, package information, categories, prices, and whether automatic installation is available.

#### Workflow

1. Open `/admin/plugin-marketplace`.
2. Search by plugin name or filter by category and status.
3. Review the CMS/PHP requirements, package size, download host, checksum state, and price.
4. Use **Install** only when the card reports automatic installation support.
5. If the card reports manual installation, follow the purchase or download information and place the verified plugin package in `CMS/plugins/<slug>/`.
6. Open `/admin/plugins` and activate the installed plugin after reviewing its header and permissions.

An installed plugin is not automatically active. Paid, incompatible, missing-checksum, unsupported-host, unsupported-extension, or oversized packages remain manual-install cases. Never bypass a missing SHA-256 value by installing an unverified archive.

#### Catalog status

The page displays available, installed, installable, and manual-only counts. A source warning means that the registry could not be loaded or validated; it does not mean that arbitrary remote packages are safe to install.

### Technical reference

#### Route and authorization

The entry point is `CMS/admin/plugin-marketplace.php`, routed at `/admin/plugin-marketplace`. Read and install access require an authenticated administrator with `manage_settings`. The page shell uses the CSRF action `admin_plugin_mp`. The only accepted POST action is `install`, and the normalized plugin slug is limited to 120 characters.

The installed-plugin page is `/admin/plugins`. Its accepted actions are `activate`, `deactivate`, and `delete`, also protected by `manage_settings` and the `admin_plugins` CSRF action. Protected plugins, including `cms-importer`, cannot be deleted through the normal UI.

#### Registry sources and cache

`PluginMarketplaceModule` reads the configured `plugin_registry_url` (editable under **Settings → Marketplace**, `CMS\Services\MarketplaceEndpoints`); when it is empty, the default is `https://365cms.de/marketplace/plugins/index.json`. The registry is JSON shaped as `{"plugins":[…]}` (a bare array is also accepted) and is limited to 1 MiB.

Load order in `loadRegistry()`:

1. **Fresh cache** – settings options `plugin_marketplace_registry_cache` / `plugin_marketplace_registry_cache_meta`, valid for 900 seconds (source type `cache`, status `info`).
2. **Remote registry** – fetched via `HttpClient` with the host allowlist; a non-empty result is persisted to the cache (source type `remote`).
3. **Stale cache** – if the remote fetch fails, the last cache is used regardless of age (status `warning`, including the remote error).
4. **Local index** – `index.json` in the parent directory of `PLUGIN_PATH`, i.e. `CMS/index.json` in a default installation (symlinked roots are refused). This file is **not** shipped; the repository file `CMS/marketplace/plugins/index.json` is the *published* registry served at `365cms.de/marketplace/plugins/index.json` and is not read as the local fallback.
5. Otherwise the source status is `none` with a warning and the catalog is empty.

Per-entry manifests (`manifest` key) are loaded relative to the registry base, limited to 512 KiB and filtered through `MANIFEST_ALLOWED_KEYS`.

Only these marketplace hosts are allowed by the current implementation:

`365cms.de`, `www.365cms.de`, `365network.de`, `www.365network.de`, `api.github.com`, `codeload.github.com`, `github.com`, `objects.githubusercontent.com`, and `raw.githubusercontent.com`.

Remote registry and package URLs must pass the allowed-host and safe-external-URL checks. HTTP is not accepted for cloud downloads.

#### Catalog and manifest validation

Catalog values are normalized and bounded. A manifest is limited to 512 KiB and its keys are allowlisted. The catalog can describe slug, name, description, version, author, category, update/download/package/archive URLs, purchase data, pricing, SHA-256, package size, documentation, screenshots, CMS/PHP requirements, release notes, and submission metadata.

Automatic installation requires all of the following:

- a catalog entry with a valid normalized slug;
- a package download URL;
- a valid 64-character SHA-256 checksum;
- an allowed marketplace host;
- the `.zip` archive extension;
- a package size of at most 100 MiB;
- compatible `requires_cms`/`min_cms_version` and `requires_php`/`min_php` values;
- a package archive with at most 2,000 entries and at most 50 MiB uncompressed;
- a package root containing a safe plugin slug and a matching bootstrap file.

The module refuses an already installed slug, a slug absent from the current catalog, an incompatible package, an invalid archive, and any archive with unsafe paths or excessive extraction limits. Paid packages or entries without sufficient installation metadata are presented as manual-only.

#### Installation path

The POST handler normalizes and validates the action and slug, confirms that the slug exists in the current catalog, and delegates to `PluginMarketplaceModule::installPlugin()`. Installation uses the update service's staging and integrity flow: download, verify SHA-256, inspect and extract into staging, validate the package root, swap into the allowed `PLUGIN_PATH` target, clean temporary files, and report an explicit result. The plugin is not activated implicitly.

Failures return a structured result containing a stable error code, route context, details, and a report payload. The admin shell renders the message without exposing credentials or unsafe remote content.

#### Browser asset

`CMS/assets/js/admin-plugin-marketplace.js` provides local search, category/status filtering, empty-state handling, and submit-button locking during installation. It does not authorize requests or verify packages; all security decisions remain server-side.

#### Developer checklist

Keep registry and manifest fields within the current allowlists, publish a matching SHA-256 value and package size, use an HTTPS download host from the allowed list, include a single safe plugin root, and provide current CMS/PHP requirements. Test invalid slugs, stale catalog entries, missing checksums, unsupported hosts, non-ZIP files, archive traversal, oversized archives, incompatible requirements, duplicate installation, and failed downloads.

## Deutsch

### Anwenderleitfaden

Der Marketplace ist die geschützte Adminseite unter `/admin/plugin-marketplace`. Sie zeigt Katalogeinträge, Installationsstatus, Kompatibilität, Paketdaten, Kategorien, Preise und ob eine automatische Installation möglich ist.

#### Ablauf

1. `/admin/plugin-marketplace` öffnen.
2. Nach Pluginname suchen oder Kategorie und Status filtern.
3. CMS-/PHP-Anforderungen, Paketgröße, Download-Host, Prüfsumme und Preis prüfen.
4. **Installieren** nur verwenden, wenn die Karte die automatische Installation unterstützt.
5. Bei manueller Installation Kauf- oder Downloadinformationen verwenden und das geprüfte Paket unter `CMS/plugins/<slug>/` ablegen.
6. `/admin/plugins` öffnen und das installierte Plugin nach Prüfung von Header und Berechtigungen aktivieren.

Ein installiertes Plugin ist nicht automatisch aktiv. Kostenpflichtige, inkompatible, ohne Prüfsumme, mit nicht erlaubtem Host oder Dateityp sowie zu große Pakete bleiben manuelle Installationsfälle. Eine fehlende SHA-256-Prüfsumme wird niemals durch die Installation eines ungeprüften Archivs umgangen.

#### Katalogstatus

Die Seite zeigt verfügbare, installierte, installierbare und nur manuell installierbare Plugins. Eine Quellenwarnung bedeutet, dass die Registry nicht geladen oder validiert werden konnte; beliebige Remote-Pakete werden dadurch nicht sicher.

### Technische Referenz

#### Route und Berechtigungen

Der Einstieg liegt in `CMS/admin/plugin-marketplace.php` und ist unter `/admin/plugin-marketplace` erreichbar. Lesen und Installieren verlangen einen angemeldeten Administrator mit `manage_settings`. Die Shell verwendet die CSRF-Action `admin_plugin_mp`. Als POST-Action ist ausschließlich `install` erlaubt; der normalisierte Plugin-Slug ist auf 120 Zeichen begrenzt.

Die Seite für installierte Plugins liegt unter `/admin/plugins`. Ihre Actions sind `activate`, `deactivate` und `delete`; auch sie verlangen `manage_settings` und die CSRF-Action `admin_plugins`. Geschützte Plugins, darunter `cms-importer`, können nicht über die normale Oberfläche gelöscht werden.

#### Registry-Quellen und Cache

`PluginMarketplaceModule` liest `plugin_registry_url` (pflegbar unter **Einstellungen → Marketplace**, Defaults in `CMS\Services\MarketplaceEndpoints`); bei leerem Wert gilt `https://365cms.de/marketplace/plugins/index.json`. Die Registry ist JSON im Format `{"plugins":[…]}` (ein reines Array wird ebenfalls akzeptiert) und auf 1 MiB begrenzt.

Ladereihenfolge in `loadRegistry()`:

1. **Frischer Cache** – Settings-Optionen `plugin_marketplace_registry_cache` / `plugin_marketplace_registry_cache_meta`, gültig 900 Sekunden (Quelle `cache`, Status `info`).
2. **Remote-Registry** – Abruf über `HttpClient` mit Host-Allowlist; ein nicht leeres Ergebnis wird in den Cache geschrieben (Quelle `remote`).
3. **Veralteter Cache** – schlägt der Remote-Abruf fehl, wird der letzte Cache unabhängig vom Alter genutzt (Status `warning`, inklusive Remote-Fehler).
4. **Lokaler Index** – `index.json` im Elternverzeichnis von `PLUGIN_PATH`, also in einer Standardinstallation `CMS/index.json` (Symlink-Wurzeln werden abgelehnt). Diese Datei wird **nicht** mitgeliefert; `CMS/marketplace/plugins/index.json` im Repository ist die *veröffentlichte* Registry, die unter `365cms.de/marketplace/plugins/index.json` ausgeliefert wird, und wird nicht als lokaler Fallback gelesen.
5. Sonst ist die Quelle `none` mit Warnung, der Katalog bleibt leer.

Manifeste pro Eintrag (Schlüssel `manifest`) werden relativ zur Registry-Basis geladen, auf 512 KiB begrenzt und über `MANIFEST_ALLOWED_KEYS` gefiltert.

Die aktuelle Implementierung erlaubt nur diese Marketplace-Hosts:

`365cms.de`, `www.365cms.de`, `365network.de`, `www.365network.de`, `api.github.com`, `codeload.github.com`, `github.com`, `objects.githubusercontent.com` und `raw.githubusercontent.com`.

Remote-Registry- und Paket-URLs müssen die Host- und Safe-External-URL-Prüfung bestehen. HTTP wird für Cloud-Downloads nicht akzeptiert.

#### Katalog- und Manifestprüfung

Katalogwerte werden normalisiert und begrenzt. Ein Manifest darf höchstens 512 KiB groß sein und nur Allowlist-Schlüssel enthalten. Der Katalog kann Slug, Name, Beschreibung, Version, Autor, Kategorie, Update-/Download-/Paket-/Archiv-URLs, Kaufdaten, Preise, SHA-256, Paketgröße, Dokumentation, Screenshots, CMS-/PHP-Anforderungen, Releasehinweise und Einreichungsdaten beschreiben.

Automatische Installation verlangt alle folgenden Bedingungen:

- ein Katalogeintrag mit gültigem normalisiertem Slug;
- eine Paket-Download-URL;
- eine gültige SHA-256-Prüfsumme mit 64 Hex-Zeichen;
- ein erlaubter Marketplace-Host;
- die Archivendung `.zip`;
- maximal 100 MiB Paketgröße;
- kompatible Werte für `requires_cms`/`min_cms_version` und `requires_php`/`min_php`;
- höchstens 2.000 Archiveinträge und 50 MiB entpackte Größe;
- eine Paketwurzel mit sicherem Plugin-Slug und passender Bootstrap-Datei.

Das Modul lehnt bereits installierte Slugs, nicht im aktuellen Katalog vorhandene Slugs, inkompatible Pakete, ungültige Archive sowie Archive mit unsicheren Pfaden oder überschrittenen Entpackgrenzen ab. Kostenpflichtige Einträge oder unvollständige Installationsmetadaten werden als manuell zu installieren angezeigt.

#### Installationsablauf

Der POST-Handler normalisiert und prüft Action und Slug, bestätigt den Slug im aktuellen Katalog und delegiert an `PluginMarketplaceModule::installPlugin()`. Die Installation verwendet den Staging- und Integritätsablauf des Update-Service: Download, SHA-256-Prüfung, Prüfung und Entpacken im Staging, Validierung der Paketwurzel, Austausch in das erlaubte `PLUGIN_PATH`-Ziel, Bereinigung temporärer Dateien und explizites Ergebnis. Das Plugin wird nicht automatisch aktiviert.

Fehler liefern ein strukturiertes Ergebnis mit stabilem Fehlercode, Routenkontext, Details und Report-Payload. Die Admin-Shell zeigt die Meldung, ohne Zugangsdaten oder unsichere Remote-Inhalte preiszugeben.

#### Browser-Asset

`CMS/assets/js/admin-plugin-marketplace.js` stellt lokale Suche, Kategorie-/Statusfilter, Leerzustand und Sperrung des Installationsbuttons während des Sendens bereit. Das Script autorisiert keine Requests und prüft keine Pakete; alle Sicherheitsentscheidungen bleiben serverseitig.

#### Entwickler-Checkliste

Registry- und Manifestfelder bleiben innerhalb der aktuellen Allowlists, SHA-256 und Paketgröße werden passend veröffentlicht, Download-Hosts verwenden HTTPS und die Allowlist, die Paketwurzel enthält genau ein sicheres Plugin und aktuelle CMS-/PHP-Anforderungen. Zu testen sind ungültige Slugs, veraltete Katalogeinträge, fehlende Prüfsummen, nicht erlaubte Hosts, Nicht-ZIP-Dateien, Archive-Traversal, übergroße Archive, inkompatible Anforderungen, doppelte Installation und fehlgeschlagene Downloads.

## Ergänzung (Stand 2026-10-02)

### Registry-Eintrag – Beispiel

```json
{
  "plugins": [
    {
      "slug": "hello-world",
      "name": "Hello World",
      "description": "Beispielplugin",
      "version": "1.0.0",
      "author": "Example GmbH",
      "category": "Tools",
      "requires_cms": "3.4.00",
      "requires_php": "8.4",
      "download_url": "https://github.com/example/hello-world/releases/download/v1.0.0/hello-world.zip",
      "sha256": "<64 Hex-Zeichen>",
      "package_size": 48213,
      "is_paid": false,
      "docs_url": "https://365cms.de/docs/hello-world"
    }
  ]
}
```

- `sha256` oder `checksum_sha256` muss exakt 64 Hex-Zeichen enthalten, sonst bleibt der Eintrag „manuell“.
- `is_paid: true` + `purchase_url` → Hinweis „Kostenpflichtiges Plugin – bitte zuerst über den Marketplace erwerben oder anfragen.“
- Katalog-Strings werden auf 500 Zeichen gekürzt (`MAX_CATALOG_STRING_LENGTH`).

### Fehlercodes von `installPlugin()`

| Code | Bedeutung |
|---|---|
| `plugin_marketplace_invalid_slug` | Slug nach Normalisierung leer/ungültig |
| `plugin_marketplace_missing_catalog_entry` | Slug nicht im aktuellen Katalog |
| `plugin_marketplace_already_installed` / `plugin_marketplace_target_exists` | Plugin-Ordner existiert bereits |
| `plugin_marketplace_manual_install_required` | Prüfsumme, Kompatibilität, Preis oder Download-URL verhindern Auto-Install |
| `plugin_marketplace_package_too_large` | `package_size` > 100 MiB |
| `plugin_marketplace_disallowed_download_host` | Host nicht in `ALLOWED_MARKETPLACE_HOSTS` |
| `plugin_marketplace_disallowed_archive_extension` | keine `.zip`-Endung |
| `plugin_marketplace_plugins_dir_missing` / `plugin_marketplace_invalid_target_dir` | Zielverzeichnis fehlt bzw. liegt außerhalb von `PLUGIN_PATH` |
| `plugin_marketplace_install_failed` | `UpdateService::downloadAndInstallUpdate()` meldet Fehler (Download, Hash, Archiv) |

Bei Erfolg lautet die Meldung „Plugin "<slug>" installiert. Aktiviere es unter Plugin-Verwaltung.“ inklusive Zielpfad und „SHA-256 verifiziert: ja/nein“.

### Hinweise

- Der mitgelieferte Katalog `CMS/marketplace/plugins/index.json` listet derzeit nur `cms-importer` mit veralteten Anforderungen (`requires_cms` 0.26.0, `requires_php` 8.1) und `install_supported: false` – siehe [core/STATUS.md](../core/STATUS.md).
- Plugin-Updates bereits installierter Plugins laufen über `/admin/updates` (`UpdateService`, ebenfalls über `plugin_registry_url`), nicht über den Marketplace.

## Verwandte Dokumente

- [GUIDE.md](GUIDE.md) · [PLUGIN-DEVELOPMENT.md](PLUGIN-DEVELOPMENT.md)
- [admin/plugins/MARKETPLACE.md](../admin/plugins/MARKETPLACE.md) · [admin/system-settings/UPDATES.md](../admin/system-settings/UPDATES.md)
- [workflow/MARKETPLACE-WORKFLOW.md](../workflow/MARKETPLACE-WORKFLOW.md)
