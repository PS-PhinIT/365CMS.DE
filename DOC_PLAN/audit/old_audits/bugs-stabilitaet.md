# Bugs und Stabilität

**Bereichsscore:** 78/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
Die PHP-Syntaxprüfung der fokussierten First-Party-Dateien war fehlerfrei. Stabilitätsrisiken entstehen vor allem durch unparametrisierte Low-Level-Query-Pfade, breite Seiteneffekte in Admin-Modulen und lokale Fallbacks ohne durchgängige Testabdeckung.

## Score-Begründung
- Startwert 100
- BUG-001: -8 (hoch)
- BUG-002: -6 (mittel)
- BUG-003: -5 (mittel)
- BUG-004: -3 (niedrig)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|
| BUG-001 | Database | Query API | query() | hoch | -8 | CMS\core\Database.php:164-178 | Codefund |
| BUG-002 | Backup | DB Dump | writeDatabaseDumpToWriter | mittel | -6 | CMS\core\Services\BackupService.php:278-303 | Codefund |
| BUG-003 | System | Maintenance | runTableMaintenanceStatement | mittel | -5 | CMS\core\Services\SystemService.php:611-624 | Codefund |
| BUG-004 | Bootstrap/Install/Cron | Prozessende | exit() | niedrig | -3 | CMS\config.php, CMS\core\Bootstrap.php, CMS\install\InstallerService.php, CMS\cron.php | Codefund |

## Umsetzungsschritte

## Umsetzungsstand (2026-06-13)
- ✅ **BUG-001 (Low-Level-Query-Nutzung begrenzen) umgesetzt**
	- `Database::query()` ist jetzt hart begrenzt auf definierte Read-/Schema-/Maintenance-Verben.
	- Multi-Statement-Raw-SQL wird blockiert.
	- Schreibverben (z. B. `INSERT/UPDATE/DELETE/REPLACE/TRUNCATE`) sind in `query()` gesperrt und müssen über parametrisierte APIs laufen.
- 🟨 **BUG-002 (Backup-Dump Robustheit) teilweise umgesetzt**
	- Datenbank-Dump läuft chunkweise (`LIMIT/OFFSET`) statt Full-Table-Iteration in einem Zug.
	- Laufzeit-Guard gegen lange/blockierende Dumps ergänzt.
	- Vollständiger Resume-Mechanismus inkl. UI-Fortschrittsstatus bleibt offen.
- ✅ **BUG-003 (deterministische Wartungsresultate) umgesetzt**
	- Einheitliche, normalisierte Detailstruktur für DB-Wartungs-Responses.
	- Leere Treiber-Resultsets werden deterministisch behandelt (nicht mehr uneinheitlich als Hard-Fail).
	- Audit-/Operational-Logging integriert.
- ✅ **BUG-004 (Prozessabbrüche kapseln) umgesetzt**
	- Zentrale Terminate-Kapseln in `cron.php`, `config.php`, `Bootstrap` und `InstallerService` eingeführt.
	- Direkte Prozessabbrüche in den Restarbeits-Entry-Points durch gekapselte Aufrufe ersetzt.

### step-001
- **Ziel:** Low-Level-Query-Nutzung begrenzen.
- **Befund:** `Database::query()` akzeptiert beliebige SQL-Strings und dokumentiert selbst, dass es nur für vertrauenswürdige SQL-Strings gedacht ist.
- **Risiko:** Fehlerhafte Aufrufer können Stabilitäts- und Sicherheitsprobleme erzeugen.
- **Technische Ursache:** Generische öffentliche API ohne technische Einschränkung.
- **Lösungsweg:** Query-Nutzung auf Schema-/Maintenance-Klassen beschränken, statische Analyse-Regel oder Wrapper für Identifier-SQL einführen.
- **Betroffene Dateien:** CMS\core\Database.php, aufrufende Module mit `->query(`.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** Migrationspfad für bestehende Aufrufer.
- **Status:** ✅ umgesetzt (2026-06-13)

### step-002
- **Ziel:** Backup-Dump robust gegen große Datenbestände machen.
- **Befund:** BackupService iteriert alle Tabellen und schreibt INSERTs zeilenweise.
- **Risiko:** Laufzeit-/Speicher-/Timeout-Probleme bei großen Installationen.
- **Technische Ursache:** Vollständiger Dump über PHP-Prozess statt Streaming mit Limits/Chunking-Metadaten.
- **Lösungsweg:** Chunk-Größen, Fortschrittsstatus, Abbruch-/Resume-Mechanismus und Max-Runtime einführen.
- **Betroffene Dateien:** CMS\core\Services\BackupService.php:270-305.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Backup-UI und Scheduler.
- **Status:** 🟨 teilweise umgesetzt (Chunking + Laufzeit-Guard, Resume offen)

### step-003
- **Ziel:** Wartungsoperationen deterministisch fehlschlagen lassen.
- **Befund:** Tabellenwartung wertet leere Ergebnissets als Fehler, aber Details hängen vom DB-Treiber ab.
- **Risiko:** Uneinheitliche Admin-Rückmeldungen und schwer reproduzierbare Fehlerfälle.
- **Technische Ursache:** Direkte PDO-Statements für DB-spezifische Maintenance-Kommandos.
- **Lösungsweg:** Treiber-/Versionserkennung, normalisierte Ergebnisstruktur, Tests mit simulierten PDO-Ergebnissen.
- **Betroffene Dateien:** CMS\core\Services\SystemService.php:611-624.
- **Priorität:** P2
- **Aufwand:** S
- **Abhängigkeiten:** Test-Doubles für DB.
- **Status:** ✅ umgesetzt (2026-06-13)

### step-004
- **Ziel:** Prozessabbrüche kapseln.
- **Befund:** Mehrere Einstiegspunkte verwenden `exit()` direkt.
- **Risiko:** Schwer testbare Kontrollflüsse und unerwartete Abbrüche bei Wiederverwendung.
- **Technische Ursache:** Frontcontroller/CLI-Skripte mischen Runtime-Logik und Prozesssteuerung.
- **Lösungsweg:** Response-/Exit-Code-Objekte zurückgeben; `exit()` nur in äußerster Bootstrap-Schicht.
- **Betroffene Dateien:** CMS\config.php, CMS\core\Bootstrap.php, CMS\install\InstallerService.php, CMS\cron.php.
- **Priorität:** P3
- **Aufwand:** M
- **Abhängigkeiten:** Smoke-Tests.
- **Status:** ✅ umgesetzt (2026-06-13)
