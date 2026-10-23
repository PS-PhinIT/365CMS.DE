# 365CMS – Projektdokumentation | Abschnitt: README
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02

## English (summary)

This folder is the public documentation tree for 365CMS; runtime code lives in [`CMS/`](../CMS/). Core version `3.5.3` ([`CMS/core/Version.php`](../CMS/core/Version.php), released 2026-10-23, `stable`), PHP 8.4+, schema `v23`. In October 2026 every document under `DOC/` was reviewed against the 3.4 code: admin, core, member, theme, plugin, AI and workflow docs were rewritten or extended, and code/doc discrepancies were collected in [core/STATUS.md](core/STATUS.md) under "Bekannte Lücken". Most documents contain a short English summary followed by a detailed German section.

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

### Eckdaten 3.5.3

- `CMS/core/Version.php`: `CURRENT = '3.5.3'`, `RELEASE_DATE = '2026-10-23'`, `STATUS = 'stable'`
- `SchemaManager::SCHEMA_VERSION = 'v23'`, Tabellenpräfix `cms_`
- Konfiguration: `CMS/config/app.php` (vom Installer erzeugt); `CMS/config.php` ist nur ein Stub
- Bootstrap-Modi `cli`, `api`, `admin`, `web`; je Request wird nur die passende Routengruppe geladen
- Adminbereich nur für Rolle `admin`; Capabilities steuern die einzelnen Seiten
- Release 3.5.0 bündelt alle Änderungen seit 3.4.00 (Zwischenstände 3.4.01–3.4.25 und Folgeaudit vom 14.10.2026); 3.5.2 enthält die Korrekturen aus 3.5.1 (Speichern von Seiten); 3.5.3 ergänzt die Bearbeitung von Kommentaren im Adminbereich ([../Changelog.md](../Changelog.md))

### Dokumentationsbereiche

| Ordner | Inhalt |
|---|---|
| [`core/`](core/) | Architektur, Bootstrap/Routing, Klassen, Services, Hooks, Datenbank, API, Sicherheit, Status |
| [`admin/`](admin/) | Jede Admin-Seite nach Sidebar-Gruppe (Inhalte, Medien, Benutzer, Abos, Design, SEO, Performance, Sicherheit, Recht, System, Diagnose, Plugins) inkl. neuer Dokumente [MAIL.md](admin/system-settings/MAIL.md) und [MODULES.md](admin/system-settings/MODULES.md) |
| [`member/`](member/) | Mitgliederbereich: Routen, Dashboard, Plugin-Registry, Sicherheit |
| [`theme/`](theme/) | Theme-Entwicklung, Templates, JavaScript, Komponenten, Design-System |
| [`plugins/`](plugins/) | Quick Start, Entwicklerhandbuch, Marketplace |
| [`workflow/`](workflow/) | Schritt-für-Schritt-Abläufe: Inhalte, Medien-Upload, API, Marketplace, Update/Deployment, Integrationsfahrpläne Forum/Newsletter |
| [`ai/`](ai/) | AI Services, Provider, Assets |
| [`assets/`](assets/) | Gebündelte Bibliotheken unter `CMS/assets/` und `CMS/vendor/dompdf/` |

### Wichtige Hinweise

- **Konfiguration:** `CMS/index.php` lädt `config/app.php` vor `config.php`. Eigene Konstanten deshalb in `config/app.php` pflegen und nach jedem Installer-Lauf prüfen.
- **Wartungsmodus:** Seit 3.4.12 liefert der Router Besuchern HTTP 503; Admins und Login-Routen bleiben erreichbar (siehe [checks/FUNKTIONEN.md](checks/FUNKTIONEN.md)).
- **Bekannte Lücken:** Abweichungen zwischen Code und Doku (z. B. Upload-Token im Member-Medienbereich, fehlende `/order`-Ansicht, `config/app.php`-Neuerzeugung) stehen gesammelt in [core/STATUS.md](core/STATUS.md).
- **Audits:** Code-Audit vom 2026-10-03 unter [checks/README.md](checks/README.md); Laufzeit-Sicherheitsprüfungen über `/admin/security-audit` ([admin/security/](admin/security/)).
- **Release-Änderungen:** [../Changelog.md](../Changelog.md) ist die führende Datei.

### Verwandte Einstiege

- [Dokumentationsindex](INDEX.md)
- [Root-README](../README.md)
- [Projekt-Changelog](../Changelog.md)
