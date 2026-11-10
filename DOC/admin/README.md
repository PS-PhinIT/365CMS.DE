# 365CMS – Projektdokumentation | Abschnitt: Admin – Einstieg & Navigation

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

The administration starts at `/admin` and is reserved for users with role **`admin`** (`AdminRouter` checks `Auth::isAdmin()`; guests are redirected to the login page, other roles to `/member`). `CMS/core/Routing/AdminRouter.php` registers four patterns: `/admin`, `/admin/:page`, `/admin/logs/:section` and `/admin/plugins/:plugin/:page` (GET and POST). Since 4.0.0 the layout is a calm, light shell: a collapsible left sidebar (264 px, icon rail 76 px, off-canvas drawer below 992 px) with a site switcher at the top, the core groups, a "Plugins" group, a "Verwaltung" group and a user footer with system notices. The navigation model is built by `CMS\Services\AdminNavigationService` (presentation only; plugin menu registration is unchanged) and rendered by `CMS/admin/partials/sidebar.php`. Groups whose core module is disabled stay hidden. Per-user sidebar state is stored server-side (`admin_ui_prefs`). This folder documents every sidebar group.

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

Reihenfolge von oben nach unten: Site-Umschalter, Core-Gruppen, Gruppe *Plugins*, Gruppe *Verwaltung*, Benutzerbereich.

| Gruppe | Eintrag | Dokumentation |
|---|---|---|
| **Übersicht** | Dashboard (`/admin`) | [dashboard/](dashboard/README.md) |
| | KI-Dienste | [ai/AI-SERVICES.md](ai/AI-SERVICES.md) |
| **Inhalte** | Seiten & Beiträge | [pages-posts/](pages-posts/README.md) |
| | Medienverwaltung | [media/](media/README.md) |
| **Benutzer** | Benutzer & Gruppen | [users-groups/](users-groups/README.md) |
| | Mitglieder-Dashboard | [member/](member/README.md) |
| | Aboverwaltung | [subscription/](subscription/README.md) |
| **Gestaltung & Marketing** | Themes & Gestaltung | [themes-design/](themes-design/README.md), [landing-page/](landing-page/README.md) |
| | SEO | [seo/](seo/README.md) |
| | Performance | [performance/](performance/README.md) |
| **Sicherheit & Recht** | Recht | [legal/](legal/README.md) |
| | Sicherheit | [security/](security/README.md) |
| | Protokolle & Audit | [diagnose/DIAGNOSE.md](diagnose/DIAGNOSE.md#protokolle--audit-sidebar-gruppe) |
| **Plugins** | ein Eintrag je Plugin mit Menü (alphabetisch, Unterseiten eingerückt), gefiltert nach der Plugin-Zuweisung der aktuellen Site | [plugins/PLUGINS.md](plugins/PLUGINS.md#plugin-adminseiten), [Plugin-Zuweisung](plugins/PLUGINS.md#plugin-zuweisung) |
| **Verwaltung** | Plugins (Plugins verwalten, Marketplace, Plugin-Zuweisung) | [plugins/](plugins/README.md) |
| | System & Dokumentation | [system-settings/](system-settings/README.md) |
| | Diagnose | [diagnose/](diagnose/README.md), [info/](info/README.md) |

Der Eintrag *Protokolle & Audit* trägt ein rotes Badge mit der Anzahl neuer Fehler. Der Marketplace-Eintrag erscheint nur bei `marketplace_enabled = 1`. Die Gruppe *Plugins* erscheint nur, wenn mindestens ein Plugin ein Menü registriert hat.

### Admin-Shell (seit 4.0.0)

| Baustein | Verhalten |
|---|---|
| Layout | Seitenleiste + Hauptspalte (`<main id="cms-main">`); erstes fokussierbares Element ist der Skip-Link „Zum Inhalt springen“. Topbar mit Brotkrümelnavigation und Kontextaktion. |
| Gruppen | Auf- und zuklappbar (`aria-expanded`). Standard: nur die ersten zwei Gruppen offen; die Gruppe und der Plugin-Eintrag der aktiven Seite sind immer offen. Manuelle Zustände werden je Benutzer gespeichert. |
| Aktiver Eintrag | `aria-current="page"` und Akzent-Hintergrund (`--cms-accent-soft`) |
| Icon-Leiste | Der Panel-Button im Kopf der Seitenleiste (`aria-expanded`) klappt sie auf 76 px ein: Labels ausgeblendet, Tooltips bei Hover und Tastaturfokus, Untereinträge als Flyout. Der Zustand wird je Benutzer serverseitig gespeichert. |
| Drawer (< 992 px) | Hamburger „Navigation öffnen“ in der Topbar öffnet die Seitenleiste als Off-Canvas-Drawer mit Fokusfalle; Escape, Hintergrund-Klick und „Navigation schließen“ schließen ihn. |
| Site-Umschalter | Zeigt die aktuelle Site mit ihrem Akzent; die Liste enthält alle Sites (`AdminSiteContextService`, Option `admin_sites`). Ein Wechsel speichert den Kontext serverseitig und lädt den Admin in dieser Site neu. Akzent: phinit.de `#4f46e5`, 365network.de `#0f766e`. |
| Benutzerbereich | Avatar mit Name und Rolle; das Popover enthält Mein Profil, Website ansehen und Abmelden. |
| Systemhinweise | Nur mit `manage_system`: ein Punkt am Avatar, sobald es Hinweise gibt (letzter Cron-Lauf fehlgeschlagen, letztes Backup älter als 24 Stunden oder keines vorhanden, Core- oder Plugin-Updates verfügbar). Die Liste mit Links steht im Popover. Ohne Hinweise entfällt der Punkt. |
| Tastatur | Tab wechselt zwischen Einträgen, Enter/Leertaste klappt Gruppen, Pfeil hoch/runter und Pos1/Ende bewegen innerhalb einer Gruppe bzw. eines Flyouts, Pfeil rechts/links öffnet und schließt Einträge mit Untereinträgen, Escape schließt Flyouts und den Drawer. |
| Bewegung | Bei `prefers-reduced-motion: reduce` laufen Auf-/Zuklappen und Flyouts ohne Animation. |

Technik:

- Navigationsmodell: `CMS\Services\AdminNavigationService` (Core-Gruppen, Plugin-Gruppe, aktive Markierung) und `CMS\Services\PluginSiteAssignmentService` (Filter je Site). Ausgabe über `CMS/admin/partials/sidebar.php` und `topbar.php`.
- Styles: `CMS/assets/css/admin-shell.css`, Tokens in `CMS/assets/css/admin-tabler.css` ([PANEL-INTEGRATION.md](PANEL-INTEGRATION.md#gestaltung)).
- Verhalten: ES-Module unter `CMS/assets/js/admin-shell/` (`shell.js` als Einstieg, `sidebar.js`, `drawer.js`, `site-switcher.js`, `user-menu.js`, `recent-links.js`, `api.js`, `dom.js`), aufgelöst über eine Importmap in `header.php`, ohne Build-Schritt.
- Benutzer-Präferenzen: `cms_user_meta.meta_key = 'admin_ui_prefs'` (`CMS\Services\AdminUiPreferenceService`) mit `sidebar_collapsed`, Gruppen-Zuständen und der gewählten Site.
- JSON-Endpunkt `/admin/admin-ui` (`CMS/admin/admin-ui.php`): `GET action=notices` (Systemhinweise, `manage_system`, 5 Minuten Cache), `GET action=token`, `POST action=prefs` und `POST action=switch_site` (CSRF-Aktion `admin_ui`). Details: [ADMIN-API-AJAX.md](ADMIN-API-AJAX.md).

### Querschnittsdokumente

| Thema | Dokument |
|---|---|
| Bedienleitfaden für Administratoren | [GUIDE.md](GUIDE.md) |
| Dateistruktur `CMS/admin/` | [FILESTRUCTURE.md](FILESTRUCTURE.md) |
| Eigene Adminseiten (Core und Plugin) | [PANEL-INTEGRATION.md](PANEL-INTEGRATION.md) |
| JSON-API und AJAX | [ADMIN-API-AJAX.md](ADMIN-API-AJAX.md) |
| Prüf-Checkliste für Änderungen | [PRUEF-CHECKLISTE.md](PRUEF-CHECKLISTE.md) |

### Gemeinsame Konventionen

- **Layout:** Tabler-basierte Admin-Shell (seit 4.0.0 eigene Seitenleiste statt `navbar-vertical`), Header/Sidebar/Topbar/Footer aus `CMS/admin/partials/`.
- **Formulare:** POST mit `csrf_token` (eine Token-Aktion je Seite, z. B. `admin_pages`), danach Redirect (PRG) und Flash-Meldung.
- **Berechtigung:** Rolle `admin` plus Capability je Seite (meist `manage_settings`).
- **Module:** Feature-Gruppen lassen sich unter `/admin/modules` abschalten ([system-settings/MODULES.md](system-settings/MODULES.md)).
- **Abmelden:** über das Benutzermenü im Fuß der Seitenleiste (`/logout` mit CSRF-Token `logout`).
