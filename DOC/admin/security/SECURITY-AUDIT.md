# 365CMS – Projektdokumentation | Abschnitt: Admin – Sicherheits-Audit

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/security-audit` | **Capability:** `manage_settings` | **Core-Modul:** `security`

## English (summary)

`/admin/security-audit` (`CMS/admin/security-audit.php` → `CMS/admin/modules/security/SecurityAuditModule.php` → `CMS/admin/views/security/audit.php`) runs an on-demand configuration check of the installation (`run_audit`) and lists the latest 50 audit-log entries. `clear_log` deletes security audit entries older than 30 days. Each check reports `ok`, `warning` or `critical`. Since 4.0.0 the backup check recognises the backup directories written by *System → Backup & Restore* (before, it only looked for `backups/*.sql*` and reported existing backups as missing), `install.php` counts as safe while `config/install.lock` locks it, and file permissions are judged by the group/world write bits. Since 4.0.0 the check *Backup-Speicherort & Verschlüsselung* warns when backups lie unencrypted inside the web root (`CMS_BACKUP_PATH` unset), when encryption runs without a real key, and when old backups are still unencrypted or left behind in `CMS/backups/`.

## Deutsch

### Prüfpunkte (`run_audit`)

| Prüfpunkt | Was geprüft wird |
|---|---|
| HTTPS aktiv | Aktuelle Anfrage über HTTPS (`HTTPS`, Port 443 oder die Proxy-Header `X-Forwarded-Proto`, `X-Forwarded-SSL`, `Front-End-Https`). Dashboard und `StatusService` verwenden seit 4.0.0 dieselbe Erkennung |
| PHP-Version | Seit 3.4.12 `ok` ab `CMS_MIN_PHP_VERSION` (8.4), `warning` bei 8.2–8.3, sonst `critical`. |
| `install.php` entfernt / gesperrt | OK, wenn der Installer gelöscht ist oder `config/install.lock` ihn sperrt (nur angemeldete Admins); Warnung, wenn `install.php` ohne Sperrdatei vorhanden ist. Updates liefern `install.php` wieder mit aus |
| Debug-Modus | `CMS_DEBUG` in Produktion aus |
| Uploads-Schutz (.htaccess) | `CMS/uploads/.htaccess` vorhanden (siehe [../media/MEDIA.md](../media/MEDIA.md)) |
| Passwort-Policy | Mindestlänge und Zeichenklassen aktiv |
| CSRF-Token-System | Token-Erzeugung und -Prüfung funktionsfähig |
| Content-Security-Policy | CSP-Header gesetzt |
| Trusted Types | CSP-Direktive für Trusted Types |
| Strict-Transport-Security | HSTS gemäß `CMS_HSTS_MODE` / `CMS_HSTS_MAX_AGE` |
| `.htaccess`-Sicherheits-Fallback | Schutzregeln im Webroot (max. 128 KB gelesen) |
| Letztes Backup | Jüngstes **vollständiges** lokales Backup im Backup-Verzeichnis (`backups/` bzw. `CMS_BACKUP_PATH`; Voll- und Datenbank-Backups mit Manifest, ältere Einzel-Dumps `database_*.sql[.gz]`): bis 7 Tage OK, bis 30 Tage Warnung, älter oder keines kritisch. Unvollständige Backups werden genannt, zählen aber nicht. Externe Hoster-Backups erkennt die Prüfung nicht |
| Backup-Speicherort & Verschlüsselung (ab 4.0.0) | Warnung, wenn Backups unverschlüsselt im Webroot liegen (nur `.htaccess`-Schutz, nginx/IIS ignorieren ihn), wenn die Verschlüsselung ohne echten Schlüssel läuft (Platzhalter in `config/app.php`), wenn bei aktiver Verschlüsselung ältere Backups unverschlüsselt im Webroot liegen oder nach dem Umstellen auf `CMS_BACKUP_PATH` noch Alt-Backups in `CMS/backups/` liegen; ist das Verzeichnis nicht nutzbar, ebenfalls Warnung. OK bei Speicherort außerhalb des Webroots oder verschlüsselten Backups (mit Hinweis, den Schlüssel getrennt zu sichern). Details: [../system-settings/BACKUP.md](../system-settings/BACKUP.md) |
| Admin-Passwort-Hashes | Administratoren mit aktuellem Hash-Verfahren |
| Firewall Runtime aktiv | Kritisch, wenn `SecurityRuntimeService` nicht im Bootstrap verdrahtet ist; Warnung, solange `firewall_enabled` nicht `1` ist (Regeln und Request-Rate-Limit greifen dann nicht, Login-Sperren schon); sonst OK |
| AntiSpam Runtime aktiv | Kritisch bei fehlender Anbindung (Kommentare, aktive Kontakt-Plugins); Warnung, solange `antispam_enabled` nicht `1` ist und Kommentare oder Kontaktformulare aktiv sind; OK mit Liste der tatsächlich aktiven Einzelprüfungen. Sind Kommentare global aus und kein Kontakt-Plugin aktiv, ist ein deaktivierter AntiSpam OK |
| Unerwartete Runtime-Fremdassets | Keine unbekannten externen Skripte/Styles |
| Editor.js Alt-Embeds gehärtet | Alte Embed-Blöcke ohne unsichere iframes |
| Dateien vorhanden / Berechtigungen | `config.php` (Pflicht) und `config/app.php`: Warnung, wenn Gruppe oder alle schreiben dürfen; der Modus wird angezeigt (z. B. `0644`). Schreibgeschützte Modi wie `0444` oder `0400` gelten als sicher |

Das Ergebnis zeigt Zähler (bestanden / Warnung / kritisch) und je Prüfpunkt einen Hinweis zur Behebung. Der Lauf selbst wird im Audit-Log festgehalten.

### Audit-Log

- Anzeige der letzten **50** Einträge aus dem Sicherheits-Audit-Log (`cms_audit_log`, Kategorie Sicherheit).
- `clear_log` löscht Einträge älter als **30 Tage**.
- Vollständige Auswertung mit Filtern: `/admin/logs/security-audit`.

### Empfehlungen

1. Audit nach jeder Installation, jedem Update und jeder Server-Änderung ausführen.
2. Kritische Punkte sofort beheben (z. B. Backup erstellen, Debug aus, HTTPS erzwingen, `install.php` löschen, falls keine Sperrdatei existiert).
3. Warnungen bewerten und dokumentieren, falls bewusst akzeptiert.

### Verwandte Dokumente

[FIREWALL.md](FIREWALL.md) · [ANTISPAM.md](ANTISPAM.md) · [../system-settings/BACKUP.md](../system-settings/BACKUP.md) · [../../core/SECURITY.md](../../core/SECURITY.md) · [../PRUEF-CHECKLISTE.md](../PRUEF-CHECKLISTE.md)
