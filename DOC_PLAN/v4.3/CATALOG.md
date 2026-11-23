# 365CMS 4.3: Releasekatalog

> **Stand:** 2026-11-23 | **Baseline:** 4.2.0 | **Ziel:** 4.3.0

4.3 ist der Betriebsrelease für zentrale Wartung und Alerting. Größere
Inhalts-, Editor- und API-Funktionen sind nach 4.4.0 verschoben.

## Verbindlicher Kern

| ID | Pri. | Status | Fähigkeit | Abnahme |
|---|---|---|---|---|
| V43-01 | MUST | [ ] | Zentrale CLI unter `CMS/cli.php` | sichere Unterbefehle, Web-SAPI-Sperre, Exitcodes, JSON-/Textmodus, Audit |
| V43-02 | MUST | [ ] | Echtzeit-Alerting über `alert.raised` | Deduplizierung, Cooldown, sichere Kanäle, `alert_state`, Tests |
| V43-03 | MUST | [ ] | Gemeinsames Preflight-/Worker-Modell | CLI, Cron und Admin verwenden dieselben Sperren, Limits, Exit-/Statuswerte |
| V43-04 | SHOULD | [ ] | Betriebsreife Alert-Quellen | Cron, Backup, Update, Mail-Queue, Firewall und Offsite-Upload |
| V43-05 | SHOULD | [ ] | Redigierte Betriebsdiagnostik | Alert-/CLI-Kontext enthält keine Secrets, Tokens oder personenbezogenen Inhalte |

## Unterstützende Kandidaten

| ID | Pri. | Status | Fähigkeit | Grenze |
|---|---|---|---|---|
| V43-06 | SHOULD | PROPOSED | Maschinenlesbare Konfigurations-Snapshots | nur Betriebsstatus und nach Freigabe; Secrets bleiben ausgeschlossen |
| V43-07 | SHOULD | PROPOSED | Einheitlicher Diagnose-/Health-Export | keine zweite Monitoring-Plattform |
| V43-08 | COULD | PROPOSED | Cache-/Query-Budget im CLI-Report | kein neuer Cache-Vertrag; nur beobachtbar machen |
| V43-09 | COULD | [ ] | Community-P2-Nachlauf | nur kleine, sauber abgegrenzte Moderations-/Benachrichtigungsteile; keine 4.4-Content-Migration voraussetzen |

## Nach 4.4 verschoben

| 4.4-Katalog | Inhalt |
|---|---|
| V44-01 | Custom Fields und eigene Inhaltstypen |
| V44-02 | normalisiertes N-Sprachen-Modell |
| V44-03 | Live-Splitscreen-Vorschau |
| V44-04 | Managed API, Client Credentials/API-Keys und OpenAPI |

Damit gelten die Custom-Field-, Sprach-, Preview- und Managed-API-Punkte aus der
ersten Marktsichtung nicht als 4.3-Umfang. Maßgeblich sind
[`../v4.4/CATALOG.md`](../v4.4/CATALOG.md) und
[`../v4.4/ROADMAP.md`](../v4.4/ROADMAP.md).

## 4.3-Nichtziele

- neue Datenmodelle für Custom Fields oder eigene Inhaltstypen;
- Migration der festen DE/EN-Spalten;
- Live-Preview im Editor;
- neue Integrationsschlüssel, Client Credentials oder OpenAPI;
- GraphQL;
- neue Commerce-, Mitglieder- oder Community-Domänen.

## Abnahme

```bash
php TESTS/run-all.php
php TESTS/cli/run.php
php TESTS/alerting/run.php
```

Zusätzlich:

- jede Schreiboperation über CLI wird auditiert;
- Web-Aufruf von `cli.php` wird abgelehnt;
- Cron/Admin/CLI teilen Sperr-, Retry- und Zeitbudgetregeln;
- Alerting ist dedupliziert und enthält keine Secrets;
- der 4.3-Release enthält keine vorbereitende, aber unvollständige 4.4-Schemaänderung.
