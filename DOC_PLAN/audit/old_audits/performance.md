# Performance

**Bereichsscore:** 88/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
Caching- und Monitoring-Module sind vorhanden. Die zuvor teure Homepage-Tagcloud wurde auf gecachte Aggregation mit Frische-Schlüssel umgestellt. Backup-Dumps schreiben inzwischen chunkweise/streamend mit Laufzeitlimit, synchrone DB-Wartung wird im Admin-Request begrenzt, und vendored Dependency-Wurzeln im gültigen `CMS`-Scope sind nun maschinenlesbar inventarisiert. Verbleibende Haupttreiber sind ein fehlendes vollständiges Queue/Resume-Modell und das weiterhin hohe Dependency-Gewicht innerhalb von `CMS/assets` und `CMS/vendor`.

## Score-Begründung
- Startwert 100
- PERF-001: -4 (teilweise reduziert)
- PERF-002: -3 (teilweise reduziert)
- PERF-003: -3 (teilweise reduziert)
- PERF-004: -2 (teilweise reduziert)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|
| PERF-001 | Backup | Datenbankdump | Chunked/streaming Dump, Queue offen | mittel | -4 | CMS\core\Services\BackupService.php (Chunking/Runtime-Guard) | Codefund |
| PERF-002 | Theme | Tagcloud | Homepage Sidebar | niedrig | -3 | CMS\themes\cms-default\home.php (Tagcloud-Cache mit Frische-Schlüssel) | Codefund |
| PERF-003 | System | DB Wartung/Status | Inline-Wartungslimits | niedrig | -3 | CMS\core\Services\SystemService.php (Inline-Limits/Skip großer Tabellen) | Codefund |
| PERF-004 | Assets/Repo | Dependencies | Dependency-Governance-Inventar | niedrig | -2 | docs\audit\dependency-governance.json, TESTS\dependency-governance\run.php | Codefund/Test |

## Umsetzungsschritte

### step-001
- **Ziel:** Backup-Performance skalierbar machen.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** reduziert; vollständiges Queue/Resume-Modell bleibt offen.
- **Technische Ursache:** Dump-Pfad wurde bereits auf Chunks, Writer-Streaming und Laufzeitlimit gehärtet.
- **Lösungsweg:** Vorhanden: `DATABASE_DUMP_SELECT_CHUNK_SIZE`, `DATABASE_DUMP_MAX_RUNTIME_SECONDS`, `writeDatabaseDumpToWriter()` und streamendes Schreiben. Offen: persistierter Fortschritt/Resume über Scheduler/CLI.
- **Betroffene Dateien:** CMS\core\Services\BackupService.php.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** Scheduler/CLI.

### step-002
- **Ziel:** Tagcloud ohne Vollscan erzeugen.
- **Befund:** ✅ weitgehend umgesetzt.
- **Risiko:** deutlich reduziert.
- **Technische Ursache:** durch Cache-Layer entschärft.
- **Lösungsweg:** Gecachte Tag-Aggregation in `home.php` umgesetzt, inkl. Frische-Schlüssel aus `COUNT(*)` + `MAX(updated_at/published_at/created_at)` und TTL.
- **Betroffene Dateien:** CMS\themes\cms-default\home.php:111-121.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Datenmodell/Cache-Invalidierung.

## Umsetzungsstand (2026-06-13)
- 🟨 **PERF-001** teilweise reduziert (Chunking, Writer-Streaming und Laufzeit-Guard vorhanden; vollständiges Queue/Resume-Modell offen)
- ✅ **PERF-002** umgesetzt (Tagcloud-Caching mit Frische-Schlüssel in Theme-Homepage)
- 🟨 **PERF-003** teilweise reduziert (Inline-Tabellenlimit und Skip großer Tabellen aktiv; Job-Auslagerung offen)
- 🟨 **PERF-004** teilweise reduziert (Dependency-/Vendor-Inventar und Smoke-Test aktiv; physische Repo-Verschlankung offen)

### step-003
- **Ziel:** Systemdiagnosen begrenzen.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** reduziert; große/zu viele Tabellen werden nicht mehr blind inline gewartet.
- **Technische Ursache:** durch Inline-Limits entschärft, vollständige Job-Auslagerung noch offen.
- **Lösungsweg:** `SystemService` begrenzt `REPAIR/OPTIMIZE` auf maximal 10 Tabellen pro Request und überspringt Tabellen über 100.000 geschätzten Zeilen oder 256 MiB mit Job-/CLI-Hinweis.
- **Betroffene Dateien:** CMS\core\Services\SystemService.php, Admin-Systemmodule.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Job-/Cron-Infrastruktur.

## Update 2026-06-13
- `CMS\core\Services\SystemService.php` begrenzt synchrone DB-Wartung im Admin-Request über `MAX_INLINE_MAINTENANCE_TABLES`, `MAX_INLINE_MAINTENANCE_ROWS` und `MAX_INLINE_MAINTENANCE_BYTES`.
- `REPAIR TABLE`/`OPTIMIZE TABLE` überspringen große Tabellen fail-soft mit Job-/CLI-Hinweis statt Lastspitzen zu erzeugen.
- Backup-Befund wurde mit dem aktuellen Code abgeglichen: Chunking, Writer-Streaming und Runtime-Guard sind vorhanden; Queue/Resume bleibt Restarbeit.

### step-004
- **Ziel:** Repository-/Build-Gewicht reduzieren.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** reduziert; vendored Wurzeln und vorhandene Package-Manifeste werden maschinenlesbar geprüft.
- **Technische Ursache:** durch Inventory/Smoke-Test teilweise entschärft; physische Trennung von Release-Artefakten bleibt offen.
- **Lösungsweg:** `docs\audit\dependency-governance.json` dokumentiert Vendor-Roots, Manifest-Dateien und bekannte Lücken; `TESTS\dependency-governance\run.php` validiert Inventar, Manifest-JSON und Vendor-Root-Existenz.
- **Betroffene Dateien:** ASSETS\*, CMS\assets\*, CMS\vendor\*.
- **Priorität:** P3
- **Aufwand:** L
- **Abhängigkeiten:** Release-Prozess.

## Update 2026-06-13 (Dependency-Governance)
- `docs\audit\dependency-governance.json` ergänzt ein maschinenlesbares Inventar für `CMS\assets` und `CMS\vendor` inklusive Manifestpfaden und bekannten Governance-Lücken. Root-`ASSETS` zählt gemäß Scope-Vorgabe nicht mehr als 365CMS-Core-Auditfund.
- `TESTS\dependency-governance\run.php` prüft Inventarstruktur, JSON-validierte Manifeste und vorhandene Vendor-Roots.
- Validiert: `php TESTS\run.php --suite=dependency-governance` → **PASS**.
