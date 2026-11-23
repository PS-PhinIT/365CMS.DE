# AI/KI-Module

**Audit-Scope:** ausschließlich `365CMS.DE/CMS/**` für 365CMS-Core-Bewertungen; `TESTS/**` dient nur als Validierungsnachweis.

**Abschlussversion:** `2.9.710`

**Bereichsscore:** 98/100

## Kurzfazit

Die AI/KI-Module sind jetzt als **Single-Provider-Architektur** umgesetzt. Im Admin kann genau ein Provider-Typ konfiguriert werden (`mock`, `ollama`, `azure_openai`, `openai`, `mistral`, `openrouter`). Der zentrale `AiProviderGateway` nutzt ausschließlich diesen aktiven Provider. Es gibt keinen Runtime-Fallback, keine Parallelprovider und keine direkten AI-API-Calls außerhalb der zentralen Provider-Adapter. Seit `2.9.709` sind Modell- und Settings-Felder providerabhängig: Modellwerte kommen aus einem zentralen Dropdown-Katalog, Legacy-GPT-4.x-Modelle sind nicht mehr auswählbar oder als Fallback hinterlegt. Seit `2.9.710` blockieren Translation-Zielsprachen nicht mehr Content-/SEO-Workflows in DE; Providerwechsel synchronisiert zudem die internen Formular-IDs sichtbar auf den gewählten Providertyp.

EditorJS-Übersetzungen, Content-Previews und SEO-Previews laufen über denselben AI-Service. Wenn der aktive Provider falsch konfiguriert ist, schlägt der Workflow sichtbar fehl, statt heimlich auf Mock oder einen anderen Provider auszuweichen.

## Inventar aller AI/KI-Komponenten in `/CMS`

| Modul | Zweck | Status | Bewertung | Dateien |
|---|---|---:|---|---|
| AI Admin Routing | Gemeinsame AI-Unterseiten und Actions | ✅ OK | `test_provider`, `save_providers`, Feature-/Logging-/Quota-Actions zentral gebunden | `CMS/admin/ai-page.php`, `CMS/admin/ai-*.php` |
| AI Services Admin | Provider-Konfiguration, Gates, Quotas, Logging, Prompt-Vorlagen, Generator-Previews | ✅ OK | Single Provider statt Add/Delete-Liste; Admin steuert Typ, API-Key, providerabhängige Felder, Modell-Dropdown und Test | `CMS/admin/modules/system/AiServicesModule.php`, `CMS/admin/views/system/ai-services.php` |
| EditorJS Translation Endpoint | Geschützter Übersetzungsendpunkt für EditorJS-Blöcke | ✅ OK | nutzt ausschließlich `AiProviderGateway`; Audit-Metadaten verwenden `selection_mode=single-provider` | `CMS/admin/ai-translate-editorjs.php`, `CMS/admin/modules/system/AiEditorJsTranslationModule.php` |
| Provider Gateway | Zentrale Runtime für Translation, Content und SEO | ✅ OK | löst nur `active_provider_id` auf; keine Fallback-Kandidaten mehr | `CMS/core/Services/AI/AiProviderGateway.php` |
| Provider Settings | Provider-Katalog, Secrets, Defaults, Feature-Gates | ✅ OK | normalisiert gespeicherte Provider auf exakt einen aktiven Eintrag; alte Fallback-Keys werden bereinigt | `CMS/core/Services/AI/AiSettingsService.php` |
| Provider Interface | Einheitlicher Vertrag für Übersetzung und Textgenerierung | ✅ OK | saubere Abstraktion ohne Vendor-Hardcoding im Editor/Admin | `CMS/core/Services/AI/AiProviderInterface.php` |
| Prompting-Basis | Gemeinsame Prompt-/JSON-Logik | ✅ OK | zentrale Basis für Live-Provider | `CMS/core/Services/AI/Providers/AbstractPromptingAiProvider.php` |
| Mock Provider | lokaler Testprovider | ✅ OK | nur aktiv, wenn explizit als einziger Provider gewählt | `CMS/core/Services/AI/Providers/MockAiProvider.php` |
| Ollama Provider | lokaler/interner Live-Provider | ✅ OK | nutzt zentralen HTTP-Client; private Hosts erlaubt | `CMS/core/Services/AI/Providers/OllamaAiProvider.php` |
| Azure AI/OpenAI Provider | Azure Live-Provider | ✅ OK | prüft Endpoint, Deployment, API-Version und API-Key vor Request | `CMS/core/Services/AI/Providers/AzureOpenAiProvider.php` |
| OpenAI-kompatibler Provider | OpenAI, Mistral, OpenRouter | ✅ OK | ein Adapter für Chat-Completions-kompatible APIs | `CMS/core/Services/AI/Providers/OpenAiCompatibleProvider.php` |
| EditorJS Pipeline | Segmentierung/Rekonstruktion von EditorJS-Inhalten | ✅ OK | strukturerhaltende Verarbeitung; keine Providerlogik | `CMS/core/Services/AI/EditorJsTranslationPipeline.php` |

## Provider-Fähigkeiten

| Provider | Konfigurierbar | Live Gateway | Secret | Endpoint/Deployment | Status |
|---|---:|---:|---:|---|---|
| Mock | ja | ja | nein | intern | ✅ Testprovider, kein automatischer Fallback |
| Ollama | ja | ja | nein | Endpoint + Modell | ✅ lokale/private Hosts erlaubt |
| Azure AI / Azure OpenAI | ja | ja | ja | Endpoint + Deployment + API-Version | ✅ produktiv nutzbar |
| OpenAI | ja | ja | ja | `/v1/chat/completions` kompatibel | ✅ produktiv nutzbar |
| Mistral AI | ja | ja | ja | `https://api.mistral.ai/v1` | ✅ produktiv nutzbar |
| OpenRouter | ja | ja | ja | `https://openrouter.ai/api/v1` | ✅ produktiv nutzbar |

Wichtig: Diese Liste beschreibt austauschbare Adapter. **Aktiv ist immer nur genau ein Provider.**

## Providerabhängige Modell- und Settings-Auswahl

Seit `2.9.709` besitzt der Core einen zentralen Modellkatalog in `AiSettingsService`:

| Provider | Modelloptionen im Admin | Providerabhängige Pflicht-/Zusatzfelder |
|---|---|---|
| Mock | `mock-local-v1` | keine externen Felder |
| OpenAI | `gpt-5.3`, `gpt-5.4`, `gpt-5.5` | Endpoint + API-Key |
| Azure AI | `gpt-5.3`, `gpt-5.4`, `gpt-5.5` | Endpoint + Deployment + API-Version + API-Key |
| Mistral AI | `mistral-small-latest`, `mistral-large-latest`, `mistral-medium-latest`, `codestral-latest` | Endpoint + API-Key |
| Ollama | `llama3.1:8b`, `llama3.2:3b`, `mistral:7b`, `qwen2.5:7b` | lokaler/interner Endpoint |
| OpenRouter | `openai/gpt-5.3`, `openai/gpt-5.4`, `openai/gpt-5.5`, Mistral-/Llama-Optionen | Endpoint + API-Key |

Freitextmodelle werden nicht mehr gespeichert. Unbekannte oder veraltete Modelle werden serverseitig auf den Provider-Default normalisiert. Damit können alte GPT-4.x-Werte weder im Admin-Dropdown gewählt noch als Gateway-Fallback verwendet werden.

## Entfernte/refaktorisierte Multi-Provider-Logik

| Früherer Punkt | Maßnahme | Ergebnis |
|---|---|---|
| Provider-Liste mit Add/Delete | Admin-UI auf `Single AI Provider` umgestellt | keine Parallelkonfiguration mehr sichtbar |
| `add_provider` / `delete_provider` Actions | aus Router-Allowed-Actions entfernt; Modulmethoden blockieren Altaufrufe | kein normaler Admin-Flow für Multi-Provider |
| `fallback_provider_id` / Fallback-Felder | beim Speichern aus Settings gelöscht; Gateway ignoriert sie | keine Runtime-Fallbacks |
| Gateway-Kandidatenloop | durch direkte Auflösung von `active_provider_id` ersetzt | genau ein Provider pro Request |
| `resolved_via` Telemetrie | durch `selection_mode=single-provider` ersetzt | Audit/History beschreibt Single-Provider-Modus |
| Azure Request ohne Pflichtfelder | `assertReady()` ergänzt | sichtbarer Fehler bei fehlendem Endpoint/Deployment/API-Key |

## Zielstruktur nach Umsetzung

```text
Admin AI Settings
  └─ speichert genau einen Provider
      ├─ provider type
      ├─ API key / secret
      ├─ endpoint
      ├─ model
      └─ Azure deployment + API version optional/typabhängig

AiSettingsService
  └─ normalisiert auf genau einen aktiven Entry

AiProviderGateway
  └─ lädt nur active_provider_id
      ├─ translateBatch()
      ├─ generateContentDraft()
      ├─ generateSeoDraft()
      └─ testActiveProvider()

Provider Adapter
  ├─ Mock
  ├─ Ollama
  ├─ AzureOpenAi
  └─ OpenAiCompatible (OpenAI/Mistral/OpenRouter)
```

## Konkrete Codeänderungen

- `CMS/core/Services/AI/AiSettingsService.php`
  - `saveProviders()` speichert genau einen aktiven Provider.
  - `normalizeProviders()` kollabiert Alt-/Mehrfacheinträge auf einen aktiven Eintrag.
  - Alte `fallback_provider_id`-/`fallback_provider`-Settings werden beim Speichern gelöscht.
  - Secrets nicht aktiver Provider werden bereinigt.
  - Providerabhängiger Modellkatalog, Settings-Feldschema und `normalizeProviderModel()` ergänzt.

- `CMS/core/Services/AI/AiProviderGateway.php`
  - `resolveProvider()` und `resolveProviderForCapability()` nutzen nur noch den aktiven Provider.
  - Fallback-/Auto-Fallback-Loop entfernt.
  - `testActiveProvider()` ergänzt; der Admin-Test läuft über denselben zentralen Runtime-Pfad.
  - Provider-Metadaten melden `selection_mode=single-provider`.
  - Gateway-Fallbackmodelle auf GPT-5.x-/Mistral-konforme Defaults umgestellt.
  - `allowed_locales` wird nur noch für Translation-Zielsprachen geprüft; Content-/SEO-Previews dürfen z. B. mit Locale `de` laufen.

- `CMS/core/Services/AI/Providers/AzureOpenAiProvider.php`
  - `assertReady()` ergänzt.
  - Fehlende Azure-Pflichtfelder führen vor dem HTTP-Request zu klaren Fehlern.

- `CMS/admin/modules/system/AiServicesModule.php`
  - `saveProviders()` verarbeitet genau einen Provider.
  - Provider-Modelle werden gegen den zentralen Katalog normalisiert.
  - `testProvider()` speichert und testet den aktiven Provider zentral.
  - Add/Delete-Altmethoden geben klare Fehler zurück.
  - Audit-Metadaten auf `selection_mode` umgestellt.

- `CMS/admin/ai-page.php`
  - `test_provider` als Settings-Action ergänzt.
  - `add_provider` und `delete_provider` aus erlaubten Actions entfernt.

- `CMS/admin/views/system/ai-services.php`
  - Multi-Provider-Karten durch `Single AI Provider`-Form ersetzt.
  - Felder für Provider-Typ, API-Key, Endpoint, Modell, Azure Deployment/API-Version und Feature-Switches gebündelt.
  - Modellfeld von Freitext auf providerabhängiges Dropdown umgestellt.
  - Endpoint, Secret, Deployment und API-Version werden je nach Provider-Typ sichtbar/unsichtbar gesteuert.
  - Providerwechsel synchronisiert `active_provider_id` und Entry-ID-Hidden-Felder auf den gewählten Typ.
  - UI weist explizit aus: kein Fallback, keine Parallelprovider.

- `CMS/admin/modules/system/AiEditorJsTranslationModule.php`
  - Logging-Metadaten auf `selection_mode=single-provider` umgestellt.

- `CMS/DOC/ai/AI-SERVICES.md`
  - Architektur auf Single-Provider-Vertrag aktualisiert.

- `CMS/DOC/admin/system-settings/AI-SERVICES.md`
  - Admin-Kontext auf Single-Provider-Steuerung aktualisiert.

## Admin-Konzept

Der Admin steuert jetzt genau diese Werte:

1. Provider-Typ (`mock`, `ollama`, `azure_openai`, `openai`, `mistral`, `openrouter`)
2. Anzeigename
3. Modell aus providerabhängigem Dropdown
4. API-Key/Secret
5. Endpoint
6. Azure Deployment und API-Version, falls Azure gewählt ist
7. erlaubte Zielsprachen und Capability-Switches
8. `Provider speichern & testen`

Der Test nutzt `AiProviderGateway::testActiveProvider()` und ist damit kein Sonderweg. Das ist wichtig, weil die Testsituation denselben zentralen Codepfad nutzt wie EditorJS-Übersetzungen und Generatoren.

## Ergebnisbewertung

| Kriterium | Status | Kommentar |
|---|---:|---|
| Genau ein aktiver Provider | ✅ erfüllt | Settings und Admin speichern nur einen Entry |
| Kein Fallback | ✅ erfüllt | Gateway enthält keine Fallback-Auswahl mehr |
| Keine Parallelprovider | ✅ erfüllt | UI und Save-Pipeline verhindern Providerlistenbetrieb |
| Provider austauschbar | ✅ erfüllt | Adapter bleiben sauber abstrahiert |
| Admin konfiguriert Provider/API-Key/Modell | ✅ erfüllt | zentrale Single-Provider-Karte mit providerabhängigem Modell-Dropdown |
| Keine GPT-4.x-Modelle | ✅ erfüllt | Defaults, Gateway-Fallbacks und Admin-Dropdown sind auf GPT-5.x/Mistral/Ollama/OpenRouter-Katalog begrenzt |
| Content/SEO in DE mit Single Provider | ✅ erfüllt | Locale-Whitelist blockiert nur Translation-Zielsprachen, nicht deutsche Generator-Previews |
| EditorJS nutzt zentralen AI-Service | ✅ erfüllt | Endpoint geht über `AiProviderGateway` |
| Direkte API-Calls außerhalb Provider-Adapter | ✅ geprüft | keine AI-API-Calls außerhalb der Adapter gefunden |
| Doku/Audit aktualisiert | ✅ erfüllt | Audit + CMS-Doku aktualisiert |

## Verbleibende Follow-ups

- Echte Tokenkosten/Usage-Metriken hängen von Provider-Antworten ab und sollten später providerweise normalisiert werden.
- CI-Verkabelung des manifestierten Test-Runners bleibt ein allgemeines Tooling-Follow-up.
- Optional: historische `audit_log`-Einträge können noch alte `resolved_via`-Metadaten enthalten; neue Läufe schreiben `selection_mode`.

## Validierung

- `php -l` für alle geänderten AI-/Test-PHP-Dateien → **PASS**.
- `php TESTS\run.php --suite=ai-services` → **PASS**.
- `php TESTS\run.php --suite=content-language-copy` → **PASS** als Regression für den zuvor reparierten DE→EN-Flow.
- VS-Code-Diagnostik für geänderte AI-/Test-/Audit-Dateien → **PASS**.
