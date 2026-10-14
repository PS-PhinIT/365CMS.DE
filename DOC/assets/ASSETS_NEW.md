# ASSETS NEW – Integrationsdoku für neue Kandidaten
> **Stand:** 2026-10-04 | **Version:** 3.5.0 | **Status:** Aktuell

## Inhaltsverzeichnis
- <a>Überblick</a>
- <a>Symfony AI Platform</a>
- <a>Google Translate Asset entfernt</a>
- <a>AI Services in 365CMS</a>
- <a>Provider-Scope und Feature-Gates</a>
- <a>Phase 1: Translate Service für Editor.js</a>
- <a>Weitere sinnvolle Unterpunkte unter AI Services</a>
- <a>Empfohlene Integrationsregeln für 365CMS</a>
- <a>Kurzfazit</a>

---

## Überblick <!-- UPDATED: 2026-04-08 -->

Diese Datei dokumentiert **neue Asset-Kandidaten und Architekturentscheidungen rund um noch nicht oder nur teilweise produktiv integrierte Funktionspakete**.

Aktueller Bewertungsstand:

| Paket / Thema | Quelle | Status | Empfehlung |
|---|---|---|---|
| `symfony/ai-platform` | `CMS/assets/ai-platform/` (Quelle: `CMS_ASSETS/ai-0.14.1.zip`, `src/platform/`) | produktive Basis aktiv, Bridges weiter separat | nur über klaren Core-Adapter, Provider-Bridges und Admin-Feature-Gates einführen |
| `stichoza/google-translate-php` | **aus `/ASSETS` entfernt am 2026-04-08** | kein aktiver Kandidat mehr | nicht weiter als Staging-Asset führen; offizielle oder austauschbare Provider-Adapter bevorzugen |

Grundsatz: **Nicht jedes Paket unter `/ASSETS` gehört automatisch nach `CMS/assets/` oder `CMS/vendor/`.** Neue Kandidaten müssen fachlich, technisch, betrieblich und dokumentarisch bewertet werden. Auch wenn eine Basis wie `symfony/ai-platform` jetzt produktiv gespiegelt wird, bleiben Brücken, Provider und Zusatzabhängigkeiten eigene Integrationsentscheidungen.

Für den aktuellen Wunschstand ist vor allem wichtig:

- AI-Funktionen sollen **nicht** als lose Einzelbibliothek einwandern, sondern als kontrollierter Bereich **„AI Services“** unter **System / Einstellungen** geplant werden.
- Der **Provider-Scope** soll steuern, **welche Provider aktiv sind** und **welche Funktionen** ein Provider im Admin überhaupt ausführen darf.
- Die **erste sinnvolle Ausbaustufe** ist **kein Chatbot**, sondern ein **Translate Service im Admin**, der bereits vorhandene Editor.js-Inhalte kontrolliert nach **Englisch** umwandeln kann.

---

## Symfony AI Platform <!-- UPDATED: 2026-04-08 -->

### Paketprofil

- **Paket:** `symfony/ai-platform`
- **Produktiver Basispfad:** `CMS/assets/ai-platform/`
- **Quelle:** `CMS_ASSETS/ai-0.14.1.zip` (Monorepo `symfony/ai`, Unterordner `src/platform/`)
- **Gebündelte Version:** `0.14.1` (nur Basis, ohne `src/Bridge/`)
- **Nutzung im Code (Stand 3.4.19):** nur die Funktionsprobe in `Diagnose → Assets`; die AI-Services arbeiten noch mit eigenen HTTP-Aufrufen. Die Basis bleibt bewusst als vorbereiteter Unterbau für künftige Core-Adapter gebündelt.
- **Pakettyp:** PHP-Library / AI-Abstraktionsschicht
- **Status laut README:** **experimentell**
- **PHP-Anforderung laut `composer.json`:** `>= 8.2`

### Was das Paket fachlich macht

`symfony/ai-platform` ist **keine einzelne Modellintegration**, sondern eine **Abstraktionsschicht für Modelle, Provider, Contracts, Tools und Result-Formate**. Das Paket selbst ist damit eher eine **technische Grundplatte** als eine sofort sichtbare Endfunktion.

Laut README wird die eigentliche Plattformanbindung über zusätzliche Bridge-Pakete hergestellt, z. B. für:

- OpenAI
- Azure OpenAI
- Anthropic
- Gemini
- Ollama
- OpenRouter
- Vertex AI
- Bedrock
- Mistral
- weitere spezialisierte Provider

Damit passt das Paket gut zu einer 365CMS-Vorstellung von **mehreren aktivierbaren Providern** statt einer fest verdrahteten Einzellösung.

### Warum die produktive Basis **nicht** mit einer vollständigen Provider-Integration verwechselt werden darf

Aus `composer.json` ergibt sich, dass bereits das Basis-Paket zusätzliche Komponenten verlangt, die aktuell **noch nicht vollständig** Teil des produktiven 365CMS-Runtime-Vertrags unter `CMS/assets/` sind:

- `symfony/clock`
- `symfony/event-dispatcher`
- `symfony/property-access`
- `symfony/property-info`
- `symfony/serializer`
- `symfony/type-info`
- `symfony/uid`
- dazu Hilfsbibliotheken wie `oskarstark/enum-helper`, `phpdocumentor/reflection-docblock` und `phpstan/phpdoc-parser`

Praktische Konsequenzen trotz produktivem Basis-Bundle:

1. Das Basis-Paket allein reicht nicht.
2. Für echte Provider werden zusätzliche Bridges benötigt.
3. Secrets, Modellwahl, Quotas, Rate-Limits, Timeouts und Audit-Logging müssen separat geplant werden.
4. Die API ist experimentell und **nicht** vom normalen Symfony-BC-Versprechen gedeckt.

### Empfehlung für 365CMS

**Die Basis darf unter `CMS/assets/` liegen, aber nur als kontrollierte Core-Grundplatte.**

Empfohlen ist stattdessen eine interne Schicht wie:

- `CMS/core/Services/AI/`
- `CMS/core/Services/AI/Contracts/`
- `CMS/core/Services/AI/Providers/`
- `CMS/core/Services/AI/Policies/`
- `CMS/core/Services/AI/Prompts/`

Zentrale Rollen dieser Schicht:

- Provider registrieren
- Provider-Scope und Funktionsrechte prüfen
- Secrets sicher aus Konfiguration/Settings lesen
- Prompt- und Content-Transport protokollierbar, aber datensparsam gestalten
- Ergebnisse in 365CMS-spezifische Strukturen zurückführen

### Für 365CMS besonders passend

Das Paket ist dann interessant, wenn folgende Admin-Funktionen gezielt und schrittweise eingeführt werden:

- automatische Zusammenfassungen
- Prompt-basierte SEO-/Meta-Generierung
- Translation-/Rewrite-Helfer mit mehreren Providern
- spätere Assistenzfunktionen für redaktionelle Workflows

---

## Google Translate Asset entfernt <!-- UPDATED: 2026-04-08 -->

Das zuvor mitgeführte Paket `stichoza/google-translate-php` wurde **bewusst aus `/ASSETS` entfernt** und wird **nicht mehr** als aktiver Kandidat weitergeführt.

### Gründe für die Entfernung

Die frühere Bewertung bleibt fachlich wichtig:

- die Bibliothek arbeitet über **inoffizielle Website-/Crawling-Mechanik**
- sie benötigt zusätzlich `guzzle`
- laut README drohen **`429` / `503`**, **CAPTCHA-Anforderungen** und **IP-Sperren**
- es gibt ein hartes Praxislimit von rund **5000 Zeichen pro Request**
- der Disclaimer spricht ausdrücklich von **educational purposes**

Für eine künftige 365CMS-Kernfunktion ist das zu fragil. Deshalb gilt jetzt klar:

- **nicht mehr als Repo-Staging-Asset führen**
- **nicht** als Basis eines produktiven Übersetzungsdienstes einplanen
- maschinelle Übersetzung nur über **offizielle oder austauschbare Provider-Adapter** betrachten

Die Übersetzungsfunktion selbst bleibt fachlich relevant – nur **dieses konkrete Paket** ist dafür nicht mehr die gewünschte Basis.

---

## AI Services in 365CMS <!-- UPDATED: 2026-04-08 -->

### Zielbild im Admin

Wenn AI-Funktionen eingeführt werden, sollte dies als neuer Bereich **„AI Services“** unter dem bestehenden System-/Einstellungsumfeld erfolgen.

Empfohlene spätere Einordnung in der Admin-Navigation:

- `System`
	- `Einstellungen`
	- `Mail & Azure OAuth2`
	- `AI Services` *(geplant, noch nicht implementiert)*
	- `Module`
	- `CMS Logs`
	- `Backups`
	- `Updates`

Empfohlene Zielroute:

- `/admin/ai-services`

### Warum ein eigener Hauptpunkt sinnvoll ist

AI-Funktionen betreffen mehrere Querschnittsthemen gleichzeitig:

- Provider- und Secret-Verwaltung
- Feature-Gates und Provider-Scope
- Datenschutz- und Audit-Fragen
- Quota-/Kostenbegrenzung
- redaktionelle Assistenten in verschiedenen Modulen

Diese Themen passen fachlich **eher zu System/Einstellungen** als zu SEO, Content oder einem einzelnen Editor.

### Geplante Unterpunkte unter „AI Services“

Der Bereich sollte nicht mit einem einzigen Monolithen starten, sondern klein und kontrolliert:

| Unterpunkt | Status | Zweck |
|---|---|---|
| Übersicht / Provider Scope | zuerst sinnvoll | aktive Provider, Funktionen, Limits und Status zentral steuern |
| Translate Service | **Phase 1** | vorhandene Editor.js-Texte im Admin nach Englisch übersetzen |
| Automatische Zusammenfassungen | später | Inhalte, Auszüge oder Einleitungstexte komprimieren |
| SEO-/Meta-Generierung | später | Title, Description, OG-/Schema-Hilfen promptbasiert erzeugen |
| Rewrite-Helfer | später | Umformulieren, kürzen, formalisieren, Tonalität anpassen |

### Wichtiger Architekturgrundsatz

AI Services sollen in 365CMS **keine unkontrollierte Freitext-Blackbox** sein. Jede Funktion sollte:

- an **explizite Admin-Aktionen** gebunden sein
- den **Provider-Scope** respektieren
- nachvollziehbare Ein-/Ausgabegrenzen haben
- strukturierte Fehlermeldungen liefern
- keine Secrets oder Roh-Prompts unkontrolliert loggen

Für die ausführlichere Fach- und Architekturbeschreibung ist die kanonische Dokumentation unter [ai/AI-SERVICES.md](../ai/AI-SERVICES.md) vorgesehen. Der Admin-Kontext bleibt zusätzlich unter [admin/system-settings/AI-SERVICES.md](../admin/system-settings/AI-SERVICES.md) verlinkt.

---

## Provider-Scope und Feature-Gates <!-- UPDATED: 2026-04-08 -->

Der **Provider-Scope** soll steuern, **welcher Provider aktiv ist** und **was dieser Provider innerhalb von 365CMS überhaupt darf**.

### Kernidee

Nicht jeder aktivierte Provider darf automatisch alles. Die effektive Verfügbarkeit einer Funktion ergibt sich aus:

1. aktiviertem Provider
2. unterstützter Provider-Fähigkeit
3. freigeschalteter Funktion im Admin
4. gültigem Benutzer-/Capability-Kontext
5. inhaltlichen Guardrails (Sprache, Blocktyp, Zeichenlimit, Datensensibilität)

### Empfohlene Steuerungsdimensionen

| Scope | Bedeutung | Beispiele |
|---|---|---|
| `provider_enabled` | Provider grundsätzlich aktiv | OpenAI an, Ollama aus |
| `translation_enabled` | Übersetzen erlaubt | ja/nein |
| `rewrite_enabled` | Umformulieren erlaubt | ja/nein |
| `summary_enabled` | Zusammenfassungen erlaubt | ja/nein |
| `seo_meta_enabled` | SEO-/Meta-Generierung erlaubt | ja/nein |
| `editorjs_enabled` | Editor.js-Verarbeitung erlaubt | ja/nein |
| `allowed_locales` | Erlaubte Sprachen: Übersetzungsziele und Editorsprache für SEO-/Content-Entwürfe | z. B. `de,en` (nur `en` blendet AI-SEO im DE-Editor aus) |
| `max_chars_per_request` | Kosten-/Stabilitätsgrenze | z. B. 12.000 Zeichen |
| `max_blocks_per_request` | Strukturgrenze | z. B. 50 Blöcke |
| `beta_only` | nur für Testbetrieb | ja/nein |

### Was im Admin steuerbar sein sollte

Mindestens folgende Schalter und Felder sind sinnvoll:

- Provider aktiv / inaktiv
- Standardmodell pro Funktion
- Zielsprachen erlauben/verbieten
- Maximale Block- bzw. Zeichenzahl
- Retry-/Timeout-Strategie
- Logging-Level ohne Prompt-/Secret-Leak
- Fallback-Verhalten bei Teilausfällen

### Empfohlene Zielstruktur im Core

- `CMS/core/Services/AI/AiProviderInterface.php`
- `CMS/core/Services/AI/AiGatewayService.php`
- `CMS/core/Services/AI/AiProviderRegistry.php`
- `CMS/core/Services/AI/AiFeaturePolicy.php`
- `CMS/core/Services/AI/EditorJsTranslationService.php`

---

## Phase 1: Translate Service für Editor.js <!-- UPDATED: 2026-04-08 -->

### Fachliches Ziel

Der erste reale Nutzen soll **kein allgemeiner Chat** sein, sondern ein **kontrollierter Translate Service im Adminbereich**:

- Nutzer haben bereits Text in einen Editor.js-Inhalt eingefügt oder geschrieben.
- 365CMS kann diesen vorhandenen Inhalt gezielt nach **Englisch** umwandeln.
- Die Funktion arbeitet **nur im Admin**, **nicht** als Frontend-Live-Übersetzung.

### Erwartetes Verhalten

Der Service soll vorhandene Editor.js-Daten **strukturerhaltend** bearbeiten:

1. vorhandenes Editor.js-JSON lesen
2. translatierbare Textfelder blockweise extrahieren
3. Text an einen aktivierten Provider senden
4. Ergebnis wieder blockweise in dieselbe Struktur zurückführen
5. Medien, IDs, Layout und nicht-textliche Blöcke unangetastet lassen
6. Ergebnis erst nach expliziter Bestätigung übernehmen

### Sinnvolle Ziel-UX

Empfohlener erster Bedienpfad:

- Button oder Aktion im Admin-Editor wie `Nach Englisch übersetzen`
- nur sichtbar, wenn:
	- AI Services aktiviert sind
	- mindestens ein Translation-fähiger Provider aktiv ist
	- der Provider-Scope `translation_enabled` und `editorjs_enabled` erlaubt

Empfohlener Ablauf:

1. Quelle ist der **aktuelle Editor.js-Inhalt**
2. Ziel ist **Englisch (`en`)**
3. vor dem Senden: Blockanzahl, Zeichenzahl, Sprache und Einschränkungen anzeigen
4. nach dem Lauf: Vorschau / Zusammenfassung der geänderten Blöcke anzeigen
5. Anwender entscheidet zwischen:
	 - EN-Fassung übernehmen
	 - Ergebnisse verwerfen
	 - blockweise erneut versuchen

### Welche Blocktypen sich zuerst eignen

| Blocktyp | Phase 1 | Hinweis |
|---|---|---|
| Paragraph | ja | Standardfall |
| Header / Überschrift | ja | gute Sofortwirkung |
| List / Checklist | ja | strukturerhaltend übersetzbar |
| Quote / Callout | ja | meist textbasiert |
| Image-Caption / Alt-Text | optional ja | nur Textfelder, nicht Datei selbst |
| Table-Zellen | optional später | höherer Strukturaufwand |
| Code / API Endpoint / Mermaid | nein | in Phase 1 standardmäßig unverändert lassen |
| Raw HTML / Embed | nein | nur später und nur mit klaren Guards |

### Was Phase 1 bewusst **nicht** sein soll

- keine automatische Übersetzung beim Tippen
- keine Frontend-Übersetzung öffentlicher Seiten
- keine Vollautomatik für alle Inhalte der Website
- keine ungefragte Überschreibung bestehender EN-Fassungen
- keine direkte Bindung an einen einzelnen inoffiziellen Provider

### Sicherheits- und Betriebsregeln

- nur für berechtigte Admin-/Redaktionskontexte
- CSRF-gesicherter Action-Flow
- Quota-/Timeout-/Größenlimits
- keine Secret-Ausgabe in Fehlermeldungen oder Logs
- Logs nur mit Provider, Feature, Blockanzahl, Zeichenanzahl, Dauer und Status
- Teilfehler müssen blockweise reportbar bleiben

### Empfohlene Zielbausteine

- `CMS/admin/modules/system/AiServicesModule.php`
- `CMS/admin/views/system/ai-services.php`
- `CMS/core/Services/AI/EditorJsTranslationService.php`
- `CMS/core/Services/AI/Providers/*`
- `CMS/assets/js/admin-ai-services.js` *(erst später bei echter UI nötig)*

---

## Weitere sinnvolle Unterpunkte unter AI Services <!-- UPDATED: 2026-04-08 -->

Die folgenden Themen sind **sinnvoll**, aber laut aktuellem Wunschstand **noch nicht umzusetzen**. Sie sollen zunächst nur sauber dokumentiert und architektonisch mitgedacht werden.

### Automatische Zusammenfassungen

Mögliche Einsätze:

- Einleitungen aus langen Artikeln ableiten
- kurze Teaser für Listen-/Archivansichten
- interne Redaktionszusammenfassungen

Wichtig:

- keine stille Veröffentlichung ohne redaktionelle Bestätigung
- Trennung zwischen `Kurzfassung`, `TL;DR`, `Teaser` und `interner Zusammenfassung`

### Prompt-basierte SEO-/Meta-Generierung

Mögliche Einsätze:

- Title-Vorschläge
- Meta-Description-Vorschläge
- Open-Graph-/Social-Varianten
- FAQ- oder Schema-Hinweise als redaktionelle Vorlage

Wichtig:

- SEO-Generierung darf keine verbindliche Auto-Persistenz sein
- Inhalte müssen im SEO-Kontext überprüfbar und editierbar bleiben

### Translation-/Rewrite-Helfer mit mehreren Providern

Mögliche Einsätze:

- DE → EN Übersetzung
- Umformulieren in formeller/technischer/verkaufsorientierter Tonalität
- Kürzen oder Vereinfachen bestehender Texte
- Variantenbildung für Snippets, Teaser oder Social-Auszüge

Wichtig:

- Rewrite ist **nicht** dasselbe wie Übersetzung
- jede Funktion braucht eigene Provider-Scope-Gates
- spätere Mehrprovider-Unterstützung sollte austauschbar bleiben

---

## Empfohlene Integrationsregeln für 365CMS <!-- UPDATED: 2026-04-08 -->

Für neue Pakete aus `/ASSETS` sollte künftig dieselbe Checkliste gelten:

1. **Runtime-Relevanz prüfen** – wird das Paket wirklich produktiv gebraucht?
2. **Abhängigkeiten vollständig inventarisieren** – nicht nur das Hauptpaket, auch transitive Komponenten
3. **Service-/Adapter-Schicht definieren** – keine Direktkopplung quer durchs System
4. **Provider-/Security-/Quota-Risiken dokumentieren**
5. **Nur den echten Runtime-Scope übernehmen** – nicht die komplette Upstream-Struktur
6. **Admin- und Doku-Zielbild früh festhalten** – Menüpunkt, Capability-Modell, Feature-Gates, Operator-Sicht
7. **Dokumentation synchron halten** – `DOC/ASSET.md`, `DOC/assets/README.md`, `DOC/ASSETS_OwnAssets.md`, `DOC/ai/AI-SERVICES.md`, `DOC/admin/system-settings/AI-SERVICES.md`, `README.md`, `Changelog.md`

Kurzform für Entscheidungen:

- **UI-Bundle mit klarer Oberfläche** → potenziell lokal bundlebar oder ersetzbar
- **Security-/Auth-/Sanitizer-Bibliothek** → behalten und kapseln
- **Provider-/SDK-/AI-/Cloud-Paket** → nur über Adapter und mit klarer Betriebsentscheidung
- **inoffizielle Web-Crawling-Library** → nicht als Kernpfad führen und möglichst aus dem Bewertungsbestand entfernen

---

## Kurzfazit <!-- UPDATED: 2026-04-08 -->

Für 365CMS ist aktuell die saubere Linie:

- `symfony/ai-platform` ist jetzt **als produktive Basis unter `CMS/assets/ai-platform` registriert**, aber noch **kein fertig durchverdrahteter Multi-Provider-Stack**
- `google-translate-php` wurde **bewusst aus `/ASSETS` entfernt** und ist **keine gewünschte Basis** mehr
- AI-Funktionen sollen künftig über einen eigenen Bereich **„AI Services“** mit **Provider-Scope** und **Feature-Gates** eingeführt werden
- die erste sinnvolle Ausbaustufe ist ein **Translate Service für vorhandene Editor.js-Inhalte im Admin**, zunächst gezielt **nach Englisch**

Kurz: **nicht blind integrieren, sondern AI kontrolliert, providerbasiert und admin-zentriert aufbauen**.
