# ASSETS OwnAssets – Roadmap für Eigenersatz
> **Stand:** 2026-10-04 | **Version:** 3.5.0 | **Status:** Aktuell

## Zielbild

Diese Liste beschreibt, **welche aktiven Fremd-Assets 365CMS schrittweise durch eigene Assets oder eigene Wrapper ersetzen kann**, ohne Sicherheit, Standards, Wartbarkeit oder Updatefähigkeit unnötig zu opfern.

Wichtig: Nicht jede Drittbibliothek sollte ersetzt werden. Bei sicherheitskritischen, standardnahen oder komplexen Bibliotheken ist **Kapselung statt Eigenbau** die bessere Strategie.

Bereits entfernte oder nicht mehr führende Legacy-Assets aus früheren Laufzeitpfaden sind **nicht mehr Teil dieser Ersatzliste**. Der Fokus liegt auf dem aktuell relevanten Restbestand unter `CMS/assets/` sowie auf neuen Kandidaten aus `/ASSETS`, die bewusst noch **nicht vollständig** produktiv verdrahtet wurden.

---

## Entscheidungslogik

| Priorität | Bedeutung |
|---|---|
| P1 | Schnell ersetzbar, geringer Folgeschaden, hoher Vereinheitlichungsgewinn |
| P2 | Mittelfristig ersetzbar, benötigt Wrapper, Migration oder schrittweisen Parallelbetrieb |
| P3 | Nur langfristig sinnvoll, hoher Aufwand oder größere funktionale Tiefe |
| P4 | Nicht ersetzen, sondern kapseln, härten und sauber dokumentieren |

---

## Aktive Kandidaten für Eigenersatz

| Asset | Status in 2.9.1 | Rolle heute | Empfehlung | Priorität | Ziel für Eigenlösung |
|---|---|---|---|---|---|
| `gridjs` | **erledigt** – in 3.4.02 aus `CMS/assets/` entfernt | Admin-Listen werden serverseitig gerendert, ohne Grid.js | – | – | umgesetzt |
| `photoswipe` | aktiv | Frontend-Lightbox | durch eigene Lightbox-Komponente ablösen | P2 | `cms-lightbox.js` + schlanke CSS-Komponente |
| `melbahja-seo` | aktiv | Schema, Sitemap, SEO-Helfer | Funktionen schrittweise in Core-Services überführen | P2 | eigene `Seo*Service`-/`Sitemap*Service`-Bausteine |

---

## Neue Bewertungsgruppe aus `/ASSETS`

Diese Pakete liegen bereits im Staging-Bereich, sind aber **nicht** automatisch Kandidaten für einen direkten Runtime-Import und schon gar nicht für Eigenbau im engeren Sinn.

| Asset | Status heute | Empfehlung | Priorität | Warum |
|---|---|---|---|---|
| `symfony/ai-platform` | Basis jetzt zusätzlich produktiv unter `CMS/assets/ai-platform`, Provider-/Bridge-Stack weiter in Bewertung | **nicht selbst nachbauen**, sondern nur über klaren Core-Adapter einführen | P4 | experimentelles Framework, viele zusätzliche Symfony-Abhängigkeiten, provider- und bridge-lastig |
| `msgraph-sdk-php` | nicht mehr im Bestand (Staging-Ordner `ASSETS/` entfernt, Diagnose-Eintrag seit 3.4.19 weg) | bei Bedarf nur über isolierte Services einführen | P4 | große Provider-SDK-Fläche; der Graph-Mailversand nutzt eigene HTTP-Aufrufe |

### Was das für 365CMS bedeutet

- **AI-Funktionen** dürfen trotz der jetzt produktiv gebündelten Basis nicht als unkontrollierte Direktabhängigkeit quer durchs System landen, sondern nur über `CMS/core/Services/AI/*` plus provider-spezifische Adapter.
- **Automatische Übersetzung** sollte nicht an einer einzelnen inoffiziellen Bibliothek als Kernfunktion hängen. Das früher mitgeführte `google-translate-php` wurde deshalb aus `/ASSETS` entfernt. Wenn 365CMS Übersetzung anbietet, dann nur providerbasiert, optional, gedrosselt, cachebar und mit sauberem Fallback.
- **Große Provider-SDKs** wie `msgraph-sdk-php` gehören hinter einen kleinen Service-Vertrag, nicht als quer ins System gestreute Direktabhängigkeit.

---

## Empfohlene Reihenfolge

### Phase 1 – Kleine UI-Bibliotheken herauslösen

Diese Bibliotheken sind aktiv, aber funktional gut durch 365CMS-eigene UI-Bausteine ersetzbar:

1. ~~`gridjs`~~ (in 3.4.02 entfernt)
2. `photoswipe`

**Empfohlene Eigenmodule:**

- `CMS/assets/js/cms-grid.js`
- `CMS/assets/js/cms-lightbox.js`
- optionale Styles unter `CMS/assets/css/cms-grid.css` und `CMS/assets/css/cms-lightbox.css`

### Phase 2 – SEO-Abhängigkeit strategisch verkleinern

`melbahja-seo` sollte nicht in einem Schritt „hart“ entfernt werden. Sinnvoll ist eine **funktionale Zerlegung**:

- Sitemap-Erzeugung in einen eigenen Sitemap-Service verschieben
- Schema-Aufbau in eigene `Seo`-/`Schema`-Bausteine überführen
- IndexNow sauber als isolierten Service oder Adapter pflegen

### Phase 3 – Neue externe Kandidaten nur über Adapter bewerten

Neue Pakete aus `/ASSETS` sollten künftig zuerst in eine von drei Klassen einsortiert werden:

1. **UI-Asset, ersetzbar** → Kandidat für Eigenlösung
2. **Bibliothek, kapselbar** → hinter internen Service / Adapter stellen
3. **Provider-/Security-/SDK-Komplexität** → bewusst behalten, aber niemals Eigenbau

`symfony/ai-platform` und `msgraph-sdk-php` liegen im aktuellen Stand klar in Klasse 2 bis 3. Inoffizielle Crawling-basierte Übersetzungslibraries sollen dagegen gar nicht erst weiter als Staging-Basis gepflegt werden.

---

## Detailbewertung der Kandidaten

### `gridjs`

- **Status:** erledigt. Grid.js wurde in 3.4.02 aus `CMS/assets/` entfernt, die historische README unter `DOC/assets/gridjs/` in 3.4.19.

### `photoswipe`

- **Heute:** Frontend-Lightbox für Galerien
- **Warum ersetzbar:** klar abgegrenzte UI-Komponente mit begrenztem Runtime-Scope
- **Nächster Schritt:** eigenes Lightbox-Modul mit Zoom, Keyboard-Steuerung, Touch, Fokusfalle und Galerie-Navigation planen
- **Eigenlösung:** machbar
- **Risiko:** mittel
- **Zielbild:** barriereärmere 365CMS-Lightbox, die Theme-seitig leichter angepasst werden kann

### `melbahja-seo`

- **Heute:** SEO-Helfer für Schema, Sitemap und Indexing-nahe Features
- **Warum nur schrittweise:** fachlich sinnvoll, aber enger am Core und an Suchmaschinenformaten
- **Nächster Schritt:** Sitemap-, Schema- und IndexNow-Funktionen getrennt bewerten und nicht als monolithische „Alles neu“-Aktion angehen
- **Eigenlösung:** teilweise sinnvoll
- **Risiko:** mittel
- **Zielbild:** kleinere, testbare Core-Services statt generischem SEO-Helferpaket

---

## Was ausdrücklich nicht Eigenbau werden sollte

Diese Bibliotheken lieber **hinter Services kapseln**, aber **nicht selbst neu implementieren**:

- `htmlpurifier`
- `php-jwt`
- `webauthn`
- `ldaprecord`
- `mailer`
- `mime`
- `twofactorauth`
- `symfony/ai-platform`
- `msgraph-sdk-php`

### Warum nicht?

- hohe Sicherheits- und Standardisierungsanforderungen
- schwer korrekt und vollständig nachzubauen
- hoher Pflege-, Test- und Kompatibilitätsaufwand
- Fehler in diesen Bereichen wären teurer als der Nutzen eines Eigenbaus
- bei AI-/Provider-Stacks zusätzlich starke Abhängigkeit von externen APIs, Bridges, Token-Handling, Rate-Limits und veränderlicher Plattformpolitik

---

## Was 365CMS eher selbst bauen sollte – und was nicht

### Gute Eigenbau-Ziele

- kleine Admin-Grid-Helfer
- Frontend-Lightbox-Komponenten
- schmale SEO-Services mit klarer fachlicher Verantwortung
- interne Wrapper für Asset-Ladung, Cache-Busting und Fallback-Verhalten

### Schlechte Eigenbau-Ziele

- komplette WYSIWYG- oder Block-Editor-Engines
- Auth-/JWT-/Passkey-/LDAP-Standards
- HTML-Sanitizer
- AI-Plattform-Abstraktionen mit Provider-Bridges
- inoffizielle Web-Crawling-Translation-Layer

---

## Empfohlenes Vorgehen pro Ersatz

Jeder künftige Ersatz sollte in derselben Reihenfolge umgesetzt werden:

1. **Wrapper definieren** – bestehenden Einsatz über eine kleine interne Schnittstelle kapseln
2. **Nativen Ersatz parallel einführen** – zunächst nur in klar abgegrenzten Pfaden
3. **Feature-Lücken schließen** – UX, Accessibility und Edge-Cases angleichen
4. **Altpfad entkoppeln** – alte Bibliothek nur noch fallback-artig halten
5. **Dokumentation aktualisieren** – `DOC/ASSET.md`, `DOC/assets/README.md`, `DOC/ASSETS_NEW.md`, Changelog und diese Datei synchron halten

---

## Kurzfazit

Der schnellste Gewinn liegt **nicht** im Ersetzen aller Drittbibliotheken, sondern im gezielten Abbau kleiner UI-Abhängigkeiten und in der sauberen Kapselung komplexer Sicherheits-, Provider- oder Standardbibliotheken.

Die beste Reihenfolge lautet daher:

1. kleine UI-Bibliotheken wie `gridjs` und `photoswipe` durch 365CMS-eigene Module ersetzen
2. `melbahja-seo` funktional in kleinere Core-Services aufteilen
3. komplexe Sicherheits-, AI-, Übersetzungs- und Infrastruktur-Bibliotheken bewusst behalten, produktiv sauber bündeln und nur abstrahieren

Wichtig für den aktuellen Dokumentationsstand: Die führende Runtime- und Strukturreferenz für aktive Asset-Pfade liegt jetzt zusätzlich in `DOC/FILELIST.md` und `DOC/assets/README.md`. Neue Integrationskandidaten außerhalb der Runtime werden in `DOC/ASSETS_NEW.md` separat bewertet, damit Roadmap, Runtime und Integrationsplanung nicht in einer Liste vermischt werden. Die kanonische AI-Zieldoku liegt ergänzend in `DOC/ai/AI-SERVICES.md`; der Admin-Kontext dazu bleibt unter `DOC/admin/system-settings/AI-SERVICES.md` beschrieben.