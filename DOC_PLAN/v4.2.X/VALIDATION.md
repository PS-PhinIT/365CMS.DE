# 365CMS 4.2.x: Validierung und Release-Gates

> **Stand:** 2026-11-23 | **Baseline:** 4.2.0 / Schema v27

## Aufnahme-Check

Jeder Eintrag muss vor Aufnahme mindestens folgendes enthalten:

1. erwartetes und tatsächliches Verhalten;
2. kleinster Reproduktionsfall oder fehlender Abnahmenachweis;
3. betroffene Version, Schemastand und Upgrade-Pfad;
4. Rechte-/Datenschutz-/Sicherheitswirkung;
5. betroffene Tests oder ein neuer fokussierter Regressionstest;
6. Rückwärtskompatibilitäts- und Rollback-Prüfung;
7. dokumentierte Entscheidung, ob `patch`, `4.3`, `4.4` oder `out of scope`.

## Pflicht-Gates für 4.2.x

| Gate | Abbruchkriterium |
|---|---|
| Patch-Kompatibilität | öffentliches API-, Theme-, Plugin- oder Datenbankverhalten bricht ohne explizite Entwarnung |
| v27-Integrität | Tabellen-, Spalten-, Index- oder `capabilities_v27`-Vertrag weicht ab |
| Upgrade | Neuinstallation oder Upgrade aus 4.1.x/v26 sowie 4.2.0 schlägt fehl bzw. verändert Daten ungeplant |
| Rechte | Admin/Member/Frontend zeigt Daten oder Aktionen außerhalb der Capabilities |
| Datenschutz | Retention, Export, Löschung oder private Anhänge verletzen den 4.2-Vertrag |
| Sicherheit | CSRF, Rate-Limits, Captcha, Update-Integrität oder Signaturprüfung werden umgangen |
| Regressionstests | mindestens ein reproduzierender Test für jede Codekorrektur fehlt |
| Paket | Update-/Vollpaket, Prüfsumme, Version und Changelog stimmen nicht überein |

## Pflichtlauf

```bash
php TESTS/run-all.php
php TESTS/schema-v27/run.php
php TESTS/release-package/run.php
```

Zusätzliche Suiten werden nach betroffener Änderung gewählt:

- Community: `community-*`, `schema-upgrade`;
- Rollen/SSO: `user-role-hook`, `sso-entra`;
- Captcha: `captcha`, `community-captcha`;
- Medien: `media-images`, `community-uploads`;
- Redirects: `admin-redirects`;
- Payments: `payments`;
- Audit-/Release-Doku: Link- und Diff-Prüfung.

## Abnahmeprotokoll

| Prüflauf | Umgebung | Nachweis |
|---|---|---|
| Externer Theme-Pfad | `cms-phinit` gegen 4.2.x | Screenshots, relevante DOM-/JS-Fehler, freigegebene Overrides |
| MariaDB/Last | Version, Datenmenge, Hardware, Datenbank-Konfiguration | Bericht mit Zeit, Abfragen, EXPLAIN, Fehlern |
| Barrierefreiheit | Browser + assistive Technologie + Datum | Protokoll je Arbeitsablauf |
| Upgrade | Ausgangsversionen und Backup/Restore | Migrationsprotokoll und Datenvergleich |
| Release-Paket | Version, Dateien, SHA-256, ggf. Signatur | Paketliste und Verifikationslog |
