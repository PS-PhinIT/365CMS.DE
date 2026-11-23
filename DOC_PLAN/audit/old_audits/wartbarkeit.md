# Wartbarkeit

**Bereichsscore:** 75/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
Die Codebasis ist funktionsreich, aber sehr groß und historisch gewachsen. Die zentrale Test-Orchestrierung wurde inzwischen durch `TESTS\run.php` plus versioniertes `TESTS\manifest.php` nachvollziehbarer gemacht; zusätzlich dokumentiert ein Dependency-Governance-Inventar die wichtigsten vendored Wurzeln und Paketmanifeste innerhalb des gültigen `CMS`-Scopes.

## Score-Begründung
- Startwert 100
- MAINT-001: -7 (teilweise reduziert)
- MAINT-002: -8 (hoch)
- MAINT-003: -7 (mittel)
- MAINT-004: -3 (teilweise reduziert)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|
| MAINT-001 | Dependencies | Governance | Vendored CMS-Assets/Inventar | mittel | -7 | docs\audit\dependency-governance.json, TESTS\dependency-governance\run.php | Codefund/Test |
| MAINT-002 | Request Layer | Input Handling | Superglobals | hoch | -8 | Fokus-Scan: 676 direkte Superglobal-Fundstellen | Heuristik/Codefund |
| MAINT-003 | Data Layer | SQL APIs | query/get_var/get_results | mittel | -7 | CMS\core\Database.php:164-178 und zahlreiche Aufrufer | Codefund |
| MAINT-004 | Tests/CI | Qualitätssicherung | Runner/Manifest | niedrig | -3 | TESTS\run.php, TESTS\manifest.php | Codefund/Test |

## Umsetzungsschritte

### step-001
- **Ziel:** Dependency-/Artifact-Governance nachvollziehbar machen.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** reduziert; neue oder geänderte Vendor-Wurzeln können gegen ein zentrales Inventar geprüft werden.
- **Technische Ursache:** durch maschinenlesbares Inventar und Smoke-Test teilweise entschärft; physische Trennung von Source und Release-Artefakten bleibt offen.
- **Lösungsweg:** `docs\audit\dependency-governance.json` plus `TESTS\dependency-governance\run.php` ergänzt.
- **Betroffene Dateien:** CMS\assets\*, CMS\vendor\*, TESTS\*.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** Release-/Build-Prozess.

### step-002
- **Ziel:** Einheitlichen Request-Layer etablieren.
- **Befund:** Viele direkte Zugriffe auf `$_GET`, `$_POST`, `$_FILES`, `$_SERVER`.
- **Risiko:** Validierungslogik dupliziert und schwer testbar.
- **Technische Ursache:** Module lesen globale Eingaben selbst.
- **Lösungsweg:** Request-DTOs/Validatoren, Controller-Konventionen, statische Regel gegen direkte Superglobal-Nutzung.
- **Betroffene Dateien:** CMS\admin\modules, CMS\core\Services, CMS\plugins.
- **Priorität:** P1
- **Aufwand:** L
- **Abhängigkeiten:** Regressionstests.

### step-003
- **Ziel:** SQL-Zugriff vereinheitlichen.
- **Befund:** Neben Prepared Statements existiert eine breite generische Query-API.
- **Risiko:** Unterschiedliche Sicherheits- und Fehlerbehandlungsqualität.
- **Technische Ursache:** Datenzugriff ist nicht strikt repository-/servicegebunden.
- **Lösungsweg:** Repositories pro Domäne, Identifier-SQL separat, Deprecation von generischer Query in Fachmodulen.
- **Betroffene Dateien:** CMS\core\Database.php, CMS\admin\modules\*, CMS\core\Services\*.
- **Priorität:** P2
- **Aufwand:** L
- **Abhängigkeiten:** Datenzugriffstests.

### step-004
- **Ziel:** CI-/Teststruktur nachvollziehbar machen.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** reduziert; lokale Qualitätssicherung hat nun einen reproduzierbaren manifestierten Entry-Point.
- **Technische Ursache:** durch zentrales Suite-Manifest teilweise entschärft; CI-Verkabelung bleibt offen.
- **Lösungsweg:** `TESTS\manifest.php` ergänzt und `TESTS\run.php` auf manifestbasierte Suite-Auswahl mit Discovery-Fallback umgestellt; `--list` zeigt Suite-Beschreibungen.
- **Betroffene Dateien:** TESTS\*, README.md, .github\*.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Testumgebung.

## Umsetzungsstand (2026-06-13)
- 🟨 **MAINT-001** teilweise reduziert (Dependency-Governance-Inventar und Smoke-Test aktiv; Artefakt-Auslagerung offen).
- 🟨 **MAINT-002** teilweise reduziert im Admin-Modul-Scope (`CMS\Http\Request`), globale Restmenge bleibt offen.
- 🟨 **MAINT-003** offen/teilweise reduziert (Prepared APIs vorhanden, generische Query-API bleibt breit genutzt).
- 🟨 **MAINT-004** teilweise reduziert (`TESTS\manifest.php` + zentraler Runner aktiv; CI-Workflow bleibt offen).

## Update 2026-06-13
- `TESTS\manifest.php` versioniert die fokussierten Suites `release-smoke`, `pdf-service` und `sitemap-service` inklusive Beschreibung und Required-Flag.
- `TESTS\run.php --list` nutzt das Manifest und gibt reproduzierbare Suite-Beschreibungen aus.
- Validiert: `release-smoke`, `pdf-service` und `sitemap-service` laufen über den manifestierten Runner erfolgreich; fehlendes `mbstring` bzw. fehlende CI/Doku bleiben als SKIP markiert.

## Update 2026-06-13 (Dependency-Governance)
- `docs\audit\dependency-governance.json` inventarisiert die bekannten Vendor-Wurzeln und referenzierten Package-Manifeste im `CMS`-Scope.
- `TESTS\dependency-governance\run.php` ist im zentralen Manifest registriert und validiert Inventar, Manifeste und Vendor-Roots.
- Validiert: `php TESTS\run.php --suite=dependency-governance` → **PASS**.
