# 365CMS – Projektdokumentation | Abschnitt: Workflow – Umsetzungsfortschritt Release 4.0.0
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** In Umsetzung | **Update:** 2026-11-10
> **Specs:** [ADMIN-MENU-PROFILE.md](ADMIN-MENU-PROFILE.md) · [PLUGIN-INTEGRATION.md](PLUGIN-INTEGRATION.md) · [ROADMAP-15-FEATURES.md](ROADMAP-15-FEATURES.md) · **Vorrang:** [KLARSTELLUNGEN.md](KLARSTELLUNGEN.md)

## English (summary)

Checklist with the status of every item from the three specs. Legend: ✅ done · 🟡 in progress · ⬜ open · ➖ dropped by a clarification.
Order of work: admin menu → plugin integration → work packages 1–4.

## Deutsch

**Legende:** ✅ erledigt · 🟡 in Arbeit · ⬜ offen · ➖ entfällt laut [Klarstellungen](KLARSTELLUNGEN.md)

### 0. Vorarbeiten (erledigt)

| Punkt | Status | Ergebnis |
|---|---|---|
| MultiSites als eigener Einstellungsbereich | ✅ | `/admin/multisites`: Sites mit Name, URL, Hostnamen und Akzentfarbe (Option `admin_sites`) |
| Dunklere Sidebar, mehr Kontrast im Inhaltsbereich | ✅ | Design-Tokens in `admin-tabler.css`, `admin-shell.css` |
| Fehlende Editor-Bereiche Beiträge/Seiten (SEO usw.) | ✅ | SEO-Abschnitte im Seiten- und Beitragseditor |
| Bugfix: doppeltes Template-Select im Seiten-Editor | ✅ | `admin/views/pages/edit.php` band `page-template-fields.php` zweimal ein. Beim Speichern gewann das zweite `<select name="page_template">` und überschrieb die Auswahl im ersten → Datenverlust bei `page_template`. Die zweite Einbindung ist entfernt. |
| Bugfix: SEO-Karten-Überlappung | ✅ | Die geteilten SEO-Partials tragen `.h-100`, dadurch wurde jede Karte so hoch wie das ganze Kartenpaar und schob sich über die folgenden SEO-Karten. Gegenregel `height: auto` in `admin-post-editor.css` (`.cms-editor-extras__pair`) und `admin.css` (`.cms-page-editor-extras`). |
| Bugfix: `cmsConfirm()`-Abbrechen-Button | ✅ | `admin/partials/footer.php` wählte mit `[data-bs-dismiss="modal"]` das ✕ im Kopf statt „Abbrechen“ im Footer und formte es zu einem leeren, vollbreiten Button um. Der Selektor ist jetzt auf `.modal-footer` beschränkt. |
| Bugfix: Unsaved-Indicator im Seitentitel | ✅ | Der unsichtbare Hinweis „Ungespeicherte Änderungen“ rückte den Titel im Seiten-Editor um ca. 200 px ein. Er steht jetzt hinter dem Titel und ist ohne `.is-visible` ausgeblendet (`admin.css`). |
| Gemeinsame Erweiterungspunkte | ✅ | `FeatureHooks\Registry`, Retention-Regel-Registry (`DataRetentionService::registerRule`), `AdminNoticeService::registerCheck`, `EditorJsBlockRegistry`, `BatchJobService` + `admin-batch-runner.js`, `PrivateStorageService`, `IntegratedPluginService` + `PluginMigrator`, Erweiterungs-Tabs in `/admin/settings`, neue Capabilities, `cmsToast()`. Dokumentiert in `SERVICES.md`, `RBAC.md`, `ARCHITECTURE.md` (Bootstrap-Schritt) und `DOC/assets/js/README.md`. `CMS_PRIVATE_PATH` bleibt beim Neuschreiben von `config/app.php` erhalten (Installer, *Einstellungen → Allgemein*; Rundlauf geprüft); die Root-`.htaccess` sperrt `private/` (Apache-Probe: 403, `/privatefoo` unberührt), Nginx-Beispiele ergänzt. |
| Alte Plugin-Adminseiten umleiten | ✅ | `/admin/plugins/cms-{feed,downloads,contact}/…` → 301 auf die neuen Core-Seiten. Deaktivierung, Umleitung und Hinweis greifen je Plugin erst, wenn sein Core-Ersatz vorhanden ist (`IntegratedPluginService::isIntegrated()` prüft die Migrator-Klasse); bis dahin laufen die Plugins unverändert weiter. Laufzeitprobe: ohne Migrator bleibt `cms-feed` aktiv und ohne Umleitung, mit Migrator wird es deaktiviert und umgeleitet. |
| Inhaltstyp-Provider (KLARSTELLUNGEN §5) | ✅ | `ContentTypes\ContentTypeRegistry` mit den Schnittstellen `TrashProvider`, `ExchangeProvider` und `SearchDocumentProvider` samt Wertobjekten; Anmeldung über `cms_register_content_type_providers`. Papierkorb, Export/Import und Suchindex nutzen nur diese Schnittstellen ([CONTENT-TYPE-PROVIDERS.md](../../core/CONTENT-TYPE-PROVIDERS.md)). Test-Suite `TESTS/content-types/run.php`; der Test-Runner liegt jetzt in `TESTS/lib/TestRunner.php`, `TESTS/run-all.php` führt alle Suiten aus (CI). |
| SQL-Prüfung des neuen Codes | ✅ | Alle Werte gebunden (`BatchJobService`: Status-Werte als Parameter, Spalten-Whitelist) |

### A. Admin-Menü mit Profilen – [Spec](ADMIN-MENU-PROFILE.md)

| # | Punkt | Status | Ergebnis |
|---|---|---|---|
| A1 | Menü-Registry (Items als Datenobjekte: id, route, label, icon, capability, badge, source, locked), alle Core-Seiten registriert | ✅ | `AdminMenu\AdminMenuItem` (unveränderliches Datenobjekt) und `AdminMenu\CoreCatalog` mit 107 Einträgen: Route, Label, Tabler-Icon, Capabilities als Werte, Seitendatei, Badge-Schlüssel, Kachel-Kategorie, Beschreibung. 4 Einträge sind gesperrt, 1 ist ein externer Link („Mein Profil“). Alle Admin-Seiten sind erfasst. Nicht im Katalog stehen nur Weiterleitungs-Aliasse, JSON-Endpunkte und gemeinsame Renderer. Die 14 vorgeplanten Seiten aus B und WP1–4 bleiben ausgeblendet, bis ihre Seitendatei existiert. |
| A2 | Plugin-Items über den bestehenden Hook in dieselbe Registry, optionale bevorzugte Eltern je Profil, Fallback-Gruppe | ✅ | `AdminMenu\MenuRegistry` liest die Plugin-Menüs aus `get_registered_admin_menus()`; der Hook bleibt unverändert. IDs: `plugin:<menü>` bzw. `plugin:<menü>:<unterseite>`. Plugins, die der Site nicht zugewiesen sind, werden ausgelassen und gezählt. Wunschplätze je Profil setzen Plugins mit `cms_admin_menu_set_preferred_parent('slug', ['classic' => 'tools', 'structured' => 'reports'])` (neue Datei `includes/functions/admin-menu-profiles.php`; `admin-menu.php` bleibt unverändert) oder über den Filter `cms_admin_menu_preferred_parents`. Die Registry normalisiert sie: nur bekannte Profile und gültige Gruppen-IDs. Ohne Wunschplatz greift die Auffanggruppe (Plugins bzw. Erweitern). Nutzerentscheidung „Aktive Plugins“: nicht platzierte Plugin-Seiten stehen gesammelt im eingeklappten letzten Abschnitt; bevorzugte Eltern ohne Wirkung. |
| A3 | Profile „classic“ und „structured“ deklarativ (Gruppen, Reihenfolge, Trenner), max. 2 Ebenen | ✅ | `AdminMenu\ProfileLayouts`: Classic mit den Abschnitten Arbeit, Gestaltung und Verwaltung, Structured ohne Trenner. Kachelgruppen: Einstellungen und Website-Zustand bzw. Konfiguration und Berichte, dazu der Mitgliederbereich. Der Probelauf mit allen Rechten ergibt genau die Bäume aus [ADMIN-MENU-PROFILE.md](ADMIN-MENU-PROFILE.md); der Validator meldet für beide Profile keine Warnung. |
| A4 | MenuBuilder: Profil + Registry + Capabilities + Site, leere Gruppen ausblenden, nicht platzierte Items nie verlieren | ✅ | `AdminMenu\MenuBuilder` ist rein funktional: Rechte, Modulstatus, Dateiprüfung und Übersetzung werden injiziert. Einträge ohne Recht, mit abgeschaltetem Modul oder ohne Seitendatei sind ausgeblendet (`hiddenReason()` nennt dem Editor den Grund); leere Gruppen und Abschnitte entfallen. Nicht platzierte Einträge gehen nie verloren: Gruppe des Basisprofils, Geschwister-Seiten desselben Plugins, Wunschplatz, Auffanggruppe oder eigene Plugin-Gruppe nach der Auffanggruppe. Site-Bezug über das Layout je Site und die Plugin-Zuweisung (`AdminMenuService::tree()`). |
| A5 | Konfiguration als Kachel-Übersicht (structured) | ✅ | `/admin/configuration[?group=<id>]` (`admin/configuration.php`, `views/configuration/tiles.php`, `admin-configuration.css`) zeigt jede Gruppe im Anzeigemodus „tiles“ – in beiden Profilen und in eigenen Layouts. Quelle ist derselbe Baum wie die Sidebar (`AdminNavigationService::tilesPage()`): Kacheln nach Kategorie, mit Beschreibung und Badge. Ohne `group` öffnet die Wurzel-Kachelgruppe (Einstellungen bzw. Konfiguration); eine unbekannte oder ungültige Gruppe liefert 404. |
| A6 | Installer-Schritt „Admin-Layout“ (Classic/Structured, Vorschau), Setting `admin_menu_profile` | ✅ | Neuer Schritt 4 (`install/views/layout.php`), der Installer hat jetzt sechs Schritte. Auswahl Klassisch/Strukturiert mit einer Skizze der Hauptpunkte aus dem echten MenuBuilder (alle Rechte, Core-Module aus Schritt 3, vorhandene Seitendateien) – ohne Datenbank und ohne Autoloader. Speichert `admin_menu_profile`; „Eigenes Layout“ entsteht erst im Editor. Die Wahl bleibt beim Zurückgehen erhalten, der Administrator-Schritt setzt den Layout-Schritt voraus. Nebenbei behoben: zusammengedrückte Fortschrittspunkte in den Schritt-Ansichten. |
| A7 | Settings-Tab `?tab=admin-menu`: Profil-Karten, Live-Vorschau, Editor öffnen bzw. kopieren | ✅ | Erweiterungs-Tab `admin/settings-tabs/admin-menu.php` (ohne Router-Änderung): Profil-Karten Klassisch, Strukturiert und Eigenes Layout mit serverseitig gerenderter Live-Vorschau (`POST action=preview`, JSON). Editor öffnen oder ab Classic, Structured bzw. leer starten; Übersicht der Layouts je Site mit Export, Import und Zurücksetzen. Nur Admins mit `manage_admin_menu`, Änderungen nur per POST mit CSRF-Token. |
| A8 | Menü-Editor: zwei Spalten, Drag & Drop + Tastatur, Gruppen/Trenner, Labels, Icon-Picker, Ausblenden, Start von leer/Classic/Structured, Speichern/Verwerfen/Zurücksetzen, Export/Import JSON, Live-Vorschau, Scope global/Site | ✅ | `/admin/settings?tab=admin-menu&view=editor[&scope=<site>][&start=…]` mit `admin-sidebar-editor.js`/`.css` und `views/settings/admin-sidebar-editor.php`; der Theme-Menü-Editor `admin-menu-editor.js` bleibt unverändert. Zwei Spalten (verfügbare Einträge, Layout), Ziehen und Ablegen, Pfeil-Buttons und Alt+Pfeiltasten mit Screenreader-Ansagen. Gruppen und Trenner, eigene Bezeichnungen, Icon-Auswahl, Anzeigemodus Liste/Kacheln, Ausblenden mit Grund. Speichern, Verwerfen, Zurücksetzen, JSON-Export/-Import, Live-Vorschau und Bereich global oder je Site; Warnung bei ungespeicherten Änderungen. |
| A9 | Persistenz `admin_menu_layouts`, gesperrte Items (Dashboard, Menü-Editor, Benutzer/Rollen), CSRF, `manage_admin_menu`, Audit-Log, Cache mit Invalidierung | ✅ | `AdminMenu\LayoutRepository`: Tabelle `admin_menu_layouts` mit einer Zeile je Site-Schlüssel (`''` = global) und Versionszähler. Die Tabelle gehört zum Schema-Stand v25 (Neuinstallation und Update); `runSchemaOnce` legt sie nur zur Absicherung an, falls sie fehlt. Gesperrt sind Dashboard, Benutzer, Rollen & Rechte und Admin-Menü; der Validator setzt sie wieder ein. `manage_admin_menu` (Admin-Rolle), CSRF und Audit-Log für Profilwechsel, Speichern, Import und Zurücksetzen (`AdminMenuService`). Cache des Baums (10 min); der Schlüssel enthält Profil, Bereich, Site, Layout-Version, Plugin-Fingerprint, Capabilities, Core-Module, Seitendateien, Marketplace, Sprache und Code-Stand. Badges werden nie gecacht. Layouts gelöschter Sites werden beim Speichern der MultiSites bereinigt. |
| A9a | Serverseitige Validierung der Layouts | ✅ | `AdminMenu\LayoutValidator`: Format und Version, JSON-Größe (256 KB), höchstens 200 Elemente. Prüft IDs, Labels (ohne HTML, max. 60 Zeichen) und Icons. Unbekannte Core-IDs und Duplikate werden mit Warnung verworfen, verschachtelte Gruppen abgeflacht. Fehlende gesperrte Einträge setzt er wieder ein; Auffang- und Kachel-Wurzelgruppe werden geprüft. Beide Profile bestehen ohne Warnung. Alle Fehler- und Warnfälle sind durch A11 abgedeckt, ebenso der JSON-Import: Größe, Syntax, Tiefe und Export-Hülle `{"layout": …}`. |
| A10 | Sidebar rendert nur aus dem Builder, aktiver Zustand, Badges in allen Profilen | ✅ | `partials/sidebar.php` rendert nur noch das Modell aus `AdminNavigationService::build()` (Adapter auf `AdminMenuService::tree()`); die Liste steht in `partials/sidebar-nav.php`, die auch die Vorschau nutzt. Aktiver Eintrag über Query-Routen, Plugin-Pfade, `$activePage` und Kachelseiten (`aria-current`), dazu Brotkrumen, „Zuletzt besucht“ und der gespeicherte Aufklappzustand. Badges werden bei jedem Aufbau frisch gezählt: Updates, Kommentare, DSGVO-Anfragen und Protokoll-Hinweise; Formular-Eingänge sind über `BadgeProvider::register()` vorbereitet. Ist der Site kein Plugin zugewiesen, erscheint ein Hinweis mit Link zur Plugin-Zuweisung. |
| A11 | Tests MenuBuilder (Capabilities, nicht platzierte Plugin-Items, gesperrte Items, Profilwechsel) | ✅ | `TESTS/admin-menu/run.php` mit 902 Prüfungen, ohne Datenbank, Exit-Code 1 bei Fehlern. Teil 1: Katalog. Teil 2: statischer Zugriffsvergleich Seitendatei ↔ Katalog. Teil 3: Abdeckung aller Admin-Seiten. Teil 4: beide Profile mit den Entscheidungen aus §7 als Asserts. Teile 5a–5c: Unit-Tests für LayoutValidator, MenuRegistry und MenuBuilder mit Fake-Registry und Fake-Rechteprüfung. Teil 5d: Plugin-API für Wunschplätze. Teil 5e: Sidebar-Modell (aktiver Eintrag, Brotkrumen, Aufklappzustand, Badges, Kachelseiten, Plugin-Hinweis); jede Route beider Profile markiert genau ihren Eintrag. Teil 6: Installer-Schritt im eigenen PHP-Prozess ohne Autoloader, Skizzen = echter MenuBuilder. Teil 7: Schema v25, eine Tabellendefinition für Neuinstallation, Update und Laufzeit. Gegenprobe ok: eine geänderte Capability, ein fehlender Profileintrag und eine veränderte Builder-Regel lassen den Test fehlschlagen. Grün unter PHP 8.4 und 8.5; CI-Schritt im Job „PHP Syntax Check“. |
| A12 | Entwickler-Notiz „bevorzugte Eltern je Profil“ | ✅ | [`DOC/admin/ADMIN-MENU.md`](../../admin/ADMIN-MENU.md) §4: `cms_admin_menu_set_preferred_parent()` und Filter `cms_admin_menu_preferred_parents`, Gruppen-IDs beider Profile, Empfehlungstabelle und Einsortier-Reihenfolge. Verweise in `PLUGIN-DEVELOPMENT.md`, `PANEL-INTEGRATION.md`, `plugins/PLUGINS.md` und `HOOKS-REFERENCE.md`. Seit „Aktive Plugins“ ohne Wirkung (nur Kompatibilität). |

### B. Plugin-Integration – [Spec](PLUGIN-INTEGRATION.md)

| # | Punkt | Status | Ergebnis |
|---|---|---|---|
| B0 | Phase-0-Inventar + Paritäts-Checkliste je Plugin | 🟡 | Plugin-Quellen sind über `PS-PhinIT/365CMS.DE-PLUGINS` erreichbar; eine erste Code-Inventur bestätigt `cms-feed` als RSS-Aggregator (Kategorien, Kanäle, Artikel, Einstellungen, Digests, Mitglieder-Abos und Abruf-Queue), `cms-downloads` mit Downloads/Kategorien/Einstellungen/Rate-Limits sowie `cms-contact` mit Formularen, Feldern, Eingängen, Metadaten, Einstellungen und Sicherheitsereignissen. Der Core besitzt zusätzlich `/feed` (Ausgabe) und einen eingehenden RSS/Atom-Parser. Vollständiger Routen-, Hook-, Options-, UI-, Asset-, Cron-, Mail-, Rechte- und Paritätsabgleich bleibt offen. |
| B1 | cms-feed → `FeedService`, Settings-Tab „Feeds“, RSS 2.0/Atom, Autodiscovery, ETag/304, Invalidierung, Editor.js-Rendering, absolute URLs | 🟡 | `/admin/settings?tab=feeds`: Aktivierung, 1–100 Einträge, Auszug/Volltext, Beitragsbilder, Kategorie-/Tag-/Sprachvarianten mit CSRF, `manage_feeds` und Audit-Log. `ThemeRouter` delegiert `/feed` jetzt an den bestehenden `FeedService`; dieser rendert RSS 2.0, bereinigt Editor.js-Volltext mit `PurifierService`, unterstützt Media-RSS und ETag/Last-Modified/304. Kategorie-Feeds berücksichtigen Primär-/Mehrfachzuordnungen und Unterkategorien. Kategorie und Tag werden über den deutschen oder englischen Slug gefunden (wie im Archiv); unbekannte oder abgeschaltete Begriffe liefern 404, der Kanaltitel nennt den Begriff. Der Feed-Cache wird über die Inhalts-Hooks (`FeatureHooks\FeedHooks`) und als Sicherheitsnetz bei Schreibzugriffen auf Inhaltstabellen geleert. Laufzeitprüfung mit Testdaten: Speichern der Einstellungen, Volltext ohne Skripte mit absoluten Bild-URLs, wohlgeformtes XML, 304 bei `If-None-Match`. Offen bleiben vollständige cms-feed-Aggregator-Parität und eine Prüfung, ob dafür Atom-Ausgabe nötig ist. |
| B1a | Feeds je Site | ➖ | Keine Site-Dimension für Inhalte |
| B2.1 | `DownloadService`: Datenmodell, Versionen, Eigenschaftsfelder, „Verwendet in“ | ⬜ | |
| B2.1a | `site_id` bei Downloads | ➖ | Keine Site-Dimension für Inhalte |
| B2.2 | Auslieferung `/download/{slug}[/{version}]`, geschütztes Verzeichnis, Zählung ohne Personendaten, Statistik, Aufbewahrung | ⬜ | |
| B2.3 | Admin Medien → Downloads (Liste, Bearbeiten mit Tabs, Statistik, Einstellungen) | ⬜ | |
| B2.4 | Editor.js-Block „Download“ (Hochladen/Auswählen, nur Referenzen, Tunes, Server-Vorschau) | ⬜ | |
| B2.5 | Templates Kompakt/Karte/Hervorgehoben, Theme-Overrides, Zugriffsschutz, Checklisten-Prüfung | ⬜ | |
| B2.6 | Frontend `/downloads`, `/downloads/{slug}`, Schema.org, Kompatibilität alter Shortcodes/Blöcke | ⬜ | |
| B3 | cms-contact → generischer `FormService`, Kontaktformular als erste Definition, `/kontakt`, Antispam, Consent, Mails, Eingangsübersicht, DSGVO | ⬜ | |
| B4 | Migration v25 idempotent, Plugins automatisch deaktivieren + Hinweis, Marketplace `index.json`, Plugin-Zuweisung bereinigen | 🟡 | Stilllegung, 30-Tage-Hinweis und 301-Umleitung der alten Plugin-Adminseiten fertig. Beim Stilllegen wird die MultiSite-Plugin-Zuweisung bereinigt (`PluginSiteAssignmentService::removePlugins()`). Marketplace: Katalogfeld `integrated_in_core`, Kennzeichnung „Im Core enthalten“ mit Link zur Core-Funktion, keine Installation und keine Update-Angebote; die Update-Prüfung überspringt integrierte Plugins (Details: [Marketplace](../../admin/plugins/MARKETPLACE.md#im-core-enthaltene-plugins)). Die Datenmigration je Plugin folgt mit B1–B3. |
| B5 | Admin-Menüeinträge + Capabilities | 🟡 | Capabilities fertig |
| B6 | Doku, Sprachdateien | 🟡 | Feed-Einstellungen in DE/EN sowie [FEEDS.md](../../admin/system-settings/FEEDS.md) ergänzt; restliche Plugin-Dokumentation folgt mit Downloads und Formularen. |

### Arbeitspaket 1 – „Jetzt umsetzen“ – [Spec](ROADMAP-15-FEATURES.md)

| # | Punkt | Status | Ergebnis |
|---|---|---|---|
| 1 | Papierkorb für Seiten, Beiträge, Medien **und Hub-Sites** über Inhaltstyp-Provider | ⬜ | |
| 2 | Toolbar: Cache leeren & Wartungsmodus | ⬜ | |
| 3 | Login-Grenzwerte einstellbar (3–20 Versuche, 60–3600 s) | ⬜ | |

### Arbeitspaket 2 – „Als Nächstes“

| # | Punkt | Status | Ergebnis |
|---|---|---|---|
| 4 | Redaktionskalender + Dashboard-Widget (ohne Site-Filter, ohne Hub-Sites) | ⬜ | |
| 5 | Zentrale Revisionsübersicht (ohne Site-Filter, ohne Hub-Sites) | ⬜ | |
| 6 | Übersetzungsstatus (ohne Site-Filter, ohne Hub-Sites) | ⬜ | |
| 7 | Suchindex verwalten (Status je Sprache, Neuaufbau, Suchbegriff-Statistik; Hub-Sites nicht indexiert) | ⬜ | |
| 8 | Inhalts-Export/-Import (Seiten, Beiträge, Taxonomien, Medien **und Hub-Sites**; keine Ziel-Site) | ⬜ | |

### Arbeitspaket 3 – „Danach“

| # | Punkt | Status | Ergebnis |
|---|---|---|---|
| 9 | Formular-Builder + Eingänge (baut auf B3 auf) | ⬜ | |
| 10 | Löschfristen erweitern (Formulareingänge, Kommentar-IPs, Übersicht mit Probelauf) | ⬜ | |

### Arbeitspaket 4 – „Sollte zeitnah“

| # | Punkt | Status | Ergebnis |
|---|---|---|---|
| 11 | Copyright/Lizenz bei Medien | ⬜ | |
| 12 | Barrierefreiheitserklärung | ⬜ | |
| 13 | Thumbnails neu erzeugen | ⬜ | |
| 14 | RSS-Einstellungen (Prüfung nach B1) | ✅ | Feeds-Tab mit Anzahl, Volltext/Auszug, Bildern, Kategorien, Tags, Sprachen und Aktivierung umgesetzt; Details: [FEEDS.md](../../admin/system-settings/FEEDS.md). |
| 15 | Umgebungsinfo | ⬜ | |

### Querschnitt und Abschluss

| Punkt | Status | Ergebnis |
|---|---|---|
| Schema v25 in `SchemaManager` (Neuinstallation) und `MigrationManager` (Update), idempotent | 🟡 | Stand v25 mit `admin_menu_layouts` und `batch_jobs`: je eine Definition in `SchemaManager::getSchemaQueries()`, die `MigrationManager` und die Services (`LayoutRepository`, `BatchJobService`) mitnutzen. `BatchJobService` legte `batch_jobs` vorher selbst an; das ist in den Schema-Stand verschoben. Geprüft auf allen drei Wegen (Neuinstallation, Update von v24 mit Anlage beider Tabellen, Laufzeit-Absicherung samt Job-Lebenszyklus) und in Test Teil 7. Die Tabellen aus Phase B und AP1–AP4 kommen in denselben Stand. |
| Sprachdateien `CMS/lang/de.yaml` / `en.yaml` | ⬜ | |
| Doku: `DOC/admin/*`, `INDEX.md`, `FILELIST.md`, `CMSFILESTRUCTUR.md`, `DATABASE-SCHEMA.md`, `HOOKS-REFERENCE.md`, `INSTALLATION.md` | 🟡 | Phase A fertig: `admin/ADMIN-MENU.md` und `system-settings/MULTISITES.md` neu. Profilabhängige Platzierung in `admin/README.md`, `GUIDE.md`, `PANEL-INTEGRATION.md` und den Bereichs-READMEs. `SERVICES.md` (mit den Erweiterungspunkten der Vorarbeiten), `RBAC.md`, `ARCHITECTURE.md`, `HOOKS-REFERENCE.md`, `DATABASE-SCHEMA.md`, `INSTALLATION.md`, `DOC/assets/js/README.md`, `FILELIST.md` und `CMSFILESTRUCTUR.md` sind ergänzt, Schema-Stand v25 überall nachgezogen. Links und Anker aller geänderten Dokumente sind geprüft. Phase B und AP1–AP4 folgen. |
| Changelog-Abschnitt 4.0.0 | 🟡 | Vorarbeiten und Phase A eingetragen: vier Bugfixes, privater Speicher, MultiSites, Menü-Profile, Admin-Menü-Tab und Sidebar-Editor, Kachelseiten, Installer-Schritt, Plugin-Wunschplätze, SEO-Bereiche im Seiten-Editor, Erweiterungspunkte, Capabilities, Plugin-Integration, Tests. Veraltete Zeilen (helles Layout, feste Sites, alte Gruppenreihenfolge, Schema v24) korrigiert. Phase B und AP1–AP4 folgen. |
| RELEASE 4.0.0 (Update-/Voll-Zip, SHA-256, beide `update.json`) | ⬜ | |
| Testplan je Paket: Neuinstallation, Update von der Vorversion, MultiSite mit zwei Sites | ⬜ | |

### Umsetzungsentscheidungen

- **Ein Branch, ein Pull Request** mit Commits je Bereich (Phase A, Feeds, Downloads, Formulare, AP1–AP4). Parallel entwickelte Bereiche entstehen in eigenen Worktrees; gemeinsame Dateien (Schema, Sprachdateien, Doku-Indizes, Changelog, Release) werden erst bei der Zusammenführung bearbeitet. Alles ist Release 4.0.0. Die RELEASE-Zips und Prüfsummen hängen am Gesamtstand und werden deshalb am Ende einmal gebaut statt je Teil-PR.
- **Plugin-Admin-URLs** der integrierten Plugins leiten zentral per 301 auf die neuen Seiten um (`IntegratedPluginService::adminRedirectFor()` in `AdminRouter::renderPluginPage()`), sobald der jeweilige Core-Ersatz im Code vorhanden ist.
