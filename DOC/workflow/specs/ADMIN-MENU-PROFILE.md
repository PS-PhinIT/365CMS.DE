# 365CMS – Projektdokumentation | Abschnitt: Workflow – Spec: Admin-Sidebar-Menü mit Profilen
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** In Umsetzung | **Update:** 2026-11-10
> **Fortschritt:** [PROGRESS.md](PROGRESS.md) · **Verbindliche Abweichungen:** [KLARSTELLUNGEN.md](KLARSTELLUNGEN.md)

## English (summary)

Data-driven admin sidebar with three profiles (classic, structured, custom), a menu registry, builder, installer step, settings tab and a visual menu editor. The binding final trees for both profiles are in the section "Finale Struktur (verbindlich)". The original requirement text is reproduced unchanged below. Where it conflicts with
[KLARSTELLUNGEN.md](KLARSTELLUNGEN.md) (single 4.0.0 release, dark sidebar, no site assignment for content,
hub-site rules), the clarifications take precedence.

## Deutsch

Originaltext der Anforderung (unverändert). Bei Widersprüchen gelten die
[Klarstellungen](KLARSTELLUNGEN.md), insbesondere:

- Alles gehört zu Release 4.0.0 (keine eigene Version).
- Die dunklere Sidebar gilt; „light sidebar design“ ist überholt.
- Menü-Layouts je Site sind erlaubt (MultiSite = Admin-Kontext).

### Finale Struktur (verbindlich)

Diese Bäume setzen der Katalog und die Profile unter `CMS/core/Services/AdminMenu/` genau um. Grundlage sind die Rückmeldungen in [KLARSTELLUNGEN.md](KLARSTELLUNGEN.md) §7.

| Zeichen | Bedeutung |
|---|---|
| 🔒 | gesperrt: immer sichtbar, im Editor weder ausblendbar noch entfernbar |
| `*B`, `*WP1` … `*WP4` | vorgeplant: Der Eintrag erscheint erst, wenn seine Seite in Phase B bzw. im Arbeitspaket entsteht. Eine Gruppe ohne sichtbare Einträge bleibt ausgeblendet. |
| `opt. <Modul>` | nur sichtbar, wenn das Core-Modul aktiv ist |
| `Mkt.` | nur sichtbar, wenn der Marketplace aktiviert ist |
| `[Kacheln]` | Kachelseite: ein Sidebar-Link, die Einträge erscheinen als Kacheln, gegliedert nach Kategorie |
| `↗` | externer Link (Member- oder Frontend-Route), kein Admin-Seiteneintrag |
| `(Annahme)` | nach §7 selbst entschieden, nicht ausdrücklich vorgegeben |

Badges sind in jedem Profil gleich. Eine Gruppe bzw. Kachelseite zeigt an ihrem Sidebar-Link die Summe der Badges ihrer Einträge.

#### Classic (Standard)

13 Hauptpunkte ohne optionale Module, 17 mit allen Modulen.

```text
── Arbeit ─────────────────────────────────────────────────────────────────────
Dashboard 🔒                                     /admin
Updates                (Badge: Updates)          /admin/updates
Beiträge
  ├─ Alle Beiträge                               /admin/posts
  ├─ Kategorien                                  /admin/post-categories
  ├─ Tags                                        /admin/post-tags
  └─ Redaktionskalender *WP2                     /admin/editorial-calendar
Medien
  ├─ Mediathek                                   /admin/media
  ├─ Beitrags- & Site-Medien                     /admin/media?tab=featured
  ├─ Medienkategorien                            /admin/media?tab=categories
  ├─ Medien-Check                                /admin/media?tab=check
  ├─ Downloads *B  (Tabs: Liste · Statistik · Einstellungen)
  │                                              /admin/downloads
  ├─ Medien-Werkzeuge *WP4                       /admin/media-tools
  └─ Einstellungen                               /admin/media?tab=settings
Seiten
  ├─ Alle Seiten                                 /admin/pages
  ├─ Hub-Sites                                   /admin/hub-sites
  ├─ Tabellen                                    /admin/site-tables
  └─ Inhaltsverzeichnis                          /admin/table-of-contents
Kommentare             (Badge: offene Kommentare) /admin/comments
Formulare *B
  ├─ Formulare                                   /admin/forms
  └─ Eingänge                                    /admin/form-submissions
KI-Dienste             (opt. KI)
  ├─ KI-Dashboard                                /admin/ai-services
  ├─ Übersetzung                                 /admin/ai-translation
  ├─ Inhaltsassistent                            /admin/ai-content-creator
  └─ SEO-Assistent                               /admin/ai-seo-creator

── Gestaltung ─────────────────────────────────────────────────────────────────
Design
  ├─ Themes                                      /admin/themes
  ├─ Theme-Editor                                /admin/theme-editor
  ├─ Theme-Explorer                              /admin/theme-explorer
  ├─ Menüs                                       /admin/menu-editor
  ├─ Landingpage                                 /admin/landing-page
  ├─ Schriften                                   /admin/font-manager
  ├─ Login-Seite                                 /admin/cms-loginpage
  └─ Theme-Marktplatz (Mkt.)                     /admin/theme-marketplace
Plugins                (Auffanggruppe für Plugin-Menüs ohne Wunschplatz)
  ├─ Plugins verwalten                           /admin/plugins
  ├─ Plugin-Marktplatz (Mkt.)                    /admin/plugin-marketplace
  ├─ Plugin-Zuweisung                            /admin/plugin-assignment
  ├─ Module                                      /admin/modules
  └─ … Plugin-Einträge
SEO                    (opt. SEO, genau 9 Seiten)
  ├─ SEO-Dashboard                               /admin/seo-dashboard
  ├─ Analysen                                    /admin/analytics
  ├─ SEO-Prüfung                                 /admin/seo-audit
  ├─ Meta-Daten                                  /admin/seo-meta
  ├─ Soziale Medien                              /admin/seo-social
  ├─ Strukturierte Daten                         /admin/seo-schema
  ├─ Sitemap & robots.txt                        /admin/seo-sitemap
  ├─ Suchmaschinen                               /admin/seo-search-engines
  └─ Technisches SEO                             /admin/seo-technical

── Verwaltung ─────────────────────────────────────────────────────────────────
Benutzer
  ├─ Benutzer 🔒                                 /admin/users
  ├─ Gruppen                                     /admin/groups
  ├─ Rollen & Rechte 🔒                          /admin/roles
  ├─ Mein Profil ↗                               /member/profile
  └─ Einstellungen                               /admin/user-settings
Mitgliederbereich [Kacheln]  (opt. Mitglieder; Kacheln wie Structured: Annahme)
  │                                              /admin/configuration?group=members
  └─ Übersicht · Allgemein · Design & Farben · Frontend-Module · Dashboard-Widgets ·
     Plugin-Widgets · Profil-Felder · Benachrichtigungen · Onboarding
Abos                   (opt. Abos)
  ├─ Pakete                                      /admin/packages
  ├─ Bestellungen                                /admin/orders
  └─ Einstellungen                               /admin/subscription-settings
Werkzeuge
  ├─ Import/Export *WP2                          /admin/content-exchange
  ├─ Papierkorb *WP1                             /admin/trash
  ├─ Letzte Änderungen *WP2                      /admin/content-changes
  ├─ Übersetzungen *WP2                          /admin/translations
  ├─ Suchindex *WP2                              /admin/search-index
  ├─ Weiterleitungen (opt. SEO)                  /admin/redirect-manager
  ├─ 404-Monitor (opt. SEO)                      /admin/not-found-monitor
  └─ Backups                                     /admin/backups
Einstellungen [Kacheln]  (Badge: Summe offener DSGVO-Anfragen)
  │                                              /admin/configuration
  ├─ Allgemein:           Allgemein · Inhalte · Feeds *B · E-Mail & OAuth2 · MultiSites ·
  │                       Admin-Menü 🔒
  ├─ Sicherheit:          Login & Sperren *WP1 · Anti-Spam · Firewall
  ├─ Recht & Datenschutz: Rechtstexte · Cookie-Manager ·
  │                       Auskunft & Löschen (Badge: offene Anfragen) ·
  │                       Löschfristen *WP3 (Annahme: hier statt unter Werkzeuge)
  ├─ Performance:         Übersicht · Cache · Medien-Optimierung · Datenbank ·
  │                       Einstellungen · Sessions
  └─ KI:                  KI-Einstellungen (opt. KI)
Website-Zustand [Kacheln]  (Badge: Summe der Protokoll-Hinweise)
  │                                              /admin/configuration?group=health
  ├─ Protokolle:          Übersicht · Operativer Log · Sicherheits-Audit-Log · PHP-Fehlerlog ·
  │                       Kanal-Logs & Update-Historie
  ├─ Monitore:            Assets · Antwortzeit · Cron-Job-Status · Speichernutzung ·
  │                       Geplante Aufgaben · Systemprüfung · E-Mail-Benachrichtigungen ·
  │                       Warnungen (Annahme: bisher nicht im Menü)
  ├─ Sicherheit:          Sicherheits-Audit
  ├─ System:              Systeminfo · Diagnose (Datenbank)
  └─ Hilfe:               Dokumentation  (zusätzlich Hilfe-Icon in der Topbar)
```

#### Structured

11 Hauptpunkte ohne optionale Module, 15 mit allen Modulen. Es gibt keine Abschnitts-Trenner.

```text
Dashboard 🔒                                     /admin
Updates                (Badge: Updates)          /admin/updates
Inhalt                 (Badge: Summe offener Kommentare)
  ├─ Seiten                                      /admin/pages
  ├─ Beiträge                                    /admin/posts
  ├─ Kommentare        (Badge: offene Kommentare) /admin/comments
  ├─ Formulare *B                                /admin/forms
  ├─ Eingänge *B                                 /admin/form-submissions
  └─ Papierkorb *WP1                             /admin/trash
Medien
  ├─ Mediathek                                   /admin/media
  ├─ Beitrags- & Site-Medien (Annahme)           /admin/media?tab=featured
  ├─ Medienkategorien                            /admin/media?tab=categories
  ├─ Medien-Check                                /admin/media?tab=check
  ├─ Downloads *B  (Tabs: Liste · Statistik · Einstellungen)
  │                                              /admin/downloads
  └─ Medien-Werkzeuge *WP4                       /admin/media-tools
Redaktion              (erscheint mit der ersten WP2-Seite)
  ├─ Redaktionskalender *WP2                     /admin/editorial-calendar
  ├─ Letzte Änderungen *WP2                      /admin/content-changes
  └─ Übersetzungen *WP2                          /admin/translations
KI-Dienste             (opt. KI)
  ├─ KI-Dashboard                                /admin/ai-services
  ├─ Übersetzung                                 /admin/ai-translation
  ├─ Inhaltsassistent                            /admin/ai-content-creator
  └─ SEO-Assistent                               /admin/ai-seo-creator
Struktur
  ├─ Menüs                                       /admin/menu-editor
  ├─ Kategorien                                  /admin/post-categories
  ├─ Tags                                        /admin/post-tags
  ├─ Hub-Sites                                   /admin/hub-sites
  ├─ Tabellen                                    /admin/site-tables
  ├─ Inhaltsverzeichnis                          /admin/table-of-contents
  ├─ Weiterleitungen (opt. SEO)                  /admin/redirect-manager
  └─ 404-Monitor (opt. SEO)                      /admin/not-found-monitor
Design                 (Annahme: Reihenfolge wie Classic, ohne Menüs)
  ├─ Themes                                      /admin/themes
  ├─ Theme-Editor                                /admin/theme-editor
  ├─ Theme-Explorer                              /admin/theme-explorer
  ├─ Landingpage                                 /admin/landing-page
  ├─ Schriften                                   /admin/font-manager
  ├─ Login-Seite                                 /admin/cms-loginpage
  └─ Theme-Marktplatz (Mkt.)                     /admin/theme-marketplace
SEO & Marketing        (opt. SEO, die 9 SEO-Seiten wie Classic; Platz nach Design: Annahme)
Erweitern              (Auffanggruppe für Plugin-Menüs ohne Wunschplatz)
  ├─ Plugins verwalten                           /admin/plugins
  ├─ Plugin-Marktplatz (Mkt.)                    /admin/plugin-marketplace
  ├─ Plugin-Zuweisung                            /admin/plugin-assignment
  ├─ Module                                      /admin/modules
  └─ … Plugin-Einträge
Personen
  ├─ Benutzer 🔒                                 /admin/users
  ├─ Gruppen                                     /admin/groups
  ├─ Rollen & Rechte 🔒                          /admin/roles
  ├─ Benutzereinstellungen                       /admin/user-settings
  └─ Mein Profil ↗                               /member/profile
Mitgliederbereich [Kacheln]  (opt. Mitglieder)   /admin/configuration?group=members
  └─ Übersicht · Allgemein · Design & Farben · Frontend-Module · Dashboard-Widgets ·
     Plugin-Widgets · Profil-Felder · Benachrichtigungen · Onboarding
Abos                   (opt. Abos)
  ├─ Pakete                                      /admin/packages
  ├─ Bestellungen                                /admin/orders
  └─ Einstellungen                               /admin/subscription-settings
Konfiguration [Kacheln]  (Badge: Summe offener DSGVO-Anfragen)
  │                                              /admin/configuration
  ├─ Allgemein:           Allgemein · Inhalte · Feeds *B · E-Mail & OAuth2 · MultiSites ·
  │                       Admin-Menü 🔒
  ├─ Sicherheit:          Login & Sperren *WP1 · Anti-Spam · Firewall
  ├─ Recht & Datenschutz: Rechtstexte · Cookie-Manager ·
  │                       Auskunft & Löschen (Badge: offene Anfragen) · Löschfristen *WP3
  ├─ Performance:         Übersicht · Cache · Medien-Optimierung · Datenbank ·
  │                       Einstellungen · Sessions
  ├─ Medien:              Medien-Einstellungen (Annahme)
  ├─ KI:                  KI-Einstellungen (opt. KI; Annahme: analog Classic)
  └─ Wartung:             Backups · Import/Export *WP2 · Suchindex *WP2 (Annahme)
Berichte [Kacheln]  (Badge: Summe der Protokoll-Hinweise)
  │                                              /admin/configuration?group=reports
  ├─ Protokolle:          Übersicht · Operativer Log · Sicherheits-Audit-Log · PHP-Fehlerlog ·
  │                       Kanal-Logs & Update-Historie
  ├─ Monitore:            Assets · Antwortzeit · Cron-Job-Status · Speichernutzung ·
  │                       Geplante Aufgaben · Systemprüfung · E-Mail-Benachrichtigungen ·
  │                       Warnungen (Annahme)
  ├─ Sicherheit:          Sicherheits-Audit
  ├─ System:              Systeminfo · Diagnose (Datenbank)
  └─ Hilfe:               Dokumentation  (zusätzlich Hilfe-Icon in der Topbar)
```

#### Gemeinsame Regeln

- Nicht im Menü stehen Weiterleitungs-Aliase und JSON-Endpunkte, z. B. `support`, `system-info`, `cms-logs`, `privacy-requests`, `deletion-requests`, `design-settings`, `theme-settings`, `monitor-cron-runner`, `error-report`, `admin-ui` und `post-editor-api`. Sie leiten auf eine Seite aus dem Katalog weiter oder liefern nur Daten.
- Ohne `group`-Parameter zeigt `/admin/configuration` die Gruppe `settings` (Classic) bzw. `configuration` (Structured). Fehlt diese Gruppe als Kachelseite im aktiven Layout, gibt es ebenfalls 404 (Annahme).
- Steht ein Eintrag auf einer Kachelseite, markiert die Sidebar den Link der Kachelseite als aktiv.
- Im eigenen Layout („custom“) gelten dieselben Gruppen-IDs, wenn es aus Classic oder Structured kopiert wurde. Plugins können diese IDs als Wunschplatz angeben, z. B. `['classic' => 'tools', 'structured' => 'reports']`.

### Originaltext

```text
[TASK TYPE: Feature]

Context:
- Stack: PHP 8.4 / HTML / CSS / JavaScript (vanilla), self-built CMS "365CMS"
- Admin UI is based on Tabler with a light sidebar layout and a site switcher (MultiSite: phinit.de / 365network.de)
- The admin sidebar menu is currently hardcoded / built ad hoc. Plugins already register their own menu entries via the existing plugin hook mechanism.
- Analyze the current menu rendering, the plugin menu registration and the settings page (/admin/settings with ?tab=) first, then implement.

Goal:
Make the admin sidebar menu data-driven and switchable between three profiles:
  1. "classic"    – WordPress-style: flat sidebar, one top-level item per content type
  2. "structured" – Drupal/TYPO3-style: Start, Content, Structure, Design, Extend, People, Configuration, Reports
  3. "custom"     – user-built via a new "Admin Sidebar Menu Editor"
The profile can be chosen during installation and changed later in the settings. Routes, permissions and badges stay identical across all profiles — only grouping, order, labels, icons and visibility differ.

Requirements:

1. Menu registry (single source of truth)
- Create a central AdminMenuRegistry that collects all menu items as data objects:
  id (stable string key, e.g. "content.pages"), route, label, icon (Tabler icon name),
  capability, badge callback (optional), source ("core" | "plugin:<slug>"), locked (bool)
- Register every existing core admin page as an item (dashboard, updates, pages, posts,
  categories, tags, comments, landing pages, hub sites, tables, TOC, media, media categories,
  media check, themes, theme editor, theme files, menus, fonts, login page, plugins, modules,
  marketplace, plugin assignment, users, groups, roles, profile, legal texts, cookie consent,
  GDPR export/deletion, all settings tabs, sites/multisite, backup, scheduled tasks, logs,
  system status, documentation)
- Plugin items registered through the existing hook are fed into the same registry.
  Plugins may optionally provide a preferred parent per profile, e.g.
  ['classic' => 'tools', 'structured' => 'reports']; fallback is a "Plugins"/"Extend" group.

2. Profile definitions
- Define "classic" and "structured" as declarative layouts (PHP array or JSON file under a
  config directory): ordered groups → ordered child item ids, plus group label, icon and
  separators between blocks ("Work", "Design", "Manage").
- classic: Dashboard, Posts, Media, Pages, Comments | Design, Plugins, Users, Tools, Settings
- structured: Start, Content, Structure, Design, Extend, People, Configuration, Reports
  (Configuration renders a tile overview page instead of a long submenu)
- Max. 2 levels in the sidebar for all profiles.
- A MenuBuilder resolves the active profile + registry + current user capabilities + current
  site into the final tree. Items the user lacks capability for are hidden; empty groups are hidden.
- Items from the registry that are not placed in the active layout (e.g. newly installed
  plugin) appear automatically in their preferred parent or in a fallback group — never silently lost.

3. Installer step
- Add a step to the installer: "Admin layout" with two selectable cards (Classic / Structured),
  each with a short description and a small preview sketch of the sidebar. Default: classic.
- Store the choice as setting admin_menu_profile.

4. Settings
- New tab in /admin/settings?tab=admin-menu with:
  - Radio cards: Classic · Structured · Custom
  - Live preview of the resulting sidebar (rendered by the MenuBuilder, not a static image)
  - Button "Open menu editor" (enabled for Custom; for Classic/Structured offers
    "Copy into custom layout and edit")
- Switching the profile takes effect immediately after save, no logout required.

5. Admin Sidebar Menu Editor (/admin/settings/admin-menu/editor)
- Two-pane UI:
  left  = "Available items" (all registry items not in the layout, searchable, grouped by source)
  right = current custom layout as a nested sortable tree (groups → items)
- Drag & drop to reorder items and move them between groups and the available list
  (vanilla HTML5 drag & drop; also keyboard-accessible with up/down/indent/outdent buttons)
- Actions: add group, add separator, rename group/item label, choose icon (Tabler icon picker
  with search), hide item, delete group (children move back to "Available")
- Start from: empty / copy of Classic / copy of Structured
- Save, Discard changes, Reset to profile default
- Export layout as JSON, import JSON (validate schema and unknown ids; unknown ids are skipped with a warning)
- Live sidebar preview updates while editing
- Optional scope: global default layout, overridable per site (MultiSite). Show which scope is being edited.

6. Persistence & safety
- Store custom layouts as JSON (settings table or dedicated table admin_menu_layouts with
  site_id nullable, layout_json, updated_by, updated_at). Validate on save server-side.
- Locked items must always remain reachable for admins and cannot be hidden/removed:
  Settings → Admin menu (editor itself), Dashboard, Users/Roles. Prevent lockout.
- Labels only change display text; routes and capabilities can never be edited in the editor.
- CSRF protection on all save/import endpoints, capability "manage_admin_menu" (admins only).
- Write an audit log entry on profile change, layout save, import and reset.
- Cache the resolved menu per user role + site + profile; invalidate on layout save,
  plugin (de)activation and role changes.

7. Rendering
- The sidebar template renders only from the MenuBuilder output (no hardcoded entries left).
- Active-state detection by current route (item and its parent group expanded).
- Badges (updates, comments moderation) work in every profile.
- All UI texts in German, translatable via the existing i18n mechanism if present.

Constraints:
- DO NOT change how plugins hook into the CMS or register menu entries — existing plugins
  must work unchanged. Extend the registry behind the existing hook instead.
- Keep all existing admin routes and URLs unchanged.
- Keep the current Tabler-based light sidebar design and the site switcher; no new CSS framework.
- No new heavy JS dependencies; vanilla JS only.
- Follow existing code structure, naming and PSR conventions of the project.

Deliverables:
- Registry, builder, profile definitions, installer step, settings tab, editor page + JS, migration for storage
- Short developer note (Markdown) explaining how plugins can declare preferred parents per profile
- Tests for MenuBuilder: capability filtering, unplaced plugin items, locked items, profile switching
```
