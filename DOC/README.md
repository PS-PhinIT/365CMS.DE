# 365CMS Projektdokumentation | README
> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable | **Update:** 2026-11-15

## English (summary)

This folder is the public documentation tree for 365CMS; runtime code lives in [`CMS/`](../CMS/). Core version `4.1.0` ([`CMS/core/Version.php`](../CMS/core/Version.php), released 2026-11-15, `stable`), PHP 8.4.1+, schema `v26`. Most documents contain a short English summary followed by a detailed German section.

## Deutsch

### Womit ihr anfangen solltet

| Wenn ihr … | dann startet hier |
|---|---|
| das System neu aufsetzt | [INSTALLATION.md](INSTALLATION.md) |
| ein Update oder Deployment plant | [workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](workflow/UPDATE-DEPLOYMENT-WORKFLOW.md) |
| die Architektur verstehen wollt | [core/ARCHITECTURE.md](core/ARCHITECTURE.md) |
| den Release-/Qualitätsstand und bekannte Lücken sucht | [core/STATUS.md](core/STATUS.md) |
| das Admin-Panel nutzt | [admin/README.md](admin/README.md) · [admin/GUIDE.md](admin/GUIDE.md) |
| den Mitgliederbereich betreut | [member/README.md](member/README.md) |
| Plugins entwickelt | [plugins/GUIDE.md](plugins/GUIDE.md) → [plugins/PLUGIN-DEVELOPMENT.md](plugins/PLUGIN-DEVELOPMENT.md) |
| Themes entwickelt | [theme/THEME-DEVELOPMENT.md](theme/THEME-DEVELOPMENT.md) |
| die API oder eigene Endpunkte nutzt | [core/API-REFERENCE.md](core/API-REFERENCE.md) · [workflow/API-INTEGRATION-WORKFLOW.md](workflow/API-INTEGRATION-WORKFLOW.md) |
| Hooks sucht | [core/HOOKS-REFERENCE.md](core/HOOKS-REFERENCE.md) |
| Tabellen sucht | [core/DATABASE-SCHEMA.md](core/DATABASE-SCHEMA.md) |
| Sicherheitsmechanismen prüft | [core/SECURITY.md](core/SECURITY.md) · [member/MEMBER-SECURITY.md](member/MEMBER-SECURITY.md) |
| KI-Funktionen konfiguriert | [ai/AI-SERVICES.md](ai/AI-SERVICES.md) · [admin/system-settings/AI-SERVICES.md](admin/system-settings/AI-SERVICES.md) |
| Bibliotheken/Assets prüft | [assets/README.md](assets/README.md) |
| Dateien/Struktur sucht | [FILELIST.md](FILELIST.md) · [FILESTRUCTUR.md](FILESTRUCTUR.md) · [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) · [DEVLIST.md](DEVLIST.md) |
| alle Dokumente sehen wollt | [INDEX.md](INDEX.md) |

### Eckdaten 4.1.0

- Dokumentierter Stand: Version `4.1.0` (Release `2026-11-15`), Schema `v26` (90 Tabellen), PHP `8.4.1+`, Tabellenpräfix `cms_`
- `CMS/core/Version.php`: `CURRENT = '4.1.0'`, `RELEASE_DATE = '2026-11-15'`, `STATUS = 'stable'`; `SchemaManager::SCHEMA_VERSION = 'v26'` (Entwicklungsstand 4.2.0: `v27`)
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
| [`workflow/`](workflow/) | Schritt-für-Schritt-Abläufe: Inhalte, Medien-Upload, API, Marketplace, Update/Deployment, Integrationsfahrpläne Newsletter, Planung des Community-Moduls (Forum) unter [`workflow/specs/community/`](workflow/specs/community/README.md) |
| [`ai/`](ai/) | AI Services, Provider, Assets |
| [`assets/`](assets/) | Gebündelte Bibliotheken unter `CMS/assets/` und `CMS/vendor/dompdf/` |

### Roadmap 4.2.0 / 4.3.0

- **4.1.0** (Schema `v26`): Bereiche 1–5 vollständig umgesetzt, einschließlich Rechnungen ([workflow/specs/v4.1/PROGRESS.md](workflow/specs/v4.1/PROGRESS.md)); veröffentlicht am 2026-11-15.
- **4.2.0** (Schema `v27`): Community-Modul (Forum). Umsetzungsfahrplan: [workflow/specs/community/IMPLEMENTATION-PLAN.md](workflow/specs/community/IMPLEMENTATION-PLAN.md); Planungsset: [workflow/specs/community/README.md](workflow/specs/community/README.md); Fortschritt: [workflow/specs/community/ROADMAP.md](workflow/specs/community/ROADMAP.md).
- **4.3.0**: zentrale CLI `CMS/cli.php`, Echtzeit-Alerting und Webhook-Ereignis `alert.raised` (verschoben aus 4.1.0, Spezifikation [workflow/specs/v4.1/06-CLI-ALERTING.md](workflow/specs/v4.1/06-CLI-ALERTING.md)).
- Entwicklerübersicht und Planungsabschnitt: [DEVLIST.md](DEVLIST.md) (Abschnitt 31).

### Wichtige Hinweise

- **Konfiguration:** `CMS/index.php` lädt `config/app.php` vor `config.php`. Eigene Konstanten deshalb in `config/app.php` pflegen und nach jedem Installer-Lauf prüfen.
- **Wartungsmodus:** Seit 3.4.12 liefert der Router Besuchern HTTP 503; Admins und Login-Routen bleiben erreichbar.
- **Offene Punkte:** [core/STATUS.md](core/STATUS.md) (Abschnitt „Offene Punkte“).
- **Audits:** Prüfberichte unter [audit/README.md](audit/README.md); Laufzeit-Sicherheitsprüfungen über `/admin/security-audit` ([admin/security/](admin/security/)).
- **Release-Änderungen:** [../Changelog.md](../Changelog.md) ist die führende Datei.

### Verwandte Einstiege

- [Dokumentationsindex](INDEX.md)
- [Root-README](../README.md)
- [Projekt-Changelog](../Changelog.md)
