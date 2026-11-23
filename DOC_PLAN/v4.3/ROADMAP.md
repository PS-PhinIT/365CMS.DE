# 365CMS 4.3: Lieferplan

> **Stand:** 2026-11-23 | **Kernziel:** CLI + Alerting

## W0 – Entscheidungen

- [CLI-Spezifikation](../v4.1/06-CLI-ALERTING.md) gegen 4.2.0-Code verifizieren.
- Unterbefehle, Rechte, Exitcodes, Audit und Secret-Eingabe freigeben.
- Alertquellen, Schwere, Deduplizierung, Cooldown und Kanäle freigeben.
- 4.4-Verträge ausdrücklich als Folgearbeit kennzeichnen; keine Vorab-Migration.

## W1 – Gemeinsamer Betriebsvertrag

- CLI-Bootstrap ohne Web-SAPI;
- Schreib-/Lesebefehl-Klassen;
- gemeinsamer Preflight für UI, CLI und Cron;
- standardisierte Statusobjekte und Exitcodes;
- redigierter Diagnose-Kontext.

**Gate:** Backup-, Update-, Cache-, Queue- und Diagnosepfade sind über CLI und
Admin konsistent.

## W2 – CLI-Befehle

Priorität:

1. `status`, `check`, `cache`, `cron`, `queue`;
2. Backup, Restore-Vorprüfung, Datenbank-Update;
3. ausgewählte Content-/Benutzer-Wartung mit expliziter Bestätigung;
4. JSON-Ausgabe für CI und Automation.

Kein Befehl darf ein Web-Login, Session-Cookie oder ungeschütztes Klartext-Passwort
voraussetzen.

## W3 – Alerting

- `alert.raised` und normalisiertes Eventobjekt;
- deduplizierter Zustand, Cooldown und Wiederkehr;
- Mail/Webhook/Admin-Systemhinweis;
- Quellzuordnung für Cron, Backup, Update, Mail, Firewall und Speicher;
- Testfail für Secret-/PII-Lecks.

## W4 – Betriebsabnahme

- erfolgreiche, fehlerhafte, abgebrochene und blockierte Befehle;
- Datenbankausfall und fehlende Erweiterungen;
- Queue-Locks und lange Jobs;
- Alert-Spam, doppelte Kanäle und Kanalfehler;
- Releasepaket, Doku und Changelog.

## Nach 4.4 verschoben

- Inhaltsmodell B: [v4.4/V44-01](../v4.4/CATALOG.md#v44-01--custom-fields-und-eigene-inhaltstypen);
- N Sprachen: [v4.4/V44-02](../v4.4/CATALOG.md#v44-02--normalisiertes-n-sprachen-modell);
- Live Preview: [v4.4/V44-03](../v4.4/CATALOG.md#v44-03--live-splitscreen-vorschau);
- Managed API: [v4.4/V44-04](../v4.4/CATALOG.md#v44-04--managed-public-api).

## Release-Gates

| Gate | Kriterium |
|---|---|
| Sicherheit | Web-SAPI gesperrt, Audit, keine Secrets, bestätigte destruktive Befehle |
| Zuverlässigkeit | Timeout, Lock, Retry und Teilfehler sind reproduzierbar |
| Kompatibilität | 4.2.0-Web/Admin/CLI-fremde Installation wird nicht verändert |
| Tests | `php TESTS/run-all.php` und neue CLI/Alert-Suiten |
| Release | Version, Schema, Manifeste und Changelog konsistent |
