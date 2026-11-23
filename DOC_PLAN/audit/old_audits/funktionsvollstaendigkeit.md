# Funktionsvollständigkeit

**Bereichsscore:** 98/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
Die Funktionsabdeckung bleibt breit und wurde im aktuellen Schritt gezielt stabilisiert: Analytics/Tracking besitzt ein sichtbares Vollständigkeitsmodell (`configured/partial/missing`) mit Placeholder-Blockierung, die Media-Einstellungen bieten eine explizite Member-Upload-Readiness-Checkliste, und der AI/KI-Bereich ist nun logisch aufgebaut sowie als Single-Provider-Architektur für Azure AI, Mistral AI, OpenAI, OpenRouter, Ollama und Mock umgesetzt. Inhalts- und SEO-Assistent erzeugen echte serverseitige Preview-Ausgaben statt nur Prompt-Settings zu verwalten. Die AI-Settings nutzen jetzt providerabhängige Felder und ein validiertes Modell-Dropdown ohne Legacy-GPT-Modelle. Der DE→EN-Copy-Flow für Seiten und Beiträge wurde erneut end-to-end geprüft und um zusätzliche Root-Cause-Fixes für Slug-Normalisierung, Inline-SEO-Drafts, EditorJS-Revisionsvergleich, EditorJS-Asset-Loading bei neuen Inhalten und bootstrap-sichere EditorJS-Asset-URLs in Service sowie Page-/Post-Routern gehärtet. Nach wiederholtem Live-Fallback wurde die Page-/Post-EditorJS-Integration vollständig neu aufgebaut: `admin/partials/editorjs-inline-boot.php` wird direkt in den Edit-Views gerendert und übernimmt Initialisierung, Fallback-Umschaltung, Hidden-JSON-Sync und Submit-Serialisierung ohne zusätzliche kritische externe Boot-Datei.

Offen bleibt vor allem die CI-Verkabelung der jetzt zentral verfügbaren Testsuite; die PDF-/Dependency-Story ist durch vorhandenen `PdfService` + VendorRegistry-Einbindung und Smoke-Test deutlich konsolidiert.

## Score-Begründung
- Startwert 100
- FUNC-001: 0 (abgeschlossen)
- FUNC-002: -2 (teilweise reduziert)
- FUNC-003: 0 (abgeschlossen)
- FUNC-004: -3 (teilweise reduziert)
- FUNC-005: 0 (abgeschlossen, erweitert)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Status | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|---|
| FUNC-001 | Analytics/Consent | Tracking | Placeholder IDs | niedrig | 0 | ✅ abgeschlossen | CMS\admin\modules\seo\SeoSuiteModule.php; CMS\admin\views\seo\analytics.php | Codefund |
| FUNC-002 | Member/Media | Uploads | Member Uploads | niedrig | -2 | 🟨 teilweise reduziert | CMS\admin\views\media\settings.php (Readiness-Checkliste), CMS\core\Services\FileUploadService.php | Codefund |
| FUNC-003 | Dependencies | PDF/Reports | dompdf vendored | niedrig | 0 | ✅ abgeschlossen | `CMS\core\Services\PdfService.php`, `CMS\core\VendorRegistry.php`, `TESTS\pdf-service\run.php` | Codefund |
| FUNC-004 | Tests | Modultests | TESTS-Verzeichnis | niedrig | -3 | 🟨 teilweise reduziert | `TESTS\run.php` + `TESTS\bootstrap.php` vorhanden; CI-Verkabelung noch offen | Heuristik |
| FUNC-005 | AI/KI | Provider, Admin & Generatoren | Single Provider, Azure/Mistral/OpenAI-kompatible APIs, Content-/SEO-Previews | niedrig | 0 | ✅ abgeschlossen | `CMS\core\Services\AI\*`, `CMS\admin\views\system\ai-services.php`, `TESTS\ai-services\run.php` | Codefund/Test |
| FUNC-006 | Content | DE→EN Copy | Seiten/Beiträge inkl. EditorJS, SEO, Metadaten, Medienreferenzen und Sprachrelation | niedrig | 0 | ✅ abgeschlossen | `CMS\core\Services\ContentLanguageCopyService.php`, `CMS\admin\pages.php`, `CMS\admin\posts.php`, `TESTS\content-language-copy\run.php` | Codefund/Test |

## Umsetzungsschritte

### step-001
- **Ziel:** Placeholder-Konfigurationen als unvollständig kennzeichnen.
- **Befund:** ✅ umgesetzt.
- **Risiko:** deutlich reduziert.
- **Technische Ursache:** adressiert durch Save-Validierung + Statusmodell.
- **Lösungsweg:** Feature-Statusmodell mit `configured/partial/missing`, sichtbare Integrationsmatrix im Analytics-UI, Blockierung ungültiger IDs beim Speichern sowie Legacy-/manuell gesetzte ungültige Kennungen als `partial` statt `configured`.
- **Betroffene Dateien:** CMS\admin\modules\seo\SeoSuiteModule.php, CMS\admin\views\seo\analytics.php.
- **Priorität:** P2
- **Aufwand:** S
- **Abhängigkeiten:** Settings-Modul.

### step-002
- **Ziel:** Member-Uploads vollständig produktionsreif absichern.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** reduziert, aber nicht vollständig eliminiert.
- **Technische Ursache:** UI-Transparenz jetzt vorhanden, weiterführende Quota-/Moderationsnachweise noch nicht zentralisiert.
- **Lösungsweg:** Readiness-Checkliste in den Media-Settings ergänzt (Enable-Status, Limits, Typen, Sicherheitskontrollen); Quota-/Moderationsabdeckung als nächste Etappe offen.
- **Betroffene Dateien:** CMS\core\Services\FileUploadService.php, CMS\admin\modules\media.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Media Settings.

### step-003
- **Ziel:** PDF-/Dokumentfunktionen konsolidieren.
- **Befund:** ✅ umgesetzt.
- **Risiko:** deutlich reduziert.
- **Technische Ursache:** durch zentrale Service-/Registry-Pfade entschärft.
- **Lösungsweg:** `PdfService` nutzt `VendorRegistry::loadPackage('dompdf')`; ergänzender Smoke-Test in `TESTS\pdf-service\run.php` dokumentiert Runtime-Fähigkeit.
- **Betroffene Dateien:** CMS\core\Services\PdfService.php, CMS\core\VendorRegistry.php, TESTS\pdf-service\run.php.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Dependency-Management.

### step-004
- **Ziel:** Vollständigkeit über Tests nachweisen.
- **Befund:** 🟨 teilweise umgesetzt.
- **Risiko:** Neue oder optionale Features bleiben ohne einheitliche Abnahmekriterien.
- **Technische Ursache:** Tests sind verzeichnisbasiert, nicht als zentrale Pipeline erkennbar.
- **Lösungsweg:** Zentralen Runner (`TESTS/run.php`) und gemeinsames Bootstrap (`TESTS/bootstrap.php`) etabliert; nächste Etappe: CI-Job + feste Gates.
- **Betroffene Dateien:** TESTS\*, CMS\*.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** CI.

### step-005
- **Ziel:** AI/KI-Module auf logischen Adminaufbau und Provider-Vollständigkeit prüfen.
- **Befund:** ✅ umgesetzt.
- **Risiko:** deutlich reduziert; Azure AI, Mistral AI, OpenAI, OpenRouter, Ollama und Mock sind sichtbar, aber gemäß Zielarchitektur ist immer genau ein Provider aktiv. Content-/SEO-Ausgaben laufen als Preview mit redaktioneller Freigabe.
- **Technische Ursache:** zuvor waren OpenAI/OpenRouter nur vorbereitet, Mistral fehlte im Provider-Katalog und die UI ließ mehrere Provider parallel/fallbackartig denken.
- **Lösungsweg:** `OpenAiCompatibleProvider` ergänzt, Provider-Katalog erweitert, Gateway-Factory und Readiness-Prüfung angepasst, generische `generateText()`-Fähigkeit plus `generateContentDraft()`/`generateSeoDraft()` ergänzt, Admin-Hinweise/Formulare auf Single Provider aktualisiert, providerabhängigen Modellkatalog mit Dropdown ergänzt und `TESTS\ai-services\run.php` erweitert.
- **Betroffene Dateien:** CMS\core\Services\AI\*, CMS\admin\views\system\ai-services.php, TESTS\ai-services\run.php.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** API-Key/Endpoint-Konfiguration pro Provider.

### step-006
- **Ziel:** DE→EN-Kopierfunktion für Seiten und Beiträge vollständig end-to-end prüfen und Root Causes statt Workarounds beheben.
- **Befund:** ✅ umgesetzt.
- **Risiko:** deutlich reduziert; Copy läuft serverseitig deterministisch über eine zentrale Payload-Builder-Schicht und erhält den ursprünglichen Formular-Submitter auch bei EditorJS-Sync.
- **Technische Ursache:** zusätzlich zum bereits behobenen Client-only-Copy waren fünf Restfehler vorhanden: Slug-Normalisierung im Copy-Service war schwächer als die System-Slugger, Inline-Fehler-Renderdaten enthielten EN-SEO-Felder nicht vollständig, der EditorJS-Revisionsvergleich prüfte einen nicht berechneten `sha1`-Key statt `sha256`, mehrere „Neue Seite/Neuer Beitrag“-CTAs verlinkten auf `action=new`, obwohl die Router nur `action=edit` als Editor-View mit EditorJS-Assets akzeptierten, und EditorJS-/Edit-Assets setzten `cms_asset_url()` in mehreren Bootstrappfaden zu früh hart voraus. Zusätzlich wurden die Plain-Textarea-Fallbacks in Page-/Post-Edit-Views schon serverseitig versteckt/deaktiviert, bevor der EditorJS-JavaScript-Boot tatsächlich erfolgreich war. Die Live-Nachprüfung zeigte danach noch einen Root-Cause im Formular-/Boot-Vertrag: aktives Hidden-JSON-Feld und aktiver Plain-Fallback nutzten gleichzeitig denselben `name` (`content`/`content_en`), während `admin-content-editor.js` den Plain-Fallback bereits zu `bind-start` versteckte und der Init-Watchdog bei leerem Editor nur loggte statt den Fallback wieder sichtbar zu machen. Die erneute Meldung „Inhalt (DE) – Plain-Fallback“ zeigte zusätzlich, dass der sichere Fallback korrekt stehen bleibt, der JS-Boot aber in bestimmten Ladefolgen nicht in den Enhanced-Zustand wechselte. Wegen des FTP-Root-Deployments wurde der finale EditorJS-Boot direkt in die Page-/Post-Views gelegt, damit keine zusätzliche externe Boot-Datei als Fehlerquelle existiert.
- **Lösungsweg:** Slug-Transliteration für deutsche Umlaute/ß und häufige Akzente im `ContentLanguageCopyService` ergänzt; `meta_title_en`/`meta_description_en` in Page-/Post-Inline-Draftdaten aufgenommen und sprachabhängig geschützt; EditorJS-Revisionsdiff auf vorhandene `sha256`-Summaries umgestellt; Pages-/Posts-Router akzeptieren `new/create/add` als Edit-Alias; Dashboard-/Topbar-CTAs nutzen `action=edit`; EditorJS-Asset-Service sowie Page-/Post-Router erzeugen Editor-/SEO-Asset-URLs nun auch ohne bereits geladenen globalen Asset-Helper und nutzen vorhandene runtime-aware `cms_assets_url()`-Helper; Page-/Post-Edit-Views blenden die Plain-Textarea nicht mehr serverseitig aus, sondern lassen das aktive Plain-Feld den Submit-Namen bis zum erfolgreichen EditorJS-Boot besitzen; aktive Hidden-JSON-Felder tragen nur `data-editor-submit-name`. Neu eingeführt wurde `CMS/admin/partials/editorjs-inline-boot.php`: Der Inline-Boot wird direkt nach `contentEditorEditorJsConfig` in den Page-/Post-Views gerendert, wartet selbst auf `window.EditorJS` plus `window.createCmsEditor`, initialisiert nur die sichtbaren Page-/Post-Holder, schaltet den Plain-Fallback erst nach `onReady`/`isReady` oder nachweislich gerendertem EditorJS-DOM ab, synchronisiert Änderungen live in das Hidden-JSON-Feld und serialisiert vor jedem Formular-Submit. `admin-content-editor.js` erkennt `window.cmsInlineEditorJsBootState` und delegiert den EditorJS-Boot, damit keine doppelte Initialisierung mehr stattfindet. Smoke-Suite um Routing, CSRF-Kontext, Submitter-Erhalt, Hidden-JSON-Sync, Copy-Buttons, EditorJS-Asset-Loading, bootstrap-sichere Asset-URL-Erzeugung, Inline-Boot, gerendertes-EditorJS-DOM im Watchdog, aktiven Submit-Namen-Vertrag, Inline-SEO-Drafts, Renderer-/Schema-Pfade und Revisionsvergleich erweitert.
- **Betroffene Dateien:** CMS\core\Services\ContentLanguageCopyService.php, CMS\core\Services\EditorJs\EditorJsAssetService.php, CMS\admin\pages.php, CMS\admin\posts.php, CMS\admin\modules\pages\PagesModule.php, CMS\admin\modules\posts\PostsModule.php, TESTS\content-language-copy\run.php.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** bestehende Tabellen-Spalten für `title_en`, `slug_en`, `content_en`, `excerpt_en`, `meta_title_en`, `meta_description_en`; keine neue Migration erforderlich.

## Update 2026-06-13 (AI/KI Provider-Vollständigkeit)
- AI/KI-Adminbereich bestätigt: Dashboard, Übersetzung, Inhaltsassistent, SEO-Assistent und Einstellungen bilden einen logischen Aufbau.
- `OpenAiCompatibleProvider` ermöglicht OpenAI-kompatible Chat-Completions für OpenAI, Mistral AI und OpenRouter.
- Azure AI / Azure OpenAI bleibt mit Endpoint, Deployment, API-Version und API-Key als eigener Provider verdrahtet.
- Die Admin-Speicherung erzwingt genau einen aktiven Provider; Fallback-/Parallelprovider-UI wurde entfernt.
- Die Modellwahl ist providerabhängig (`gpt-5.3/5.4/5.5`, Mistral-, Ollama- und OpenRouter-Optionen); alte GPT-4.x-Defaults/Fallbacks wurden entfernt.
- Provider-Allowed-Locales gelten nur noch für Translation-Zielsprachen; Content- und SEO-Previews können mit demselben aktiven Provider auch auf `DE` laufen.
- Inhalts- und SEO-Assistenten erzeugen jetzt serverseitige Preview-Ausgaben über Provider-Capability-Gates; automatische Veröffentlichung findet nicht statt.
- Validiert: `php TESTS\run.php --suite=ai-services` → **PASS**.

## Update 2026-06-13 (DE→EN Copy End-to-End)

### Annahmen
- Für den 365CMS-Core zählt ausschließlich `365CMS.DE/CMS/**`; Testdateien unter `TESTS/**` sind Validierungsnachweis.
- Der Copy-Flow ist eine strukturierte DE→EN-Kopie, keine maschinelle Übersetzung: Titel, Slug, EditorJS-Inhalt, SEO, Status, Metadaten, Medienreferenzen und Sprachrelationen werden deterministisch übernommen.
- Medienreferenzen werden erhalten und nicht physisch dupliziert; temporäre Upload-Relocation bleibt Aufgabe des Save-Flows.

### Fehlerursachen
- Die Slug-Normalisierung im zentralen Copy-Service entfernte deutsche Umlaute/ß zu schwach bzw. inkonsistent gegenüber den System-Sluggern.
- Inline-Renderdaten nach Validierungsfehlern oder Sprachwechsel enthielten `meta_title_en` und `meta_description_en` nicht vollständig, wodurch EN-SEO-Drafts verloren oder leer angezeigt werden konnten.
- EditorJS-Revisionsvergleiche nutzten einen veralteten `sha1`-Array-Key, obwohl die Summary-Funktion `sha256` berechnet.
- Der frühere Client-Copy war bereits ersetzt; die erneute Prüfung bestätigte, dass der aktuelle POST-Flow den serverseitigen Submitter erhält und alle EditorJS-Hidden-JSON-Felder vor dem Submit synchronisiert.

### Betroffene Dateien/Funktionen
- `CMS\core\Services\ContentLanguageCopyService.php`: `buildPageGermanToEnglishPayload()`, `buildPostGermanToEnglishPayload()`, `normalizeEditorJson()`, `normalizeSlug()`, `buildSeoRelationPayload()`.
- `CMS\admin\pages.php`: `cms_admin_pages_build_inline_edit_data()`, `post_handler` für `copy_de_to_en`, `cms_admin_pages_target_url()`.
- `CMS\admin\posts.php`: `cms_admin_posts_build_inline_edit_data()`, `post_handler` für `copy_de_to_en`, `cms_admin_posts_target_url()`.
- `CMS\admin\modules\pages\PagesModule.php`: `copyGermanToEnglish()`, Save-Preservation, EditorJS-Revisionsdiff.
- `CMS\admin\modules\posts\PostsModule.php`: `copyGermanToEnglish()`, Save-Preservation, Tag-/Kategorie-Sync, EditorJS-Revisionsdiff.
- `CMS\assets\js\admin-content-editor.js`: Submitter-Erhalt, `Promise.all(...saveEditorContent...)`, `requestSubmit(resolvedSubmitter)`, `suppressPlainEditorSubmitNames()`.

### Konkrete Codeänderungen
- `normalizeSlug()` im Copy-Service transliteriert jetzt Umlaute/ß und häufige Akzente (`ä→ae`, `ö→oe`, `ü→ue`, `ß→ss` usw.) vor dem ASCII-Slug-Cleanup.
- Inline-Draftdaten für Seiten und Beiträge enthalten und bewahren `meta_title_en` und `meta_description_en` sprachabhängig.
- Beim Bearbeiten der EN-Sicht werden DE-SEO-Felder aus dem Bestand geschützt; beim Bearbeiten der DE-Sicht werden EN-SEO-Felder aus dem Bestand geschützt.
- EditorJS-Revisionsvergleich in Page-/Post-Modulen vergleicht nun die tatsächlich erzeugten `sha256`-Summaries.
- Neue Seiten/Beiträge laden EditorJS wieder über die Edit-Route: `action=new/create/add` wird serverseitig als `edit` normalisiert und Admin-CTAs zeigen direkt auf `action=edit`.
- `EditorJsAssetService::buildAssetUrl()` sowie die Page-/Post-Asset-Helper besitzen jetzt robuste Fallbacks für Bootstraps, in denen `cms_asset_url()` noch nicht geladen ist; Versionierung per `filemtime()` bleibt erhalten, wenn `ASSETS_PATH` verfügbar ist.
- Page-/Post-Edit-Views verstecken/deaktivieren die Plain-Textarea nicht mehr serverseitig; aktive Hidden-JSON-Felder besitzen initial keinen Submit-`name`, sondern `data-editor-submit-name`, damit No-JS/Fallback-Saves eindeutig über das sichtbare Plain-Feld laufen.
- `CMS/admin/partials/editorjs-inline-boot.php` ersetzt den Page-/Post-EditorJS-Initialisierungspfad vollständig: Runtime-Wait, `createCmsEditor()`-Aufruf, Enhanced-/Fallback-Umschaltung, Live-Sync, Submit-Serialisierung, `data-confirm` für serverseitige Copy-Actions und Debug-State unter `window.cmsInlineEditorJsBootState`.
- `admin-content-editor.js` delegiert EditorJS an den Inline-Boot (`[EJS-BRIDGE-DELEGATED]`) und bleibt für übrige UI-/SEO-Initialisierung verfügbar.
- Nachprüfung 2026-06-13: Die sichtbare Plain-Ebene ist nicht mehr als „EditorJS Notfall-Fallback“ beschriftet, sondern neutral als sichere Speicherebene. `editor-init.js` meldet Runtime-Bereitschaft erst, wenn sowohl `window.EditorJS` als auch `window.createCmsEditor` verfügbar sind. Externe Advanced-Tools (`quote`, `code`, `table`, `delimiter`) sind im Boot-Vertrag optional, damit ein einzelnes fehlendes/zu spät geladenes UMD-Tool den gesamten Block-Editor nicht mehr in den Fallback zwingt.
- `TESTS\content-language-copy\run.php` prüft zusätzlich Router, CSRF-Shell-Kontext, EN-Redirect, Copy-Buttons, `formnovalidate`, Bestätigung, Hidden-Originalfelder, Submitter-Erhalt, Hidden-JSON-Sync, EditorJS-Asset-Service-Fallback, Inline-Boot, Delegation, gerendertes-EditorJS-DOM im Watchdog, aktiven Submit-Namen-Vertrag und Inline-SEO-Drafts.

### DB/Migrationen
- Keine neue Migration erforderlich.
- Bereits vorhandene/abgesicherte Spalten werden weiter genutzt: `meta_title_en`, `meta_description_en`, `title_en`, `slug_en`, `content_en`, `excerpt_en`.
- Sprachrelation läuft über vorhandenes SEO-Meta-Feld `hreflang_group` mit deterministischer Gruppe (`page-{id}` / `post-{id}`), wenn leer.

### Tests
- `php -l` auf allen geänderten PHP-Dateien → **PASS**.
- `node --check CMS\assets\js\admin-content-editor.js` → **PASS**.
- `node --check CMS\assets\js\editor-init.js` → **PASS**.
- `php TESTS\run.php --suite=content-language-copy` → **PASS**.
- Regression: `php TESTS\run.php --suite=ai-services` → **PASS**.
- VS-Code-Diagnostics für geänderte Copy-/Routing-/Modul-/View-/Asset-/Testdateien → **keine Fehler**.

### Manuelle Prüfliste
- Seite mit DE-Titel/Inhalt/SEO/Featured Image öffnen, „DE → EN kopieren“ auslösen, Bestätigung akzeptieren, danach EN-Edit-Ansicht prüfen.
- Beitrag mit Teaser, Tags, Kategorie, Template-Metadaten, Veröffentlichungsdatum und Featured Image kopieren und EN-Felder prüfen.
- Slugs mit Umlauten testen, z. B. `Große Übersicht` → `grosse-uebersicht`.
- Nach Copy speichern und Frontend/Preview auf EN-Lokalisierung, SEO-Titel/-Beschreibung und `hreflang_group` prüfen.
- EditorJS-Inhalte mit mehreren Blöcken und Medienblöcken kopieren; Hidden-Felder dürfen weder leer noch durch Plain-Fallback überschrieben werden.
- Validierungsfehler absichtlich auslösen und kontrollieren, dass EN-SEO-Drafts im Inline-Render erhalten bleiben.
