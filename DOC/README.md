# 365CMS Projektdokumentation | README
> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable | **Schema:** v27

## English (summary)

This folder is the public documentation tree for 365CMS; runtime code lives in [`CMS/`](../CMS/). Core version `4.2.0` ([`CMS/core/Version.php`](../CMS/core/Version.php), released 2026-11-23, `stable`), PHP 8.4.1+, schema `v27`. Most documents contain a short English summary followed by a detailed German section.

## Deutsch

### Womit ihr anfangen solltet

| Wenn ihr … | dann startet hier |
|---|---|
| das System neu aufsetzt | [INSTALLATION.md](INSTALLATION.md) |
| ein Update oder Deployment plant | [workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../DOC_PLAN/UPDATE-DEPLOYMENT-WORKFLOW.md) |
| die Architektur verstehen wollt | [core/ARCHITECTURE.md](core/ARCHITECTURE.md) |
| den Release-/Qualitätsstand und bekannte Lücken sucht | [core/STATUS.md](core/STATUS.md) |
| das Admin-Panel nutzt | [admin/README.md](admin/README.md) · [admin/GUIDE.md](admin/GUIDE.md) |
| den Mitgliederbereich betreut | [member/README.md](member/README.md) |
| Plugins entwickelt | [plugins/GUIDE.md](plugins/GUIDE.md) → [plugins/PLUGIN-DEVELOPMENT.md](plugins/PLUGIN-DEVELOPMENT.md) |
| Themes entwickelt | [theme/THEME-DEVELOPMENT.md](theme/THEME-DEVELOPMENT.md) |
| die API oder eigene Endpunkte nutzt | [core/API-REFERENCE.md](core/API-REFERENCE.md) · [workflow/API-INTEGRATION-WORKFLOW.md](../DOC_PLAN/API-INTEGRATION-WORKFLOW.md) |
| Hooks sucht | [core/HOOKS-REFERENCE.md](core/HOOKS-REFERENCE.md) |
| Tabellen sucht | [core/DATABASE-SCHEMA.md](core/DATABASE-SCHEMA.md) |
| Sicherheitsmechanismen prüft | [core/SECURITY.md](core/SECURITY.md) · [member/MEMBER-SECURITY.md](member/MEMBER-SECURITY.md) |
| KI-Funktionen konfiguriert | [ai/AI-SERVICES.md](ai/AI-SERVICES.md) · [admin/system-settings/AI-SERVICES.md](admin/system-settings/AI-SERVICES.md) |
| Bibliotheken/Assets prüft | [assets/README.md](assets/README.md) |
| Dateien/Struktur sucht | [FILELIST.md](FILELIST.md) · [FILESTRUCTUR.md](FILESTRUCTUR.md) · [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) · [DEVLIST.md](DEVLIST.md) |
| alle Dokumente sehen wollt | [INDEX.md](INDEX.md) |

### Eckdaten 4.2.0

- Version `4.2.0` (Release `2026-11-23`), Schema `v27`, PHP `8.4.1+`, Tabellenpräfix `cms_`
- Das vollständige Community-Modul ist im Core enthalten, standardmäßig deaktiviert; Konfiguration und Betrieb sind unter [admin/community/README.md](admin/community/README.md) und [member/COMMUNITY.md](member/COMMUNITY.md) beschrieben.
- Konfiguration: `CMS/config/app.php` (vom Installer erzeugt); `CMS/config.php` ist nur ein Stub
- Bootstrap-Modi `cli`, `api`, `admin`, `web`; je Request wird nur die passende Routengruppe geladen
- Adminbereich nur für Rolle `admin`; Capabilities steuern die einzelnen Seiten
- Änderungen je Release: [../Changelog.md](../Changelog.md) (führende Datei)

### Dokumentationsbereiche

| Ordner | Inhalt |
|---|---|
| [`core/`](core/) | Architektur, Bootstrap/Routing, Klassen, Services, Hooks, Datenbank, API, Sicherheit, Status |
| [`admin/`](admin/) | Jede Admin-Seite nach Fachbereich (Inhalte, Medien, Benutzer, Abos, Design, SEO, Performance, Sicherheit, Recht, System, Diagnose, Plugins; Seitenleisten-Profile siehe [admin/README.md](admin/README.md) und [admin/ADMIN-MENU.md](admin/ADMIN-MENU.md)) inkl. [MAIL.md](admin/system-settings/MAIL.md), [MODULES.md](admin/system-settings/MODULES.md) und [MULTISITES.md](admin/system-settings/MULTISITES.md) |
| [`member/`](member/) | Mitgliederbereich: Routen, Dashboard, Plugin-Registry, Sicherheit |
| [`theme/`](theme/) | Theme-Entwicklung, Templates, JavaScript, Komponenten, Design-System |
| [`plugins/`](plugins/) | Quick Start, Entwicklerhandbuch, Marketplace |
| [`workflow/`](../DOC_PLAN) | Schritt-für-Schritt-Abläufe: Inhalte, Medien-Upload, API, Marketplace, Update/Deployment, Integrationsfahrpläne Newsletter, Planung des Community-Moduls (Forum) unter [`workflow/specs/community/`](../DOC_PLAN/v4.2/README.md) |
| [`ai/`](ai/) | AI Services, Provider, Assets |
| [`assets/`](assets/) | Gebündelte Bibliotheken unter `CMS/assets/` und `CMS/vendor/dompdf/` |

### Release- und Prüfstatus

- **4.2.0 / v27** ist veröffentlicht. Der Umsetzungsstand des Community-Moduls ist in [workflow/specs/community/README.md](../DOC_PLAN/v4.2/README.md) zusammengefasst; [IMPLEMENTATION-PLAN.md](../DOC_PLAN/v4.2/IMPLEMENTATION-PLAN.md) und [ROADMAP.md](../DOC_PLAN/v4.2/ROADMAP.md) dokumentieren die erledigten Lieferpakete.
- Frühere Versionen und ihre Korrekturen sind historische Einträge in [../Changelog.md](../Changelog.md) und den datierten Dokumenten unter [audit/](audit/), nicht der aktuelle Produktstand.
- Nicht angekündigte Folgefunktionen werden nicht als zugesagter Roadmap-Stand geführt.
- Tests werden nicht anhand einer festen Summe dokumentiert: `php TESTS/run-all.php` entdeckt laufend alle `TESTS/<suite>/run.php`. Eine einzelne Suite läuft mit `php TESTS/<suite>/run.php [-v]`; der gemeinsame Runner gibt Einleitung und Entdeckungslogik aus.

### Wichtige Hinweise

- **Konfiguration:** `CMS/index.php` lädt `config/app.php` vor `config.php`. Eigene Konstanten deshalb in `config/app.php` pflegen und nach jedem Installer-Lauf prüfen.
- **Wartungsmodus:** Seit 3.4.12 liefert der Router Besuchern HTTP 503; Admins und Login-Routen bleiben erreichbar.
- **Offene Punkte:** [core/STATUS.md](core/STATUS.md) (Abschnitt „Offene Punkte“).
- **Audits:** Prüfberichte unter [audit/README.md](audit/README.md); Laufzeit-Sicherheitsprüfungen über `/admin/security-audit` ([admin/security/](admin/security/)).
- **Release-Änderungen:** [../Changelog.md](../Changelog.md) ist die führende Datei; der Codezustand in `CMS/` und das Schema in `SchemaManager` haben Vorrang gegenüber älteren Planungsnotizen.

### Verwandte Einstiege

- [Dokumentationsindex](INDEX.md)
- [Root-README](../README.md)
- [Projekt-Changelog](../Changelog.md)
