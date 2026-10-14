# 365CMS – Projektdokumentation | Abschnitt: Admin – Sicherheits-Audit

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/security-audit` | **Capability:** `manage_settings` | **Core-Modul:** `security`

## English (summary)

`/admin/security-audit` (`CMS/admin/security-audit.php` → `CMS/admin/modules/security/SecurityAuditModule.php` → `CMS/admin/views/security/audit.php`) runs an on-demand configuration check of the installation (`run_audit`) and lists the latest 50 audit-log entries. `clear_log` deletes security audit entries older than 30 days. Each check reports `ok`, `warning` or `critical`.

## Deutsch

### Prüfpunkte (`run_audit`)

| Prüfpunkt | Was geprüft wird |
|---|---|
| HTTPS aktiv | `SITE_URL` bzw. aktuelle Anfrage über HTTPS |
| PHP-Version | Seit 3.5.0 `ok` ab `CMS_MIN_PHP_VERSION` (8.4), `warning` bei 8.2–8.3, sonst `critical`. |
| `install.php` entfernt | Installer nach der Einrichtung nicht mehr erreichbar |
| Debug-Modus | `CMS_DEBUG` in Produktion aus |
| Uploads-Schutz (.htaccess) | `CMS/uploads/.htaccess` vorhanden (siehe [../media/MEDIA.md](../media/MEDIA.md)) |
| Passwort-Policy | Mindestlänge und Zeichenklassen aktiv |
| CSRF-Token-System | Token-Erzeugung und -Prüfung funktionsfähig |
| Content-Security-Policy | CSP-Header gesetzt |
| Trusted Types | CSP-Direktive für Trusted Types |
| Strict-Transport-Security | HSTS gemäß `CMS_HSTS_MODE` / `CMS_HSTS_MAX_AGE` |
| `.htaccess`-Sicherheits-Fallback | Schutzregeln im Webroot (max. 128 KB gelesen) |
| Letztes Backup | Alter der jüngsten Sicherung |
| Admin-Passwort-Hashes | Administratoren mit aktuellem Hash-Verfahren |
| Firewall Runtime aktiv | `firewall_enabled` |
| AntiSpam Runtime aktiv | `antispam_enabled`, Formular-Anbindung aktiver Kontakt-Plugins |
| Unerwartete Runtime-Fremdassets | Keine unbekannten externen Skripte/Styles |
| Editor.js Alt-Embeds gehärtet | Alte Embed-Blöcke ohne unsichere iframes |
| Dateien vorhanden / Berechtigungen | `config.php`, `config/app.php` und weitere kritische Dateien mit restriktiven Rechten |

Das Ergebnis zeigt Zähler (bestanden / Warnung / kritisch) und je Prüfpunkt einen Hinweis zur Behebung. Der Lauf selbst wird im Audit-Log festgehalten.

### Audit-Log

- Anzeige der letzten **50** Einträge aus dem Sicherheits-Audit-Log (`cms_audit_log`, Kategorie Sicherheit).
- `clear_log` löscht Einträge älter als **30 Tage**.
- Vollständige Auswertung mit Filtern: `/admin/logs/security-audit`.

### Empfehlungen

1. Audit nach jeder Installation, jedem Update und jeder Server-Änderung ausführen.
2. Kritische Punkte sofort beheben (z. B. `install.php` löschen, Debug aus, HTTPS erzwingen).
3. Warnungen bewerten und dokumentieren, falls bewusst akzeptiert.

### Verwandte Dokumente

[FIREWALL.md](FIREWALL.md) · [ANTISPAM.md](ANTISPAM.md) · [../../core/SECURITY.md](../../core/SECURITY.md) · [../PRUEF-CHECKLISTE.md](../PRUEF-CHECKLISTE.md)
