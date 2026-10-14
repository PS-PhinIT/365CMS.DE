# 365CMS – Projektdokumentation | Abschnitt: Admin – Einstieg & Navigation

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

The administration starts at `/admin` and is reserved for users with role **`admin`** (`AdminRouter` checks `Auth::isAdmin()`; guests are redirected to the login page, other roles to `/member`). `CMS/core/Routing/AdminRouter.php` registers four patterns: `/admin`, `/admin/:page`, `/admin/logs/:section` and `/admin/plugins/:plugin/:page` (GET and POST). The sidebar (`CMS/admin/partials/sidebar.php`) is grouped into sections and hides groups whose core module is disabled. This folder documents every sidebar group.

## Deutsch

### Routing

| Muster | Auflösung |
|---|---|
| `/admin` | `CMS/admin/index.php` (Dashboard) |
| `/admin/:page` | `CMS/admin/<page>.php` → Fallback `CMS/admin/modules/<page>/page.php` → `CMS/admin/old/<page>.php`; `<page>` nur `[a-zA-Z0-9_-]` |
| `/admin/logs/:section` | `CMS/admin/logs/<section>.php` |
| `/admin/plugins/:plugin/:page` | registrierter Plugin-Callback ([PANEL-INTEGRATION.md](PANEL-INTEGRATION.md)) |

Jeder Aufruf wird für „zuletzt genutzt“ erfasst (`FeatureUsageService`). Unbekannte Seiten liefern die 404-Seite.

**Alt-Routen** (werden umgeleitet, falls keine gleichnamige Datei existiert): `backup` → `backups`, `cms-firewall` → `firewall`, `cookies` → `cookie-manager`, `system` → `info`, `theme-customizer` → `theme-editor`, `menus` → `menu-editor`, `rbac` → `roles`, `seo` → `seo-dashboard`, `subscriptions` → `subscription-settings`, `data-deletion` → `deletion-requests`, `data-access` → `privacy-requests`, `fonts-local` → `font-manager`.

### Sidebar und Dokumentation

| Abschnitt | Gruppe | Dokumentation |
|---|---|---|
| **Kernsystem** | Dashboard (`/admin`) | [dashboard/](dashboard/README.md) |
| | KI-Dienste | [ai/AI-SERVICES.md](ai/AI-SERVICES.md) |
| **Inhalte** | Seiten & Beiträge | [pages-posts/](pages-posts/README.md) |
| | Medienverwaltung | [media/](media/README.md) |
| **Benutzer** | Benutzer & Gruppen | [users-groups/](users-groups/README.md) |
| | Mitglieder-Dashboard | [member/](member/README.md) |
| | Aboverwaltung | [subscription/](subscription/README.md) |
| **Marketing & Gestaltung** | Themes & Gestaltung | [themes-design/](themes-design/README.md), [landing-page/](landing-page/README.md) |
| | SEO | [seo/](seo/README.md) |
| | Performance | [performance/](performance/README.md) |
| **Sicherheit & Protokolle** | Recht | [legal/](legal/README.md) |
| | Sicherheit | [security/](security/README.md) |
| **System** | Plugins | [plugins/](plugins/README.md) |
| | Protokolle & Audit | [diagnose/DIAGNOSE.md](diagnose/DIAGNOSE.md#protokolle--audit-sidebar-gruppe) |
| | System & Dokumentation | [system-settings/](system-settings/README.md) |
| | Diagnose | [diagnose/](diagnose/README.md), [info/](info/README.md) |
| **Plugin-Erweiterungen** | Menüs aktiver Plugins (alphabetisch) | [plugins/PLUGINS.md](plugins/PLUGINS.md#plugin-adminseiten) |

Die Gruppe *Protokolle & Audit* trägt ein rotes Badge mit der Anzahl neuer Fehler. Der Marketplace-Eintrag erscheint nur bei `marketplace_enabled = 1`.

### Querschnittsdokumente

| Thema | Dokument |
|---|---|
| Bedienleitfaden für Administratoren | [GUIDE.md](GUIDE.md) |
| Dateistruktur `CMS/admin/` | [FILESTRUCTURE.md](FILESTRUCTURE.md) |
| Eigene Adminseiten (Core und Plugin) | [PANEL-INTEGRATION.md](PANEL-INTEGRATION.md) |
| JSON-API und AJAX | [ADMIN-API-AJAX.md](ADMIN-API-AJAX.md) |
| Prüf-Checkliste für Änderungen | [PRUEF-CHECKLISTE.md](PRUEF-CHECKLISTE.md) |

### Gemeinsame Konventionen

- **Layout:** Tabler (`navbar-vertical`), Header/Sidebar/Footer aus `CMS/admin/partials/`.
- **Formulare:** POST mit `csrf_token` (eine Token-Aktion je Seite, z. B. `admin_pages`), danach Redirect (PRG) und Flash-Meldung.
- **Berechtigung:** Rolle `admin` plus Capability je Seite (meist `manage_settings`).
- **Module:** Feature-Gruppen lassen sich unter `/admin/modules` abschalten ([system-settings/MODULES.md](system-settings/MODULES.md)).
- **Abmelden:** Sidebar/Topbar senden `/logout` mit CSRF-Token `logout`.
