# 365CMS – Projektdokumentation | Abschnitt: Admin – Updates

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/updates` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_updates`

## English (summary)

`/admin/updates` (`CMS/admin/updates.php` → `CMS/admin/modules/system/UpdatesModule.php` → `CMS\Services\UpdateService`, view `CMS/admin/views/system/updates.php`) checks and installs updates for the core, plugins and the active theme, and runs pending database migrations (`DatabaseUpdateRunner`). Packages must come from allow-listed hosts and carry a SHA-256 checksum; core updates preserve `backups/`, `cache/`, `config/`, `config.php`, `logs/` and `uploads/`. Actions: `check_updates`, `run_database_update`, `install_core`, `install_plugin`, `install_theme`.

## Deutsch

### Übersicht

| Bereich | Inhalt |
|---|---|
| Core | installierte Version (`CMS\Version::CURRENT`, derzeit `3.5.2`), verfügbare Version laut Update-Quelle, Release-Notizen, kritisch ja/nein |
| Datenbank | installierte und Ziel-Schema-Version (`SchemaManager::SCHEMA_VERSION = v23`), Downgrade-Erkennung |
| Plugins | installierte vs. verfügbare Versionen aus der Plugin-Registry |
| Theme | aktives Theme vs. Marktplatz-Version |
| Systemvoraussetzungen | PHP-Version, MySQL/MariaDB-Version, Erweiterungen (`pdo`, `pdo_mysql`, `mbstring`, `json`, `curl`, `gd`, `zip`), Schreibrechte (`uploads`, `cache`, `logs`, `backups`, `assets`), freier Speicherplatz |
| Historie | letzte Update-Vorgänge |

### Update-Quellen

| Typ | Einstellung | Standard |
|---|---|---|
| Core | `core_update_url` | `https://365cms.de/marketplace/core/365cms/update.json` |
| Plugins (Index) | `plugin_registry_url` | `https://365cms.de/marketplace/plugins/index.json` |
| Plugins (Basis) | `plugin_marketplace_base_url` | `https://365cms.de/marketplace/plugins` |
| Themes (Index) | `theme_registry_url` | `https://365cms.de/marketplace/themes/index.json` |
| Themes (Basis) | `theme_marketplace_url` | `https://365cms.de/marketplace/themes` |
| Marketplace-Übersicht | `marketplace_public_url` | `https://365cms.de/marketplace-public` |
| Public Einreichung | `marketplace_submit_url` | `https://365cms.de/marketplace-submit` |
| Fallback | GitHub-Repository | `PS-easyIT/365CMS.DE` über `api.github.com` |

Alle Endpunkte und ihre Defaults sind zentral in `CMS\Services\MarketplaceEndpoints` hinterlegt. Index-Feeds werden geladen, relative Manifest-, Paket- und Asset-Pfade werden gegen die jeweilige Basis-URL aufgelöst. Wird nur Basis oder nur Index angepasst, leitet das System das Gegenstück ab.

Erlaubte Hosts: `365cms.de`, `www.365cms.de`, `365network.de`, `www.365network.de`, `github.com`, `api.github.com`, `codeload.github.com`, `objects.githubusercontent.com`, `raw.githubusercontent.com`. Prüfergebnisse werden eine Stunde zwischengespeichert; `check_updates` erzwingt eine neue Prüfung.

**Format `update.json` (Core):**

```json
{
  "slug": "365cms-core",
  "type": "core",
  "version": "3.5.2",
  "min_php": "8.4.1",
  "released": "2026-10-17",
  "download_url": "https://365cms.de/marketplace/core/365cms/365CMS-3.5.2-update.zip",
  "changelog_url": "https://raw.githubusercontent.com/PS-easyIT/365CMS.DE/main/Changelog.md",
  "checksum_sha256": "<64 Hex-Zeichen>",
  "notes": "…",
  "critical": false
}
```

### Installation (`install_core`, `install_plugin`, `install_theme`)

1. **Preflight:** Systemvoraussetzungen, Schreibrechte, mindestens 1 GB freier Speicher (Warnung unter 2 GB).
2. **Download** nur von erlaubten Hosts.
3. **Integrität:** Ohne gültige SHA-256-Prüfsumme (64 Hex-Zeichen) wird abgebrochen (`updates.install.integrity_hash_missing`); abweichender Hash bricht ebenfalls ab.
4. **Entpacken** mit Grenzen: 5000 Einträge, 256 MB je Datei, 512 MB entpackt.
5. **Austausch:** Beim Core bleiben `backups/`, `cache/`, `config/`, `config.php`, `logs/` und `uploads/` unangetastet.
6. Protokoll in der Update-Historie und im Audit-Log. Der Bootstrap erkennt beim nächsten Aufruf geänderte Dateien und wärmt den OPcache für die 30 wichtigsten Dateien vor (`OpcacheWarmupService::maybeWarmAfterDeploy()`).

### Datenbank-Update (`run_database_update`)

`DatabaseUpdateRunner::run()` führt die idempotenten `CREATE`/`ALTER`-Migrationen von `SchemaManager`/`MigrationManager` erneut aus (es werden keine Daten gelöscht) und schreibt danach `installed_cms_version`, `installed_cms_schema_version` und `db_schema_version`. Ein **Downgrade** (Datenbank neuer als Code) wird nicht automatisch ausgeführt.

### Empfohlener Ablauf

1. Backup erstellen ([BACKUP.md](BACKUP.md)).
2. `check_updates`, Release-Notizen lesen ([../../../Changelog.md](../../../Changelog.md)).
3. Core aktualisieren, dann `run_database_update`, dann Plugins und Theme.
4. Sicherheits-Audit und Systemprüfung ausführen.

### Verwandte Dokumente

[BACKUP.md](BACKUP.md) · [../plugins/UPDATES.md](../plugins/UPDATES.md) · [../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md)
