# 365CMS – Projektdokumentation | Abschnitt: Workflow – Klarstellungen zu den Specs
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Verbindlich | **Update:** 2026-11-10
> **Gilt für:** [ADMIN-MENU-PROFILE.md](ADMIN-MENU-PROFILE.md) · [PLUGIN-INTEGRATION.md](PLUGIN-INTEGRATION.md) · [ROADMAP-15-FEATURES.md](ROADMAP-15-FEATURES.md) · Fortschritt: [PROGRESS.md](PROGRESS.md)

## English (summary)

Binding clarifications that override the original spec texts:

- Everything ships as release 4.0.0, with one schema bump (v24 → v25) and idempotent migrations.
- The darker admin sidebar stays.
- MultiSite remains an admin context only: there is no site assignment for content.
- Hub sites take part in the trash and in export/import, but not in the search index, revisions, editorial calendar or translation status.
- Trash, export/import and search index use content-type providers rather than hub-site special cases, so downloads and forms can plug in later.
- SQL values are always bound parameters.

## Deutsch

Diese Festlegungen haben Vorrang vor den Originaltexten der Specs. Sie stammen aus den Rückmeldungen
zur Umsetzung und werden hier fortgeschrieben.

### 1. Version und Schema

- Alles gehört zu **Release 4.0.0** (ein großes Release). Versionsangaben in den Specs werden ignoriert, z. B. „4.1.0“, „next minor version“ oder „eine Version pro Arbeitspaket/PR“.
- Schema-Änderungen laufen sauber über `SchemaManager` und `MigrationManager`, mit **einem** neuen Schema-Stand **v25**. Die Migrationen sind idempotent, d. h. ein erneuter Lauf erzeugt keine Duplikate.
- Der Changelog-Abschnitt `### v4.0.0` wird ergänzt. Die RELEASE-Pakete 4.0.0 werden neu gebaut, inklusive SHA-256 in beiden `update.json`.

### 2. Admin-Sidebar

- Die **dunklere Sidebar** gilt. Die Formulierung „light sidebar design“ im Menü-Spec ist überholt.

### 3. MultiSite

- Es gibt **keine Site-Zuordnung für Inhalte** und keine `site_id`/`site_key`-Spalten in Inhaltstabellen.
- MultiSite ist und bleibt ein **Admin-Kontext**. Dazu gehören:
  - Domains/Hosts
  - Akzentfarbe
  - Site-Umschalter
  - Plugin-Zuweisung
  - Menü-Layout je Site
- Die Inhalte von 365network.de laufen über die bestehende **Hub-Site** und bleiben dort.
- Die Site-Dimension für Inhalte entfällt überall, wo die Specs „respect site_id“, „per site“, „target site“ oder „site filter“ fordern. Das betrifft Kalender, Revisionen, Übersetzungen, Suchindex, Export/Import, Downloads, Formulare, Feeds und Statistiken.
- Wo der Host eine Rolle spielt, wird die **bestehende** Host- bzw. Hub-Site-Auflösung genutzt; es wird nichts Neues erfunden. Das gilt für absolute URLs in Feeds, Sitemap und Canonicals, für Download- und Formular-Links sowie für Mails.

### 4. Hub-Sites

Hub-Sites sind Einträge in `cms_site_tables` mit `content_mode = 'hub'`. Verwaltet werden sie unter `/admin/hub-sites` (`HubSitesModule`), ausgeliefert über `SiteTableService` (Auflösung per Slug bzw. Domain). Technisch sind sie **keine** Seiten oder Beiträge.

| Feature | Hub-Sites | Festlegung |
|---|---|---|
| Papierkorb (#1) | **ja** | Löschen verschiebt in den Papierkorb. Wiederherstellen und automatisches Leeren funktionieren wie bei Seiten. Die bestehenden Hub-Site-Daten (Einstellungen, CSS/JS, Templates, Einträge) kommen beim Wiederherstellen **vollständig** zurück. Deshalb wird nur markiert (Soft-Delete) und nicht gelöscht. |
| Export/Import (#8) | **ja** | Eigener Inhaltstyp „Hub-Sites“. Der Export umfasst Einstellungen, Templates, CSS/JS, Einträge und referenzierte Medien. Der Import bietet Vorschau und Konfliktbehandlung (überspringen / überschreiben / umbenennen). |
| Suchindex (#7) | **nein** | Hub-Site-Inhalte werden nicht indexiert. Das entspricht dem bisherigen Verhalten. Die alternative Variante „indexieren, sofern öffentlich ausgeliefert“ wird **nicht** umgesetzt; sie wäre später ein zusätzlicher Such-Provider. |
| Revisionen (#5) | **nein** | ausdrücklich ausgenommen |
| Redaktionskalender (#4) | **nein** | ausdrücklich ausgenommen |
| Übersetzungsstatus (#6) | **nein** | ausdrücklich ausgenommen |

### 5. Erweiterbarkeit statt Sonderfälle

Papierkorb, Export/Import und Suchindex arbeiten über **Inhaltstyp-Provider**, die sich in einer zentralen Registry anmelden. Es gibt eine Schnittstelle je Fähigkeit:

- Papierkorb: auflisten, verschieben, wiederherstellen, endgültig löschen, Fristablauf
- Export/Import: exportieren, Vorschau, importieren mit Konfliktstrategie
- Suchindex: Dokumente liefern

Für den Start sind diese Provider vorgesehen:

- Seiten, Beiträge und Medien (Papierkorb)
- Hub-Sites (Papierkorb und Export/Import)
- Seiten, Beiträge und Taxonomien (Export/Import)

Downloads und Formulare können später eigene Provider anmelden, ohne dass der Feature-Code geändert wird. Der Code der Features enthält keine `if hub`-Sonderfälle.

### 6. Arbeitsweise

- Die Specs liegen vollständig unter `DOC/workflow/specs/`. Den Status je Punkt führt [PROGRESS.md](PROGRESS.md).
- Reihenfolge der Umsetzung:
  1. Admin-Menü fertigstellen
  2. Plugin-Integration
  3. Arbeitspakete 1 bis 4
- Sub-Agenten werden nur für Features eingesetzt, die **keine gemeinsamen Dateien** anfassen. Gemeinsame Dateien (Schema, Rollen, Navigation, Sprachdateien, Doku-Indizes, Changelog, Release) bearbeitet nur die Integration.
- **SQL:** Werte sind immer gebundene Parameter (PDO Prepared Statements). Das gilt auch nach einer Regex-Prüfung und auch für feste Status-Werte (`IN (?, ?)`). Nur Tabellen- und Spaltennamen aus internen Whitelists werden eingesetzt (siehe `DOC/DEVLIST.md`).
- Die frühere Lückenliste (Funktion / Vorbild / Einordnung A/B) dient nur als **Referenz** für die Menü-Einordnung. Aus ihr wird nichts zusätzlich umgesetzt.

### 7. Admin-Menü (Hinweise vor der Implementierung)

- **Cache-Schlüssel** des aufgelösten Menüs:
  - Capabilities
  - Site
  - Profil
  - Plugin-Fingerprint
  - Layout-Version des eigenen Layouts
  - Zustand der Core-Module (`CoreModuleService`)
  - Marketplace-Einstellung
  - Sprache
- **Badges** werden **nie** gecacht. Dazu gehören Updates, Kommentare, Logs usw. Sie werden nach dem Cache-Abruf frisch ausgewertet.
- **Dateinamen:** `CMS/assets/js/admin-menu-editor.js` gehört zum bestehenden Theme-Menü-Editor und bleibt unverändert.
  - Der neue Sidebar-Editor hat eigene Dateien: `admin-sidebar-editor.js`, `admin-sidebar-editor.css` und den View `admin/views/settings/admin-sidebar-editor.php`.
  - Am bestehenden Menü-Editor (`/admin/menu-editor`) wird nichts geändert.
- **Katalog vorausgeplant:** IDs und Plätze in „classic“ und „structured“ sind schon jetzt für alle Seiten aus Phase B und WP1–4 registriert:
  - Formulare
  - Downloads
  - Feeds
  - Papierkorb
  - Redaktionskalender
  - Letzte Änderungen
  - Übersetzungen
  - Suchindex
  - Import/Export
  - Medien-Werkzeuge
  - Konfigurations-Kacheln

  Einträge ohne vorhandene Seite bleiben ausgeblendet, bis die Seite existiert. Die Profile müssen später nicht umgebaut werden.
- **Editor-Route ohne Router-Änderung:** `/admin/settings?tab=admin-menu&view=editor`. Die Spec-Route `/admin/settings/admin-menu/editor` wird nicht angelegt.
- **Zugriffsprüfung (statisch abgesichert):**
  - Die `cms_admin_*_can_access()`-Funktionen bleiben unverändert in den Seitendateien. Es gibt keine gemeinsame Include-Datei dafür.
  - Der Katalog führt die Capabilities als **Werte**, auch „eine von mehreren“ (z. B. Beiträge: `edit_all_posts` | `edit_own_posts` | `posts.view`).
  - Ein **statischer Test** (`TESTS/admin-menu/run.php`) bleibt bewusst einfach, ohne Tokenizer oder Code-Analyse:
    - Für jeden Katalogeintrag prüft er, dass Route und Seitendatei existieren.
    - Capabilities vergleicht er automatisch nur dort, wo die Seite sie als einfaches String-Literal oder als Konstante in derselben Datei prüft.
    - Alle anderen Seiten stehen in einer **expliziten, kommentierten Liste** im Test, mit der erwarteten Capability. Diese Liste wird von Hand gepflegt. Dazu gehören Bereichs-Maps (SEO, Performance, Mitglieder-Dashboard), Closures und gemeinsame Renderer.
    - Jede Abweichung lässt den Test fehlschlagen.
  - Wichtiger sind die **Unit-Tests für den MenuBuilder** mit Fake-Registry und Fake-Rechteprüfung. Sie decken ab:
    - Capability-Filter
    - nicht platzierte Plugin-Einträge
    - gesperrte Einträge
    - Profilwechsel
    - Validierung
  - Es gibt **keinen** Laufzeittest, der Rechte der Admin-Rolle in der Datenbank verändert.
  - Hinweis: `Auth::hasCapability()` liefert für die Rolle `admin` immer `true` (`CMS/core/Auth.php:455`). Die Capability-Filter im Menü sind damit eine reine Schutzschicht.
- **Dashboard und Updates** sind in beiden Profilen **direkte Einträge** (Links, keine Gruppe). „Updates“ trägt das Update-Badge. Das Dashboard ist so mit einem Klick erreichbar.
- **Gruppengröße:**
  - Faustregel für beide Profile: Eine Gruppe mit mehr als ca. 8 Einträgen wird eine Kachelseite (`display: tiles`, ein Sidebar-Link, Einzelseiten als Kacheln) oder wird aufgeteilt. Ausnahme: Die SEO-Gruppe behält ihre 9 Seiten als normales Menü.
  - Classic: höchstens ca. 12 Hauptpunkte ohne optionale Module. Das ist ein Richtwert; 13 sind in Ordnung. Kachelseiten sind „Einstellungen“ und „Website-Zustand“ (Protokolle, Monitore, Diagnose, Systeminfo, Sicherheits-Audit, Dokumentation).
  - Structured: Kachelseiten sind „Konfiguration“, „Berichte“ und „Mitgliederbereich“.
- **Kachelseiten:**
  - Sie laufen über `/admin/configuration?group=<gruppen-id>`. Für „Einstellungen“ (Classic) bzw. „Konfiguration“ (Structured) entfällt der Parameter.
  - Ein ungültiger oder unbekannter `group`-Wert liefert **404**. Es gibt keinen stillen Fallback.
  - Die Kacheln sind nach der Katalog-Kategorie des Eintrags gegliedert, z. B. Allgemein, Sicherheit, Recht & Datenschutz, Performance, Protokolle, Monitore, Hilfe.
- **Platzierung (verbindlich, nicht weiter abwägen):**
  - Kommentare bleibt in Classic ein eigener Hauptpunkt mit Badge.
  - SEO ist ein eigener Hauptpunkt mit genau den **9 SEO-Seiten** und nur bei aktivem SEO-Modul sichtbar. Die SEO-Seiten werden nicht auf andere Gruppen verteilt.
  - Weiterleitungen und 404-Monitor stehen in Classic unter „Werkzeuge“ und in Structured unter „Struktur“, jeweils nur bei aktivem SEO-Modul.
  - Downloads sind in beiden Profilen **ein** Eintrag unter „Medien“. Liste, Statistik und Einstellungen sind Tabs der Downloads-Seite.
  - Formulare sind in Classic ein eigener Hauptpunkt (Formulare, Eingänge). In Structured sind es zwei Einträge unter „Inhalt“.
  - Feeds stehen unter Einstellungen bzw. Konfiguration.
  - KI-Dienste sind ein optionaler Hauptpunkt: in Classic im Abschnitt „Arbeit“ direkt nach Formulare, in Structured direkt nach „Redaktion“. Die KI-Einstellungen sind eine Kachel unter Einstellungen.
  - Sicherheit & Recht und Performance sind Kacheln unter Einstellungen bzw. Konfiguration. Ausnahme: Der Sicherheits-Audit gehört zu Website-Zustand bzw. Berichte.
  - Dokumentation ist eine Kachel in der Kategorie „Hilfe“ auf Website-Zustand bzw. Berichte und zusätzlich ein Hilfe-Icon in der Topbar.
  - Optionale Module (Mitglieder, Abos, KI, SEO) sind eigene Hauptpunkte und nur sichtbar, wenn das Modul aktiv ist.
  - Die finalen Bäume beider Profile stehen in [ADMIN-MENU-PROFILE.md](ADMIN-MENU-PROFILE.md) unter „Finale Struktur (verbindlich)“. Punkte, die ich selbst nach diesem Abschnitt entschieden habe, sind dort mit „(Annahme)“ markiert.
  - Der Eintrag Medienkategorien heißt in beiden Profilen **„Medienkategorien“**, nie nur „Kategorien“. So ist er im Editor und in Listen eindeutig von den Beitragskategorien unterscheidbar.
- **Badges an Kachelseiten:**
  - Der Sidebar-Link einer Gruppe oder Kachelseite zeigt die Summe der Badges ihrer Einträge.
  - Die DSGVO-Anfragen (Auskunft & Löschung) zeigen die Zahl offener Anfragen auf ihrer Kachel. Dieselbe Zahl erscheint summiert am Sidebar-Link „Einstellungen“ bzw. „Konfiguration“, damit offene Anfragen nicht übersehen werden.
- **Externe Links:** Der Katalog erlaubt Einträge vom Typ „externer Link“ für Member- und Frontend-Routen, z. B. „Mein Profil“ (`/member/profile`). Sie sind keine Admin-Seiteneinträge. Der statische Test nimmt sie vom Seitendatei-Check aus und weist sie in seiner Ausgabe als externe Links aus.
- **Kein Unterverzeichnis:** `SITE_URL` hat laut `DOC/INSTALLATION.md` und DEVLIST kein Unterverzeichnis. Das Route-Matching geht vom Pfad ab `/admin` aus und braucht keine Basis-Pfad-Logik.
- **isAdmin-Gate bleibt:** Der Adminbereich ist nur für die Rolle `admin` zugänglich. Die Capability-Filter im Menü bleiben trotzdem drin, als Schutz bei reduzierten Admin-Rechten und für später.
  - Es gibt keine Sonderlogik für Redakteure im Admin.
  - Am bestehenden `isAdmin()`-Gate wird nichts geändert.
- **Annahmen bei der Umsetzung** (von mir nach diesem Abschnitt entschieden, durch `TESTS/admin-menu/run.php` abgesichert):
  - **Nicht platzierte Plugin-Seiten** (Nutzerentscheidung; ersetzt die frühere Wunschplatz-Regel und hat Vorrang vor [ADMIN-MENU-PROFILE.md](ADMIN-MENU-PROFILE.md)): Alle Seiten aktiver Plugins, die das Layout nicht selbst platziert, stehen in jedem Profil im letzten Abschnitt „Aktive Plugins“ – auch die früher unter „Plugins“ bzw. „Erweitern“ einsortierten. Der Abschnitt ist standardmäßig eingeklappt, zeigt die Zahl seiner Einträge und öffnet sich bei aktueller Plugin-Seite, „Alle aufklappen“ oder gespeichertem Zustand. Einzelseiten sind Links, mehrseitige Plugins Gruppen `plugin.<menü>`, gemeinsam natürlich sortiert. Weitere Seiten eines Plugins folgen einer im Layout platzierten Seite desselben Plugins. Wunschplätze (`cms_admin_menu_set_preferred_parent()`) wirken nicht mehr.
  - **Nicht platzierte Core-Einträge** kommen in ihre Gruppe des Basisprofils, falls es sie im Layout gibt, sonst in die Auffanggruppe, ohne Auffanggruppe in „Weitere“. Hauptpunkte des Basisprofils (z. B. Kommentare) haben keine Gruppe und kommen direkt in die Auffanggruppe. Kein Eintrag geht verloren.
  - **Gesperrte Einträge** sind Dashboard, Benutzer, Rollen & Rechte und Admin-Menü. Sie bleiben trotz „ausblenden“ sichtbar, brauchen aber weiterhin ihre Capability. Fehlen sie in einem gespeicherten oder importierten Layout, setzt der Validator sie wieder ein.
  - **Erweiterungs-Tabs** in `/admin/settings` (Feeds, Sicherheit, Admin-Menü) werden vor dem `manage_settings`-Gate der Einstellungsseite verteilt. Jeder Tab prüft das isAdmin-Gate und seine eigene Capability selbst.
  - **Vorgeplante Seiten:** Der statische Test führt die 14 noch fehlenden Seitendateien aus Phase B und WP1–4 in der Liste `RESERVED_FILES`. Bis dahin ist der Seitendatei-Check für sie ausgesetzt, und die Einträge bleiben ausgeblendet. Sobald eine dieser Dateien existiert, schlägt der Test fehl, bis sie aus der Liste entfernt ist. Ab dann gilt der normale Zugriffsvergleich.
  - **Abdeckung in Gegenrichtung:** Jede Datei unter `admin/*.php`, `admin/logs/*.php` und `admin/settings-tabs/*.php` steht entweder im Katalog oder in der kommentierten Liste `NOT_IN_MENU` (Weiterleitungs-Aliasse, JSON-Endpunkte, gemeinsame Renderer). Eine neue Admin-Seite ohne Menüeintrag fällt so auf.
  - **Test im Repository:** `TESTS/admin-menu/run.php` lief ohne Datenbank im CI-Job „PHP Syntax Check“. Mit dem Ordner `TESTS/` wurde er am 14.11.2026 entfernt.
  - **Welches Layout gilt:**
    1. Hat die aktuelle Site ein eigenes Layout, gilt es immer.
    2. Sonst gilt das globale eigene Layout, wenn als Profil „Eigenes Layout“ gewählt ist.
    3. Sonst gilt das eingebaute Profil. „Eigenes Layout“ ohne gespeichertes globales Layout fällt auf Classic zurück.
  - **Cache:** Der Schlüssel enthält zusätzlich die vorhandenen Seitendateien, die CMS-Version und den Code-Stand der Menü-Klassen. Ein Update verwirft alte Einträge so ohne manuelles Leeren. Die Lebensdauer beträgt 10 Minuten.
  - **Layouts gelöschter Sites** werden beim Speichern der MultiSites entfernt, zusammen mit deren Plugin-Zuweisung.
  - **Plugin-Hinweis:** Sind alle Plugin-Menüs ausgeblendet, weil der aktuellen Site keine Plugins zugewiesen sind, zeigt der Abschnitt „Aktive Plugins“ statt nichts einen Hinweis mit Link zur Plugin-Zuweisung.
  - **Installer-Schritt „Admin-Layout“:**
    - Er ist der neue Schritt 4 zwischen „Core-Module“ (3) und „Administrator“ (jetzt 5). Der Installer hat damit sechs Schritte.
    - Die Skizze je Profil kommt aus dem echten MenuBuilder: mit allen Rechten, den Core-Modulen aus Schritt 3 und den vorhandenen Seitendateien. Der Installer lädt dafür die Menü-Klassen ohne Autoloader und ohne Datenbank (`InstallerService::loadAdminMenuClasses()`).
    - Gruppen ohne vorhandene Seitendatei bleiben in der Skizze ausgeblendet, wie später in der Sidebar. Bis zu Phase B und WP2 betrifft das „Formulare“ (Classic) und „Redaktion“ (Structured).
    - „Eigenes Layout“ ist bei der Installation nicht wählbar. Es entsteht später im Editor.
    - Die Wahl bleibt beim Zurückgehen zu Schritt 3 erhalten. Der Administrator-Schritt setzt den Layout-Schritt voraus.
