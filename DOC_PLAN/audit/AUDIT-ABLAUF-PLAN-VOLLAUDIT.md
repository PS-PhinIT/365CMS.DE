# 365CMS Audit-Ablaufplan: Vollaudit

> **Stand:** 2026-11-23 | **Release:** 4.2.0 / Schema v27 | **Prüfliste:** [AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md)

## Ziel

Der Vollaudit prüft den gesamten produktiven Corebestand, die mitgelieferte
Runtime, Administration, Member, Theme, Tests, Dokumentation und Lieferkette.
Er wird bei Major-/Feature-Release, nach größerer Architekturänderung oder auf
Anforderung mit externem Prüfungsbedarf genutzt.

## Phase 0 – Basis und Beweismittel

```bash
git status --short
git describe --tags --always
php -v
php TESTS/run-all.php
php TESTS/release-package/run.php
```

Für externe Prüfräume zusätzlich:

- PHP 8.4 und, soweit verfügbar, 8.5 Lint;
- echte MariaDB/MySQL für Schema-/Upgrade-/Lastpfade;
- Chromium für Editor, A11y und Live-UI;
- CI: CodeQL, Semgrep, Trivy, PHP-Lint;
- aktuelle Bundles und Manifeste, ohne externen Download während der Prüfung.

## Phase 1 – Inventar

1. Routen und direkte PHP-Einstiege.
2. Admin- und Member-Seiten, Capabilities, CSRF-Aktionen.
3. Hooks, Filter, Services, Provider und Container-Aliase.
4. Tabellen, Spalten, Indizes, Schema-/Repair-Wege.
5. Einstellungen, Sprachschlüssel, Menüs und Feature-Gates.
6. Upload-, Export-, Import-, Backup- und Auslieferungsendpunkte.
7. Externe Aufrufe, Secrets, Redirects, KI und Zahlungsanbieter.
8. Releasepakete, Hashes, Versionen und Schutzdateien.

Jedes Inventar wird mit einer Fundstelle und vorhandener Dokumentation abgeglichen.

## Phase 2 – Risikogesteuerte Reihenfolge

Die AP werden in folgender Reihenfolge bearbeitet:

1. AP-02 Autorisierung
2. AP-03 Injection/Traversal/SSRF
3. AP-04 Ausgabe/XSS/CSP
4. AP-01 Anmeldung/Sitzung
5. AP-11 Community
6. AP-06 Upload/Datei/Backup
7. AP-09 Install/Update/Marketplace
8. AP-08 Payments/Webhooks
9. AP-07 Kryptografie/Secrets
10. AP-05 CSRF/Header/Cookies
11. AP-10 Datenbank/Konsistenz
12. AP-12 Performance/Skalierung
13. AP-13 Funktionen/Verweise
14. AP-17 Datenschutz
15. AP-16 Drittbibliotheken
16. AP-18 Betrieb
17. AP-19 KI
18. AP-20 Integration
19. AP-14 Barrierefreiheit
20. AP-15 Best Practice

Jeder AP wird mit `geprüft`, `Befund`, `akzeptiert`, `verworfen` oder
`nicht prüfbar` abgeschlossen.

## Phase 3 – Befunde umsetzen

- CRITICAL/HIGH zuerst und einzeln.
- Jede Korrektur bekommt einen Regressionstest, der vor dem Fix fehlschlägt.
- Wo vorhanden: Mutant/Gegenprobe.
- Inventare, Hooks, Schema, Sprachdateien, Changelog und Release-Doku direkt
  mitpflegen.
- Keine neue Release-Paketdatei für Zwischenstände; Pakete am Release-Schnitt.
- Exploitdetails, Zugangsdaten und personenbezogene Werte niemals in den Bericht.

## Phase 4 – Gegenprüfung

```bash
php -l <alle geaenderten PHP-Dateien>
php TESTS/run-all.php
php TESTS/release-package/run.php
git diff --check
```

Zusätzlich:

- wiederholter AP-Durchlauf an allen geänderten Stellen;
- DB-Upgrade, Reparatur und Neuinstallation;
- Browser/A11y/Lighthouse auf repräsentativen Seiten;
- Paketinhalt, Hashes, `update.json`, `Version.php`, Changelog und README;
- Prüfung, dass keine historische Auditbehauptung als aktueller Befund wiederkehrt.

## Phase 5 – Bericht

Datei:

```text
DOC_PLAN/audit/AUDIT-VOLLAUDIT-<YYYY-MM-DD>.md
```

Pflichtstruktur:

1. Kurzfassung Englisch und Hauptteil Deutsch.
2. Basiscommit, Tag, Version, Schema, Umgebung.
3. Befundzahlen je Schwere/AP.
4. Befundtabelle und Details.
5. akzeptiert/verworfen mit Begründung.
6. geprüft ohne Befund je AP.
7. nicht prüfbare Bereiche samt konkretem Folgeablauf.
8. Verifikation, Tests, Mutanten, Browser/DB.
9. offene Punkte mit Releasezuordnung.

## Abnahme

- AP-01 bis AP-20 vollständig beantwortet;
- alle CRITICAL/HIGH korrigiert oder ausdrücklich akzeptiert;
- MEDIUM korrigiert oder terminierte Nacharbeit;
- dynamisch entdeckte Tests grün;
- keine neuen CI-Sicherheitsbefunde;
- Paket-/Doku-/Auditstatus konsistent;
- historisches Auditarchiv bleibt unverändert belegt.
