# 365CMS – Projektdokumentation | Abschnitt: Admin – Dateistruktur

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

`CMS/admin/` follows a three-layer pattern: **entry files** (`CMS/admin/<page>.php`, one per route, thin controllers with allow-lists and capability checks), **modules** (`CMS/admin/modules/<area>/<Name>Module.php`, business logic and persistence through core services) and **views** (`CMS/admin/views/<area>/*.php`, rendering only). Shared layout and request handling live in `CMS/admin/partials/`; the log sub-routes in `CMS/admin/logs/`. Several entry files are thin wrappers that select a section of a shared controller (`seo-page.php`, `performance-page.php`, `system-monitor-page.php`, `logs-page.php`, `ai-page.php`, `member-dashboard-page.php`) or redirect aliases.

## Deutsch

### Verzeichnisbaum

```text
CMS/admin/
├── index.php                  # Dashboard (/admin)
├── <seite>.php                # ein Einstieg je Route (/admin/<seite>), z. B. pages.php, posts.php, media.php
├── *-page.php                 # gemeinsame Controller für Seitengruppen (siehe unten)
├── logs/                      # /admin/logs/<section>: index, operational, security-audit, php-errors, channels
├── modules/                   # Fachlogik je Bereich
│   ├── comments/  dashboard/  hub/  landing/  legal/  media/  member/  menus/
│   ├── pages/  plugins/  posts/  security/  seo/  settings/  subscriptions/
│   └── system/  tables/  themes/  toc/  users/
├── partials/                  # header.php, sidebar.php, topbar.php, footer.php,
│                              # section-page-shell.php, redirect-alias-shell.php,
│                              # post-action-shell.php, editorjs-inline-boot.php
└── views/                     # Darstellung je Bereich (+ views/partials/ für gemeinsame Bausteine)
```

### Gemeinsame Controller

| Controller | Routen (Einstiegsdateien setzen nur die Sektion) |
|---|---|
| `seo-page.php` | `seo-dashboard`, `seo-audit`, `seo-meta`, `seo-social`, `seo-schema`, `seo-sitemap`, `seo-technical`, `analytics` |
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

### Verwandte Dokumente

[README.md](README.md) · [PANEL-INTEGRATION.md](PANEL-INTEGRATION.md) · [../CMSFILESTRUCTUR.md](../CMSFILESTRUCTUR.md)
