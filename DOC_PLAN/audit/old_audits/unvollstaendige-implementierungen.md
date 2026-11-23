# Unvollständige Implementierungen

**Bereichsscore:** 98/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
Die zuvor identifizierten Implementierungslücken wurden in den produktiven Pfaden weitgehend geschlossen: Web-Cron Query-Token ist jetzt mit Sunset-Datum hart gedeckelt, Import-Uploads laufen über einen einheitlichen persistierten Lifecycle mit Job-ID, und Analytics-Konfigurationen validieren Placeholder-/Formatfehler mit sichtbarem Statusmodell (`configured/partial/missing`).

Die verbleibende Restlücke betrifft primär die CI-Anbindung des neuen zentralen Runners (lokal ist die reproduzierbare Suite-Ausführung jetzt gegeben).

## Score-Begründung
- Startwert 100
- IMPL-001: 0 (abgeschlossen)
- IMPL-002: 0 (abgeschlossen)
- IMPL-003: 0 (abgeschlossen)
- IMPL-004: -2 (niedrig, teilweise reduziert)
- IMPL-005: 0 (abgeschlossen)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Status | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|---|
| IMPL-001 | Cron | Web Cron | Query-Fallback | niedrig | 0 | ✅ abgeschlossen | CMS\cron.php (Query-Token mit Sunset-Block) | Codefund |
| IMPL-002 | Importer | Upload/Import | Lifecycle vereinheitlicht | niedrig | 0 | ✅ abgeschlossen | CMS\plugins\cms-importer\includes\class-admin.php (persistenter Importpfad + Job-ID) | Codefund |
| IMPL-003 | Analytics/Consent | Tracking | Placeholder-/Formatvalidierung + Statusmodell | niedrig | 0 | ✅ abgeschlossen | CMS\admin\modules\seo\SeoSuiteModule.php; CMS\admin\views\seo\analytics.php | Codefund |
| IMPL-004 | Tooling | Tests/Dependencies | Pipeline | niedrig | -2 | 🟨 teilweise reduziert | `TESTS\run.php` + `TESTS\bootstrap.php` als zentraler Runner vorhanden; CI-Verkabelung noch offen | Konfigurationsfund/Heuristik |
| IMPL-005 | AI/KI | Provider Gateway & Generatoren | Mistral/OpenAI-kompatible Live-Adapter, Content-/SEO-Previews | niedrig | 0 | ✅ abgeschlossen | `CMS\core\Services\AI\Providers\OpenAiCompatibleProvider.php`, `CMS\core\Services\AI\AiProviderGateway.php`, `CMS\admin\modules\system\AiServicesModule.php`, `TESTS\ai-services\run.php` | Codefund/Test |

## Umsetzungsschritte

### step-001
- **Ziel:** Legacy-Fallbacks abschließen.
- **Befund:** ✅ umgesetzt.
- **Risiko:** reduziert.
- **Technische Ursache:** behoben durch hartes Removal-Datum.
- **Lösungsweg:** Deprecation-/Removal-Metadaten plus Sunset-Block für Query-Token.
- **Betroffene Dateien:** CMS\cron.php.
- **Priorität:** P2
- **Aufwand:** S
- **Abhängigkeiten:** Externe Cron-Konfiguration.

### step-002
- **Ziel:** Import-Upload-Workflow eindeutig definieren.
- **Befund:** ✅ umgesetzt.
- **Risiko:** reduziert.
- **Technische Ursache:** behoben durch Entfernen des optionalen Bypass-Pfads.
- **Lösungsweg:** Einheitlicher persistierter Import-Lifecycle, Job-ID-basierte Dateibenennung im Import-Verzeichnis.
- **Betroffene Dateien:** CMS\plugins\cms-importer\includes\class-admin.php.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Import-UI.

### step-003
- **Ziel:** Konfigurations-Placeholder aus produktiven Flows entfernen.
- **Befund:** ✅ umgesetzt.
- **Risiko:** reduziert.
- **Technische Ursache:** über Save-Validierung und Statusmodell adressiert.
- **Lösungsweg:** Format-/Placeholder-Validierung beim Speichern, Enable-Flags aus gültigen Werten, UI-Statusmatrix `configured/partial/missing` sowie zusätzliche Legacy-Härtung: bereits gespeicherte manuell/alt ungültige Kennungen werden im Statusmodell als `partial` statt `configured` ausgewiesen.
- **Betroffene Dateien:** CMS\admin\modules\seo\SeoSuiteModule.php, CMS\admin\views\seo\analytics.php.
- **Priorität:** P2
- **Aufwand:** S
- **Abhängigkeiten:** Settings-Speicherung.

### step-004
- **Ziel:** Pipeline-Lücke schließen.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** Implementierungsstatus kann nicht automatisch vollständig geprüft werden.
- **Technische Ursache:** Gewachsene Projektstruktur mit separaten Testordnern und vendored Dependencies.
- **Lösungsweg:** Zentralen Runner bereitstellen (`TESTS/run.php`) und gemeinsames Bootstrap (`TESTS/bootstrap.php`) nutzen; optional nächste Etappe: CI-Job für automatische Ausführung.
- **Betroffene Dateien:** Repository-Root, TESTS\*.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Dependency-Management.

### step-005
- **Ziel:** AI/KI-Provider-Lücke schließen.
- **Befund:** ✅ umgesetzt.
- **Risiko:** reduziert; OpenAI-kompatible Provider sind nicht mehr nur vorbereitet, sondern gatewayseitig produktiv verdrahtet. Content- und SEO-Creator sind nicht mehr reine Settings-Seiten.
- **Technische Ursache:** Mistral fehlte im Katalog, OpenAI/OpenRouter waren nicht live/addable.
- **Lösungsweg:** Generischer OpenAI-kompatibler Provider, Provider-Katalog-Erweiterung, Gateway-Factory, generische `generateText()`-Providerfähigkeit, Content-/SEO-Preview-Actions und AI-Smoke-Test.
- **Betroffene Dateien:** CMS\core\Services\AI\*, CMS\admin\views\system\ai-services.php, TESTS\ai-services\run.php.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** API-Key/Endpoint-Konfiguration.
