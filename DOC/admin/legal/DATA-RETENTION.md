# 365CMS – Admin: Löschfristen

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

*Legal → Retention periods* (`/admin/data-retention`) lists every automatic deletion rule in one place: period,
action (delete or anonymise), a dry run showing how many entries the next run would affect, and the last run. Rules
run daily with the cron and can be run manually per rule or all at once (with confirmation). Periods are configured
where the data lives; comment IP retention is configured on this page.

## Deutsch

### Regeln im Core

| Regel | Schlüssel | Frist (Standard) | Aktion | Einstellung |
|---|---|---|---|---|
| Seitenaufrufe | `page_views` | 365 Tage | Löschen | Filter `cms_data_retention_days` |
| Core Web Vitals | `core_web_vitals` | 90 Tage | Löschen | Filter |
| 404-Monitor | `not_found_logs` | 90 Tage | Löschen | Filter |
| Tages-Logdateien | `log_files` | 30 Tage | Löschen | Filter |
| Aktivitäts-/Audit-Protokoll | `activity_log`, `audit_log` | nie | Löschen | Sicherheit → Sicherheits-Audit |
| Suchbegriff-Statistik | `search_terms` | 365 Tage | Löschen | Suchindex → Einstellungen |
| Download-Abrufe (Rohdaten) | `downloads.events` | 90 Tage | Löschen | Downloads → Einstellungen |
| Formulareingänge | `forms.submissions` | 365 Tage (je Formular änderbar) | Löschen samt Dateien | Formulare → Einstellungen |
| Papierkorb | `trash` | 30 Tage | Endgültig löschen (verwendete Medien bleiben) | Papierkorb |
| Kommentar-IP-Adressen | `comment_ips` | nie | Kürzen (letzter Block 0) oder entfernen | diese Seite |

### Seite `/admin/data-retention`

- Kopf: Anzahl Regeln, aktive Regeln, fällige Einträge; *Alle Regeln jetzt ausführen*.
- Je Regel: Frist, Aktion, **Probelauf** (Anzahl, die der nächste Lauf betreffen würde), letzter Lauf mit Anzahl,
  Links zu den Einstellungen, *Jetzt ausführen* (nur bei Frist > 0, mit Bestätigung).
- Abschnitt *IP-Adressen in Kommentaren*: Frist in Tagen (0 = nie) und Vorgehen (kürzen oder entfernen). Die
  Kommentare selbst bleiben erhalten.

Geprüft (4.0.0, Laufzeit): Kommentar mit IP, 40 Tage alt; Frist 30 Tage gespeichert → Probelauf „1 fällig“ →
*Jetzt ausführen* → IP `203.0.113.77` wird zu `203.0.113.0`, Probelauf danach 0.

### Erweiterung

Bereiche melden eigene Regeln mit `DataRetentionService::registerRule(new RetentionRule(...))` an (Schlüssel,
Bezeichnung, Frist, Zähl- und Lösch-Callback); sie erscheinen automatisch auf dieser Seite.

### Dateien

`CMS/admin/data-retention.php`, `CMS/admin/modules/legal/DataRetentionModule.php`,
`CMS/admin/views/legal/data-retention.php`, `CMS/core/Services/DataRetentionService.php`,
`CMS/core/Services/Retention/*`
