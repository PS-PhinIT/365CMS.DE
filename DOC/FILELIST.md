# 365CMS – Projektdokumentation | Abschnitt: FILELIST
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02

## Table of contents | Inhaltsverzeichnis

- [1. Repository picture](#1-repository-picture--gesamtbild-des-repositories)
- [2. Runtime under CMS/](#2-runtime-under-cms--produktive-kernstruktur-unter-cms)
- [3. Inventory vs this map](#3-inventory-vs-this-map--inventar-und-strukturkarte)
- [4. config](#4-cmsconfig--konfiguration)
- [5. core](#5-cmscore--technischer-kern)
- [6. admin](#6-cmsadmin--backend-struktur)
- [7. assets](#7-cmsassets--produktive-frontend-admin-assets)
- [8. member](#8-cmsmember--benutzerbereich)
- [9. plugins](#9-cmsplugins--produktiv-geladene-plugins)
- [10. themes](#10-cmsthemes--produktiv-geladene-themes)
- [11. Other CMS areas](#11-other-cms-areas--weitere-runtime-bereiche)
- [12. DOC](#12-doc--dokumentationsbaum)
- [13. Sibling repositories](#13-sibling-repositories--benachbarte-repositories)
- [14. Task orientation](#14-task-orientation--schnelle-orientierung-nach-aufgabenart)
- [15. Summary](#15-summary--kurzfazit)

---

## 1. Repository picture | Gesamtbild des Repositories

**English**

This file is the **readable structure map**. It answers: where does something live, what does the running CMS load, and which paths matter when you change code.

Product version in code: `3.5.0`. PHP: 8.4.1+. Schema: `v23`.

**Deutsch**

Diese Datei ist die **lesbare Strukturkarte**. Sie beantwortet: wo liegt etwas, was lädt das laufende CMS, und welche Pfade zählen bei Änderungen.

Produktversion im Code: `3.5.0`. PHP: 8.4.1+. Schema: `v23`.

### 1.1 Workspace context | Workspace-Kontext

**English**

Relevant repositories in the workspace:

- `365CMS.DE-MAIN` — this repository, **productive runtime base**
- `365CMS.DE-THEME` — separate theme source repository
- `365CMS.DE-PLUGINS` — separate plugin source repository

The CMS loads themes and plugins only from `CMS/themes/` and `CMS/plugins/`.

**Deutsch**

Relevante Repositories im Workspace:

- `365CMS.DE-MAIN` — dieses Repository, **produktive Laufzeitbasis**
- `365CMS.DE-THEME` — separates Theme-Quellrepository
- `365CMS.DE-PLUGINS` — separates Plugin-Quellrepository

Das CMS lädt Themes und Plugins nur aus `CMS/themes/` und `CMS/plugins/`.

### 1.2 Root of this repository | Wurzel dieses Repositories

| Path | Purpose / Zweck |
|---|---|
| `CMS/` | productive application runtime |
| `DOC/` | documentation |
| `RELEASE/` | release zips and checksums |
| `SCREENSHOTS/` | screenshots |
| `CODE_OF_CONDUCT.md` | code of conduct |
| `README.md` | public README |
| `Changelog.md` | changelog |

`ASSETS/`, `TESTS/` and `tools/` are not part of this repository (see [FILESTRUCTUR.md](FILESTRUCTUR.md)). / `ASSETS/`, `TESTS/` und `tools/` sind nicht Teil dieses Repositories.

Rule: **always know whether you are editing runtime, documentation, or source context.**

Regel: **immer klären, ob gerade Runtime, Doku oder Quellkontext bearbeitet wird.**

---

## 2. Runtime under CMS/ | Produktive Kernstruktur unter CMS/

**English**

`CMS/` is the application the web server should serve.

**Deutsch**

`CMS/` ist die Anwendung, die der Webserver ausliefern soll.

| Path | Purpose / Zweck |
|---|---|
| `.htaccess` | rewrite and protection rules |
| `index.php` | public entry |
| `config.php` | config stub, PHP 8.4.1+ gate |
| `config/` | real configuration |
| `install.php` / `install/` | installer |
| `cron.php` | cron entry |
| `update.php` / `update.json` | updates |
| `orders.php` | orders entry |
| `default.php` | default helper entry |
| `core/` | bootstrap, security, routing, services |
| `admin/` | backend |
| `member/` | member area |
| `themes/` | theme runtime |
| `plugins/` | plugin runtime |
| `assets/` | CSS, JS, bundled libraries |
| `includes/` | helpers |
| `lang/` | `de.yaml`, `en.yaml` |
| `marketplace/` | marketplace manifests |
| `vendor/` | extra vendor (`dompdf`) |
| `views/` | extra views |
| `uploads/`, `cache/`, `logs/`, `backups/`, `db/` | storage / ops |

Hotspots: `core/Bootstrap.php`, `core/Security.php`, `core/ThemeManager.php`, `core/PluginManager.php`, `admin/`, `assets/js/`, `member/includes/class-member-controller.php`.

---

## 3. Inventory vs this map | Inventar und Strukturkarte

**English**

Do not use the old 467-file / 28.03.2026 numbers. Current counts and file lists are in [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). `DOC/_cms_inventory_current.txt` does not exist.

| Document | Role |
|---|---|
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | inventory |
| [FILELIST.md](FILELIST.md) | this readable map |
| [DEVLIST.md](DEVLIST.md) | architecture and contracts |
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | repository layout |

**Deutsch**

Die alten Zahlen (467 Dateien, 28.03.2026) nicht mehr verwenden. Aktuelle Zählungen und Dateilisten stehen in [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). `DOC/_cms_inventory_current.txt` existiert nicht.

---

## 4. CMS/config/ | Konfiguration

**English** / **Deutsch**

| File | Purpose / Zweck |
|---|---|
| `config/.htaccess` | deny direct access |
| `config/app.php` | DB, keys, paths, SMTP, LDAP, JWT, HSTS, theme |
| `config/media-meta.json` | media metadata |
| `config/media-settings.json` | media settings |

`config/app.php` defines `ABSPATH`, `CMS_DEBUG`, `DB_*`, `AUTH_KEY`, `SECURE_AUTH_KEY`, `NONCE_KEY`, `SITE_NAME`, `SITE_URL` (no trailing slash, no subdirectory), `ADMIN_EMAIL`, `CMS_VERSION` from `Version::CURRENT`, path constants, `DEFAULT_THEME = cms-default`, login limits, optional LDAP/JWT/SMTP.

Changes here affect bootstrap, HTTPS, sessions, mail, LDAP, JWT, and logging at once.

---

## 5. CMS/core/ | Technischer Kern

**English**

If you need to know **how** 365CMS works, start in `core/`. Suggested order: `Bootstrap.php` → router/manager → service → Auth/Security/Hooks → then views.

Bootstrap modes (`Bootstrap::detectMode()`):

| Mode | Trigger |
|---|---|
| `cli` | `PHP_SAPI === 'cli'` |
| `api` | path starts with `/api/` |
| `admin` | path is `/admin` or starts with `/admin/` |
| `web` | everything else |

**Deutsch**

Wer verstehen will, **wie** 365CMS arbeitet, beginnt in `core/`. Lesereihenfolge: `Bootstrap.php` → Router/Manager → Service → Auth/Security/Hooks → danach Views.

Bootstrap-Modi (`Bootstrap::detectMode()`):

| Modus | Auslöser |
|---|---|
| `cli` | `PHP_SAPI === 'cli'` |
| `api` | Pfad beginnt mit `/api/` |
| `admin` | Pfad ist `/admin` oder beginnt mit `/admin/` |
| `web` | alles andere |

Root classes, Auth, Routing, and the service list are inventoried in [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). Service families:

| Family | Examples |
|---|---|
| Mail | `MailService`, `MailQueueService`, `MailLogService`, `AzureMailTokenProvider`, `GraphApiService` |
| SEO | `SEOService`, `SeoAnalysisService`, `RedirectService`, `SitemapService` |
| Media | `MediaService`, `MediaDeliveryService`, `FileUploadService`, `ImageService` |
| Editor | `EditorJsService`, `EditorJsRenderer`, `EditorService` |
| Landing | `LandingPageService` + `Services/Landing/` |
| AI | `Services/AI/` (admin-only in 3.5.0) |
| Member | `MemberService`, `MessageService` |
| Ops | `BackupService`, `UpdateService`, `StatusService`, `SystemService` |

---

## 6. CMS/admin/ | Backend-Struktur

**English**

298 files. Layout:

- `CMS/admin/*.php` — HTTP entry points
- `CMS/admin/modules/<domain>/` — domain modules (`comments`, `dashboard`, `hub`, `landing`, `legal`, `media`, `member`, `menus`, `pages`, `plugins`, `posts`, `security`, `seo`, `settings`, `subscriptions`, `system`, `tables`, `themes`, `toc`, `users`)
- `CMS/admin/views/` — templates
- `CMS/admin/partials/` — shared chrome (including sidebar)
- `CMS/admin/logs/` — log UI helpers

Notable routes present as files: `/admin/cms-loginpage` (`cms-loginpage.php`), `/admin/cms-logs` (`cms-logs.php`), AI admin scripts (`ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php`, `ai-translation.php`, …).

**Deutsch**

298 Dateien. Aufbau:

- `CMS/admin/*.php` — HTTP-Einstiege
- `CMS/admin/modules/<domain>/` — Fachmodule
- `CMS/admin/views/` — Templates
- `CMS/admin/partials/` — gemeinsame Oberfläche (inkl. Sidebar)
- `CMS/admin/logs/` — Log-UI

Besondere Dateien: `cms-loginpage.php`, `cms-logs.php`, AI-Admin-Skripte (`ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php`, `ai-translation.php`, …).

---

## 7. CMS/assets/ | Produktive Frontend-/Admin-Assets

**English**

Runtime assets are under `CMS/assets/`, not the repository `ASSETS/` folder.

- `assets/css/` — 13 stylesheets (admin, hub, consent, EditorJS content, member, public)
- `assets/js/` — 41 scripts (admin modules, EditorJS boot, cookie consent init, member dashboard, PhotoSwipe, web vitals)
- bundled libraries — see [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)

`cookieconsent`, `elfinder`, `filepond`, and `msgraph` directories are **not** present under `CMS/assets/` in this tree. `cookieconsent-init.js` still exists.

**Deutsch**

Runtime-Assets liegen unter `CMS/assets/`, nicht im Repository-Ordner `ASSETS/`.

- `assets/css/` — 13 Stylesheets
- `assets/js/` — 41 Skripte
- gebündelte Bibliotheken — siehe [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)

Die Ordner `cookieconsent`, `elfinder`, `filepond` und `msgraph` liegen in diesem Baum **nicht** unter `CMS/assets/`. `cookieconsent-init.js` existiert weiterhin.

---

## 8. CMS/member/ | Benutzerbereich

**English**

17 files. Pages: dashboard, favorites, media, messages, notifications, plugin-section, privacy, profile, security, subscription. Controller: `member/includes/class-member-controller.php`. Router: `core/Routing/MemberRouter.php`. Service: `core/Services/MemberService.php`.

**Deutsch**

17 Dateien. Seiten: Dashboard, Favoriten, Medien, Nachrichten, Benachrichtigungen, Plugin-Bereich, Datenschutz, Profil, Sicherheit, Abo. Controller: `member/includes/class-member-controller.php`. Router: `core/Routing/MemberRouter.php`. Service: `core/Services/MemberService.php`.

---

## 9. CMS/plugins/ | Produktiv geladene Plugins

**English**

Loaded by `PluginManager` from `CMS/plugins/<slug>/`. Currently installed: **`cms-importer`** only. Sibling repo `365CMS.DE-PLUGINS` is not an automatic load path.

**Deutsch**

Geladen durch `PluginManager` aus `CMS/plugins/<slug>/`. Derzeit installiert: nur **`cms-importer`**. Das Nachbar-Repo `365CMS.DE-PLUGINS` ist kein automatischer Ladepfad.

---

## 10. CMS/themes/ | Produktiv geladene Themes

**English**

`ThemeManager` reads the active theme from settings and uses `themes/<slug>/`. Currently installed: **`cms-default`**. Fallback constant: `DEFAULT_THEME = cms-default`. Typical files: `style.css`, `functions.php`, `index.php`, `header.php`, `footer.php`, `page.php`, `home.php`, `404.php`, `search.php`, `theme.json`, `update.json`. Sibling repo `365CMS.DE-THEME` is not an automatic load path.

**Deutsch**

`ThemeManager` liest das aktive Theme aus den Settings und nutzt `themes/<slug>/`. Derzeit installiert: **`cms-default`**. Fallback: `DEFAULT_THEME = cms-default`. Das Nachbar-Repo `365CMS.DE-THEME` ist kein automatischer Ladepfad.

---

## 11. Other CMS areas | Weitere Runtime-Bereiche

**English** / **Deutsch**

| Path | Notes |
|---|---|
| `CMS/includes/` | 10 helper files, including `wordpress-compat.php` |
| `CMS/lang/` | `de.yaml`, `en.yaml` |
| `CMS/logs/` | `LOG_PATH` — not `var/logs/` (that directory does not exist here) |
| `CMS/uploads/` | public uploads; `.gitkeep` in git |
| `CMS/cache/` | cache, including optimizer output |
| `CMS/db/` | DB artefacts |
| `CMS/install/` | 9 installer files |
| `CMS/vendor/` | `dompdf` only in this tree — **no TCPDF** |
| `CMS/views/` | extra views including auth |
| `CMS/marketplace/` | core/plugin/theme index JSON |
| `CMS/backups/` | local backups, protected |

---

## 12. DOC/ | Dokumentationsbaum

**English**

Canonical `DOC/` files: `README.md`, `INDEX.md`, `INSTALLATION.md`, `DEVLIST.md`, `FILELIST.md`, `FILESTRUCTUR.md`, `CMSFILESTRUCTUR.md`.

Subfolders: `admin/`, `ai/`, `assets/`, `checks/`, `core/`, `member/`, `plugins/`, `theme/`, `workflow/`.

Asset Markdown is under `DOC/assets/`. The code audit of 2026-10-03 is in `DOC/checks/`; open gaps are tracked in `core/STATUS.md`.

**Deutsch**

Kanonische `DOC/`-Dateien: `README.md`, `INDEX.md`, `INSTALLATION.md`, `DEVLIST.md`, `FILELIST.md`, `FILESTRUCTUR.md`, `CMSFILESTRUCTUR.md`.

Unterordner: `admin/`, `ai/`, `assets/`, `checks/`, `core/`, `member/`, `plugins/`, `theme/`, `workflow/`.

Asset-Markdown liegt unter `DOC/assets/`. Das Code-Audit vom 2026-10-03 liegt unter `DOC/checks/`; offene Lücken stehen in `core/STATUS.md`.

---

## 13. Sibling repositories | Benachbarte Repositories

**English**

`365CMS.DE-THEME` and `365CMS.DE-PLUGINS` hold sources. Runtime effect requires files under `CMS/themes/` or `CMS/plugins/`.

**Deutsch**

`365CMS.DE-THEME` und `365CMS.DE-PLUGINS` enthalten Quellen. Runtime-Wirkung entsteht erst unter `CMS/themes/` bzw. `CMS/plugins/`.

---

## 14. Task orientation | Schnelle Orientierung nach Aufgabenart

| If you … / Wenn du … | start at / beginne bei |
|---|---|
| Bootstrap | `CMS/core/Bootstrap.php`, `CMS/config/app.php` |
| Auth / MFA / Passkeys | `CMS/core/Auth.php`, `CMS/core/Auth/`, `CMS/views/auth/` |
| Themes | `CMS/core/ThemeManager.php`, `CMS/themes/` |
| Plugins | `CMS/core/PluginManager.php`, `CMS/plugins/` |
| Redirects / 404 | `CMS/admin/modules/seo/`, `CMS/assets/js/admin-seo-redirects.js` |
| EditorJS | `CMS/assets/js/admin-content-editor.js`, `CMS/core/Services/EditorJs/`, `CMS/assets/editorjs/` |
| Member | `CMS/member/`, `CMS/core/Routing/MemberRouter.php` |
| Admin UI | `CMS/admin/modules/`, `CMS/admin/views/`, `CMS/assets/js/admin*.js` |
| Assets | `CMS/assets/` |
| Security constants | `CMS/config/app.php`, `CMS/core/Security.php` |
| AI (admin only) | `CMS/admin/ai-*.php`, `CMS/core/Services/AI/` |
| Docs | `DOC/INDEX.md`, `DOC/DEVLIST.md`, `DOC/FILELIST.md` |

---

## 15. Summary | Kurzfazit

**English**

Read 365CMS along the runtime:

`config/` sets rules → `core/` is the foundation → `admin/` is the backend → `member/` is the member area → `assets/` is UI and libraries → `plugins/` and `themes/` are the real extension runtime → `DOC/` explains it.

**The path that is actually loaded under `CMS/` wins.** Sibling source repos and the top-level `ASSETS/` folder are not automatic runtime.

**Deutsch**

365CMS entlang der Runtime lesen:

`config/` setzt Regeln → `core/` ist das Fundament → `admin/` das Backend → `member/` der Benutzerbereich → `assets/` UI und Bibliotheken → `plugins/` und `themes/` die echte Erweiterungs-Runtime → `DOC/` erklärt das Ganze.

**Entscheidend ist der tatsächlich geladene Runtime-Pfad unter `CMS/`.** Nachbar-Quellrepos und der Top-Level-Ordner `ASSETS/` sind keine automatische Runtime.
