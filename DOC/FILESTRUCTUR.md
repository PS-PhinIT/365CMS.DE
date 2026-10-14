# 365CMS – Projektdokumentation | Abschnitt: FILESTRUCTUR
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02

## Table of contents | Inhaltsverzeichnis

- [Purpose](#purpose--zweck)
- [Repository root](#repository-root--repository-wurzel)
- [Documentation tree](#documentation-tree--dokumentationsbaum)
- [Related workspace repositories](#related-workspace-repositories--benachbarte-workspace-repositories)
- [How to read this file](#how-to-read-this-file--lesehilfe)

---

## Purpose | Zweck

**English**

This document describes the **repository layout** of `365CMS.DE-MAIN`. It is not the CMS runtime inventory.

| Document | Role |
|---|---|
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | this file — repository and documentation layout |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | inventory of the `CMS/` runtime |
| [FILELIST.md](FILELIST.md) | developer-oriented structure map |
| [DEVLIST.md](DEVLIST.md) | technical reference |

**Deutsch**

Dieses Dokument beschreibt die **Repository-Struktur** von `365CMS.DE-MAIN`. Es ist kein Inventar der CMS-Runtime.

| Dokument | Rolle |
|---|---|
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | diese Datei — Repository- und Dokumentationslayout |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | Inventar der Runtime `CMS/` |
| [FILELIST.md](FILELIST.md) | entwicklerfreundliche Strukturkarte |
| [DEVLIST.md](DEVLIST.md) | technische Referenz |

---

## Repository root | Repository-Wurzel

**English**

Verified top-level entries of this repository:

| Path | Type | Purpose |
|---|---|---|
| `CMS/` | directory | **productive runtime** (web root in typical deployments) |
| `DOC/` | directory | public project documentation |
| `RELEASE/` | directory | release packages and checksums (including `365CMS-3.4.0-*`) |
| `SCREENSHOTS/` | directory | screenshots |
| `README.md` | file | public project README |
| `Changelog.md` | file | project changelog |
| `CODE_OF_CONDUCT.md` | file | code of conduct |
| `.gitignore` | file | git ignore rules |

`ASSETS/` (asset staging), `AUDIT/`, `TESTS/` and `tools/` are referenced in older notes and in `VendorRegistry` (staging paths) but are **not** part of this repository (state 2026-10-02); they exist only in local maintainer workspaces.

There is **no** `var/` directory in this repository. Application logs are configured as `CMS/logs/` (`LOG_PATH` in `config/app.php`).

**Deutsch**

Verifizierte Top-Level-Einträge dieses Repositories:

| Pfad | Typ | Zweck |
|---|---|---|
| `CMS/` | Verzeichnis | **produktive Runtime** (in typischen Deployments das Webroot) |
| `DOC/` | Verzeichnis | öffentliche Projektdokumentation |
| `RELEASE/` | Verzeichnis | Release-Pakete und Prüfsummen (einschließlich `365CMS-3.4.0-*`) |
| `SCREENSHOTS/` | Verzeichnis | Screenshots |
| `README.md` | Datei | öffentliches Projekt-README |
| `Changelog.md` | Datei | Projekt-Changelog |
| `CODE_OF_CONDUCT.md` | Datei | Verhaltenskodex |
| `.gitignore` | Datei | Git-Ignore-Regeln |

`ASSETS/` (Asset-Staging), `AUDIT/`, `TESTS/` und `tools/` werden in älteren Notizen und in `VendorRegistry` (Staging-Pfade) erwähnt, sind aber **nicht** Teil dieses Repositories (Stand 2026-10-02); sie existieren nur in lokalen Maintainer-Arbeitsständen.

Es gibt **kein** Verzeichnis `var/` in diesem Repository. Anwendungslogs sind als `CMS/logs/` konfiguriert (`LOG_PATH` in `config/app.php`).

---

## Documentation tree | Dokumentationsbaum

**English**

Canonical files directly under `DOC/`:

| File | Purpose |
|---|---|
| [README.md](README.md) | documentation overview |
| [INDEX.md](INDEX.md) | documentation index |
| [INSTALLATION.md](INSTALLATION.md) | installation |
| [DEVLIST.md](DEVLIST.md) | developer reference |
| [FILELIST.md](FILELIST.md) | runtime structure map |
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | repository layout (this file) |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | CMS runtime inventory |

Subfolders (all present):

| Folder | Purpose |
|---|---|
| `DOC/admin/` | admin documentation |
| `DOC/ai/` | AI concept docs |
| `DOC/assets/` | asset / vendor docs (`ASSET.md`, `ASSETS_NEW.md`, `ASSETS_OwnAssets.md` live **here**) |
| `DOC/core/` | core documentation |
| `DOC/member/` | member documentation |
| `DOC/plugins/` | plugin documentation |
| `DOC/theme/` | theme documentation |
| `DOC/workflow/` | operational workflows |

Broken historical links (do **not** use):

- `DOC/ASSET.md` — actual path is `DOC/assets/ASSET.md`
- `DOC/ASSETS_NEW.md` — actual path is `DOC/assets/ASSETS_NEW.md`
- `DOC/ASSETS_OwnAssets.md` — actual path is `DOC/assets/ASSETS_OwnAssets.md`
- `DOC/checks/` — code audit 2026-10-03 (`README.md`, `SECURITY.md`, `PERFORMANCE.md`, `FUNKTIONEN.md`, `VERWEISE.md`); `AUDIT/` — not present in this repository
- `DOC/_cms_inventory_current.txt` — file does not exist

**Deutsch**

Kanonische Dateien direkt unter `DOC/`:

| Datei | Zweck |
|---|---|
| [README.md](README.md) | Dokumentationsüberblick |
| [INDEX.md](INDEX.md) | Dokumentationsindex |
| [INSTALLATION.md](INSTALLATION.md) | Installation |
| [DEVLIST.md](DEVLIST.md) | Entwicklerreferenz |
| [FILELIST.md](FILELIST.md) | Runtime-Strukturkarte |
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | Repository-Layout (diese Datei) |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | CMS-Runtime-Inventar |

Unterordner (alle vorhanden):

| Ordner | Zweck |
|---|---|
| `DOC/admin/` | Admin-Dokumentation |
| `DOC/ai/` | AI-Konzeptdokumente |
| `DOC/assets/` | Asset-/Vendor-Dokumente (`ASSET.md`, `ASSETS_NEW.md`, `ASSETS_OwnAssets.md` liegen **hier**) |
| `DOC/core/` | Core-Dokumentation |
| `DOC/member/` | Member-Dokumentation |
| `DOC/plugins/` | Plugin-Dokumentation |
| `DOC/theme/` | Theme-Dokumentation |
| `DOC/workflow/` | operative Workflows |

Historisch kaputte Links (nicht verwenden):

- `DOC/ASSET.md` — tatsächlicher Pfad: `DOC/assets/ASSET.md`
- `DOC/ASSETS_NEW.md` — tatsächlicher Pfad: `DOC/assets/ASSETS_NEW.md`
- `DOC/ASSETS_OwnAssets.md` — tatsächlicher Pfad: `DOC/assets/ASSETS_OwnAssets.md`
- `DOC/checks/` — Code-Audit 2026-10-03 (`README.md`, `SECURITY.md`, `PERFORMANCE.md`, `FUNKTIONEN.md`, `VERWEISE.md`); `AUDIT/` — in diesem Repository nicht vorhanden
- `DOC/_cms_inventory_current.txt` — Datei existiert nicht

---

## Related workspace repositories | Benachbarte Workspace-Repositories

**English**

On this machine, sibling folders next to `365CMS.DE-MAIN` include:

- `365CMS.DE-THEME` — theme source repository
- `365CMS.DE-PLUGINS` — plugin source repository

The running CMS loads themes and plugins **only** from:

- `365CMS.DE-MAIN/CMS/themes/`
- `365CMS.DE-MAIN/CMS/plugins/`

A change in a sibling repo is not live until it is present under those runtime paths.

**Deutsch**

Auf dieser Maschine liegen neben `365CMS.DE-MAIN` unter anderem:

- `365CMS.DE-THEME` — Theme-Quellrepository
- `365CMS.DE-PLUGINS` — Plugin-Quellrepository

Das laufende CMS lädt Themes und Plugins **nur** aus:

- `365CMS.DE-MAIN/CMS/themes/`
- `365CMS.DE-MAIN/CMS/plugins/`

Eine Änderung in einem Nachbar-Repo ist nicht live, solange sie nicht unter diesen Runtime-Pfaden liegt.

---

## How to read this file | Lesehilfe

**English**

- Working on **runtime behaviour** → start in `CMS/` and [FILELIST.md](FILELIST.md)
- Working on **repository orientation** → this file
- Working on **exact CMS file inventory** → [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)
- Working on **architecture and contracts** → [DEVLIST.md](DEVLIST.md)

**Deutsch**

- Arbeit an **Runtime-Verhalten** → in `CMS/` und [FILELIST.md](FILELIST.md) beginnen
- Arbeit an **Repository-Orientierung** → diese Datei
- Arbeit am **CMS-Dateiinventar** → [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)
- Arbeit an **Architektur und Verträgen** → [DEVLIST.md](DEVLIST.md)
