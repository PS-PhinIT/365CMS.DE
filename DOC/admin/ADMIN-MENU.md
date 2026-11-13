# 365CMS – Projektdokumentation | Abschnitt: Admin – Admin-Menü (Profile, Editor, Plugin-Plätze)

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable
> **Verbindliche Bäume:** [ADMIN-MENU-PROFILE.md](../workflow/specs/ADMIN-MENU-PROFILE.md) · **Entscheidungen:** [KLARSTELLUNGEN.md §7](../workflow/specs/KLARSTELLUNGEN.md)

## English (summary)

Since 4.0.0 the admin sidebar is data-driven. A **catalog** (`CMS\Services\AdminMenu\CoreCatalog`) describes every core admin page once: ID, route, capabilities, page file, badge and tile category. A **layout** only arranges those IDs into sections, direct entries and groups, each shown as a menu or as a tile page. Two built-in profiles ship with the core: **classic** (default, WordPress-like) and **structured** (task-oriented). Administrators can also build a **custom** layout in the sidebar editor (`/admin/settings?tab=admin-menu&view=editor`), either globally or per MultiSites site. `MenuBuilder` combines catalog, plugin menus, layout, capabilities and module state into a cached tree. `AdminNavigationService` adds URLs, the active state and badges on every request; badges are never cached.

Plugins keep registering their menus with `add_menu_page()` / `add_submenu_page()` in the `cms_admin_menu` hook, which is unchanged. Every page of an active plugin that the layout does not place itself appears in the last sidebar section, **"Aktive Plugins"** (active plugins), in every profile. The section is collapsed by default and shows the number of its entries; it opens for the current plugin page, for "expand all" or when the user expanded it. A plugin with one page is a link, a plugin with several pages a group; both are sorted alphabetically. Only a custom layout can place plugin pages elsewhere. `cms_admin_menu_set_preferred_parent()` and the filter `cms_admin_menu_preferred_parents` remain for compatibility but no longer affect placement. No entry is ever lost.

To add a core page: add a catalog row, place the ID in both profiles, update the spec trees, and run `php TESTS/admin-menu/run.php`.

## Deutsch

### 1. Bausteine

| Baustein | Datei | Aufgabe |
|---|---|---|
| `AdminMenuItem` | `CMS/core/Services/AdminMenu/AdminMenuItem.php` | Unveränderliches Datenobjekt eines Menüeintrags (Core oder Plugin) |
| `CoreCatalog` | `…/AdminMenu/CoreCatalog.php` | Alle Core-Seiten mit Kachel-Kategorien (`CATEGORY_LABELS`) und Editor-Bereichen (`AREA_LABELS`) |
| `MenuRegistry` | `…/AdminMenu/MenuRegistry.php` | Katalog plus Plugin-Menüs aus `get_registered_admin_menus()` |
| `ProfileLayouts` | `…/AdminMenu/ProfileLayouts.php` | Deklarative Profile `classic` und `structured` |
| `LayoutValidator` | `…/AdminMenu/LayoutValidator.php` | Prüft und normalisiert eigene Layouts und JSON-Importe |
| `LayoutRepository` | `…/AdminMenu/LayoutRepository.php` | Tabelle `admin_menu_layouts`: eine Zeile je Bereich (`''` = global, sonst Site-Schlüssel) mit Versionszähler |
| `MenuBuilder` | `…/AdminMenu/MenuBuilder.php` | Erzeugt aus Registry, Layout, Rechten, Modulen und Seitendateien den Baum; rein funktional und ohne Datenbank testbar |
| `BadgeProvider` | `…/AdminMenu/BadgeProvider.php` | Zähler für Badges; nie gecacht |
| `AdminMenuService` | `…/AdminMenu/AdminMenuService.php` | Fassade für Profil, Layouts, Cache, Audit-Log sowie Export und Import |
| `AdminNavigationService` | `CMS/core/Services/AdminNavigationService.php` | Sidebar-Modell: URLs, aktiver Eintrag, Brotkrumen, Badges, Aufklappzustand, Kachelseiten |
| Sidebar | `CMS/admin/partials/sidebar.php`, `sidebar-nav.php` | Ausgabe; `sidebar-nav.php` rendert auch die Vorschau |
| Plugin-API | `CMS/includes/functions/admin-menu-profiles.php` | `cms_admin_menu_set_preferred_parent()` – nur noch aus Kompatibilität, ohne Wirkung |

### 2. Welches Layout gilt?

Die Einstellung `admin_menu_profile` steht auf `classic` (Standard), `structured` oder `custom`. Für die aktuelle Site gilt:

1. Hat die Site unter **MultiSites** ein eigenes Layout, gilt dieses immer.
2. Sonst gilt bei `custom` das globale eigene Layout.
3. Sonst gilt das eingebaute Profil. `custom` ohne globales Layout fällt auf `classic` zurück.

Gespeicherte Layouts werden beim Laden erneut validiert, weil sich der Katalog seit dem Speichern geändert haben kann. Layouts von Sites, die unter MultiSites gelöscht werden, entfernt `AdminMenuService::pruneUnknownSites()` beim Speichern der MultiSites. Ohne konfigurierte Sites gibt es nur den globalen Bereich.

### 3. Katalog der Core-Seiten

Jede Core-Adminseite steht genau einmal in `CoreCatalog::rows()`. Felder einer Zeile:

| Schlüssel | Pflicht | Bedeutung |
|---|---|---|
| `id` | ja | Stabile ID `<bereich>.<name>`; Bereichspräfixe siehe `AREA_LABELS`, Muster `^[A-Za-z0-9][A-Za-z0-9_.:-]{0,150}$`. **Nie umbenennen**, denn gespeicherte Layouts verweisen darauf. |
| `label` | ja | Deutsche Bezeichnung; zugleich Fallback für den Sprachschlüssel `admin_menu.item.<id>` |
| `route` | ja | `/admin/…`, Query erlaubt (z. B. `/admin/settings?tab=feeds`); bei `type` = `link` eine Member- oder Frontend-Route |
| `icon` | ja | Name eines Tabler-Icons |
| `caps` | ja | Capabilities als Werte; eine davon genügt. `[]` heißt: nur das isAdmin-Gate. |
| `file` | Seiten | Seitendatei relativ zu `CMS/`. Fehlt die Datei, bleibt der Eintrag ausgeblendet (vorgeplante Seiten). |
| `page` | Seiten | Seiten-Slug für den aktiven Eintrag (`$activePage`) und die Core-Modul-Prüfung |
| `category`, `desc` | Kacheln | Kategorie (`CATEGORY_LABELS`) und Beschreibung; Pflicht für jeden Eintrag einer Kachelgruppe |
| `tile` | – | Abweichende Bezeichnung auf der Kachel |
| `badge` | – | Badge-Schlüssel (siehe Abschnitt 6) |
| `locked` | – | Nicht ausblendbar und nicht entfernbar: Dashboard, Benutzer, Rollen & Rechte, Admin-Menü |
| `type` | – | `page` (Standard) oder `link` (externer Link wie „Mein Profil“, ohne Seitendatei) |
| `marketplace` | – | Nur sichtbar, wenn der Marketplace aktiviert ist |

Ein Eintrag ist sichtbar, wenn die Seitendatei existiert, das Core-Modul aktiv ist, die Marketplace-Bedingung erfüllt ist, der Benutzer eine der Capabilities hat und der Eintrag nicht ausgeblendet ist. `MenuBuilder::hiddenReason()` liefert dem Editor den Grund: `file`, `module`, `marketplace` oder `capability`. Leere Gruppen und Abschnitte entfallen.

**Neue Core-Seite aufnehmen:**

1. Seitendatei mit Zugriffsprüfung anlegen. Prüft die Seite ihre Capability als String-Literal oder als Konstante in derselben Datei, vergleicht der Test sie automatisch mit dem Katalog. Andernfalls (Bereichs-Map, Closure, gemeinsamer Renderer) einen kommentierten Eintrag in `MANUAL_ACCESS` in `TESTS/admin-menu/run.php` ergänzen.
2. Katalogzeile ergänzen, mit denselben Capabilities wie die Seite.
3. Die ID in **beiden** Profilen platzieren (`ProfileLayouts::classic()` und `structured()`) und die Bäume in [ADMIN-MENU-PROFILE.md](../workflow/specs/ADMIN-MENU-PROFILE.md) nachziehen. Menügruppen haben höchstens 8 Einträge (Ausnahme: SEO); größere Bereiche werden Kachelseiten. Beides prüft der Test.
4. Entsteht die Seite erst später, die Datei in `RESERVED_FILES` eintragen. Seitendateien, die bewusst nicht im Menü stehen (Aliasse, JSON-Endpunkte, Renderer), gehören in `NOT_IN_MENU`.
5. `php TESTS/admin-menu/run.php` ausführen.

### 4. Plugin-Menüs im Abschnitt „Aktive Plugins“

Plugins registrieren ihre Menüs unverändert im Hook `cms_admin_menu` mit `add_menu_page()` und `add_submenu_page()` (`CMS/includes/functions/admin-menu.php`). Die Registry liest das Ergebnis nur.

**IDs der Plugin-Einträge:**

- `plugin:<menü-slug>` für ein Menü ohne Unterseiten
- `plugin:<menü-slug>:<unterseiten-slug>` für Unterseiten. Fehlt eine Unterseite mit dem Slug des Hauptmenüs, wird eine „Übersicht“ vorangestellt.

Unzulässige Zeichen im Slug werden durch `-` ersetzt. Administratoren können Plugin-Einträge im Editor platzieren, umbenennen und ausblenden. Die IDs bleiben stabil, solange sich die Slugs nicht ändern. Layouts behalten Plugin-IDs auch dann, wenn das Plugin gerade deaktiviert ist.

**Abschnitt „Aktive Plugins“** (Nutzerentscheidung, [KLARSTELLUNGEN.md §7](../workflow/specs/KLARSTELLUNGEN.md)):

- Alle Seiten aktiver Plugins, die das Layout nicht selbst platziert, stehen in jedem Profil gesammelt im letzten Abschnitt der Seitenleiste – auch die früher unter *Plugins* bzw. *Erweitern* einsortierten.
- Der Abschnitt ist standardmäßig **eingeklappt** und zeigt im Kopf die Zahl seiner Einträge. Er öffnet sich, wenn die aktuelle Seite darin liegt, bei „Alle aufklappen“ oder wenn der Benutzer ihn aufgeklappt hat (gespeichert unter `g:_active-plugins`).
- Ein Plugin mit **einer** Seite ist ein Link, ein Plugin mit **mehreren** Seiten eine Gruppe `plugin.<menü-slug>` mit Menütitel und Icon. Beide sind gemeinsam natürlich nach Titel sortiert.
- Die Abschnitts-ID `_active-plugins` (`MenuBuilder::ACTIVE_PLUGINS_SECTION_ID`) beginnt mit `_` und kollidiert deshalb mit keiner Layout-ID.
- Die Brotkrume einer Plugin-Einzelseite lautet „Aktive Plugins › <Titel>“.

**Weitere Regeln für Plugin-Menüs:**

- Mit `$hidden = true` registrierte Menüs erscheinen nicht.
- Plugins, die der aktuellen Site nicht zugewiesen sind (Plugin-Zuweisung), werden ausgelassen. Ist dadurch kein Plugin-Eintrag sichtbar, zeigt „Aktive Plugins“ einen Hinweis mit Link zur Plugin-Zuweisung.
- Icons aus `$icon_url`: `ti ti-<name>` oder `ti-<name>` wird ein Tabler-Icon. Inline-`<svg>` und `data:image/…;base64` werden ein Bild, ebenso ein Pfad bzw. eine URL derselben Site. Bis zu 4 Zeichen (z. B. ein Emoji) werden als Text angezeigt. Alles andere ergibt das Standard-Icon `puzzle`.
- Andere Plätze legt nur der Administrator in einem **eigenen Layout** (Sidebar-Editor) fest. Dort platzierte Plugin-Seiten bleiben an ihrem Platz.
- `cms_admin_menu_set_preferred_parent()` und der Filter `cms_admin_menu_preferred_parents` bleiben aus Kompatibilität erhalten, beeinflussen die Platzierung aber nicht mehr.

**Reihenfolge für nicht platzierte Einträge:**

1. **Core-Einträge** kommen in ihre Gruppe des Basisprofils, sonst in die Auffanggruppe (`fallback_group`). Hat ein eigenes Layout keine Auffanggruppe, entsteht am Ende des letzten Layout-Abschnitts die Gruppe `more` („Weitere“).
2. **Plugin-Einträge** kommen zu einer im Layout bereits in einer Gruppe platzierten Seite desselben Plugins.
3. Sonst in eine Layout-Gruppe mit der ID `plugin.<menü-slug>`, falls vorhanden.
4. Sonst in den Abschnitt **„Aktive Plugins“**.

**Gruppen-IDs der eingebauten Profile** (Kacheln = eigene Kachelseite unter `/admin/configuration`):

| Classic | Bezeichnung | | Structured | Bezeichnung |
|---|---|---|---|---|
| `posts` | Beiträge | | `content` | Inhalt |
| `media` | Medien | | `media` | Medien |
| `pages` | Seiten | | `editorial` | Redaktion |
| `forms` | Formulare | | `ai` | KI-Dienste ¹ |
| `ai` | KI-Dienste ¹ | | `structure` | Struktur |
| `design` | Design | | `design` | Design |
| `plugins` | Plugins (**Auffanggruppe**) | | `seo` | SEO & Marketing ¹ |
| `seo` | SEO ¹ | | `extend` | Erweitern (**Auffanggruppe**) |
| `users` | Benutzer | | `people` | Personen |
| `members` | Mitgliederbereich ¹ (Kacheln) | | `members` | Mitgliederbereich ¹ (Kacheln) |
| `subscriptions` | Abos ¹ | | `subscriptions` | Abos ¹ |
| `tools` | Werkzeuge | | `configuration` | Konfiguration (Kacheln, Startseite) |
| `settings` | Einstellungen (Kacheln, Startseite) | | `reports` | Berichte (Kacheln) |
| `health` | Website-Zustand (Kacheln) | | | |

¹ Gruppe eines optionalen Core-Moduls. Ohne aktives Modul ist sie leer und damit ausgeblendet.

Platziert ein eigenes Layout Plugin-Seiten in einer Kachelgruppe, erscheinen sie dort als Kacheln in einer Kategorie mit dem Menütitel des Plugins.

### 5. Layout-Format (Editor, Export, Import)

Beispiel (gekürzt):

```json
{
  "format": "365cms-admin-menu",
  "version": 1,
  "base": "classic",
  "fallback_group": "plugins",
  "root_group": "settings",
  "nodes": [
    { "type": "separator", "id": "sep.work", "label": "Arbeit" },
    { "type": "item", "id": "dashboard" },
    { "type": "group", "id": "posts", "label": "Beiträge", "icon": "article", "display": "menu",
      "items": ["content.posts", "content.post-categories"] },
    { "type": "group", "id": "settings", "label": "Einstellungen", "icon": "settings", "display": "tiles",
      "items": ["settings.general", "plugin:my-plugin:my-plugin-reports"] }
  ],
  "labels": { "content.posts": "Artikel" },
  "icons": { "content.posts": "news" },
  "hidden": ["design.fonts"]
}
```

- `base` ist das Basisprofil, aus dem nicht platzierte Core-Einträge ihre Gruppe beziehen.
- `fallback_group` muss eine Gruppe des Layouts sein; sie nimmt nicht platzierte Core-Einträge auf. `root_group` muss eine Kachelgruppe sein; sie öffnet unter `/admin/configuration` ohne `group`-Parameter.
- Der Export (`…&action=export`) umhüllt das Layout mit `format`, `format_version`, `exported_at`, `cms_version`, `scope` (`global` oder `site`), `site_key` und `layout`. Der Import akzeptiert die Hülle oder das reine Layout.

**Validierung (`LayoutValidator`):**

| Regel | Wert |
|---|---|
| Format und Version | `365cms-admin-menu`, Version 1 |
| JSON-Import | höchstens 256 KB, Verschachtelungstiefe höchstens 16 |
| Elemente insgesamt | höchstens 200 |
| Bezeichnungen | ohne HTML, höchstens 60 Zeichen |
| Gruppen- und Trenner-IDs | `^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`; doppelte IDs werden umbenannt |
| Icons | Tabler-Name `^[a-z0-9][a-z0-9-]{0,59}$`, sonst `folder` |
| Anzeigemodus | `menu` oder `tiles` |

Unbekannte Core-IDs und doppelte Einträge werden mit Warnung verworfen, verschachtelte Gruppen abgeflacht. Fehlende gesperrte Einträge setzt der Validator wieder ein: in ihre Gruppe des Basisprofils, sonst als direkten Eintrag (Dashboard oben). Ein unbekanntes Basisprofil wird `classic`.

### 6. Badges

`BadgeProvider` zählt bei jedem Seitenaufbau neu und nur für Schlüssel, die im sichtbaren Baum vorkommen. Zahlen über 99 erscheinen als „99+“. Remote-Abfragen gibt es nicht; Update-Zahlen stammen aus den Caches der Update-Prüfung. Ein Fehler in einer Quelle ergibt 0.

| Schlüssel | Quelle |
|---|---|
| `updates` | verfügbares Core-Update (0 oder 1) plus ausstehende Plugin-Updates |
| `comments` | Kommentare mit Status `pending` (warten auf Freigabe) |
| `privacy_requests` | DSGVO-Anfragen (Auskunft und Löschung) mit Status `pending` oder `processing` |
| `logs` | ungelesene Protokoll-Hinweise aus dem Sidebar-Kontext |
| `form_submissions` | neue Formular-Eingänge; die Formular-Verwaltung meldet den Zähler über `register()` an |

Gruppen und Kachel-Links zeigen die Summe der Badges ihrer Einträge. So erscheinen offene DSGVO-Anfragen auch am Sidebar-Link „Einstellungen“ bzw. „Konfiguration“.

Weitere Zähler meldet der Core mit `BadgeProvider::register($key, $counter, ['1 neuer Eintrag', '%d neue Einträge'])` an. Der Schlüssel entspricht `^[a-z][a-z0-9_]{0,63}$`, eingebaute Schlüssel lassen sich nicht überschreiben. Der Counter erhält den Sidebar-Kontext und gibt eine Zahl zurück.

### 7. Kachelseiten

Jede Gruppe im Anzeigemodus `tiles` ist in der Sidebar ein einzelner Link auf eine Kachelseite. Das gilt in beiden Profilen und in eigenen Layouts.

- `/admin/configuration` öffnet die Wurzel-Kachelgruppe (`root_group`): Einstellungen bzw. Konfiguration.
- `/admin/configuration?group=<id>` öffnet eine andere Kachelgruppe, z. B. `health`, `reports` oder `members`.
- Eine unbekannte, ungültige oder nicht sichtbare Gruppe liefert **404**.
- Die Kacheln sind nach Kategorie gegliedert und zeigen Beschreibung und Badge. Quelle ist derselbe Baum wie die Sidebar (`AdminNavigationService::tilesPage()`).

### 8. Einstellungen › Admin-Menü und Sidebar-Editor

| Route | Zweck |
|---|---|
| `/admin/settings?tab=admin-menu` | Profil-Karten (Klassisch, Strukturiert, Eigenes Layout) mit Live-Vorschau; Layouts je Site mit Export, Import und Zurücksetzen |
| `/admin/settings?tab=admin-menu&view=editor[&scope=<site>][&start=classic\|structured\|empty]` | Sidebar-Editor für das globale Layout oder das Layout einer Site |
| `/admin/settings?tab=admin-menu&action=export[&scope=<site>]` | JSON-Download |

- Zugriff haben nur Administratoren mit `manage_admin_menu`; die Capability ist Standard für die Rolle `admin`.
- Änderungen nur per POST (`action` = `profile`, `save`, `import`, `reset`) mit CSRF-Aktion `admin_menu_settings`. Die Live-Vorschau (`action=preview`, JSON-Antwort) nutzt das wiederverwendbare Token `admin_menu_preview`.
- Profilwechsel, Speichern, Import und Zurücksetzen landen im Audit-Log.
- Dateien des Editors: `CMS/admin/views/settings/admin-sidebar-editor.php`, `CMS/assets/js/admin-sidebar-editor.js` und `CMS/assets/css/admin-sidebar-editor.css`. Der Theme-Menü-Editor (`admin-menu-editor.js`) ist davon getrennt.

**Funktionen des Editors:**

- Zwei Spalten: verfügbare Einträge nach Bereich und das Layout.
- Bedienung per Ziehen und Ablegen, Pfeil-Buttons oder Alt+Pfeiltasten, mit Ansagen für Screenreader.
- Gruppen und Trenner, eigene Bezeichnungen, Icon-Auswahl, Anzeigemodus Liste oder Kacheln.
- Ausblenden mit Anzeige des Grunds, warum ein Eintrag gerade nicht sichtbar ist.
- Start ab Classic, Structured oder leer; Speichern, Verwerfen und Zurücksetzen; Export und Import; Live-Vorschau.
- Warnung beim Verlassen mit ungespeicherten Änderungen.

### 9. Cache und Sidebar-Zustand

Der Baum aus `MenuBuilder` wird 10 Minuten gecacht (`admin_menu_tree_v1_…`). Der Cache-Schlüssel enthält:

- Profil, Bereich und Site, Layout-Version
- Plugin-Fingerprint
- Ergebnis der Capability-Prüfungen
- Zustand der Core-Module und vorhandene Seitendateien
- Marketplace-Einstellung, Sprache, CMS-Version und Code-Stand

Jede dieser Änderungen ergibt also sofort einen neuen Baum. **Badges, URLs und der aktive Eintrag werden nie gecacht**, sondern nach dem Cache-Abruf ergänzt.

Abschnitte sind aufgeklappt, bis der Benutzer sie zuklappt. Gruppen sind zugeklappt, außer sie enthalten die aktive Seite oder wurden geöffnet. Der Zustand wird je Benutzer serverseitig gespeichert.

### 10. Installer

Schritt 4 von 6 („Admin-Layout“) bietet Klassisch und Strukturiert mit einer Skizze der Hauptpunkte. Die Skizze erzeugt der echte `MenuBuilder` mit allen Rechten und den Core-Modulen aus Schritt 3. Der Schritt läuft ohne Datenbank und ohne Autoloader; `InstallerService::loadAdminMenuClasses()` bindet die Klassen ein. Gespeichert wird `admin_menu_profile`. Ein eigenes Layout entsteht erst im Editor.

### 11. Übersetzung

Bezeichnungen sind deutsche Fallbacks. Übersetzungen kommen über diese Sprachschlüssel:

- `admin_menu.item.<id>`, `admin_menu.description.<id>` und `admin_menu.tile.<id>` für Einträge und Kacheln
- `admin_menu.category.<kategorie>` für Kachel-Kategorien
- `admin_menu.<profil>.group|separator|label.<id>` für die eingebauten Profile

Eigene Bezeichnungen aus dem Editor werden nicht übersetzt.

### 12. Tests

```bash
php TESTS/admin-menu/run.php        # PHP >= 8.4.1, ohne Datenbank; -v für Details
```

| Teil | Inhalt |
|---|---|
| 1 | Katalog: IDs, Routen, Seitendateien, Icons, Badges, Kategorien |
| 2 | Statischer Zugriffsvergleich Seitendatei ↔ Katalog; Sonderfälle in `MANUAL_ACCESS` |
| 3 | Abdeckung: jede Adminseite steht im Katalog oder in `NOT_IN_MENU` |
| 4 | Beide Profile: jeder Eintrag genau einmal platziert, Entscheidungen aus KLARSTELLUNGEN §7 |
| 5a–5e | Unit-Tests für Validator, Registry, Builder, Plugin-API und Sidebar-Modell mit Fake-Rechten |
| 6 | Installer-Schritt im eigenen PHP-Prozess ohne Autoloader |

Der Test ändert nie Rollen oder Rechte in der Datenbank. Er läuft in der CI im Workflow `security.yml`.
