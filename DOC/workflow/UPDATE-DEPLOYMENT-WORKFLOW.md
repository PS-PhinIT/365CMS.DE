# 365CMS – Projektdokumentation | Abschnitt: Workflow – Update und Deployment
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Quellen:** `CMS/admin/updates.php`, `CMS/admin/modules/system/UpdatesModule.php`, `CMS/core/Services/UpdateService.php`, `CMS/core/Services/BackupService.php`, `CMS/core/Services/OpcacheWarmupService.php`, `CMS/core/SchemaManager.php`, `CMS/install.php`, `CMS/install/`, `CMS/update.json`

## English (summary)

Recommended order for every release: **backup → check → core → database → plugins/theme → verify**. The admin page `/admin/updates` reads the core source (`core_update_url`, default `https://365cms.de/marketplace/core/365cms/update.json`, GitHub fallback), plugin registry and theme marketplace (results cached one hour). Installation is refused without a valid SHA-256 checksum; archives are extracted with limits (5,000 entries, 256 MB per file, 512 MB total). Core swaps preserve `backups/`, `cache/`, `config/`, `config.php`, `logs/`, `uploads/`. Afterwards run the database update (idempotent `CREATE`/`ALTER` migrations up to schema `v23`). For manual deployments (Git/SFTP) the same rules apply: never overwrite `config/` and `uploads/`, then open `/admin/updates` and run the database update.

## Deutsch

### Voraussetzungen

- PHP **8.4+** (`CMS_MIN_PHP_VERSION`), Erweiterungen `pdo`, `pdo_mysql`, `mbstring`, `json`, `curl`, `gd`, `zip`.
- Schreibrechte auf `uploads/`, `cache/`, `logs/`, `backups/`, `assets/` und – für Core-Updates – den CMS-Ordner.
- Mindestens 1 GB freier Speicher (Warnung unter 2 GB).
- Aktuelles Backup **inklusive** separat gesicherter `CMS/config/app.php` (nicht Bestandteil des CMS-Backups).

### A) Update über das Admin-Panel

1. **Backup:** `/admin/backups` → „Vollständiges Backup“ (`create_full`), anschließend `validate`.
2. **Prüfen:** `/admin/updates` → `check_updates` (erzwingt eine neue Abfrage statt des 1-Stunden-Caches). Release-Notizen und [Changelog](../../Changelog.md) lesen; `critical: true` beachten.
3. **Core installieren** (`install_core`): Preflight → Download (nur erlaubte Hosts) → SHA-256-Prüfung → Entpacken in Staging → Austausch. Geschützt bleiben `backups/`, `cache/`, `config/`, `config.php`, `logs/`, `uploads/`.
4. **Datenbank aktualisieren** (`run_database_update`): `DatabaseUpdateRunner` führt die Migrationen erneut aus und schreibt `installed_cms_version`, `installed_cms_schema_version`, `db_schema_version`. Ein Downgrade (Datenbank neuer als Code) wird nicht automatisch durchgeführt.
5. **Plugins/Theme** (`install_plugin`, `install_theme`) in dieser Reihenfolge aktualisieren.
6. **Nacharbeiten:** Cache leeren (`/admin/performance-cache`), Sicherheits-Audit (`/admin/security-audit`), Systemprüfung (`/admin/info`, `/admin/diagnose`), Stichprobe im Frontend. Der Bootstrap erkennt geänderte Dateien und wärmt den OPcache vor (`OpcacheWarmupService::maybeWarmAfterDeploy()`).

### B) Manuelles Deployment (Git, SFTP, CI)

1. Wartungsfenster ankündigen; Backup wie oben.
2. Neue Dateien nach `CMS/` übertragen, **ohne** `config/`, `config.php`, `uploads/`, `cache/`, `logs/`, `backups/` zu überschreiben. Bei Git-basierten Deployments diese Pfade außerhalb der Arbeitskopie halten oder ignorieren.
3. Datei-Rechte prüfen (Webserver-Benutzer muss `uploads/`, `cache/`, `logs/`, `backups/` schreiben können).
4. OPcache leeren (PHP-FPM-Reload) oder auf die automatische Erkennung im Bootstrap vertrauen.
5. Als Administrator `/admin/updates` öffnen → `run_database_update`.
6. Nacharbeiten wie in A) Schritt 6.

Beispiel (rsync):

```bash
rsync -av --delete \
  --exclude 'config/' --exclude 'config.php' \
  --exclude 'uploads/' --exclude 'cache/' --exclude 'logs/' --exclude 'backups/' \
  ./CMS/ user@server:/var/www/365cms/
```

> **Achtung `config/app.php`:** `CMS/index.php` lädt `config/app.php` vor `config.php`. Wird `config/app.php` durch ein Deployment ersetzt oder vom Installer neu erzeugt, gehen eigene Konstanten verloren. Eigene Werte daher dokumentieren und nach jedem Installer-Lauf prüfen (siehe [core/STATUS.md](../core/STATUS.md)).

### C) Neuinstallation

`/install.php` führt durch PHP-/Erweiterungsprüfung, Datenbankzugang, Admin-Konto und schreibt `config/app.php` (siehe [INSTALLATION.md](../INSTALLATION.md)). Ist bereits eine Konfiguration vorhanden, legt der Installer eine Lock-Datei an und blockiert weitere Aufrufe mit HTTP 403.

### D) Rückfall (Rollback)

1. Dateien aus dem Backup bzw. Vorversion zurückspielen (ohne `config/`, `uploads/`).
2. Datenbank aus dem `database_*.sql[.gz]`-Dump über `/admin/backups` → `restore` wiederherstellen (vorher `validate`).
3. Cache leeren, Anmeldung und Kernseiten prüfen.

### E) Cron nach dem Update

Prüfen, ob der Cron-Aufruf weiterläuft (`php CMS/cron.php --task=all` bzw. die konfigurierte Variante). Unterstützte Tasks: `all`, `mail-queue`, `hourly`, `daily`, `feeds` sowie generische `cms_cron_*`-Hooks. Details: [admin/system-settings/SYSTEM.md](../admin/system-settings/SYSTEM.md).

### Checkliste

- [ ] Backup erstellt und validiert, `config/app.php` separat gesichert
- [ ] Release-Notizen gelesen, PHP-Version passt
- [ ] Core aktualisiert, Datenbank-Update ausgeführt, Schema = `v23`
- [ ] Plugins/Theme aktualisiert
- [ ] Cache geleert, Audit ohne kritische Befunde
- [ ] Login, Admin, Member-Bereich, Kontaktformular, Mailversand getestet

## Verwandte Dokumente

- [admin/system-settings/UPDATES.md](../admin/system-settings/UPDATES.md) · [admin/system-settings/BACKUP.md](../admin/system-settings/BACKUP.md) · [admin/plugins/UPDATES.md](../admin/plugins/UPDATES.md)
- [INSTALLATION.md](../INSTALLATION.md) · [core/DATABASE-SCHEMA.md](../core/DATABASE-SCHEMA.md)
