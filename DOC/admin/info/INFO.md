# 365CMS – Projektdokumentation | Abschnitt: Admin – Diagnose-Übersicht (Info)

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/info` (Alt-Routen `/admin/system`, `/admin/system-info` leiten um) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_system_info`

## English (summary)

`/admin/info` is the first page of the *Diagnose* group. `CMS/admin/info.php` sets the section `info` and includes `CMS/admin/system-monitor-page.php`; data comes from `SystemInfoModule::getInfoData()` / `CMS\Services\SystemService` and is rendered by `CMS/admin/views/system/info.php`. It is read-only and shows CMS/PHP/server data, database status, directory permissions and sizes, content statistics and a security summary.

## Deutsch

### Angezeigte Informationen

| Block | Inhalt (Quelle `SystemService`) |
|---|---|
| CMS | Version (`CMS\Version::CURRENT`), Release-Datum, Schema-Version, aktives Theme, `SITE_URL` |
| PHP & Server | PHP-Version, Betriebssystem, Architektur, Hostname, Webserver, `memory_limit`, `max_execution_time`, `max_input_vars`, `upload_max_filesize`, `post_max_size`, `display_errors`, `error_reporting`, Zeitzone, Session-Pfad, Temp-Verzeichnis |
| Datenbank | Verbindung, MySQL/MariaDB-Version, Datenbankname, Größe, Anzahl CMS-Tabellen |
| Berechtigungen | Schreibrechte für `config/`, `uploads/`, `cache/`, `logs/`, `backups/`, `themes/`, `plugins/` |
| Verzeichnisgrößen | Belegung der wichtigsten Verzeichnisse |
| PHP-Erweiterungen (seit 4.0.0) | Vom Core genutzte Erweiterungen mit Status (vorhanden/fehlt), Bedarf (Pflicht, empfohlen, optional) und der Funktion, die sie braucht – z. B. `zip` für Updates, Backups und Import/Export, `pdo_sqlite` für die Volltextsuche, `gd`/`imagick` für Vorschaubilder (`CMS\Services\EnvironmentInfoService`) |
| Datenbank (seit 4.0.0) | Produkt (MySQL oder MariaDB), Version, Server-Kennung und Client-Bibliothek |
| OPcache (seit 4.0.0) | Status, Version, belegter Speicher, Trefferquote, JIT |
| Statistik | Anzahl Benutzer, Seiten, Beiträge, Kategorien, Medien, Plugins, Sessions, Revisionen, Login-Versuche, gesperrte IPs u. a. |
| Sicherheit | Kurzstatus (HTTPS, Debug, Installer, Firewall, Anti-Spam) |

### Typische Verwendung

- Support-Anfragen: Versionen und Umgebung ablesen (oder Diagnosebericht unter `/admin/diagnose` exportieren).
- Nach einem Umzug: PHP-Limits, Schreibrechte und `SITE_URL` prüfen.
- Vor Updates: freier Speicher, PHP ≥ 8.4, benötigte Erweiterungen.

### Verwandte Dokumente

[README.md](README.md) · [../diagnose/DIAGNOSE.md](../diagnose/DIAGNOSE.md) · [../system-settings/UPDATES.md](../system-settings/UPDATES.md)
