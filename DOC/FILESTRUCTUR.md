# 365CMS – Projektdokumentation | Abschnitt: FILESTRUCTUR
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable | **Update:** 2026-11-13

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
| `CMS_ASSETS/` | directory | source archives of the bundled third-party libraries |
| `DOC/` | directory | public project documentation |
| `RELEASE/` | directory | release packages and checksums (`365CMS-<version>-full.zip`, `-update.zip`, `-update.json`, `-SHA256SUMS.txt`, `README-<version>.md`; currently 3.5.0, 3.5.4, 3.5.5, 3.5.6, 4.0.0) |
| `SCREENSHOTS/` | directory | screenshots |
| `TESTS/admin-menu/` | directory | versioned admin menu test (catalog, static access check, profiles, validator, registry, builder, sidebar model, installer step): `php TESTS/admin-menu/run.php [-v]` |
| `TESTS/ai-admin-contract/` | directory | static contract of the AI admin integration (routes, CSRF, quota, SSRF guard, sanitizing): `php TESTS/ai-admin-contract/run.php` |
| `TESTS/ai-content-creator/` | directory | AI content draft pipeline with mock provider: `php TESTS/ai-content-creator/run.php` |
| `TESTS/ai-editorjs-translation/` | directory | Editor.js translation pipeline: batches, segment order, size limits: `php TESTS/ai-editorjs-translation/run.php` |
| `TESTS/ai-policy/` | directory | AI provider policy gates (external data sharing, beta providers, scopes): `php TESTS/ai-policy/run.php` |
| `TESTS/ai-seo-metadata/` | directory | AI SEO metadata pipeline (normalizing, schema type, robots): `php TESTS/ai-seo-metadata/run.php` |
| `TESTS/analytics-consent/` | directory | analytics output and CSP only with configured provider and consent: `php TESTS/analytics-consent/run.php` |
| `TESTS/content-types/` | directory | content type provider registry test: `php TESTS/content-types/run.php [-v]`; all suites: `php TESTS/run-all.php` (shared runner `TESTS/lib/TestRunner.php`) |
| `TESTS/csp-style-rewriter/` | directory | `InlineStyleRewriter`: `style` attributes to nonce CSS classes: `php TESTS/csp-style-rewriter/run.php` |
| `TESTS/editorjs-blocks/` | directory | Editor.js block tests: `php TESTS/editorjs-blocks/run.php`, browser part `node TESTS/editorjs-blocks/browser/run.js` |
| `TESTS/functions/` | directory | function tests without database: `php TESTS/functions/run.php` |
| `TESTS/login-showcase/` | directory | login showcase and theme colour import: settings validation, getShowcase() shape, partial escaping, core login view, theme override: `php TESTS/login-showcase/run.php [-v]` |
| `TESTS/member-area/` | directory | member area: CSRF per request, PRG, favorites, notifications, sanitizer, dashboard layout, menu tree, i18n, accessibility markup: `php TESTS/member-area/run.php [-v]` |
| `TESTS/newsletter-member/` | directory | newsletter in the member area: widget states, consent, double opt-in, rate limit, PRG of plugin sections, escaping: `php TESTS/newsletter-member/run.php [-v]` |
| `TESTS/release-3.4.0/` | directory | install/update contract since 3.4.0 (schema version, update swap, packages and SHA-256 of the current release): `php TESTS/release-3.4.0/run.php` |
| `TESTS/security/` | directory | security tests: `php TESTS/security/run.php` |
| `TESTS/theme-contract/` | directory | theme contract (CSP, layout hooks, versions), optional further theme roots as arguments: `php TESTS/theme-contract/run.php [theme roots …]` |
| `TESTS/update-swap/` | directory | update directory swap test: `php TESTS/update-swap/run.php` |
| `.github/` | directory | `CODEOWNERS`, `SECURITY`, `dependabot.yml`, `workflows/security.yml`, `deps/` (dependency manifests for the scans) |
| `.oss-scanner/` | directory | `Dockerfile`, `threat_model.md` |
| `LICENSE` | file | licence |
| `README.md` | file | public project README |
| `Changelog.md` | file | project changelog |
| `CODE_OF_CONDUCT.md` | file | code of conduct |
| `.gitignore` | file | git ignore rules |

`ASSETS/` (asset staging), `AUDIT/` and `tools/` are referenced in older notes and in `VendorRegistry` (staging paths) but are **not** part of this repository (state 2026-11-13); they exist only in local maintainer workspaces. `TESTS/` is fully versioned: `run-all.php`, `lib/TestRunner.php` and 18 suites (`admin-menu`, `ai-admin-contract`, `ai-content-creator`, `ai-editorjs-translation`, `ai-policy`, `ai-seo-metadata`, `analytics-consent`, `content-types`, `csp-style-rewriter`, `editorjs-blocks`, `functions`, `login-showcase`, `member-area`, `newsletter-member`, `release-3.4.0`, `security`, `theme-contract`, `update-swap`), all run by `php TESTS/run-all.php` in CI.

There is **no** `var/` directory in this repository. Application logs are configured as `CMS/logs/` (`LOG_PATH` in `config/app.php`).

**Deutsch**

Verifizierte Top-Level-Einträge dieses Repositories:

| Pfad | Typ | Zweck |
|---|---|---|
| `CMS/` | Verzeichnis | **produktive Runtime** (in typischen Deployments das Webroot) |
| `CMS_ASSETS/` | Verzeichnis | Quellarchive der gebündelten Drittbibliotheken |
| `DOC/` | Verzeichnis | öffentliche Projektdokumentation |
| `RELEASE/` | Verzeichnis | Release-Pakete und Prüfsummen (`365CMS-<version>-full.zip`, `-update.zip`, `-update.json`, `-SHA256SUMS.txt`, `README-<version>.md`; aktuell 3.5.0, 3.5.4, 3.5.5, 3.5.6, 4.0.0) |
| `SCREENSHOTS/` | Verzeichnis | Screenshots |
| `TESTS/admin-menu/` | Verzeichnis | versionierter Admin-Menü-Test (Katalog, statische Zugriffsprüfung, Profile, Validator, Registry, Builder, Sidebar-Modell, Installer-Schritt): `php TESTS/admin-menu/run.php [-v]` |
| `TESTS/ai-admin-contract/` | Verzeichnis | statischer Vertrag der AI-Admin-Integration (Routen, CSRF, Quota, SSRF-Schutz, Bereinigung): `php TESTS/ai-admin-contract/run.php` |
| `TESTS/ai-content-creator/` | Verzeichnis | AI-Entwurfs-Pipeline mit Mock-Provider: `php TESTS/ai-content-creator/run.php` |
| `TESTS/ai-editorjs-translation/` | Verzeichnis | Editor.js-Übersetzungs-Pipeline: Batches, Segmentreihenfolge, Größenlimits: `php TESTS/ai-editorjs-translation/run.php` |
| `TESTS/ai-policy/` | Verzeichnis | Freigaben der AI-Provider-Richtlinie (externe Datenweitergabe, Beta-Provider, Scopes): `php TESTS/ai-policy/run.php` |
| `TESTS/ai-seo-metadata/` | Verzeichnis | AI-SEO-Metadaten-Pipeline (Normalisierung, Schema-Typ, Robots): `php TESTS/ai-seo-metadata/run.php` |
| `TESTS/analytics-consent/` | Verzeichnis | Analytics-Ausgabe und CSP nur mit konfiguriertem Anbieter und Einwilligung: `php TESTS/analytics-consent/run.php` |
| `TESTS/content-types/` | Verzeichnis | Test der Inhaltstyp-Provider-Registry: `php TESTS/content-types/run.php [-v]`; alle Suiten: `php TESTS/run-all.php` (gemeinsamer Runner `TESTS/lib/TestRunner.php`) |
| `TESTS/csp-style-rewriter/` | Verzeichnis | `InlineStyleRewriter`: `style`-Attribute zu Nonce-CSS-Klassen: `php TESTS/csp-style-rewriter/run.php` |
| `TESTS/editorjs-blocks/` | Verzeichnis | Tests der Editor.js-Blöcke: `php TESTS/editorjs-blocks/run.php`, Browser-Teil `node TESTS/editorjs-blocks/browser/run.js` |
| `TESTS/functions/` | Verzeichnis | Funktionstests ohne Datenbank: `php TESTS/functions/run.php` |
| `TESTS/login-showcase/` | Verzeichnis | Login-Showcase und Theme-Farbimport: Validierung der Einstellungen, Datenform von getShowcase(), Escaping des Partials, Core-Loginseite, Theme-Override: `php TESTS/login-showcase/run.php [-v]` |
| `TESTS/member-area/` | Verzeichnis | Mitgliederbereich: CSRF je Anfrage, PRG, Favoriten, Benachrichtigungen, Sanitizer, Dashboard-Layout, Menübaum, Sprache, Barrierefreiheit: `php TESTS/member-area/run.php [-v]` |
| `TESTS/newsletter-member/` | Verzeichnis | Newsletter im Mitgliederbereich: Widget-Zustände, Einwilligung, Double-Opt-In, Rate-Limit, PRG der Plugin-Bereiche, Escaping: `php TESTS/newsletter-member/run.php [-v]` |
| `TESTS/release-3.4.0/` | Verzeichnis | Install-/Update-Vertrag seit 3.4.0 (Schema-Version, Update-Tausch, Pakete und SHA-256 des aktuellen Releases): `php TESTS/release-3.4.0/run.php` |
| `TESTS/security/` | Verzeichnis | Sicherheitstests: `php TESTS/security/run.php` |
| `TESTS/theme-contract/` | Verzeichnis | Theme-Vertrag (CSP, Layout-Hooks, Versionen), optional weitere Theme-Wurzeln als Argumente: `php TESTS/theme-contract/run.php [Theme-Wurzeln …]` |
| `TESTS/update-swap/` | Verzeichnis | Test des Verzeichnistauschs beim Update: `php TESTS/update-swap/run.php` |
| `.github/` | Verzeichnis | `CODEOWNERS`, `SECURITY`, `dependabot.yml`, `workflows/security.yml`, `deps/` (Abhängigkeitsmanifeste für die Scans) |
| `.oss-scanner/` | Verzeichnis | `Dockerfile`, `threat_model.md` |
| `LICENSE` | Datei | Lizenz |
| `README.md` | Datei | öffentliches Projekt-README |
| `Changelog.md` | Datei | Projekt-Changelog |
| `CODE_OF_CONDUCT.md` | Datei | Verhaltenskodex |
| `.gitignore` | Datei | Git-Ignore-Regeln |

`ASSETS/` (Asset-Staging), `AUDIT/` und `tools/` werden in älteren Notizen und in `VendorRegistry` (Staging-Pfade) erwähnt, sind aber **nicht** Teil dieses Repositories (Stand 2026-11-13); sie existieren nur in lokalen Maintainer-Arbeitsständen. `TESTS/` ist vollständig versioniert: `run-all.php`, `lib/TestRunner.php` und 18 Suiten (`admin-menu`, `ai-admin-contract`, `ai-content-creator`, `ai-editorjs-translation`, `ai-policy`, `ai-seo-metadata`, `analytics-consent`, `content-types`, `csp-style-rewriter`, `editorjs-blocks`, `functions`, `login-showcase`, `member-area`, `newsletter-member`, `release-3.4.0`, `security`, `theme-contract`, `update-swap`), die `php TESTS/run-all.php` in der CI ausführt.

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
| `DOC/audit/` | follow-up audits |
| `DOC/checks/` | code audit 2026-10-03 |
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
| `DOC/audit/` | Folgeaudits |
| `DOC/checks/` | Code-Audit 2026-10-03 |
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
