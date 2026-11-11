# 365CMS – Projektdokumentation | Abschnitt: Admin – Einstieg & Navigation

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

The administration starts at `/admin` and is reserved for users with role **`admin`** (`AdminRouter` checks `Auth::isAdmin()`; guests are redirected to the login page, other roles to `/member`). `CMS/core/Routing/AdminRouter.php` registers four patterns: `/admin`, `/admin/:page`, `/admin/logs/:section` and `/admin/plugins/:plugin/:page` (GET and POST). Since 4.0.0 the shell has a stone-grey collapsible sidebar on the left (264 px wide, 76 px as an icon rail, an off-canvas drawer below 992 px). From top to bottom it contains:

- a site switcher (MultiSites),
- the menu tree of the active **sidebar profile** (*Classic*, the default; *Structured*; or a custom layout),
- a user footer with system notices.

`CMS\Services\AdminMenu\AdminMenuService` with `MenuBuilder` builds the tree from the page catalogue, the profile, capabilities, core modules and plugin menus. Plugin menu registration is unchanged. `CMS\Services\AdminNavigationService` adds the active entry, badges and breadcrumbs, and `CMS/admin/partials/sidebar.php` renders the result. Details: [ADMIN-MENU.md](ADMIN-MENU.md).

Pages of disabled core modules stay hidden. The sidebar state of each user is stored server-side (`admin_ui_prefs`). This folder documents every admin area; the table below shows where each area sits in both profiles.

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

Die Sidebar zeigt das aktive **Sidebar-Profil**. Es wird über die Einstellung `admin_menu_profile` unter *Einstellungen › Admin-Menü* gewählt; zur Auswahl stehen:

- *Klassisch* (Standard, ähnlich wie WordPress),
- *Strukturiert* (aufgabenorientiert),
- ein eigenes Layout aus dem Sidebar-Editor, global oder je Site.

Katalog, Editor, Kachelseiten und die Plätze von Plugin-Menüs beschreibt [ADMIN-MENU.md](ADMIN-MENU.md). Die verbindlichen Bäume beider Profile stehen in [ADMIN-MENU-PROFILE.md](../workflow/specs/ADMIN-MENU-PROFILE.md).

Von oben nach unten folgen Site-Umschalter, Menübaum und Benutzerbereich.

- Im Profil *Klassisch* gliedern die Abschnitte *Arbeit*, *Gestaltung* und *Verwaltung* den Baum.
- Das Profil *Strukturiert* hat keine Abschnitte.
- Kachelgruppen sind ein einzelner Link auf ihre Kachelseite (`/admin/configuration?group=<id>`; die Hauptgruppe *Einstellungen* bzw. *Konfiguration* hat keinen `group`-Parameter).

| Bereich und Dokumentation | Klassisch | Strukturiert |
|---|---|---|
| [dashboard/](dashboard/README.md) | Dashboard (direkter Link) | Dashboard (direkter Link) |
| [Updates](system-settings/UPDATES.md) | Updates (direkter Link mit Badge) | Updates (direkter Link mit Badge) |
| [pages-posts/](pages-posts/README.md) | *Beiträge* (mit Kategorien, Tags, Redaktionskalender), *Seiten* (mit Hub-Sites, Tabellen, Inhaltsverzeichnis), *Kommentare* (direkter Link mit Badge) | *Inhalt*; Kategorien, Tags, Hub-Sites, Tabellen und Inhaltsverzeichnis unter *Struktur* |
| [media/](media/README.md) | *Medien* | *Medien*; Medien-Einstellungen unter *Konfiguration* |
| [ai/AI-SERVICES.md](ai/AI-SERVICES.md) | *KI-Dienste* (optional); KI-Einstellungen unter *Einstellungen* | *KI-Dienste* (optional); KI-Einstellungen unter *Konfiguration* |
| [themes-design/](themes-design/README.md), [landing-page/](landing-page/README.md) | *Design* | *Design*; Menüs unter *Struktur* |
| [plugins/](plugins/README.md) | *Plugins* (Plugins verwalten, Plugin-Marktplatz, Plugin-Zuweisung, Module) | *Erweitern* |
| [seo/](seo/README.md) | *SEO* (optional); Weiterleitungen und 404-Monitor unter *Werkzeuge* | *SEO & Marketing* (optional); Weiterleitungen und 404-Monitor unter *Struktur* |
| [users-groups/](users-groups/README.md) | *Benutzer* | *Personen* |
| [member/](member/README.md) | *Mitgliederbereich* (optional, Kachelseite) | *Mitgliederbereich* (optional, Kachelseite) |
| [subscription/](subscription/README.md) | *Abos* (optional) | *Abos* (optional) |
| [Backups](system-settings/BACKUP.md) und weitere Werkzeuge | *Werkzeuge* | *Konfiguration* › Wartung |
| [system-settings/](system-settings/README.md), [MultiSites](system-settings/MULTISITES.md) | *Einstellungen* (Kachelseite) | *Konfiguration* (Kachelseite) |
| [legal/](legal/README.md) | *Einstellungen* › Recht & Datenschutz | *Konfiguration* › Recht & Datenschutz |
| [security/](security/README.md) | *Einstellungen* › Sicherheit; Sicherheits-Audit unter *Website-Zustand* | *Konfiguration* › Sicherheit; Sicherheits-Audit unter *Berichte* |
| [performance/](performance/README.md) | *Einstellungen* › Performance | *Konfiguration* › Performance |
| [RSS-Feeds](system-settings/FEEDS.md) | *Einstellungen* | *Konfiguration* |
| [diagnose/](diagnose/README.md), [info/](info/README.md) | *Website-Zustand* (Kachelseite: Protokolle, Monitore, System, Hilfe) | *Berichte* (Kachelseite) |
| Plugin-Menüs ([plugins/PLUGINS.md](plugins/PLUGINS.md#plugin-adminseiten), [ADMIN-MENU.md](ADMIN-MENU.md#4-plugin-menüs-und-wunschplätze-je-profil)) | Auffanggruppe *Plugins*, eigene Gruppe direkt danach oder Wunschplatz | Auffanggruppe *Erweitern*, eigene Gruppe oder Wunschplatz |

*Optional* bedeutet: Die Gruppe erscheint nur bei aktivem Core-Modul.

Badges zeigen Updates, Kommentare, neue Fehler in den Protokollen und offene DSGVO-Anfragen. Badges von Kacheln werden am Link der Kachelseite summiert.

Weitere Sichtbarkeitsregeln:

- Der Plugin-Marktplatz erscheint nur bei `marketplace_enabled = 1`.
- Plugins, die der aktuellen Site nicht zugewiesen sind, fehlen in der Sidebar. Bleibt dadurch kein Plugin-Eintrag übrig, zeigt die Auffanggruppe einen Hinweis mit Link zur Plugin-Zuweisung.

### Admin-Shell (seit 4.0.0)

| Baustein | Verhalten |
|---|---|
| Layout | Seitenleiste + Hauptspalte (`<main id="cms-main">`); erstes fokussierbares Element ist der Skip-Link „Zum Inhalt springen“. Topbar mit Brotkrümelnavigation und Kontextaktion. |
| Abschnitte und Gruppen | Beide sind auf- und zuklappbar (`aria-expanded`). Abschnitte (nur im Profil *Klassisch*) sind standardmäßig offen. Gruppen sind standardmäßig geschlossen; die Gruppe der aktiven Seite ist immer offen. Manuelle Zustände werden je Benutzer gespeichert. |
| Aktiver Eintrag | `aria-current="page"` und Akzent-Hintergrund (`--cms-accent-soft`) |
| Icon-Leiste | Der Panel-Button im Kopf der Seitenleiste (`aria-expanded`) klappt sie auf 76 px ein: Labels ausgeblendet, Tooltips bei Hover und Tastaturfokus, Untereinträge als Flyout. Der Zustand wird je Benutzer serverseitig gespeichert. |
| Drawer (< 992 px) | Hamburger „Navigation öffnen“ in der Topbar öffnet die Seitenleiste als Off-Canvas-Drawer mit Fokusfalle; Escape, Hintergrund-Klick und „Navigation schließen“ schließen ihn. |
| Site-Umschalter | Zeigt die aktuelle Site mit ihrer Akzentfarbe. Ab zwei Sites öffnet er eine Liste aller Sites (`AdminSiteContextService`, Option `admin_sites`, gepflegt unter [MultiSites](system-settings/MULTISITES.md)). Ein Wechsel speichert den Kontext serverseitig und lädt die Administration in dieser Site neu. Ohne gespeicherte Sites gilt nur die Haupt-URL (`SITE_URL`) mit der Standard-Akzentfarbe `#4f46e5`. |
| Benutzerbereich | Avatar mit Name und Rolle; das Popover enthält Mein Profil, Website ansehen und Abmelden. |
| Systemhinweise | Nur mit `manage_system`: ein Punkt am Avatar, sobald es Hinweise gibt (letzter Cron-Lauf fehlgeschlagen, letztes Backup älter als 24 Stunden oder keines vorhanden, Core- oder Plugin-Updates verfügbar). Die Liste mit Links steht im Popover. Ohne Hinweise entfällt der Punkt. |
| Tastatur | Tab wechselt zwischen Einträgen, Enter/Leertaste klappt Gruppen, Pfeil hoch/runter und Pos1/Ende bewegen innerhalb einer Gruppe bzw. eines Flyouts, Pfeil rechts/links öffnet und schließt Einträge mit Untereinträgen, Escape schließt Flyouts und den Drawer. |
| Bewegung | Bei `prefers-reduced-motion: reduce` laufen Auf-/Zuklappen und Flyouts ohne Animation. |

Technik:

- Menübaum: `CMS\Services\AdminMenu\AdminMenuService` mit `MenuBuilder`. Er setzt den Baum aus Katalog, Profil bzw. eigenem Layout, Rechten, Core-Modulen und Plugin-Menüs zusammen, gefiltert über `PluginSiteAssignmentService` je Site, und speichert ihn im Cache. Siehe [ADMIN-MENU.md](ADMIN-MENU.md).
- Sidebar-Modell: `CMS\Services\AdminNavigationService::build()` ergänzt aktiven Eintrag, Badges, Brotkrumen und Aufklappzustand; Badges werden nie gecacht. Ausgabe über `CMS/admin/partials/sidebar.php`, `sidebar-nav.php` und `topbar.php`.
- Styles: `CMS/assets/css/admin-shell.css`, Tokens in `CMS/assets/css/admin-tabler.css` ([PANEL-INTEGRATION.md](PANEL-INTEGRATION.md#gestaltung)).
- Verhalten: ES-Module unter `CMS/assets/js/admin-shell/` (`shell.js` als Einstieg, `sidebar.js`, `drawer.js`, `site-switcher.js`, `user-menu.js`, `recent-links.js`, `api.js`, `dom.js`), aufgelöst über eine Importmap in `header.php`, ohne Build-Schritt.
- Benutzer-Präferenzen: `cms_user_meta.meta_key = 'admin_ui_prefs'` (`CMS\Services\AdminUiPreferenceService`) mit `sidebar_collapsed`, Gruppen-Zuständen und der gewählten Site.
- JSON-Endpunkt `/admin/admin-ui` (`CMS/admin/admin-ui.php`): `GET action=notices` (Systemhinweise, `manage_system`, 5 Minuten Cache), `GET action=token`, `POST action=prefs` und `POST action=switch_site` (CSRF-Aktion `admin_ui`). Details: [ADMIN-API-AJAX.md](ADMIN-API-AJAX.md).

### Querschnittsdokumente

| Thema | Dokument |
|---|---|
| Bedienleitfaden für Administratoren | [GUIDE.md](GUIDE.md) |
| Admin-Menü: Profile, Katalog, Editor, Kachelseiten, Plugin-Plätze | [ADMIN-MENU.md](ADMIN-MENU.md) |
| MultiSites (Admin-Kontext je Domain) | [system-settings/MULTISITES.md](system-settings/MULTISITES.md) |
| Dateistruktur `CMS/admin/` | [FILESTRUCTURE.md](FILESTRUCTURE.md) |
| Eigene Adminseiten (Core und Plugin) | [PANEL-INTEGRATION.md](PANEL-INTEGRATION.md) |
| JSON-API und AJAX | [ADMIN-API-AJAX.md](ADMIN-API-AJAX.md) |
| Prüf-Checkliste für Änderungen | [PRUEF-CHECKLISTE.md](PRUEF-CHECKLISTE.md) |

### Gemeinsame Konventionen

- **Layout:** Tabler-basierte Admin-Shell (seit 4.0.0 eigene Seitenleiste statt `navbar-vertical`), Header/Sidebar/Topbar/Footer aus `CMS/admin/partials/`.
- **Formulare:** POST mit `csrf_token` (eine Token-Aktion je Seite, z. B. `admin_pages`), danach Redirect (PRG) und Flash-Meldung.
- **Berechtigung:** Rolle `admin` plus Capability je Seite (meist `manage_settings`).
- **Module:** Feature-Bereiche lassen sich unter `/admin/modules` abschalten. Ihre Menüeinträge verschwinden dann in jedem Profil ([system-settings/MODULES.md](system-settings/MODULES.md)).
- **Abmelden:** über das Benutzermenü im Fuß der Seitenleiste (`/logout` mit CSRF-Token `logout`).
