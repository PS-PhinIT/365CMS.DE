# 365CMS – Projektdokumentation | Abschnitt: Admin – Dateistruktur

> **Stand:** 2026-11-13 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

`CMS/admin/` follows a three-layer pattern: **entry files** (`CMS/admin/<page>.php`, one per route, thin controllers with allow-lists and capability checks), **modules** (`CMS/admin/modules/<area>/<Name>Module.php`, business logic and persistence through core services) and **views** (`CMS/admin/views/<area>/*.php`, rendering only). Shared layout and request handling live in `CMS/admin/partials/`; the log sub-routes in `CMS/admin/logs/`; the extension tabs of `/admin/settings` in `CMS/admin/settings-tabs/`. Several entry files are thin wrappers that select a section of a shared controller (`seo-page.php`, `performance-page.php`, `system-monitor-page.php`, `logs-page.php`, `ai-page.php`, `member-dashboard-page.php`) or redirect aliases.

## Deutsch

### Verzeichnisbaum

```text
CMS/admin/
├── index.php                  # Dashboard (/admin)
├── <seite>.php                # ein Einstieg je Route (/admin/<seite>), z. B. pages.php, posts.php, media.php
├── *-page.php                 # gemeinsame Controller für Seitengruppen (siehe unten)
├── logs/                      # /admin/logs/<section>: index, operational, security-audit, php-errors, channels
├── modules/                   # Fachlogik je Bereich
│   ├── comments/  content/  dashboard/  downloads/  feeds/  forms/  hub/
│   ├── knowledgebase/  landing/  legal/  media/  member/  menus/  newsletter/
│   ├── pages/  plugins/  posts/  security/  seo/  settings/  subscriptions/
│   └── system/  tables/  themes/  toc/  trash/  users/
├── partials/                  # header.php, sidebar.php, topbar.php, footer.php,
│                              # section-page-shell.php, redirect-alias-shell.php,
│                              # post-action-shell.php, editorjs-inline-boot.php
├── settings-tabs/             # Erweiterungs-Tabs von /admin/settings: admin-menu.php, feeds.php, security.php
└── views/                     # Darstellung je Bereich (+ views/partials/ für gemeinsame Bausteine)
```

### Gemeinsame Controller

| Controller | Routen (Einstiegsdateien setzen nur die Sektion) |
|---|---|
| `seo-page.php` | `seo-dashboard`, `seo-audit`, `seo-meta`, `seo-social`, `seo-schema`, `seo-sitemap`, `seo-search-engines`, `seo-technical`, `analytics` |
| `performance-page.php` | `performance`, `performance-cache`, `-media`, `-database`, `-settings`, `-sessions` |
| `system-monitor-page.php` | `info`, `diagnose`, `monitor-warnings`, `monitor-assets`, `cms-logs`, `monitor-response-time`, `monitor-disk-usage`, `monitor-scheduled-tasks`, `monitor-health-check`, `monitor-email-alerts`, `monitor-cron-status` |
| `logs-page.php` | `logs`, `logs/operational`, `logs/security-audit`, `logs/php-errors`, `logs/channels` |
| `ai-page.php` | `ai-services`, `ai-translation`, `ai-content-creator`, `ai-seo-creator`, `ai-settings` |
| `member-dashboard-page.php` | alle `member-dashboard-*`-Unterseiten |

### Alias- und Spezialeinstiege

| Datei | Zweck |
|---|---|
| `design-settings.php`, `theme-settings.php`, `privacy-requests.php`, `deletion-requests.php`, `support.php`, `system-info.php` | Weiterleitungen über `redirect-alias-shell.php` |
| `ai-translate-editorjs.php`, `ai-generate-seo-metadata.php` | JSON-Endpunkte für den Editor |
| `admin-ui.php` | JSON-Endpunkt der Admin-Shell (`/admin/admin-ui`): Sidebar-Präferenzen, Site-Wechsel, Systemhinweise (seit 4.0.0) |
| `post-editor-api.php` | JSON-Endpunkt des Beitragseditors (`/admin/post-editor-api`): Autosave, Revisionen, Vergleich, Wiederherstellen (seit 4.0.0) |
| `plugin-assignment.php` | *Einstellungen › Plugins › Plugin-Zuweisung* (`/admin/plugin-assignment`), View `views/settings/plugin-assignment.php` (seit 4.0.0) |
| `error-report.php` | Fehlerbericht speichern |
| `monitor-cron-runner.php` | Cron aus dem Admin auslösen (POST) |

### Schichtenregeln

| Schicht | Darf | Darf nicht |
|---|---|---|
| Einstieg | Zugriff prüfen, Aktion/Ansicht gegen Allowlist normalisieren, Shell konfigurieren | SQL ausführen, HTML erzeugen (außer Fehlerseiten) |
| Modul | Daten laden/speichern über `Database` (Prepared Statements) und Core-Services, Ergebnis-Arrays liefern, protokollieren | Ausgaben erzeugen, `$_GET`/`$_POST` ungeprüft übernehmen |
| View | geprüfte Daten escapen und darstellen, Formulare mit `csrf_token` | Persistenz, Berechtigungsentscheidungen |

Views sind vor Direktaufruf geschützt (`ABSPATH`-Prüfung, teils `guard_constant`).

### Assets des Adminbereichs

`CMS/assets/css/admin*.css`, `CMS/assets/js/admin-*.js` (je Bereich eine Datei, z. B. `admin-media-integrations.js`, `admin-seo-editor.js`), Tabler unter `CMS/assets/tabler/`, Icons unter `CMS/assets/tabler-icons/`. Einbindung über `page_assets` der Shell bzw. `cms_asset_url()`.

Seit 4.0.0 zusätzlich:

| Pfad | Inhalt |
|---|---|
| `CMS/assets/css/admin-tabler.css` | Design-Tokens (`--cms-*`, auf `--tblr-*` abgebildet), Site-Akzente, `@font-face` |
| `CMS/assets/css/admin-shell.css` | Layout-Shell: Seitenleiste, Icon-Leiste, Drawer, Topbar, Site-Umschalter, Benutzerbereich |
| `CMS/assets/css/admin-post-editor.css`, `admin-plugin-assignment.css` | Beitragseditor bzw. Plugin-Zuweisung |
| `CMS/assets/js/admin-shell/` | ES-Module der Shell (`shell.js` als Einstieg, geladen in `partials/footer.php`; Importmap in `partials/header.php`) |
| `CMS/assets/js/post-editor/` | ES-Module des Beitragseditors (`editor.js`, `checklist.js`, `dialog.js`, `focus-mode.js`, `autosave.js`, `revisions.js`) |
| `CMS/assets/js/admin-plugin-assignment.js` | „Alle“/„Keine“ je Site-Spalte |
| `CMS/assets/fonts/` | lokal ausgelieferte Schriften Inter, Source Serif 4, JetBrains Mono (je mit Lizenzdatei) |

ES-Module werden über `page_assets['modules']` bzw. `$pageAssets['modules']` eingebunden und im Footer als `<script type="module">` geladen – ohne Build-Schritt.

### Verwandte Dokumente

[README.md](README.md) · [PANEL-INTEGRATION.md](PANEL-INTEGRATION.md) · [../CMSFILESTRUCTUR.md](../CMSFILESTRUCTUR.md)
