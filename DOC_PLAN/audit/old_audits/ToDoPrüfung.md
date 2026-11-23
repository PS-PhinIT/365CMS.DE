# ToDoPrüfung

## Ziel

Diese Datei protokolliert die inkrementelle Abarbeitung des konsolidierten Audit-Backlogs aus `DOC/audit/Audit-Backlog.md`.
Jeder Schritt dokumentiert:

- geprüften Scope
- umgesetzte Änderungen
- verifizierte Alt-Fixes
- Auswirkungen auf Bewertung und nächste Schritte

> Hinweis zur Struktur: Der frühere Detail-Prüfplan aus `PRÜFUNG.MD` sowie die verteilten Admin-/Asset-Indizes wurden in die Sammelstruktur unter `Audit-*.md` überführt. Die Verlaufshistorie bleibt hier bestehen.

## Gesamtstand aus `Audit-Backlog.md`

- Gesamtpunkte im Prüfplan: **444**
- Priorität **kritisch**: **31**
- Priorität **hoch**: **89**
- Priorität **mittel**: **167**
- Priorität **niedrig**: **157**

## Abarbeitungslog

### Folge-Schritt 464 — 28.03.2026 — Zentrale DOC-Dateien auf konsistenten 2.8.0-Snapshot harmonisiert

**Status:** umgesetzt, reviewed und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **20 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `DOC/README.md`
- `DOC/INDEX.md`
- `DOC/INSTALLATION.md`
- `DOC/core/README.md`
- `DOC/core/STATUS.md`
- `DOC/core/ARCHITECTURE.md`
- `DOC/core/STRUCTURE.md`
- `README.md`

**Ergebnis dieses Schritts**

- Die zentralen Dokumentationsdateien unter `DOC/` führen den Basis-Stand jetzt konsistent als `2.8.0` statt als gemischten `2.8.0 RC`-/Patch-Wortlaut.
- Das Root-README erklärt ergänzend, dass diese zentrale Doku bewusst einen stabilen `2.8.0`-Snapshot abbildet, während Folge-Härtungen separat über Audit- und Release-Log dokumentiert werden.
- Damit bleiben Kern-Einstiege wie Dokumentationsindex, Installation, Core-Überblick, Core-Status und Architektur als zusammenhängender Release-Snapshot lesbar, ohne unnötig zwischen Basis-Stand und Folge-Batches zu springen.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- Die zentrale Projektdokumentation reduziert Widersprüche im Versionswortlaut und trennt Basissnapshot (`2.8.0`) klarer von späteren Audit-/Patch-Folgen.

**Nächste Kandidaten nach der Folge-Runde**

1. IndexNow-Workflow optional um das sichere Erzeugen einer passenden Root-Keydatei aus dem Admin ergänzen
2. Weitere Fachdokumente unter `DOC/` gesammelt auf denselben `2.8.0`-Grundsnapshot prüfen
3. Release-/Patch-Hinweise im Root-Changelog bei Bedarf weiter konsolidieren, falls gemischte Snapshot-/Folge-Einträge weiter auseinanderlaufen

### Folge-Schritt 463 — 28.03.2026 — FeedService staffelt Redirect- und Remote-Fetch-Härtung weiter gegen SSRF-Restkanten

**Status:** umgesetzt, reviewed und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **19 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/core/Services/FeedService.php`
- `README.md`
- `Changelog.md`

**Ergebnis dieses Schritts**

- `FeedService` folgt Redirects beim nativen Remote-Fetch jetzt nicht mehr implizit über cURL-/Stream-Defaults, sondern verarbeitet 30x-Antworten manuell und validiert jedes Redirect-Ziel erneut gegen denselben URL-/Host-Vertrag.
- cURL-Fetches nutzen zusätzlich ein pro Request neu aufgebautes `CURLOPT_RESOLVE`-Mapping auf zuvor geprüfte Ziel-IPs. Dadurch bleiben Resolver- und Redirect-Wechsel näher an derselben SSRF-Schutzentscheidung wie bereits die ursprüngliche URL-Normalisierung.
- README und Changelog dokumentieren die nachgezogene Feed-Härtung als weiteren Folge-Batch innerhalb des dokumentierten `2.8.0`-Stands.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/core/Services/FeedService.php` reduziert implizite Redirect- und DNS-Entscheidungen im nativen Feed-Import und bündelt sie stärker in einem kleinen, explizit validierten Service-Vertrag.

**Nächste Kandidaten nach der Folge-Runde**

1. IndexNow-Workflow optional um das sichere Erzeugen einer passenden Root-Keydatei aus dem Admin ergänzen
2. Zentrale Doku-Dateien unter `DOC/` bei Bedarf gesammelt auf denselben `2.8.0`-Grundsnapshot nachziehen
3. FeedService optional noch um feinere Host-Allowlisten oder Telemetrie für verworfene Redirect-Ketten ergänzen

### Folge-Schritt 462 — 28.03.2026 — Release-Stand 2.8.0 mit README/Changelog/Metadaten synchronisiert

**Status:** umgesetzt, reviewed und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **18 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/core/Version.php`
- `CMS/update.json`
- `README.md`
- `Changelog.md`

**Ergebnis dieses Schritts**

- Der dokumentierte Release-Stand wurde auf `2.8.0` synchronisiert: `Version.php`, `update.json`, README-Badges und der Changelog-Kopf zeigen jetzt denselben Grundstand.
- `Changelog.md` führt die nachgezogene SEO-/IndexNow-Härtung, den Review-Status und den Metadaten-Abgleich innerhalb des `2.8.0`-Blocks konsistent mit demselben Release-Snapshot.
- `README.md` nennt den aktuellen Patch-Stand nun im deutschen und englischen Einstieg explizit, sodass Release- und Sicherheitskontext direkt im Überblick sichtbar bleiben.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` laufen wieder konsistent auf einem kleinen `2.8.0`-Release-Snapshot statt auf gemischten Versionssignalen.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. IndexNow-Workflow optional um das sichere Erzeugen einer passenden Root-Keydatei aus dem Admin ergänzen
3. Zentrale Doku-Dateien unter `DOC/` bei Bedarf gesammelt auf denselben `2.8.0`-Grundsnapshot nachziehen

### Folge-Schritt 461 — 28.03.2026 — IndexNow-Dateilese-Guard fail-closed nachgeschärft

**Status:** umgesetzt, reviewed und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **17 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/core/Services/IndexingService.php`
- `README.md`
- `Changelog.md`

**Ergebnis dieses Schritts**

- `IndexingService` liest eine ausgewählte Root-`.txt`-Datei für IndexNow jetzt nur noch dann ein, wenn Lesbarkeit und Dateigröße vorher sauber bestätigt wurden.
- Wenn die Datei nicht lesbar ist, ungewöhnlich groß ausfällt oder ihre Größe nicht sicher ermittelt werden kann, beendet die Prüfung den Lesepfad mit einem klaren Validierungsfehler statt mit einem nachgelagerten Dateizugriffsversuch.
- `README.md` und `Changelog.md` dokumentieren die zusätzliche Guard-Logik; zusätzlich wurde die Workspace-Struktur im README am tatsächlichen Test-Pfad `tests/` ausgerichtet.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/core/Services/IndexingService.php` schließt eine kleine Security-/Robustheitskante im neuen SEO-Vertrag fail-closed, sodass Guard-Fehler nicht in einen unnötigen Datei-Readthrough münden.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. IndexNow-Workflow optional um das sichere Erzeugen einer passenden Root-Keydatei aus dem Admin ergänzen
3. SEO-Views weiter auf vorbereitete Hinweis-/Constraint-Blöcke harmonisieren, um lokale Prüftexte zu reduzieren

### Folge-Schritt 460 — 28.03.2026 — SEO-/IndexNow-Konfiguration mit Root-TXT-Prüfung ergänzt

**Status:** umgesetzt, reviewed und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **16 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/core/Services/IndexingService.php`
- `CMS/admin/modules/seo/SeoSuiteModule.php`
- `CMS/admin/views/seo/technical.php`
- `CMS/admin/views/seo/sitemap.php`
- `README.md`
- `Changelog.md`

**Ergebnis dieses Schritts**

- Der SEO-Bereich unterstützt jetzt direkt im Admin die Pflege eines `IndexNow`-API-Keys und die Auswahl einer vorhandenen Root-`.txt`-Datei im CMS-Webroot.
- `IndexingService` liefert dafür eine zentrale Status- und Validierungssicht: verfügbare Root-`.txt`-Dateien, dynamische Keydatei-URL, gewählte physische Datei sowie Prüfzustände für Dateiname, Dateiinhalt, Lesbarkeit und plausibles Größenlimit.
- `SeoSuiteModule`, `technical.php` und `sitemap.php` hängen an diesem gemeinsamen Vertrag und zeigen den Prüfstand sichtbar im Bereich „Technisches SEO“ und ergänzend im Bereich „Sitemap & Indexing“ an.
- Die Änderungen wurden auf Fehler, Best Practice und Security geprüft: keine neuen Editorfehler, keine PHP-Lint-Fehler; Dateiauswahl bleibt basename-/Allowlist-basiert auf vorhandene Root-`.txt`-Dateien begrenzt; die bestehende dynamische `/<key>.txt`-Auslieferung im Core bleibt der maßgebliche öffentliche Pfad.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/core/Services/IndexingService.php`, `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/views/seo/technical.php` und `CMS/admin/views/seo/sitemap.php` hängen den neuen IndexNow-Workflow enger an einen kleinen Admin-/Core-Vertrag statt an manuelle Dateisystem-Annahmen oder lose Prüfhinweise.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. IndexNow-Workflow optional um das sichere Erzeugen einer passenden Root-Keydatei aus dem Admin ergänzen
3. SEO-Views weiter auf vorbereitete Hinweis-/Constraint-Blöcke harmonisieren, um lokale Prüftexte zu reduzieren

### Folge-Schritt 459 — 28.03.2026 — Medien-Systemordner-Klassifikation und Bootstrap-Modal-Trigger-Fallback korrigiert

**Status:** umgesetzt, reviewed und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **15 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/core/Services/Media/MediaRepository.php`
- `CMS/assets/js/admin-media-integrations.js`
- `CMS/assets/js/member-dashboard.js`
- `README.md`
- `Changelog.md`

**Ergebnis dieses Schritts**

- `MediaRepository::isSystemPath()` klassifiziert Member-erstellte Unterordner (Ebene 3+, z. B. `member/user-1/fotos`) nicht mehr als Systemordner. Nur Root-Ebene (z. B. `member`) und direkte User-Roots (`member/user-X`) sind weiterhin geschützt; selbst angelegte Unterordner erhalten korrekt Aktions-Dropdowns.
- `admin-media-integrations.js` und `member-dashboard.js` speichern den auslösenden Button per Click-Listener synchron als Pending-Trigger. Der `show.bs.modal`-Handler liest `event.relatedTarget || pendingTrigger` und nullt danach den Pending-Slot. Umbenennen und Verschieben befüllen das Modal damit auch dann korrekt, wenn Bootstrap `event.relatedTarget` wegen des Dropdown-Close-Timings nicht setzt.
- Alle drei Änderungen wurden auf Fehler, Best Practice und Security geprüft: keine neuen Editorfehler; Pfad-Normalisierung baut korrekt auf vorgelagerter Sanitierung auf; Pending-Trigger-Referenzen werden nach Konsum genullt.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/core/Services/Media/MediaRepository.php`: `isSystemPath` schützt jetzt schärfer auf den tatsächlich schutzwürdigen Ebenen, ohne Member-Content fälschlicherweise zu sperren.
- `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js`: Modal-Population ist nicht mehr von Bootstrap-internem Dropdown-Timing abhängig.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. Media-Bulk optional um Kategorie-Zuweisung bündeln, falls der Flow im Alltag trägt
3. Geschützte Systempfade im Medienbereich UX-seitig durch ein eigenständiges Badge oder eine gesonderte Filteransicht sichtbarer machen

### Folge-Schritt 458 — 27.03.2026 — Medienbereich wird kompakter, erhält Bulk-Aktionen und geht als RC 2.8.0 in den Review-Stand

**Status:** umgesetzt, reviewed und als Release Candidate angehoben

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **14 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/media.php`
- `CMS/admin/modules/media/MediaModule.php`
- `CMS/admin/views/media/library.php`
- `CMS/assets/js/admin-media-integrations.js`
- `CMS/member/includes/class-member-controller.php`
- `CMS/member/media.php`
- `CMS/assets/js/member-dashboard.js`
- `CMS/core/Version.php`
- `CMS/update.json`
- `README.md`
- `Changelog.md`

**Ergebnis dieses Schritts**

- Der Medienbereich nutzt für Rename/Move jetzt kompakte Dropdowns mit zentralen Modal-Dialogen statt breiten Inline-Formularblöcken.
- Die Admin-Medienbibliothek unterstützt zusätzlich Bulk-Löschen und Bulk-Verschieben über Mehrfachauswahl, Zielordner-Select und serverseitig deduplizierte Pfadlisten.
- Member-Medien übernehmen dieselbe kompakte Aktionslogik mit vorbereiteten Zielordnern innerhalb des persönlichen Root-Pfads.
- Die geänderten Dateien wurden vor dem Versionssprung nochmals auf Fehler, Best Practice und Security geprüft; ohne neue Blocker wurde der Stand auf **Release Candidate 2.8.0** angehoben.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- Der Medienbereich kombiniert jetzt kompaktere UX mit denselben robusten POST-/CSRF- und Pfadgrenzen; Bulk-Pfade vermeiden zusätzlich doppelte Unterpfad-Aktionen bei Ordner-/Dateimischungen.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. Media-Bulk optional um Kategorie-Zuweisung oder Download bündeln, falls der Flow im Alltag trägt
3. Geschützte Systempfade im Medienbereich zusätzlich UX-seitig markieren oder gesondert filtern

### Folge-Schritt 457 — 27.03.2026 — Medienbereich zieht Rename/Move robust nach und schließt den Member-No-op-Typ

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **13 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/media.php`
- `CMS/admin/modules/media/MediaModule.php`
- `CMS/admin/views/media/library.php`
- `CMS/member/includes/class-member-controller.php`
- `CMS/member/media.php`

**Ergebnis dieses Schritts**

- Admin und Member unterstützen im Medienbereich jetzt zusätzlich echtes Umbenennen und Verschieben über serverseitige POST-Formulare mit CSRF-Prüfung.
- `move_item`, `media_move` und `media_rename` laufen damit nicht über implizite JavaScript-Dialoge, sondern über klar normalisierte Request-Payloads bis in den Media-Service.
- Im Member-Bereich bleibt im Medienmodul damit kein vergleichbarer stiller No-op-Typ mehr zurück; alle sichtbaren Medienaktionen sind einem Controller-Handler zugeordnet.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/member/includes/class-member-controller.php` und `CMS/member/media.php` hängen Rename-/Move-Aktionen jetzt enger an kleinen POST-/CSRF- und Pfadverträgen statt an losen UI-Annahmen.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. Medienbereich UX-seitig Zielordner-Auswahl komfortabler machen (z. B. vorbereitete Folder-Optionen statt Freitext)
3. Admin-/Member-Medienbereich auf Bulk-Aktionen oder konsistente Inline-Hinweise für systemgeschützte Pfade erweitern

### Folge-Schritt 456 — 27.03.2026 — Admin-Medienbibliothek löscht Dateien und Ordner wieder robust ohne JS-Zwang

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **12 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/views/media/library.php`

**Ergebnis dieses Schritts**

- Datei- und Ordnerlöschungen in der Admin-Medienbibliothek laufen jetzt über echte POST-Formulare mit Confirm statt über rein JS-abhängige Delete-Buttons.
- Damit bleiben die Aktionen funktionsfähig, auch wenn der Medien-JS-Handler oder globale Confirm-Initialisierung nicht sauber verfügbar sind.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/views/media/library.php` hängt Destruktiv-Aktionen jetzt enger an einem kleinen, robusten POST-/CSRF-Vertrag statt an einem fragilen Button-/JS-Pfad.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. `CMS/member/` Medien-UX weiter angleichen (Rename/Move, leichtere Bulk-Aktionen, konsistenter Confirm-Flow)
3. `CMS/admin/views/media/library.php` und `CMS/assets/js/admin-media-integrations.js` auf einen gemeinsamen Rename-/Bulk-Action-Vertrag erweitern

### Folge-Schritt 455 — 27.03.2026 — Member-Medienbereich richtet Ordnernavigation, Delete-Flow und Script-Härtung sauber aus

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **11 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/member/includes/class-member-controller.php`
- `CMS/member/media.php`
- `CMS/assets/js/cookieconsent-init.js`
- `CMS/core/Services/CookieConsentService.php`
- `CMS/assets/js/admin-media-integrations.js`
- `CMS/assets/js/member-dashboard.js`
- `CMS/core/Services/FeedService.php`

**Ergebnis dieses Schritts**

- Der Member-Medienbereich unterstützt jetzt aktuellen Ordnerpfad, Breadcrumbs und konsistente Redirects zurück in den gerade geöffneten Bereich.
- Datei- und Ordnerlöschung laufen für Member wieder innerhalb des persönlichen Upload-Wurzelpfads statt an einem Root-only-Flow oder fehlender Ordneraktion zu hängen.
- Consent-, Medien- und Feed-Skripte wurden nach dem Review zusätzlich gegen DOM-XSS-, Cookie- und Hash-Hinweise gehärtet.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/member/`-Medienpfade und die neue native Consent-/Upload-/Feed-Strecke hängen enger an einem kleinen, sicheren Pfad- und DOM-Vertrag.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/Services/FeedService.php` Redirect-/Remote-Fetch-Validierung weiter gegen SSRF-Restkanten staffeln
2. `CMS/member/` Medien-UX weiter angleichen (Rename/Move, leichtere Bulk-Aktionen, konsistenter Confirm-Flow)
3. `CMS/assets/cookieconsent/`, `CMS/assets/filepond/`, `CMS/assets/simplepiesrc/` als reine Altbestände weiter reduzieren oder später physisch entfernen

### Folge-Schritt 454 — 27.03.2026 — Consent-, Upload- und Feed-Laufzeitpfade laufen jetzt nativ über 365CMS

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **10 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/core/Services/CookieConsentService.php`
- `CMS/assets/js/cookieconsent-init.js`
- `CMS/assets/js/admin-media-integrations.js`
- `CMS/admin/views/media/library.php`
- `CMS/admin/views/settings/general.php`
- `CMS/member/media.php`
- `CMS/assets/js/member-dashboard.js`
- `CMS/core/Services/FeedService.php`
- `CMS/assets/autoload.php`
- `CMS/core/Routing/ApiRouter.php`

**Ergebnis dieses Schritts**

- Cookie-Consent läuft im aktiven Frontend jetzt über native 365CMS-CSS/JS statt über die externe Runtime.
- Admin- und Member-Uploads laufen jetzt ohne FilePond; Media-Picker und Bibliothek setzen auf interne Listen-/Grid- und API-Flows statt auf elFinder.
- RSS-/Atom-Feeds werden ohne SimplePie direkt per DOM/XML samt abgesichertem Fetch und JSON-Cache verarbeitet.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- Consent-, Upload-, Picker- und Feed-Laufzeitpfade wurden auf interne Verträge konzentriert und von aktiven Fremd-Assets entkoppelt.

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/core/VendorRegistry.php` bzw. Asset-Dokumentation weiter auf verbliebene aktive Bundles reduzieren
2. `CMS/member/` Upload-/Medienpfade weiter funktional angleichen (Preview/Delete-UX und Hinweise)
3. `CMS/assets/cookieconsent/`, `CMS/assets/filepond/`, `CMS/assets/simplepiesrc/` als reine Altbestände dokumentieren oder in einem späteren Bereinigungsschritt physisch entfernen

### Folge-Schritt 453 — 27.03.2026 — Gruppen-Asset öffnet Modale und blockt Doppel-Submits robuster

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **9 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/assets/js/admin-user-groups.js`

**Ergebnis dieses Schritts**

- Gruppen-Modale werden jetzt über `show.bs.modal` zuverlässiger mit Bearbeitungsdaten befüllt.
- Save- und Delete-Aktionen erhalten Pending-State und blocken wiederholte Folge-Submits robuster.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/assets/js/admin-user-groups.js`: Modal- und Submit-Vertrag für Gruppen weiter verdichtet

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (ZIP-/Entpack- und Signaturpfade weiter staffeln)
2. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Datei-/MIME- und Upload-Verträge nachziehen)
3. `CMS/admin/users.php` bzw. `CMS/admin/modules/users/UsersModule.php` (Entry- und Reportpfade weiter annähern)

### Folge-Schritt 452 — 27.03.2026 — Gruppen-View verdrahtet Modal- und Formularziele belastbarer

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **8 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/views/users/groups.php`

**Ergebnis dieses Schritts**

- Gruppen-Buttons öffnen das Modal jetzt über Bootstrap-Datenattribute robuster.
- Save- und Delete-Formulare feuern explizit an `/admin/groups`, statt still auf implizite Ziele zu hoffen.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/views/users/groups.php`: Gruppen-UI- und Routenvertrag weiter verdichtet

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/assets/js/admin-user-groups.js` (Modal-/Submit-Flow auf den neuen View-Vertrag annähern)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (ZIP-/Entpack- und Signaturpfade weiter staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Datei-/MIME- und Upload-Verträge nachziehen)

### Folge-Schritt 451 — 27.03.2026 — User-Form spiegelt Eingabegrenzen direkter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **7 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/views/users/edit.php`

**Ergebnis dieses Schritts**

- Benutzername, E-Mail, Passwort sowie Namensfelder tragen jetzt frühere Formulargrenzen und Hinweise.
- Dadurch werden typische Fehl-Eingaben clientseitig früher abgefangen.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/views/users/edit.php`: Save-Vorbedingungen früher im UI gespiegelt

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/views/users/groups.php` bzw. `CMS/assets/js/admin-user-groups.js` (Modal- und Submit-Flow robuster machen)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (ZIP-/Entpack- und Signaturpfade weiter staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Datei-/MIME- und Upload-Verträge nachziehen)

### Folge-Schritt 450 — 27.03.2026 — UsersModule staffelt Save-Fehler und Rückgabekanten robuster

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **6 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/modules/users/UsersModule.php`

**Ergebnis dieses Schritts**

- Save-Payloads werden jetzt defensiver normalisiert.
- Fehlende Erfolgsrückgaben und Exceptions landen expliziter als reportbare Fehler statt nur in einer generischen Catch-All-Meldung.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/modules/users/UsersModule.php`: Benutzer-Save- und Fehlervertrag weiter verdichtet

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/views/users/edit.php` (neue Save-Regeln direkt im Formular spiegeln)
2. `CMS/admin/views/users/groups.php` bzw. `CMS/assets/js/admin-user-groups.js` (Modal- und Submit-Flow robuster machen)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (ZIP-/Entpack- und Signaturpfade weiter staffeln)

### Folge-Schritt 449 — 27.03.2026 — Media-Settings erklären unveränderte Saves verständlicher

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **5 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/modules/media/MediaModule.php`

**Ergebnis dieses Schritts**

- Settings-Saves mit unveränderten Werten nennen jetzt explizit, dass bestehende Werte bestätigt wurden.
- Reale Änderungen werden zusätzlich mit Anzahl und Feldliste klarer zusammengefasst.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/modules/media/MediaModule.php`: Settings-Rückmeldung im Admin klarer und nachvollziehbarer

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/modules/users/UsersModule.php` (Save- und Fehlerpfade für neue Benutzer nachziehen)
2. `CMS/admin/views/users/edit.php` (neue Regeln direkt im Formular spiegeln)
3. `CMS/admin/views/users/groups.php` bzw. `CMS/assets/js/admin-user-groups.js` (Modal- und Submit-Flow robuster machen)

### Folge-Schritt 448 — 27.03.2026 — Marketplace-Asset blockt doppelte Install-Submits robuster ab

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **4 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/assets/js/admin-plugin-marketplace.js`

**Ergebnis dieses Schritts**

- Install-Formulare erhalten jetzt zusätzlich einen Form-Pending-State.
- Der Install-Button setzt `aria-disabled` und blockt Folge-Submits robuster.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/assets/js/admin-plugin-marketplace.js`: Install-Submit-Guard weiter verdichtet

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Datei-/MIME- und Upload-Verträge weiter nachziehen)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (ZIP-/Entpack- und Signaturpfade weiter staffeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-/Datei- und Scan-Pfade weiter nachziehen)

### Folge-Schritt 447 — 27.03.2026 — Marketplace-View nennt Archiv-Endungen als weiteres Risiko deutlicher

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **3 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/views/plugins/marketplace.php`

**Ergebnis dieses Schritts**

- Die View nennt jetzt erlaubte Archiv-Endungen explizit im Hinweisbereich.
- Plugin-Karten markieren unzulässige Archiv-Endungen zusätzlich mit einem sichtbaren Warnbadge.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/views/plugins/marketplace.php`: Auto-Install-Risiken klarer im UI gespiegelt

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/assets/js/admin-plugin-marketplace.js` (Install-Submit-Guard weiter härten)
2. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Datei-/MIME- und Upload-Verträge weiter nachziehen)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (ZIP-/Entpack- und Signaturpfade weiter staffeln)

### Folge-Schritt 446 — 27.03.2026 — Marketplace-Modul verlangt erlaubte Archiv-Endungen für Auto-Install

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **2 Folge-Batches** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Auto-Installationen verlangen jetzt zusätzlich eine erlaubte Archiv-Endung.
- Der Zustand landet explizit im Plugin-Datensatz sowie im Fehlerkontext der Install-Pfade.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Download- und Archivvertrag weiter verdichtet

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/views/plugins/marketplace.php` (neue Archiv-Risiken vollständig im UI spiegeln)
2. `CMS/assets/js/admin-plugin-marketplace.js` (Install-Submit-Guard weiter härten)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Datei-/MIME- und Upload-Verträge weiter nachziehen)

### Folge-Schritt 445 — 27.03.2026 — Marketplace-Entry lehnt überlange Slugs sauberer ab

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt
- zusätzlich **1 Folge-Batch** über den ursprünglichen Plan hinaus umgesetzt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`

**Ergebnis dieses Schritts**

- Der Entry weist überlange Slugs jetzt explizit zurück, statt sie nur still zu kürzen.
- Payload-Fehler erhalten spezifischere Fehlercodes und mehr Kontext.

**Bewertungswirkung (inkrementell, Folge-Runde)**

- `CMS/admin/plugin-marketplace.php`: Install-Request-Vertrag weiter verdichtet

**Nächste Kandidaten nach der Folge-Runde**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Archiv-Endungen enger an Auto-Install koppeln)
2. `CMS/admin/views/plugins/marketplace.php` (neue Archiv-Risiken im UI spiegeln)
3. `CMS/assets/js/admin-plugin-marketplace.js` (Install-Submit-Guard weiter härten)

### Schritt 444 — 27.03.2026 — Font-Manager-Entry validiert Save- und Google-Font-Payloads strenger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **444 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Save-Requests prüfen jetzt Font-Größe und Zeilenabstand explizit als numerische Eingaben.
- Zu lange Google-Font-Namen werden sauber abgewiesen, statt nur still abgeschnitten zu werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Request-Validierung für Font-Mutationen weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. Prüfplan aktuell vollständig nachgezogen — nächste Runde nur bei neuer Bewertungsmatrix oder Folge-Audit

### Schritt 443 — 27.03.2026 — Theme-Explorer-Entry staffelt Datei- und Inhaltsgrenzen präziser

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **443 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-explorer.php`

**Ergebnis dieses Schritts**

- Der Entry erzwingt jetzt zusätzlich einen Write-Capability-Guard für Save-Aktionen.
- Dateipfade und Editor-Inhalte werden früher gegen sichere Grenzen geprüft und liefern spezifischere Fehlercodes.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Entry-Grenzen und Payload-Fehler weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` (letzte Request-Validierungen nachziehen)

### Schritt 442 — 27.03.2026 — Missing-Customizer-View nennt sichere nächste Schritte deutlicher

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **442 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/customizer-missing.php`

**Ergebnis dieses Schritts**

- Die Fallback-View nennt jetzt Reason-Hinweis, erwarteten Customizer-Pfad und sicheren Ausweichpfad deutlicher.
- Dadurch bleibt der fehlende Customizer nicht nur eine Warnung, sondern ein klarerer nächster Arbeitsschritt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/customizer-missing.php`: sicherer Fallback und nächste Schritte klarer gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
2. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Rest-Request- und Path-Constraints nachziehen)
5. `CMS/admin/theme-editor.php` (Fallback-Zustände bei erfolgreichem Customizer-Fund weiter annähern)

### Schritt 441 — 27.03.2026 — Theme-Editor-Entry staffelt Reason-Hinweise präziser

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **441 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-editor.php`

**Ergebnis dieses Schritts**

- Der Entry bereitet jetzt pro Fallback-Code einen strukturierteren Hinweis für die View vor.
- Der sichere Fallback-Kontext wird konsistenter durchgereicht, statt nur Reason und Code zu liefern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-editor.php`: Fallback-Vertrag weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/customizer-missing.php` (neue Hinweise vollständig im UI spiegeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
5. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Rest-Request- und Path-Constraints nachziehen)

### Schritt 440 — 27.03.2026 — Theme-Explorer-Asset blockt Mehrfach-Submits robuster ab

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **440 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/assets/js/admin-theme-explorer.js`

**Ergebnis dieses Schritts**

- Editor-Submits erhalten jetzt einen gemeinsamen Pending-Zustand für Button und `Ctrl+S`.
- Der Save-Button signalisiert laufende Aktionen zusätzlich über `aria-disabled` und blockt Folge-Submits robuster.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/assets/js/admin-theme-explorer.js`: Pending-/Submit-Guard für Theme-Explorer-Saves nachgezogen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php` bzw. `CMS/admin/views/themes/customizer-missing.php` (Fallback- und Customizer-Hinweise weiter staffeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
5. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Rest-Request- und Path-Constraints nachziehen)

### Schritt 439 — 27.03.2026 — Theme-Explorer-View nennt Limits und erlaubte Endungen expliziter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **439 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- Die View nennt jetzt erlaubte Endungen, Skip-Segmente, Baumtiefe und Browser-Limits direkter im Admin.
- Editor-Kopf und Footer spiegeln Schutzgrenzen früher, statt diese Informationen nur implizit aus dem Modul zu erben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/editor.php`: Explorer- und Browser-Grenzen klarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/assets/js/admin-theme-explorer.js` (neue Pending-Zustände robuster über Save-Aktionen staffeln)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/views/themes/customizer-missing.php` (Fallback- und Customizer-Hinweise weiter staffeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)

### Schritt 438 — 27.03.2026 — ThemeEditorModule staffelt Validierungsfehler und Constraints enger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **438 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeEditorModule.php`

**Ergebnis dieses Schritts**

- Das Modul liefert jetzt strukturierte Fehlerresultate auch für Browser-Editor-Validierungen wie Pfad-, Größen- und Dateitypfehler.
- Erlaubte Endungen und Skip-Segmente werden als Constraints vorbereitet.
- Fehlerkontext aus Exceptions wird enger sanitisiert, bevor er in Logs und Reports landet.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeEditorModule.php`: Save- und Tree-Vertrag weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/editor.php` (neue Explorer- und Constraint-Daten vollständig im UI spiegeln)
2. `CMS/assets/js/admin-theme-explorer.js` (neue Pending-Zustände robuster über Save-Aktionen staffeln)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/views/themes/customizer-missing.php` (Fallback- und Customizer-Hinweise weiter staffeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)

### Schritt 437 — 27.03.2026 — Legal-Sites-Asset blockt Mehrfach-Submits robuster ab

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **437 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/assets/js/admin-legal-sites.js`

**Ergebnis dieses Schritts**

- Post-Formulare erhalten jetzt einen gemeinsamen Pending-Zustand und blocken Folge-Submits direkt.
- Submit-Buttons tragen dabei zusätzlich `aria-disabled`, damit der laufende Zustand konsistenter signalisiert wird.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/assets/js/admin-legal-sites.js`: Pending-/Submit-Guard für Save-, Generate- und Create-Page-Aktionen nachgezogen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Request-/Action-Constraints staffeln)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)

### Schritt 436 — 27.03.2026 — Legal-View nennt Generator-Typen und Eingabegrenzen expliziter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **436 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/legal/sites.php`

**Ergebnis dieses Schritts**

- Die View nennt jetzt Generator-Bereiche, Vorlagentypen, Toggle-Anzahl und HTML-Limits direkter im Admin.
- Bereichsformulare spiegeln Generator-Typ und Zeichengrenze früher, statt diese Informationen nur implizit aus dem Modul zu erben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/legal/sites.php`: Generator- und Eingabegrenzen klarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/assets/js/admin-legal-sites.js` (neue Pending-Zustände robuster über alle Mutationen staffeln)
2. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Request-/Action-Constraints staffeln)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service-Pfade vorbereiten)

### Schritt 435 — 27.03.2026 — LegalSitesModule staffelt Save-, Profil- und Sammelseiten-Kontext enger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **435 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/legal/LegalSitesModule.php`

**Ergebnis dieses Schritts**

- Das Modul liefert jetzt zusätzliche Generator- und Profil-Constraints an die View.
- Save- und Profil-Operationen zählen geänderte Schlüssel nachvollziehbarer.
- Sammel-Seitenläufe und Fehlerpfade liefern detailreicheren Kontext für Fortschritt und Reportability.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/legal/LegalSitesModule.php`: Save-, Profil- und Generator-Vertrag weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/legal/sites.php` (neue Generator- und Constraint-Daten vollständig im UI spiegeln)
2. `CMS/assets/js/admin-legal-sites.js` (neue Pending-Zustände robuster über alle Mutationen staffeln)
3. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Request-/Action-Constraints staffeln)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)
5. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Rest-Reportpfade staffeln)

### Schritt 434 — 27.03.2026 — Font-Manager-Asset blockt Mehrfach-Submit und Delete-Replays robuster

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **434 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/assets/js/admin-font-manager.js`

**Ergebnis dieses Schritts**

- Das Asset setzt jetzt einen gemeinsamen Pending-Zustand auf Formulare, damit Folge-Submits direkt geblockt werden.
- Delete-Buttons ignorieren wiederholte Klicks auf bereits laufende Aktionen, und Buttons tragen zusätzlich `aria-disabled`.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/assets/js/admin-font-manager.js`: Pending-/Submit-Guard für Save-, Scan-, Download- und Delete-Aktionen nachgezogen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Save-/Delete-Reportpfade staffeln)
2. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
4. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)

### Schritt 433 — 27.03.2026 — Font-View spiegelt Scan-, Host- und Download-Grenzen direkter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **433 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Die View zeigt jetzt vorbereitete Hinweise für Scan-Gesamtlimit, Einzeldatei-Limit, erlaubte Hosts, Endungen und Skip-Segmente.
- Direktdownload und Scan-Bereich nennen die Schutzplanken früher, statt sie nur still aus Modulkonstanten zu erben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: Scan- und Self-Hosting-Grenzen klarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/assets/js/admin-font-manager.js` (neue Pending-Zustände robuster über alle Mutationen staffeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Save-/Delete-Reportpfade staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 432 — 27.03.2026 — FontManagerModule staffelt Constraints, Save-Details und Bulk-Fehler enger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **432 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Das Modul liefert jetzt zusätzliche Scan-, Host- und Download-Constraints inklusive Labels an die View.
- Save-, Delete- und Scan-Erfolge geben detailreichere Kontextdaten zurück.
- Sammeldownload-Fehler laufen jetzt strukturierter mit Report-Kontext statt als knappe Fehlersätze zurück.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Scan-, Save- und Bulk-Download-Vertrag weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (neue Constraints und Schutzgrenzen vollständig im UI spiegeln)
2. `CMS/assets/js/admin-font-manager.js` (neue Pending-Zustände robuster über alle Mutationen staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 431 — 27.03.2026 — Marketplace-View nennt Hosts sowie Manifest- und Archivgrenzen expliziter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **431 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/plugins/marketplace.php`

**Ergebnis dieses Schritts**

- Der Marketplace nennt jetzt erlaubte Hosts sowie Manifest- und Archivgrenzen direkt über dem Suchbereich.
- Auto-Install-Limits hängen damit sichtbarer am UI statt nur an einzelnen Warnbadges oder Fehlermeldungen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/plugins/marketplace.php`: Remote- und Archivgrenzen expliziter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
4. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)

### Schritt 430 — 27.03.2026 — PluginMarketplaceModule staffelt Install-Ziel und Verifizierungsstatus klarer

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **430 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Erfolgreiche Auto-Installationen spiegeln jetzt Zielpfad und SHA-256-Verifizierungsstatus direkter im Marketplace-Kontext.
- Fehlerpfade übernehmen zusätzlich strukturiertere Installer-Ergebnisdaten aus dem Update-Service.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Install-Erfolge und Installer-Fehlerkontext nachvollziehbarer gemacht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/plugins/marketplace.php` (neue Host-/Manifest-/Archivgrenzen im UI spiegeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Service-Pfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 429 — 27.03.2026 — PluginMarketplaceModule macht Remote-Registry-Fehler und Cache-Fallbacks präziser sichtbar

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **429 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Remote-Registry-Ladevorgänge liefern jetzt Fehler- und Detailkontext statt stiller Leer-Arrays.
- Cache- und None-Fallbacks nennen HTTP-/Content-Type-/Eintragsprobleme dadurch präziser im Marketplace.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Remote-Registry-Fallbacks nachvollziehbarer und detailreicher gemacht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Install-Ziel und Verifizierungsstatus weiter staffeln)
2. `CMS/admin/views/plugins/marketplace.php` (neue Host-/Manifest-/Archivgrenzen im UI spiegeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 428 — 27.03.2026 — Media-Bibliothek spiegelt Finder-Grenzen und Upload-Limits direkter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **428 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/library.php`

**Ergebnis dieses Schritts**

- Die Bibliothek nennt Upload-, Such- und Ordnergrenzen jetzt direkt oberhalb des Finders bzw. Browsers.
- Upload- und Ordnerformulare hängen ihre Maximalwerte zusätzlich an vorbereitete Modul-Constraints statt an harte View-Limits.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/library.php`: Finder-Grenzen und Upload-Limits expliziter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
4. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)

### Schritt 427 — 27.03.2026 — Media-Kategorien zeigen Eingabegrenzen und System-Schutz deutlicher an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **427 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/categories.php`

**Ergebnis dieses Schritts**

- Kategorien nennen Name-/Slug-Limits jetzt explizit im Admin.
- Formularfelder hängen ihre Maximalwerte an denselben Modulvertrag wie die Delete-Schutzgrenzen für System-Slugs.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/categories.php`: Kategorie-Constraints sichtbarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/library.php` (Finder-Grenzen und Upload-Limits im UI spiegeln)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 426 — 27.03.2026 — Media-Settings ziehen Upload- und Dimensionslimits aus dem Modulvertrag

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **426 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/settings.php`

**Ergebnis dieses Schritts**

- Die Settings-View nutzt Upload-, Qualitäts- und Thumbnail-Grenzen jetzt direkt aus vorbereiteten Modul-Constraints.
- Lokale Default-/Max-Werte wurden reduziert, damit UI und Serverpfad enger am selben Vertrag hängen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/settings.php`: Settings-Limits konsequenter aus Modul-Constraints gerendert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/categories.php` (Kategorie-Limits und System-Schutz im UI spiegeln)
2. `CMS/admin/views/media/library.php` (Finder-Grenzen und Upload-Limits im UI spiegeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Service- und Reportpfade vorbereiten)

### Schritt 425 — 27.03.2026 — MediaModule liefert Constraints, Erfolgsdetails und Fehlerkontext strukturierter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **425 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/media/MediaModule.php`

**Ergebnis dieses Schritts**

- Finder-, Kategorie- und Settings-Constraints werden jetzt vorbereitet an die Views geliefert.
- Erfolgreiche Medien-Aktionen liefern zusätzliche Details, und Fehlerpfade geben `details`, `error_details` sowie Report-Kontext strukturierter aus.
- Settings-Fehler erhalten zusätzlich den Kontext geänderter Felder.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/media/MediaModule.php`: Constraints, Erfolgsdetails und Reportpfade enger am Modulvertrag gebündelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/settings.php` (neue Constraints im UI spiegeln)
2. `CMS/admin/views/media/categories.php` (Kategorie-Limits und System-Schutz im UI spiegeln)
3. `CMS/admin/views/media/library.php` (Finder-Grenzen und Upload-Limits im UI spiegeln)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
5. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)

### Schritt 424 — 27.03.2026 — Media-Entry staffelt unbekannte Aktionen und Payloadfehler reportbar

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **424 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Unbekannte Aktionen, fehlende Berechtigungen und ungültige Media-Payloads laufen jetzt als strukturierte Failure-Rückgaben mit Details und Report-Kontext an die Section-Shell.
- Der Media-Entry nutzt dafür zusätzlich einen zentralen Route-Pfad statt verstreuter Redirect-Strings.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Entry-Fehlerpfade an gemeinsamen Report-/Detailvertrag angeglichen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/media/MediaModule.php` (Constraints, Erfolgsdetails und Reportpfade weiter staffeln)
2. `CMS/admin/views/media/settings.php` (neue Constraints im UI spiegeln)
3. `CMS/admin/views/media/categories.php` (Kategorie-Limits und System-Schutz im UI spiegeln)
4. `CMS/admin/views/media/library.php` (Finder-Grenzen und Upload-Limits im UI spiegeln)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)

### Schritt 423 — 27.03.2026 — Theme-Explorer-View spiegelt Editor-Limits direkter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **423 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- Der Explorer nennt das Browser-Editor-Limit jetzt zusätzlich im Dateibaum und im Datei-Kopf.
- Bearbeitungsgrenzen bleiben dadurch sichtbarer, bevor Nutzer in Save-Sperren oder Oversize-Dateien laufen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/editor.php`: Editor-Limits und Dateistatus expliziter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
3. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)
4. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)

### Schritt 422 — 27.03.2026 — ThemeEditorModule gibt Fehler- und Save-Kontext strukturierter aus

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **422 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeEditorModule.php`

**Ergebnis dieses Schritts**

- Save-Fehler liefern jetzt `details`, `error_details` und `report_payload`.
- Erfolgreiche Saves geben zusätzlich Datei- und Größenkontext an die Section-Shell zurück.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeEditorModule.php`: Fehler- und Save-Vertrag für Theme-Dateien enger gefasst

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/editor.php` (neue Limits und Dateistatus im UI spiegeln)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 421 — 27.03.2026 — Theme-Explorer-Entry normalisiert Payloadfehler jetzt reportbar

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **421 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-explorer.php`

**Ergebnis dieses Schritts**

- Ungültige Save-POSTs werden jetzt als strukturierte Failure-Rückgaben mit Detail- und Report-Kontext an die Section-Shell gereicht.
- Datei- und Aktionsfehler bleiben damit im Explorer konsistenter nachvollziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Entry-Fehlerpfade an den gemeinsamen Report-Vertrag angeglichen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` (Save-Fehler und Save-Erfolge strukturierter staffeln)
2. `CMS/admin/views/themes/editor.php` (neue Limits im UI spiegeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 420 — 27.03.2026 — Theme-Editor-Fallback zeigt Reason-Code und Erwartungspfad deutlicher an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **420 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/customizer-missing.php`

**Ergebnis dieses Schritts**

- Der Fallback nennt jetzt Reason-Code, Theme-Slug und erwarteten Customizer-Pfad expliziter.
- Theme-Entwickler sehen dadurch schneller, warum der direkte Theme-Customizer nicht geladen werden konnte.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/customizer-missing.php`: Fallback-Kontext klarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Explorer-Fehler- und Save-Verträge staffeln)
2. `CMS/admin/views/themes/editor.php` (neue Limits im UI spiegeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 419 — 27.03.2026 — Theme-Editor-Entry bereitet Fallback-Zustand strukturierter auf

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **419 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-editor.php`

**Ergebnis dieses Schritts**

- Der Theme-Editor liefert für seinen Fallback jetzt Reason-Code, erwarteten Customizer-Pfad und Constraints vorbereitet an die View.
- Der Fallback hängt dadurch weniger an bloßem Freitext und bleibt besser maschinen- sowie UI-lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-editor.php`: Fallback-State enger als Runtime-Vertrag modelliert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/customizer-missing.php` (neue Fallback-Metadaten im UI spiegeln)
2. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Explorer-Fehler- und Save-Verträge staffeln)
3. `CMS/admin/views/themes/editor.php` (neue Limits im UI spiegeln)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
5. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)

### Schritt 418 — 27.03.2026 — Legal-Sites-View zeigt Statuskarten und Eingabegrenzen transparenter an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **418 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/legal/sites.php`

**Ergebnis dieses Schritts**

- Die View nutzt vorbereitete Kennzahlen für Bereiche, Zuordnungen und veröffentlichte Zielseiten.
- Zusätzlich werden sichere Eingabegrenzen für HTML- und Profilfelder direkt im Admin gespiegelt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/legal/sites.php`: Status- und Constraint-Daten klarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Page-Optionen serverseitig vorbereiten)

### Schritt 417 — 27.03.2026 — LegalSitesModule staffelt Fehler und Statusdaten enger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **417 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/legal/LegalSitesModule.php`

**Ergebnis dieses Schritts**

- Save-, Profil- und Generatorfehler liefern jetzt `details`, `error_details` und `report_payload`.
- Zusätzlich stellt das Modul Statuskarten und Eingabe-Constraints vorbereitet an die View bereit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/legal/LegalSitesModule.php`: Fehler- und UI-Vertrag für Legal Sites weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/legal/sites.php` (neue Status-/Constraint-Daten im UI spiegeln)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 416 — 27.03.2026 — Legal-Sites-Entry gibt Requestfehler jetzt reportbar zurück

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **416 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Berechtigungs- und Requestfehler laufen jetzt als strukturierte Failure-Rückgaben mit Detail- und Report-Kontext in die Section-Shell.
- Damit bleiben ungültige Generator- oder Save-POSTs im Legal-Bereich konsistenter nachvollziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Entry-Fehlerpfade an gemeinsamen Report-Vertrag angeglichen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/legal/LegalSitesModule.php` (Fehler und Statusdaten strukturierter ausgeben)
2. `CMS/admin/views/legal/sites.php` (neue Status-/Constraint-Daten im UI spiegeln)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Fallback-Verträge weiter annähern)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
5. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)

### Schritt 415 — 27.03.2026 — Font-View zeigt Asset-Status lokaler Fonts transparenter an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **415 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Die Tabelle lokaler Fonts zeigt jetzt Dateigröße, CSS-Pfad und Asset-Status direkter im Admin.
- Fehlende Font- oder CSS-Dateien werden zusätzlich direkt als Warnzustand markiert.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: Lokale Font-Assets und Dateimetadaten transparenter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Scan-/Bulk-Reportpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 414 — 27.03.2026 — FontManagerModule staffelt Reportpfade und Asset-Metadaten enger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **414 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Download- und Delete-Fehler liefern jetzt zusätzlich `error_details` und `report_payload`.
- Für lokale Fonts werden Dateipfade, CSS-Pfade, Dateigröße und Asset-Status serverseitig vorbereitet.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Fehler- und Asset-Vertrag enger gefasst

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (neue Asset-Metadaten im UI vollständig spiegeln)
2. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 413 — 27.03.2026 — Font-Manager-Entry gibt Payload-Fehler jetzt reportbar zurück

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **413 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Berechtigungs- und Payloadfehler werden jetzt als strukturierte Failure-Rückgaben mit Detail- und Report-Kontext an die Section-Shell gereicht.
- Ungültige Font-ID, leere Google-Font-Namen oder verbotene Aktionen bleiben damit im Admin konsistenter nachvollziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Entry-Fehlerpfade an gemeinsamen Report-Vertrag angeglichen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/FontManagerModule.php` (Download-/Delete-Reportpfade und Asset-Metadaten staffeln)
2. `CMS/admin/views/themes/fonts.php` (Asset-Metadaten im UI spiegeln)
3. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)

### Schritt 412 — 27.03.2026 — Marketplace-View spiegelt Paket- und Cache-Grenzen direkter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **412 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/plugins/marketplace.php`

**Ergebnis dieses Schritts**

- Über der Suche nennt der Marketplace jetzt zusätzlich das Auto-Install-Paketlimit und die TTL des Registry-Caches.
- Dadurch werden Install- und Cache-Grenzen früher sichtbar, bevor Nutzer erst in Warnbadges oder Fehlermeldungen hineinlaufen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/plugins/marketplace.php`: Paket- und Cache-Limits expliziter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports für Downloads staffeln)
2. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Registry-/Remote-Fallbackpfade staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 411 — 27.03.2026 — PluginMarketplaceModule gibt Installfehler jetzt strukturiert reportbar zurück

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **411 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Installationsfehler liefern jetzt `details`, `error_details` und `report_payload` inklusive Slug-, Host-, Paket- und Hash-Kontext.
- Zusätzlich stellt das Modul Paket- und Registry-Constraints expliziter an die View bereit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Fehler- und Constraint-Vertrag für Marketplace-Installationen weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/plugins/marketplace.php` (neue Paket-/Registry-Constraints im UI spiegeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports für Downloads staffeln)
3. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 410 — 27.03.2026 — Plugin-Marketplace-Entry staffelt Fehlerrückgaben an den Report-Vertrag an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **410 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`

**Ergebnis dieses Schritts**

- Permission-, Payload- und Katalog-Slug-Fehler laufen jetzt als strukturierte Failure-Rückgaben mit Details und Report-Kontext in die Section-Shell.
- Der Entry bleibt damit näher am gemeinsamen Admin-Vertrag statt nur nackte Fehlersätze zurückzugeben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Entry-Fehlerpfade an gemeinsamen Report-/Detailvertrag angeglichen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Installationsfehler und Constraints strukturierter ausgeben)
2. `CMS/admin/views/plugins/marketplace.php` (neue Paket-/Registry-Constraints im UI spiegeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports für Downloads staffeln)
4. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)

### Schritt 409 — 27.03.2026 — Section-Shell reicht strukturierte Flash- und Report-Daten vollständig durch

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **409 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/partials/section-page-shell.php`

**Ergebnis dieses Schritts**

- Alert-Typen bleiben jetzt auch über Redirect- und Inline-Pfade erhalten.
- Zusätzlich reicht die Section-Shell `error_details` und `report_payload` vollständig an `flash-alert.php` weiter.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/partials/section-page-shell.php`: Gemeinsamer Flash-/Report-Vertrag im Admin gehärtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (Entry-Fehler an den neuen Report-Vertrag anhängen)
2. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Installationsfehler strukturierter ausgeben)
3. `CMS/admin/views/plugins/marketplace.php` (Limits direkter im UI spiegeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports staffeln)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)

### Schritt 408 — 27.03.2026 — Marketplace-View zeigt Host-, Paket- und Hash-Kontext klarer an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **408 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/plugins/marketplace.php`

**Ergebnis dieses Schritts**

- Marketplace-Karten zeigen jetzt Download-Host, Paketgröße und eine gekürzte SHA-256 expliziter an.
- Sperren wegen übergroßer Pakete oder nicht freigegebener Hosts werden zusätzlich als eigene Warn-Badges sichtbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/plugins/marketplace.php`: Installierbarkeit und Paket-/Host-Kontext transparenter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Remote-/Signaturpfade und Fehlerrückgaben staffeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports für Downloads staffeln)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
4. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 407 — 27.03.2026 — PluginMarketplaceModule staffelt Paketgröße und Host-Vertrag enger

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **407 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Paketgrößen werden jetzt explizit normalisiert und für Auto-Install gegen ein festes Maximalvolumen geprüft.
- Zusätzlich liefert das Modul Host-, Paket- und Hash-Metadaten direkt im Datenvertrag an die View.
- Übergroße Pakete bleiben damit im Marketplace früher als manuell zu behandelnder Pfad markiert.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Paket- und Quellenvertrag für Auto-Install enger gefasst

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/plugins/marketplace.php` (neue Paket-/Host-Metadaten vollständig im UI spiegeln)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Remote-/Signaturpfade staffeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports für Downloads staffeln)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)

### Schritt 406 — 27.03.2026 — Font-Manager-View zeigt Remote-Download-Grenzen deutlicher an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **406 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Die Font-Manager-Ansicht nennt jetzt zusätzlich die Grenzen für Remote-Dateien und das Gesamtvolumen pro Schrift-Import.
- Damit sind Self-Hosting-Limits im Admin sichtbar, bevor ein Download erst im Fehlerfall daran scheitert.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: Remote-Download-Limits expliziter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Paket-/Host-Vertrag für Auto-Install staffeln)
2. `CMS/admin/views/plugins/marketplace.php` (Paket-/Host-Metadaten im UI spiegeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Dateimetadaten und Fehlerreports für Downloads staffeln)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)

### Schritt 405 — 27.03.2026 — FontManagerModule härtet Remote-Downloads und Cleanup weiter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **405 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Der Font-Manager begrenzt Remote-Dateien jetzt zusätzlich über ein Gesamtvolumen pro Schrift-Import.
- Geladene WOFF/WOFF2/TTF/OTF-Dateien werden anhand ihres Headers gegen den erwarteten Typ geprüft.
- Wenn CSS- oder DB-Persistenz fehlschlägt, werden bereits gespeicherte Teil-Downloads wieder entfernt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Remote-Download-, Binär- und Cleanup-Vertrag enger gefasst

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (neue Remote-Download-Grenzen im UI spiegeln)
2. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Paket-/Host-Vertrag für Auto-Install staffeln)
3. `CMS/admin/views/plugins/marketplace.php` (Paket-/Host-Metadaten im UI spiegeln)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)

### Schritt 404 — 27.03.2026 — Media-Kategorien ziehen System-Slugs aus dem Modulvertrag

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **404 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/categories.php`

**Ergebnis dieses Schritts**

- Die Kategorien-Ansicht übernimmt die Liste geschützter System-Slugs jetzt direkt aus `MediaModule`.
- Damit hängt die Delete-UI nicht mehr an einer eigenen lokalen Schattenliste neben dem Backend.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/categories.php`: System-Kategoriegrenzen aus dem Modulvertrag übernommen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Font-Downloadpfade und Dateimetadaten weiter staffeln)
2. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Einstellungen-/Finder-Pfade an vorbereitete Verträge hängen)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Download-/Signaturpfade gegenprüfen)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)

### Schritt 403 — 27.03.2026 — Media-Bibliothek rendert vorbereitete ViewModels statt lokaler Helper

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **403 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/library.php`

**Ergebnis dieses Schritts**

- Die Bibliotheks-View nutzt Breadcrumbs, Browse-URLs, Kategorieoptionen und Datei-/Ordner-Metadaten jetzt direkt aus dem Modulvertrag.
- Lokale Pfad-, Größen- und Confirm-Helfer wurden dadurch deutlich reduziert.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/library.php`: View rendert vorbereitete Browse-/Delete-/Anzeigezustände statt eigener Hilfslogik

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/categories.php` (System-Slug-Vertrag an das Modul koppeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Font-Downloadpfade und Dateimetadaten weiter staffeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Download-/Signaturpfade gegenprüfen)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)

### Schritt 402 — 27.03.2026 — Media-Uploads reichen Fehlerdetails und Report-Kontext strukturierter weiter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **402 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Upload-Batch-Fehler liefern jetzt neben der Flash-Nachricht auch strukturierte Detailzeilen.
- Wenn ein Modulfehler bereits einen Report-Kontext mitbringt, wird dieser bis zur Section-Shell weitergereicht.
- Dadurch können Medien-Upload-Probleme im Admin nachvollziehbarer dargestellt und bei Bedarf direkt als Report erfasst werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Upload-Flash-Vertrag um Details und optionalen Report-Payload ergänzt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/library.php` (neue Browse-/Dateimetadaten vollständig im Markup nutzen)
2. `CMS/admin/views/media/categories.php` (System-Slug-Vertrag an das Modul koppeln)
3. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Font-Downloadpfade und Dateimetadaten weiter staffeln)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Download-/Signaturpfade gegenprüfen)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Settings-Pfade vorbereiten)

### Schritt 401 — 27.03.2026 — MediaModule bereitet Bibliothekszustand und Anzeige-Metadaten serverseitig auf

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **401 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/media/MediaModule.php`

**Ergebnis dieses Schritts**

- Das Media-Modul liefert Breadcrumbs, Browse-Ziele, Kategorieoptionen und KPI-Zahlen jetzt vorbereitet an die Bibliotheks-View.
- Ordner- und Dateieinträge tragen Anzeige-Metadaten wie modifiziertes Datum, Dateityp, Bildstatus, formatierten Speicherbedarf sowie Confirm-URLs für den Member-Bereich direkt im ViewModel.
- Damit wandert weiterer Navigations- und Anzeigezustand aus dem Template in den Modulvertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/media/MediaModule.php`: Bibliotheks- und Anzeigezustand als serverseitiges ViewModel vorbereitet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php` (Upload-Flash-/Report-Vertrag für Fehlerpfade nachziehen)
2. `CMS/admin/views/media/library.php` (neue Browse-/Dateimetadaten vollständig im Markup nutzen)
3. `CMS/admin/views/media/categories.php` (System-Slug-Vertrag an das Modul koppeln)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Font-Downloadpfade und Dateimetadaten weiter staffeln)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Download-/Signaturpfade gegenprüfen)

### Schritt 400 — 27.03.2026 — Font-Manager-View macht Scan-Quelle und Stand sichtbar

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **400 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Die Font-Manager-Ansicht zeigt Scan-Metadaten jetzt direkter an:
  - Quelle (`live` oder `cache`) wird neben den Scan-Kennzahlen ausgegeben.
  - Der Zeitstempel des letzten verwerteten Scan-Ergebnisses ist im Admin sichtbar.
- Damit bleibt nachvollziehbar, ob der aktuelle Zustand aus einem frischen Scan oder aus Wiederverwendung stammt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: Scan-Herkunft und Stand sichtbar im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Font-Downloadpfade und Dateimetadaten weiter staffeln)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Download-/Signaturpfade gegenprüfen)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
4. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Cache-/Metadatenpfade weiter bündeln)
5. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (weitere Generator-/Audit-Hinweise staffeln)

### Schritt 399 — 27.03.2026 — FontManagerModule wiederverwendet Theme-Scans und strukturiert Rückgaben klarer

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **399 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Das Font-Manager-Modul wiederverwendet Theme-Scans jetzt über einen themebezogenen Cache im Settings-Speicher.
- Scan-, Sammeldownload- und Einzel-Download-Antworten liefern strukturiertere Details statt nur langer zusammengesetzter Meldungen.
- Font-Mutationen invalidieren den Cache jetzt gezielt, damit installierte oder gelöschte Fonts nicht als veraltete Scanreste im Admin verbleiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Rescan-I/O reduziert, Ergebnisvertrag und Cache-Invalidierung verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (neue Cache-/Stand-Metadaten im UI spiegeln)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Remote-Downloadpfade staffeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Download-Checks prüfen)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/theme-editor.php` (Explorer-/Customizer-Verträge weiter annähern)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Metadaten- und Cachepfade weiter bündeln)

### Schritt 398 — 27.03.2026 — Plugin-Marketplace-View zeigt Cache-Herkunft detaillierter an

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **398 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/plugins/marketplace.php`

**Ergebnis dieses Schritts**

- Die Marketplace-Ansicht rendert Quellenhinweise jetzt detailreicher:
  - Neben der Registry-URL werden bei Cache-Nutzung auch Stand und Cache-Alter ausgegeben.
  - Remote-/Cache-/Fallback-Situationen bleiben damit im Admin klarer sichtbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/plugins/marketplace.php`: Source-/Cache-Details transparenter im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/FontManagerModule.php` (Theme-Scan-Ergebnisse wiederverwenden und strukturieren)
2. `CMS/admin/views/themes/fonts.php` (Scan-Quelle/Stand im UI spiegeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Install-/Signaturpfade prüfen)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/theme-editor.php` (Explorer-/Customizer-Verträge weiter annähern)
5. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Metadaten-/Cachepfade weiter bündeln)

### Schritt 397 — 27.03.2026 — PluginMarketplaceModule mit Registry-Cache und Fallback-Vertrag verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **397 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Das Modul cached Remote-Registry-Daten jetzt über den Settings-Speicher mit TTL.
- Wenn die Remote-Quelle ausfällt, kann ein letzter bekannter Cache gezielt als Fallback genutzt und als solcher an die View gemeldet werden.
- Installationsfehler geben zusätzlich Quelle und gekürzten Hash-Kontext strukturiert an die UI zurück.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Remote-Registry entkoppelt, Fallback-Vertrag und Fehlerdetails strukturierter

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/plugins/marketplace.php` (neue Cache-Details im UI spiegeln)
2. `CMS/admin/modules/themes/FontManagerModule.php` (Theme-Scan-Wiederverwendung und Ergebnisstruktur nachziehen)
3. `CMS/admin/views/themes/fonts.php` (Scan-Quelle/Stand sichtbar machen)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Signatur-/Downloadpfade gegenprüfen)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Customizer-Verträge weiter annähern)

### Schritt 396 — 27.03.2026 — Plugin-Marketplace-Entry prüft Slugs enger gegen den Katalog

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **396 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`

**Ergebnis dieses Schritts**

- Install-Slugs werden jetzt früher begrenzt und vor dem Install-Dispatch zusätzlich gegen den aktuell geladenen Marketplace-Katalog geprüft.
- Dadurch landen veraltete oder manipulierte Install-POSTs nicht mehr blind im Modulpfad.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Slug-Vertrag enger und katanäher geprüft

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Registry-Cache und Fallback-Vertrag nachziehen)
2. `CMS/admin/views/plugins/marketplace.php` (Source-/Cache-Details im UI spiegeln)
3. `CMS/admin/modules/themes/FontManagerModule.php` (Theme-Scan-Wiederverwendung und Ergebnisstruktur staffeln)
4. `CMS/admin/views/themes/fonts.php` (Scan-Quelle/Stand sichtbar machen)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Explorer-/Customizer-Verträge weiter annähern)

### Schritt 395 — 27.03.2026 — Font-Manager-Formulare an gemeinsamen Pending-Vertrag gehängt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **395 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Die Font-Manager-View bindet mutierende Aktionen jetzt konsistenter an das Asset:
  - Scan-, Direktdownload-, Sammeldownload-, Delete- und Save-Formulare tragen nun gemeinsame `data-font-manager-form`-/Pending-Attribute.
  - Buttons melden laufende Zustände damit früher und einheitlicher an das JS.
- Dadurch hängt die UI weniger an implizitem Submit-Verhalten einzelner Buttons.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: Mutierende Aktionen an gemeinsamen Pending-/Status-Vertrag gebunden

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Download-Ergebnisse und Metadaten weiter staffeln)
2. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (weitere Tree-/Preview-Metadaten prüfen)
3. `CMS/admin/font-manager.php` bzw. `CMS/assets/js/admin-font-manager.js` (weitere Disable-/Result-Zustände für Bulk-Aktionen prüfen)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (kritische Remote-/Signaturpfade wieder hochziehen)

### Schritt 394 — 27.03.2026 — Font-Manager-Asset gegen Doppelaktionen geglättet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **394 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/assets/js/admin-font-manager.js`

**Ergebnis dieses Schritts**

- Das Font-Manager-Asset setzt Buttons jetzt bei laufenden Requests in einen gemeinsamen Pending-Zustand:
  - Confirm-Delete und klassische Form-Submits laufen über denselben Button-/Submit-Helfer.
  - Scan-, Download- und Save-Aktionen sperren ihre Buttons jetzt früher gegen hektische Mehrfachklicks.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/assets/js/admin-font-manager.js`: Doppel-Submit-Schutz und Pending-Button-Pfad vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (neuen Pending-Vertrag vollständig in alle Formulare ziehen)
2. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Download-Ergebnisse staffeln)
3. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Explorer-/Customizer-Verträge weiter annähern)
4. `CMS/assets/js/admin-theme-explorer.js` (weitere Editor-Status-/Result-Zustände prüfen)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (kritische Remote-Sonderpfade hochziehen)

### Schritt 393 — 27.03.2026 — Theme-Explorer-Asset um Dirty- und Pending-Zustände ergänzt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **393 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/assets/js/admin-theme-explorer.js`

**Ergebnis dieses Schritts**

- Das Explorer-Asset schützt Editor-Aktionen jetzt sichtbarer:
  - Ungespeicherte Änderungen setzen einen Dirty-State und warnen beim Verlassen der Seite.
  - `Ctrl+S` und Formular-Saves markieren den Save-Button als laufend, statt stille Mehrfachsubmits zu erlauben.
  - Die Suche blendet Ordner jetzt konsistenter entsprechend sichtbarer Treffer ein oder aus.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/assets/js/admin-theme-explorer.js`: Dirty-State, Pending-Save und Suchlogik weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/assets/js/admin-font-manager.js` (Pending-Zustände auf alle Form-Aktionen ausrollen)
2. `CMS/admin/views/themes/fonts.php` (Asset-Vertrag in die Formulare spiegeln)
3. `CMS/admin/modules/themes/ThemeEditorModule.php` (weitere Baum-/Datei-Metadaten prüfen)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/theme-editor.php` (Explorer-/Customizer-Verträge weiter annähern)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (kritische Remote-Pfade wieder hochziehen)

### Schritt 392 — 27.03.2026 — Theme-Explorer-View spiegelt Baumgrenzen und Save-Status sichtbarer

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **392 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- Die Explorer-View zeigt Dateibaum-Grenzen und Warnungen jetzt direkt an:
  - Anzahl geladener und übersprungener Einträge wird im Baumkopf sichtbar.
  - Modulwarnungen zu Tiefen-, Eintrags- oder Symlink-Grenzen werden als Alert im Dateibaum gerendert.
- Zusätzlich teilen sich Markup und Asset jetzt einen klareren Save-Vertrag:
  - Suchfeld, Save-Button und Formular liefern Pending-/Unsaved-Metadaten explizit ans JS.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/editor.php`: Baumgrenzen, Warnungen und Save-Zustände früher im UI sichtbar

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/assets/js/admin-theme-explorer.js` (Dirty-/Pending-Vertrag ausnutzen)
2. `CMS/assets/js/admin-font-manager.js` (Pending-Zustände für mutierende Aktionen nachziehen)
3. `CMS/admin/views/themes/fonts.php` (Action-Formulare an gemeinsamen Status-Vertrag hängen)
4. `CMS/admin/modules/themes/ThemeEditorModule.php` (weitere Explorer-Metadaten prüfen)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (kritische Remote-Pfade wieder hochziehen)

### Schritt 391 — 27.03.2026 — ThemeEditorModule begrenzt Dateibaum und Hotspot-Verzeichnisse klarer

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **391 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeEditorModule.php`

**Ergebnis dieses Schritts**

- Das Modul staffelt Theme-Dateibäume jetzt restriktiver:
  - Tiefe, Gesamtanzahl und Einträge pro Verzeichnis sind explizit begrenzt.
  - Typische Hotspot-Segmente wie `vendor`, `node_modules`, `cache`, `dist` und `build` werden im Explorer konsequent ausgelassen.
  - Symbolische Links zählen als übersprungene Elemente und erzeugen klare Schutzwarnungen.
- Zusätzlich liefert das Modul Baum-Zusammenfassung und UI-Constraints vorbereitet an die View aus.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeEditorModule.php`: Tree-Limits, Skip-Segmente und vorbereitete Baum-Metadaten ergänzt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/editor.php` (neue Baum-Metadaten im UI spiegeln)
2. `CMS/assets/js/admin-theme-explorer.js` (Save-/Dirty-Status nutzen)
3. `CMS/assets/js/admin-font-manager.js` (Pending-Zustände auf Form-Aktionen ausrollen)
4. `CMS/admin/views/themes/fonts.php` (Pending-Vertrag ans Markup hängen)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (kritische Remote-Pfade wieder hochziehen)

### Schritt 390 — 27.03.2026 — Theme-Explorer an Section-Shell und kleinen Request-Vertrag gehängt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **390 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-explorer.php`

**Ergebnis dieses Schritts**

- Der Theme-Explorer nutzt jetzt denselben Shell-Rahmen wie andere modernisierte Admin-Entrys:
  - Redirect-, Flash-, CSRF- und Layout-Boilerplate laufen über `section-page-shell.php` statt über Explorer-lokale Sonderpfade.
  - Aktion, Datei und Inhalt werden vor dem Save-Dispatch in einem kleinen Payload-Vertrag normalisiert.
- Redirects behalten die aktuell bearbeitete Datei weiterhin explizit im Zielpfad.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Entry-, Redirect- und Save-Dispatch näher an den gemeinsamen Section-Shell-Vertrag gezogen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` (Dateibaum- und Pfadgrenzen härten)
2. `CMS/admin/views/themes/editor.php` (Baumlimits und Save-Status im UI spiegeln)
3. `CMS/assets/js/admin-theme-explorer.js` (Dirty-/Pending-Status ergänzen)
4. `CMS/assets/js/admin-font-manager.js` (laufende Aktionen im UI sichtbarer machen)
5. `CMS/admin/views/themes/fonts.php` (Status-Vertrag im Markup spiegeln)

### Schritt 389 — 27.03.2026 — Font-Manager-UI und Scan-Grenzen sichtbarer gemacht

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **389 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Die Font-Manager-UI spiegelt Schutzgrenzen jetzt direkter im Admin:
  - Scan-Zusammenfassung zeigt geprüfte und übersprungene Dateien sowie Schutzwarnungen explizit an.
  - Typografie- und Direktdownload-Formulare übernehmen Font-Size-, Line-Height- und Google-Font-Limits direkt aus dem Modulvertrag.
- Dadurch bleiben Scan- und Eingabegrenzen nicht länger stilles Backend-Wissen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: Scan-Limits, Warnungen und Formulargrenzen sichtbarer im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (weitere Download-/Persistenzdetails und lokale Font-Metadaten staffeln)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/theme-explorer.php` (Customizer-/Explorer-Verträge weiter annähern)
3. `CMS/admin/font-manager.php` bzw. `CMS/assets/js/admin-font-manager.js` (weitere Client-Hinweise/Disable-Zustände für laufende Aktionen prüfen)
4. `CMS/admin/font-manager.php` bzw. `CMS/admin/views/themes/fonts.php` (Delete-/Download-Flows weiter am gemeinsamen Confirm-/Status-Vertrag ausrichten)
5. `CMS/admin/font-manager.php` bzw. `CMS/admin/modules/themes/FontManagerModule.php` (Remote-Download-Ergebnisse noch feiner strukturieren)

### Schritt 388 — 27.03.2026 — Font-Manager-Scanpfade und Font-Zuweisungen weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **388 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Das Modul begrenzt Theme-Scans jetzt deutlich enger:
  - Versteckte, große oder typische I/O-Hotspot-Dateien werden übersprungen.
  - Datei-, Gesamtvolumen- und Scan-Anzahl-Limits schützen den Font-Scan vor unnötig teuren Theme-Läufen.
- Zusätzlich wurde der Save-Vertrag enger gezogen:
  - Heading-/Body-Fonts werden nur noch gegen tatsächlich auswählbare Font-Keys akzeptiert.
  - Das Modul liefert Scan-Zusammenfassung und UI-Constraints vorbereitet an die View.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: I/O- und Save-Grenzen weiter verdichtet, Scan-/UI-Metadaten vorbereitet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (neue Scan-/Constraint-Daten im UI sichtbar machen)
2. `CMS/admin/theme-editor.php` (Entry näher an gemeinsame Shell-/Fallback-Muster ziehen)
3. `CMS/admin/font-manager.php` (Request-Vertrag weiter verdichten)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Editor-/Customizer-Verträge weiter angleichen)
5. `CMS/admin/font-manager.php` bzw. `CMS/assets/js/admin-font-manager.js` (Client-Status und Delete-/Download-Flows weiter glätten)

### Schritt 387 — 27.03.2026 — Font-Manager-Request-Vertrag weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **387 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Der Font-Manager-Entry normalisiert Aktionen und Payloads jetzt enger:
  - Aktionen laufen in Kleinbuchstaben über einen kleineren Allowlist-Vertrag.
  - Font-ID, Google-Font-Namen, Font-Keys, Schriftgröße, Zeilenhöhe und On-Prem-Schalter werden vor dem Modul-Dispatch früh normalisiert.
- `saveSettings()` bekommt damit vorbereitete Settings statt roher Formular-Payload.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Delete-, Download- und Save-Payloads früher validiert und vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/FontManagerModule.php` (Scan-I/O, Font-Keys und Limits im Modul nachziehen)
2. `CMS/admin/views/themes/fonts.php` (Formular- und Scan-Limits im UI spiegeln)
3. `CMS/admin/theme-editor.php` (Customizer-Fallback näher an gemeinsame Shell bringen)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Editor-/Customizer-Verträge weiter annähern)
5. `CMS/admin/font-manager.php` bzw. `CMS/assets/js/admin-font-manager.js` (Client-Hinweise und Delete-Vertrag weiter staffeln)

### Schritt 386 — 27.03.2026 — Theme-Editor an den gemeinsamen Section-Shell-Vertrag angenähert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **386 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-editor.php`
- `CMS/admin/views/themes/customizer-missing.php`

**Ergebnis dieses Schritts**

- Der Theme-Editor läuft nicht mehr über einen eigenen Layout-Sonderpfad:
  - `theme-editor.php` nutzt jetzt den gemeinsamen Section-Shell-Rahmen statt Header, Sidebar und Footer selbst zu verdrahten.
  - Customizer-Zustand und Fehlergründe werden als vorbereiteter Runtime-State aufgebaut.
- Für fehlende bzw. unsichere Customizer-Dateien gibt es jetzt eine dedizierte Admin-View:
  - Die Fallback-Ansicht zeigt den Grund explizit an und verlinkt direkt auf Theme-Verwaltung und Theme-Explorer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-editor.php`: eigener Layout-Sonderpfad entfernt, State-/Fallback-Vertrag enger an die Section-Shell gehängt
- `CMS/admin/views/themes/customizer-missing.php`: Fallback-Markup aus dem Entry in eine dedizierte View verlagert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` (Request-Vertrag weiter verdichten)
2. `CMS/admin/modules/themes/FontManagerModule.php` (Scan-/I/O-Grenzen und Font-Key-Validierung härten)
3. `CMS/admin/views/themes/fonts.php` (neue Grenzen und Warnungen im UI spiegeln)
4. `CMS/admin/theme-explorer.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Explorer-/Customizer-Vertrag weiter angleichen)
5. `CMS/admin/font-manager.php` bzw. `CMS/assets/js/admin-font-manager.js` (Delete-/Status-Verträge weiter glätten)

### Schritt 385 — 27.03.2026 — Medien-Upload- und Settings-Vertrag weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **385 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`
- `CMS/admin/modules/media/MediaModule.php`
- `CMS/admin/views/media/settings.php`
- `CMS/admin/views/media/library.php`

**Ergebnis dieses Schritts**

- Der Medien-Entry hält Uploads und Settings jetzt enger an einem kleineren Vertrag:
  - `media.php` normalisiert Aktionsnamen konsequent in Kleinbuchstaben, begrenzt Upload-Dateianzahl, Batch-Größe und Dateinamenslängen früher und klemmt numerische Settings-Felder bereits vor dem Modul-Dispatch auf sichere Grenzen.
  - Inkonsistente Upload-Payloads werden damit deutlich früher verworfen, statt erst tiefer im Service-Pfad aufzufallen.
- Das Modul und die Views ziehen diese Grenzen sichtbar mit:
  - `MediaModule` akzeptiert nur echte Upload-Tempdateien und mappt `allowed_types`/`member_allowed_types` zurück auf definierte Mediengruppen statt lose Erweiterungslisten zu persistieren.
  - `views/media/settings.php` und `views/media/library.php` spiegeln Zahlen- und Textlimits direkt in Formularfeldern wider.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Upload-Batch- und Settings-Payload früher validiert
- `CMS/admin/modules/media/MediaModule.php`: Typgruppen- und Tempfile-Vertrag enger am Modul gekapselt
- `CMS/admin/views/media/settings.php`, `CMS/admin/views/media/library.php`: Formulargrenzen sichtbarer im Admin gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Customizer-Entry und Explorer noch näher an einen gemeinsamen Shell-/Metadatenvertrag ziehen)
2. `CMS/admin/views/media/library.php` bzw. `CMS/admin/modules/media/MediaModule.php` (weitere Finder-/Metadaten-Caches und I/O-Bündelung prüfen)
3. `CMS/admin/legal-sites.php` bzw. `CMS/admin/modules/legal/LegalSitesModule.php` (Generator-/Profil-Sonderfälle weiter staffeln)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Remote-Fehlerzustände und Installations-Feedback weiter gegenprüfen)
5. `CMS/admin/font-manager.php` (Pfad-/MIME-/Preview-Grenzen als nächstes größeres I/O-Hotspot nachziehen)

### Schritt 384 — 27.03.2026 — Legal-Sites-POST-State und Capability-Grenze weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **384 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`
- `CMS/admin/modules/legal/LegalSitesModule.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry hält Request- und Fehlerpfade jetzt enger zusammen:
  - `legal-sites.php` normalisiert Rechtstext-HTML defensiver bereits im Entry und bündelt Aktion, Fehler und Payload über einen kleinen Request-Vertrag.
  - Fehlgeschlagene `save`-POSTs halten Rechtstexte und Seitenzuordnungen jetzt für den nächsten Render in Session-State zusammen, ähnlich wie es der Profilpfad bereits tat.
- Auch die Modulgrenze wurde nachgeschärft:
  - `LegalSitesModule` verlangt neben Admin-Status jetzt zusätzlich die Capability `manage_settings` auch im Modul selbst.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: POST-State und HTML-Payload enger normalisiert
- `CMS/admin/modules/legal/LegalSitesModule.php`: Capability-Grenze nicht mehr nur am Entry festgemacht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php` bzw. `CMS/admin/modules/media/MediaModule.php` (Upload-Batch-/Tempfile-/Settings-Vertrag weiter härten)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Dateimetadaten/Warnhinweise weiter staffeln)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Remote-Fehlerpfade weiter glätten)
4. `CMS/admin/font-manager.php` (Pfad-/MIME-/Preview-Grenzen als nächstes I/O-Hotspot angehen)
5. `CMS/admin/views/legal/sites.php` (weitere Formulargrenzen und Template-Restlogik prüfen)

### Schritt 383 — 27.03.2026 — Theme-Explorer-Dateimetadaten und Save-Grenzen sichtbarer gemacht

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **383 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeEditorModule.php`
- `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- Der Theme-Explorer liefert Dateistatus jetzt sichtbarer aus dem Modul:
  - `ThemeEditorModule` ergänzt Dateigröße, Dateiendung, Schreibbarkeit und einen konkreten Save-Sperrgrund als vorbereitete Metadaten.
  - Die Bearbeitbarkeit bleibt damit nicht länger nur implizit im Save-Pfad versteckt.
- Die View reagiert jetzt direkt auf diese Metadaten:
  - `views/themes/editor.php` zeigt Erweiterung, Dateigröße und Bearbeitbarkeitsstatus im Kopf an.
  - Nicht sicher bearbeitbare Dateien setzen Editor und Save-Button früh auf `readonly`/`disabled` statt erst beim Submit in einen vermeidbaren Fehler zu laufen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeEditorModule.php`: Dateimetadaten- und Warnvertrag weiter vorbereitet
- `CMS/admin/views/themes/editor.php`: Save-Sperrgründe und Bearbeitbarkeitsstatus früher im UI gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php` (Write-/Maskierungs- und Audit-Pfade weiter verdichten)
2. `CMS/admin/media.php` (I/O-, MIME- und Größenpfade weiter härten)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Quellen und Remote-Fehlerpfade weiter staffeln)
4. `CMS/admin/theme-editor.php` (Customizer-Entry näher an gemeinsame Shell-/Guard-Muster ziehen)
5. `CMS/admin/font-manager.php` (Pfad-/Preview-/MIME-Hotspot nachziehen)

### Schritt 382 — 27.03.2026 — Marketplace-Quellen und Fallback-Hinweise weiter geglättet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **382 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
- `CMS/admin/views/plugins/marketplace.php`
- `CMS/admin/views/themes/marketplace.php`

**Ergebnis dieses Schritts**

- Plugin- und Theme-Marketplaces halten ihre Katalogherkunft jetzt expliziter am View-Vertrag:
  - Beide Module melden Remote-, Local-Fallback- oder Ausfallstatus jetzt als kompaktes Quellenmodell inklusive URL an die View zurück.
  - Damit bleibt bei Remote-Ausfällen sichtbar, ob ein lokaler Index als Fallback genutzt wurde oder ob wirklich keine Quelle verfügbar ist.
- Die Views rendern dieses Quellenmodell direkt:
  - Plugin- und Theme-Marketplace zeigen jetzt einen klaren Flash-Hinweis mit Quellenangabe, statt stille Fallbacks nur indirekt über Listeninhalt spürbar zu machen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Remote-/Fallback-Status expliziter im Datenvertrag
- `CMS/admin/views/plugins/marketplace.php`, `CMS/admin/views/themes/marketplace.php`: Quellenhinweise und Fallback-Sichtbarkeit klarer im Admin gespiegelt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` bzw. `CMS/admin/views/themes/editor.php` (Dateimetadaten/Warnhinweise weiter staffeln)
2. `CMS/admin/legal-sites.php` (Write-/Maskierungs- und Audit-Pfade weiter verdichten)
3. `CMS/admin/media.php` (I/O-, MIME- und Größenpfade weiter härten)
4. `CMS/admin/theme-editor.php` (Customizer-Entry / Guard-Vertrag weiter gegenprüfen)
5. `CMS/admin/font-manager.php` (Pfad-/Preview-/MIME-Grenzen als nächstes I/O-Hotspot prüfen)

### Schritt 381 — 27.03.2026 — Theme-Explorer-Dateibaum und Editor-Interaktionen weiter aus dem Template gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **381 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-explorer.php`
- `CMS/admin/views/themes/editor.php`
- `CMS/assets/js/admin-theme-explorer.js`

**Ergebnis dieses Schritts**

- Der Theme-Explorer hängt bei Interaktionen jetzt enger an einem kleinen Entry-/View-/Asset-Vertrag:
  - `theme-explorer.php` bindet ein dediziertes Admin-Asset für den Explorer, statt Editor-Shortcuts und Laufzeitlogik im Template zu belassen.
  - `views/themes/editor.php` liefert Suchfeld, Dateibaum-Markierungen und Editor-Konfiguration vorbereitet für das Asset aus.
- Das neue Asset kapselt die Laufzeitlogik sauberer:
  - `admin-theme-explorer.js` übernimmt Dateifilter, Tab-Einrückung sowie `Ctrl+S`-Speichern zentral.
  - Dadurch bleibt der Explorer auch ohne geöffneten Codeblock konsistenter initialisiert und der Dateibaum besser fokussierbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Asset-Vertrag des Explorers klarer am Entry gebunden
- `CMS/admin/views/themes/editor.php`, `CMS/assets/js/admin-theme-explorer.js`: Inline-JS entfernt und Explorer-Interaktionen dediziert ausgelagert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Feedback und Remote-Fehlerpfade weiter gegeneinander glätten)
2. `CMS/admin/theme-editor.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Dateimetadaten, Warnhinweise und Edit-Grenzen weiter staffeln)
3. `CMS/admin/legal-sites.php` (Write-/Maskierungs- und Audit-Pfade weiter verdichten)
4. `CMS/admin/media.php` (I/O-, MIME- und Größenpfade weiter härten)
5. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Reorder-/Depth-Grenzen nach dem neuen Validierungsvertrag weiter gegenprüfen)

### Schritt 380 — 27.03.2026 — Landing-POST- und Redirect-Vertrag weiter stabilisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **380 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/landing-page.php`
- `CMS/admin/views/landing/page.php`

**Ergebnis dieses Schritts**

- Der Landing-Entry hält Aktionen und Redirect-Ziele jetzt enger an einem gemeinsamen Vertrag:
  - `landing-page.php` normalisiert Aktion, Feature-ID, Plugin-ID und aktiven Tab jetzt zusammen und leitet POST-Ergebnisse gezielt in den passenden Tab zurück.
  - Ungültige Feature-/Plugin-Angaben werden früher abgefangen, bevor sie lose im Modulpfad landen.
- Die View trägt diesen Vertrag jetzt mit:
  - Alle Formulare geben den aktiven Tab explizit mit, sodass Saves und Fehler nicht mehr auf den Header-Default zurückfallen.
  - Feature- und Plugin-Aktionen bleiben dadurch auch nach POSTs sichtbar im richtigen Arbeitskontext.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/landing-page.php`: POST-/Redirect-Vertrag klarer und tabstabiler
- `CMS/admin/views/landing/page.php`: Formularzustand expliziter zwischen Tabs und Aktionen getragen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI an dediziertes Asset hängen)
2. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Feedback weiter gegeneinander prüfen)
3. `CMS/admin/theme-editor.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Dateimetadaten/Warnhinweise weiter staffeln)
4. `CMS/admin/legal-sites.php` (Write-/Maskierungs- und Audit-Pfade weiter verdichten)
5. `CMS/admin/media.php` (I/O-, MIME- und Größenpfade weiter härten)

### Schritt 379 — 27.03.2026 — Kommentar-KPIs und Zeilenaktionen weiter in den Modulvertrag gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **379 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/comments/CommentsModule.php`
- `CMS/admin/views/comments/list.php`

**Ergebnis dieses Schritts**

- Die Kommentar-Moderation hängt bei KPIs und Dropdown-Aktionen jetzt enger an vorbereiteten ViewModels:
  - `CommentsModule` liefert Summary-Karten und statusabhängige Zeilenaktionen serverseitig aus, statt Rechte- und Statuskombinationen lose im Template auszuwerten.
  - Zeilenmodelle tragen damit nicht nur Badge- und Excerpt-Daten, sondern auch die konkrete Action-Liste für Moderation und Löschung.
- Die View reduziert dadurch weiteren Rest-Boilerplate:
  - KPI-Karten werden aus vorbereiteten Kartenmodellen gerendert.
  - Das Aktions-Dropdown iteriert über vorbereitete Zeilenaktionen statt viele Status-Branches lokal im Template zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/comments/CommentsModule.php`: KPI-/Action-Vertrag weiter ausgebaut
- `CMS/admin/views/comments/list.php`: Status- und Rechteverzweigungen im Template weiter reduziert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/landing-page.php` bzw. `CMS/admin/views/landing/page.php` (POST-/Tab-Vertrag weiter staffeln)
2. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI an dediziertes Asset hängen)
3. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Feedback weiter gegeneinander prüfen)
4. `CMS/admin/theme-editor.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Dateimetadaten/Warnhinweise weiter staffeln)
5. `CMS/admin/legal-sites.php` (Write-/Maskierungs- und Audit-Pfade weiter verdichten)

### Schritt 378 — 27.03.2026 — Tabellen-Editor bei Größenlimits und Save-Redirects weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **378 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/tables/TablesModule.php`
- `CMS/admin/site-tables.php`
- `CMS/admin/views/tables/edit.php`
- `CMS/assets/js/admin-site-tables.js`

**Ergebnis dieses Schritts**

- Der Tabellen-Editor hängt bei Save- und Editor-Pfaden jetzt enger an einem gemeinsamen Vertrag:
  - `TablesModule` normalisiert Tabellenname, Beschreibung, Spalten- und Zeilenstruktur jetzt restriktiver und begrenzt Spalten, Zeilen sowie Zellinhalte vor dem Persistieren.
  - `site-tables.php` fängt übergroße Editor-JSON-Payloads früher ab und leitet Save-Fehler wieder in den Editor statt zurück in die Listenansicht.
- View und Asset ziehen diese Grenzen direkt mit:
  - `views/tables/edit.php` zeigt vorbereitete Editor-Zusammenfassung und Limits aus dem Modul an.
  - `admin-site-tables.js` verhindert überschrittene Spalten-/Zeilen-Grenzen und bereinigt Eingaben bereits im Browser.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/tables/TablesModule.php`: Editor-Payload und Save-Validierung weiter gehärtet
- `CMS/admin/site-tables.php`: Redirect- und Größenlimitvertrag klarer am Entry verankert
- `CMS/admin/views/tables/edit.php`, `CMS/assets/js/admin-site-tables.js`: Editor-Limits sichtbarer zwischen View und Asset geteilt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/comments/list.php` bzw. `CMS/admin/modules/comments/CommentsModule.php` (rechteabhängige Zeilenmodelle weiter vorbereiten)
2. `CMS/admin/landing-page.php` bzw. `CMS/admin/views/landing/page.php` (POST-/Tab-Vertrag weiter staffeln)
3. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI an dediziertes Asset hängen)
4. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Feedback weiter gegeneinander prüfen)
5. `CMS/admin/theme-editor.php` bzw. `CMS/admin/modules/themes/ThemeEditorModule.php` (Dateimetadaten/Warnhinweise weiter staffeln)

### Schritt 377 — 27.03.2026 — Menü-Editor bei Parent-/Page-Picker und Item-Validierung weiter gestaffelt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **377 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/menu-editor.php`
- `CMS/admin/modules/menus/MenuEditorModule.php`
- `CMS/admin/views/menus/editor.php`
- `CMS/assets/js/admin-menu-editor.js`

**Ergebnis dieses Schritts**

- Der Menü-Editor hängt bei Request-, Picker- und Save-Pfaden jetzt enger an einem kleinen gemeinsamen Vertrag:
  - `menu-editor.php` normalisiert Payload-Fehler jetzt früher und begrenzt übergroße Item-JSON-Requests bereits im Entry, bevor der Shared-Shell-Dispatch weiterläuft.
  - `MenuEditorModule` validiert Menü-Namen, bekannte Theme-Positionen und bestehende Menü-IDs konsequenter und lehnt inkonsistente Item-Strukturen wie ungültige URLs, unbekannte Parent-Referenzen oder zyklische Parent-Ketten kontrolliert ab.
- Picker- und View-Daten wurden weiter aus dem Template gezogen:
  - Das Modul liefert vorbereitete Page-Picker-Optionen und die Editor-JSON-Konfiguration direkt für den View, statt dass `views/menus/editor.php` Seiten- und Item-Daten selbst erneut zusammenbaut.
  - Die View ersetzt die große Seiten-Button-Liste durch einen kompakteren Seiten-Picker, und `admin-menu-editor.js` ergänzt frühere Client-Validierung für Titel, URLs und Submit-Fehler direkt im Editor.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/menu-editor.php`: Payload-/Fehlervertrag weiter verdichtet
- `CMS/admin/modules/menus/MenuEditorModule.php`: Theme-Positions-, Page-Picker- und Item-Validierung weiter in den Modulvertrag gezogen
- `CMS/admin/views/menus/editor.php`, `CMS/assets/js/admin-menu-editor.js`: Picker-UI und Editor-Validierung klarer zwischen View-Daten und Asset-Vertrag getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (Editor-Payload, Größenlimits und Save-Pfade weiter verdichten)
2. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI perspektivisch an dediziertes Asset/konfigurierten Editor hängen)
3. `CMS/admin/views/comments/list.php` bzw. `CMS/admin/modules/comments/CommentsModule.php` (rechteabhängige Zeilenmodelle weiter vorbereiten)
4. `CMS/admin/landing-page.php` bzw. `CMS/admin/modules/landing/LandingPageModule.php` (POST-/Tab-Vertrag und mögliche Inline-Renderpfade weiter staffeln)
5. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Feedback weiter gegeneinander prüfen)
6. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (mögliche weitere Reorder-/Depth-Grenzen nach dem neuen Validierungsvertrag gegenprüfen)

### Schritt 376 — 27.03.2026 — Landing-Feature- und Plugin-ViewModels weiter aus dem Template gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **376 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/landing/LandingPageModule.php`
- `CMS/admin/views/landing/page.php`

**Ergebnis dieses Schritts**

- Der Landing-Admin hängt bei Content und Plugins jetzt enger an vorbereiteten Modul-Daten:
  - `LandingPageModule` liefert für den Content-Tab vorkonfigurierte Content-Typ-Optionen sowie normalisierte Feature-Karten statt roher Listen.
  - Für den Plugins-Tab bereitet das Modul Plugin-Karten inklusive Metadaten, Zielbereiche und gespeicherter Einstellungen auf, sodass die View nicht länger selbst über Override-Strukturen greifen muss.
- Die View reduziert dadurch weitere Ableitungs- und Restlogik:
  - `views/landing/page.php` rendert vorbereitete Auswahloptionen, Feature-Karten und Plugin-Karten mit konsistenterem Escaping.
  - Der Plugins-Tab hängt nicht mehr an der unsicheren Annahme eines `label`-Felds, obwohl der Service tatsächlich `name` liefert.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/landing/LandingPageModule.php`: Feature-/Plugin-ViewModel-Vertrag weiter ausgebaut
- `CMS/admin/views/landing/page.php`: Rest-Template-Logik und direkte Override-Zugriffe im Landing-Admin weiter reduziert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Parent-/Page-Picker und Item-Validierung weiter staffeln)
2. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (Editor-Payload, Größenlimits und Save-Pfade weiter verdichten)
3. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI perspektivisch an dediziertes Asset/konfigurierten Editor hängen)
4. `CMS/admin/views/comments/list.php` bzw. `CMS/admin/modules/comments/CommentsModule.php` (rechteabhängige Zeilenmodelle weiter vorbereiten)
5. `CMS/admin/landing-page.php` bzw. `CMS/admin/modules/landing/LandingPageModule.php` (POST-/Tab-Vertrag und mögliche Inline-Renderpfade weiter staffeln)
6. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/theme-marketplace.php` (Marketplace-Feedback weiter gegeneinander prüfen)

### Schritt 375 — 27.03.2026 — Theme-Marketplace an denselben Wrapper-/Feedback-Vertrag angeglichen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **375 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-marketplace.php`
- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
- `CMS/admin/views/themes/marketplace.php`
- `CMS/assets/js/admin-theme-marketplace.js`

**Ergebnis dieses Schritts**

- Der Theme-Marketplace hängt jetzt enger an demselben Wrapper-Muster wie sein Plugin-Gegenstück:
  - `theme-marketplace.php` normalisiert Aktion, Theme-Slug und Fehlerstatus in einem gemeinsamen Payload statt dieselben Einzelprüfungen lose im POST-Pfad zu verteilen.
  - `ThemeMarketplaceModule` liefert vorbereitete Statusmetriken und Statusfilter für die View und zieht beim Installieren eine zusätzliche Zielpfad-Prüfung ein.
- View und Asset sprechen damit denselben Vertrag:
  - `views/themes/marketplace.php` nutzt vorbereitete Statusdaten statt Zähler und Filteroptionen lokal im Template abzuleiten.
  - `assets/js/admin-theme-marketplace.js` sperrt bestätigte Install-Buttons bis zum Submit und verhindert so hektische Mehrfachklicks auch im Theme-Marketplace.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-marketplace.php`: Wrapper-/Payload- und Fehlerrückgabepfade weiter verdichtet
- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Statistik-, Filter- und Installationspfade klarer im Modulvertrag vorbereitet
- `CMS/admin/views/themes/marketplace.php`, `CMS/assets/js/admin-theme-marketplace.js`: Filter-/Status-UI und Install-Feedback klarer zwischen View-Daten und Asset-Vertrag getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/landing/page.php` bzw. `CMS/admin/modules/landing/LandingPageModule.php` (Feature-/Plugin-ViewModels weiter aus dem Template ziehen)
2. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Parent-/Page-Picker und Item-Validierung weiter staffeln)
3. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (Editor-Payload, Größenlimits und Save-Pfade weiter verdichten)
4. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI perspektivisch an dediziertes Asset/konfigurierten Editor hängen)
5. `CMS/admin/views/comments/list.php` bzw. `CMS/admin/modules/comments/CommentsModule.php` (rechteabhängige Zeilenmodelle weiter vorbereiten)
6. `CMS/admin/plugin-marketplace.php` bzw. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Marketplace-Feedback weiter gegen Theme-Marketplace gegenprüfen)

### Schritt 374 — 27.03.2026 — Plugin-Marketplace-Wrapper und Feedbackpfade weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **374 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
- `CMS/admin/views/plugins/marketplace.php`
- `CMS/assets/js/admin-plugin-marketplace.js`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace hängt enger an einem kleinen Wrapper-/Modulvertrag statt an verstreuten Einzelprüfungen:
  - `plugin-marketplace.php` normalisiert Aktion, Slug und Fehlerstatus jetzt in einem Payload und dispatcht danach direkt über einen kleinen `match`-Pfad.
  - `PluginMarketplaceModule` bereitet Statistik- und Filterdaten für Kategorien/Status serverseitig vor und zieht beim Installieren eine zusätzliche Zielpfad-Prüfung ein, damit auch der lokale Installationspfad an einem engeren Vertrag hängt.
- View und Asset wurden auf denselben Vertrag gezogen:
  - `views/plugins/marketplace.php` nutzt vorbereitete Filteroptionen statt Kategorien im Template selbst zusammenzubauen und escaped Laufzeitdaten konsistenter.
  - `assets/js/admin-plugin-marketplace.js` sperrt bestätigte Install-Buttons bis zum Submit, damit Marketplace-Installationen nicht durch hektische Mehrfachklicks mehrfach angestoßen werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Wrapper-/Payload- und Fehlerrückgabepfade weiter verdichtet
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Filter-/Statistikdaten und Installationspfadvertrag im Modul weiter vorbereitet
- `CMS/admin/views/plugins/marketplace.php`, `CMS/assets/js/admin-plugin-marketplace.js`: Template-Filterlogik und Install-Feedback klarer in View-Daten bzw. Asset-Vertrag getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php` bzw. `CMS/admin/modules/themes/ThemeMarketplaceModule.php` (Theme-Marketplace-Wrapper an denselben Feedback-/Payload-Vertrag angleichen)
2. `CMS/admin/views/landing/page.php` bzw. `CMS/admin/modules/landing/LandingPageModule.php` (Feature-/Plugin-ViewModels weiter aus dem Template ziehen)
3. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Parent-/Page-Picker und Item-Validierung weiter staffeln)
4. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (Editor-Payload, Größenlimits und Save-Pfade weiter verdichten)
5. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI perspektivisch an dediziertes Asset/konfigurierten Editor hängen)
6. `CMS/admin/modules/themes/ThemeMarketplaceModule.php` bzw. `CMS/admin/plugin-marketplace.php` (Marketplace-Wrapper und Download-Feedback weiter angleichen)

### Schritt 373 — 27.03.2026 — Landing-Badge im Header-Vertrag konfigurierbar gemacht

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **373 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/landing/LandingPageModule.php`
- `CMS/admin/views/landing/page.php`
- `CMS/core/Services/Landing/LandingHeaderService.php`
- `CMS/core/Services/Landing/LandingDefaultsProvider.php`
- `CMS/install/InstallerService.php`
- `CMS/themes/cms-default/partials/home-landing.php`

**Ergebnis dieses Schritts**

- Der Landing-Header erlaubt jetzt ein echtes redaktionelles Badge statt einer fest verdrahteten Versionsanzeige:
  - `views/landing/page.php` ergänzt im Header-Tab ein eigenes Badge-Feld mit klarer UI-Hilfe, dass ein leeres Feld das Badge vollständig ausblendet.
  - `LandingPageModule` sanitisiert den Badge-Wert zentral im Header-Payload, sodass der Admin-POST denselben kleinen Vertrag wie Titel und Untertitel nutzt.
  - `LandingHeaderService` führt `badge_text` als kompatibles Header-Feld ein und leitet Alt-Daten ohne explizites Badge zunächst aus `version` ab, damit bestehende Installationen nicht plötzlich ihr Hero-Badge verlieren.
- Defaults, Installer und Frontend wurden daran angeglichen:
  - `LandingDefaultsProvider` und `InstallerService` initialisieren neue Landing-Header weiterhin mit der aktuellen CMS-Version als Startwert.
  - `themes/cms-default/partials/home-landing.php` rendert das Badge nur noch bei vorhandenem Text und entfernt den starren `v`-Prefix, sodass auch freie Marker wie „Beta“, „Preview“ oder „Neu“ sauber funktionieren.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/landing/page.php`, `CMS/admin/modules/landing/LandingPageModule.php`: Landing-Header-Formular- und Payload-Vertrag um frei konfigurierbares Badge ergänzt
- `CMS/core/Services/Landing/LandingHeaderService.php`, `CMS/core/Services/Landing/LandingDefaultsProvider.php`, `CMS/install/InstallerService.php`: Header-Defaults und Kompatibilitäts-Fallback für Badge-/Versionsdaten nachgezogen
- `CMS/themes/cms-default/partials/home-landing.php`: Hero-Markup blendet Badge leerlaufsicher aus und löst sich von der harten Versionsdarstellung

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (Wrapper-/Feedback-Pfade nach der Katalog-/Filter-Härtung weiter verdichten)
2. `CMS/admin/views/landing/page.php` bzw. `CMS/admin/modules/landing/LandingPageModule.php` (Feature-/Plugin-ViewModels weiter aus dem Template ziehen)
3. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Parent-/Page-Picker und Item-Validierung weiter staffeln)
4. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (Editor-Payload, Größenlimits und Save-Pfade weiter verdichten)
5. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI perspektivisch an dediziertes Asset/konfigurierten Editor hängen)
6. `CMS/admin/modules/themes/ThemeMarketplaceModule.php` bzw. `CMS/admin/plugin-marketplace.php` (Marketplace-Wrapper und Download-Feedback weiter angleichen)

### Schritt 372 — 27.03.2026 — Comments-, Landing-, Menü-, Tabellen- und Theme-Editor-Verträge weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **372 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/comments.php`
- `CMS/admin/modules/comments/CommentsModule.php`
- `CMS/admin/views/comments/list.php`
- `CMS/admin/landing-page.php`
- `CMS/admin/views/landing/page.php`
- `CMS/admin/menu-editor.php`
- `CMS/admin/modules/menus/MenuEditorModule.php`
- `CMS/admin/site-tables.php`
- `CMS/admin/modules/tables/TablesModule.php`
- `CMS/admin/views/tables/list.php`
- `CMS/admin/theme-editor.php`
- `CMS/admin/theme-explorer.php`
- `CMS/admin/modules/themes/ThemeEditorModule.php`
- `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- Kommentar-, Landing- und Menüpfade hängen enger an vorbereiteten Modellen statt an verstreuten Request-/View-Helfern:
  - `CommentsModule` bekommt den Statusfilter jetzt explizit vom Entry, liefert Status-Tabs und normalisierte Zeilenmodelle vorverdaut an die View und hält Datum-/Excerpt-/Badge-Helfer damit aus dem Template heraus.
  - `landing-page.php` ergänzt vorkonfigurierte Tab-Metadaten, während `views/landing/page.php` auf diese View-Daten statt auf direktes `$_GET` zugreift und Plugin-Override-/Feldwerte zentraler vorbereitet.
  - `MenuEditorModule` bootstrapt Tabellen- und Theme-Sync nur noch gezielt, schützt Theme-Positionen vor Doppelzuweisung, kapselt Fehler generischer und liefert neue Menü-IDs sauber zurück, sodass `menu-editor.php` nach dem Anlegen korrekt in den Editor des neuen Menüs zurückspringt.
- Tabellen- und Theme-Editing-Verträge wurden zusätzlich nachgezogen:
  - `site-tables.php` koppelt den Zugriff jetzt explizit an `manage_settings`; `TablesModule` bekommt den Suchbegriff vom Wrapper, normalisiert Listenzeilen für die View und protokolliert Speichern-/Duplicate-/Delete-Fehler ohne rohe Exception-Texte im UI.
  - `theme-editor.php` und `theme-explorer.php` erzwingen dieselbe Capability-Grenze, der Theme-Customizer wird nur noch über einen verifizierten Pfad unterhalb des aktiven Themes eingebunden, und `ThemeEditorModule` schließt Hidden-Segmente bzw. tiefe/versteckte Dateibaum-Pfade konsequenter aus.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/views/comments/list.php`: Status-/Zeilenmodell weiter in den Modulvertrag gezogen
- `CMS/admin/views/landing/page.php`: Tab- und Formularlogik weiter aus direktem Request-Zugriff gelöst
- `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/admin/menu-editor.php`: Bootstrap-, Redirect- und Fehlersonderpfade weiter verdichtet
- `CMS/admin/modules/tables/TablesModule.php`, `CMS/admin/site-tables.php`, `CMS/admin/views/tables/list.php`: Such-/Listen- und Fehlerpfade klarer am Wrapper-/Modulvertrag ausgerichtet
- `CMS/admin/theme-editor.php`, `CMS/admin/theme-explorer.php`, `CMS/admin/modules/themes/ThemeEditorModule.php`: Theme-Dateigrenzen und Capability-Gates weiter gehärtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (Wrapper-/Feedback-Pfade nach der Katalog-/Filter-Härtung weiter verdichten)
2. `CMS/admin/views/landing/page.php` bzw. `CMS/admin/modules/landing/LandingPageModule.php` (Feature-/Plugin-ViewModels weiter aus dem Template ziehen)
3. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Parent-/Page-Picker und Item-Validierung weiter staffeln)
4. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (Editor-Payload, Größenlimits und Save-Pfade weiter verdichten)
5. `CMS/admin/theme-explorer.php` / `CMS/admin/views/themes/editor.php` (Dateibaum-UI perspektivisch an dediziertes Asset/konfigurierten Editor hängen)
6. `CMS/admin/modules/themes/ThemeMarketplaceModule.php` bzw. `CMS/admin/plugin-marketplace.php` (Marketplace-Wrapper und Download-Feedback weiter angleichen)

### Schritt 371 — 27.03.2026 — Marketplace-Härtung, Landing-Lazy-Defaults und View-Restpfade nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **371 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-marketplace.php`
- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
- `CMS/admin/views/themes/marketplace.php`
- `CMS/assets/js/admin-theme-marketplace.js`
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
- `CMS/admin/views/plugins/marketplace.php`
- `CMS/assets/js/admin-plugin-marketplace.js`
- `CMS/admin/landing-page.php`
- `CMS/admin/modules/landing/LandingPageModule.php`
- `CMS/admin/views/comments/list.php`
- `CMS/admin/views/menus/editor.php`
- `CMS/admin/views/tables/edit.php`

**Ergebnis dieses Schritts**

- Theme-Marketplace-Entry, Modul und View sind enger auf einen kleinen Install-/Filter-Vertrag gezogen:
  - `theme-marketplace.php` normalisiert Aktion und Slug jetzt über einen gemeinsamen Payload statt lose im POST-Handler.
  - `ThemeMarketplaceModule` staffelt Registry-, Manifest- und Asset-Daten stärker über skalare Allowlist-Felder, normalisiert HTTPS-Marketplace-URLs strikter und verwirft kollidierende oder doppelte Theme-Slugs robuster.
  - `views/themes/marketplace.php` plus `assets/js/admin-theme-marketplace.js` liefern zusätzlich KPI-Karten sowie Such-/Statusfilter, damit größere Kataloge im Admin gezielter überprüft werden können.
- Der Plugin-Marketplace bleibt konsistenter zu diesem Vertrag:
  - `PluginMarketplaceModule` markiert inkompatible Kandidaten expliziter, zählt manuelle Pakete separat und blockiert Doppel-Installationen früher.
  - `views/plugins/marketplace.php` und `assets/js/admin-plugin-marketplace.js` ergänzen Statusfilter, Empty-State-Handling und klarere Hinweise für manuelle bzw. inkompatible Kandidaten.
- Der Landing-Page-Admin und die Rest-Views wurden weiter verdichtet:
  - `LandingPageModule` initialisiert Defaults nicht mehr schon im Konstruktor, sondern nur noch lazy bei echten Daten-/Save-Pfaden; zugleich hängt der Modulzugriff sichtbar an `manage_settings`.
  - `views/comments/list.php`, `views/menus/editor.php` und `views/tables/edit.php` halten Status-/Excerpt-/Datumshilfen, Menü-Config-JSON und Tabellen-Editor-JSON klarer vorbereitet und robuster escaped.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Marketplace-Remote-/Manifest-Vertrag weiter gehärtet
- `CMS/admin/views/themes/marketplace.php`, `CMS/admin/views/plugins/marketplace.php`: Filter- und Status-UI weiter harmonisiert
- `CMS/admin/modules/landing/LandingPageModule.php`: unnötige Default-Initialisierung aus dem Konstruktor entfernt
- `CMS/admin/views/comments/list.php`, `CMS/admin/views/menus/editor.php`, `CMS/admin/views/tables/edit.php`: Rest-Template-/JSON-Pfade weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (Wrapper-/Feedback-Pfade nach dem UI-/Statusfilterlauf weiter verdichten)
2. `CMS/admin/views/landing/page.php` (größere View-Logik weiter in vorbereitete ViewModels/Helfer verschieben)
3. `CMS/admin/views/comments/list.php` bzw. `CMS/admin/modules/comments/CommentsModule.php` (rechteabhängige Zeilenmodelle noch stärker vorbereiten)
4. `CMS/admin/views/menus/editor.php` bzw. `CMS/admin/modules/menus/MenuEditorModule.php` (Item-/Parent-Daten weiter aus der View herausziehen)
5. `CMS/admin/views/tables/edit.php` bzw. `CMS/admin/modules/tables/TablesModule.php` (größere Editorzustände perspektivisch weiter staffeln)
6. `CMS/admin/theme-editor.php` / `CMS/admin/modules/themes/ThemeEditorModule.php` (kritische Theme-Dateipfade weiter härten)

### Schritt 370 — 27.03.2026 — Core-Modulverwaltung für Abointegration eingebaut und produktive Fatal-Errors behoben

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **370 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/pages.php`
- `CMS/admin/posts.php`
- `CMS/core/Services/CoreModuleService.php`
- `CMS/admin/modules.php`
- `CMS/admin/modules/system/ModulesModule.php`
- `CMS/admin/views/system/modules.php`
- `CMS/admin/partials/sidebar.php`
- `CMS/admin/packages.php`
- `CMS/admin/orders.php`
- `CMS/admin/subscription-settings.php`
- `CMS/core/SubscriptionManager.php`
- `CMS/core/Services/MemberService.php`
- `CMS/member/includes/class-member-controller.php`
- `CMS/member/subscription.php`
- `CMS/member/dashboard.php`
- `CMS/admin/modules/dashboard/DashboardModule.php`
- `CMS/core/Services/DashboardService.php`

**Ergebnis dieses Schritts**

- Die produktiven Admin-Fatals in `pages.php` und `posts.php` sind beseitigt:
  - beide Entries importieren `CMS\Security` wieder korrekt, sodass die Editor-/Media-Token-Erzeugung nicht mehr auf eine nicht auflösbare globale Klasse läuft.
- Die Aboverwaltung besitzt jetzt erstmals eine echte Core-Modul-Registry:
  - `CoreModuleService` registriert integrierte Module, Abhängigkeiten, Sidebar-Gruppen und Legacy-Settings zentral.
  - Unter `System -> Module` können Abo-Core, Admin-Unterbereiche, Member-Abo-Bereich sowie Limits-/Ordering-/Pricing-Gates aktiviert oder deaktiviert werden.
  - deaktivierte Admin-Module verschwinden aus der Sidebar und sperren direkte Zugriffe auf `packages`, `orders` und `subscription-settings`.
  - Member- und Dashboard-Pfade respektieren die Modulgates bereits bei Abo-Menüpunkt, Abo-Seite sowie Orders-/KPI-Anteilen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/pages.php`, `CMS/admin/posts.php`: produktive Fatal-Pfade repariert
- `CMS/core/Services/CoreModuleService.php`: zentraler Core-Modul-/Abhängigkeitsvertrag ergänzt
- `CMS/admin/partials/sidebar.php`: Admin-Sichtbarkeit an Core-Module gekoppelt
- `CMS/core/SubscriptionManager.php`, `CMS/core/Services/MemberService.php`, `CMS/core/Services/DashboardService.php`: Runtime-Gates sichtbarer vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php` bzw. `CMS/admin/modules/themes/ThemeMarketplaceModule.php` (Theme-Marketplace-Vertrag an die Marketplace-Härtung angleichen)
2. `CMS/admin/plugin-marketplace.php` (Wrapper-/Feedback-Pfade nach dem Katalog-Härtungslauf erneut gegenprüfen)
3. `CMS/admin/landing-page.php` (Restpfade und Settings-Dispatch erneut prüfen)
4. `CMS/admin/views/comments/list.php` (Moderations-/Bulk-Restpfade erneut prüfen)
5. `CMS/admin/views/menus/editor.php` (Editor-Restpfade erneut gegenprüfen)
6. `CMS/admin/views/tables/edit.php` (Editor-/Serialize-Restpfade erneut prüfen)

### Schritt 369 — 26.03.2026 — Plugin-Marketplace-Katalog weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **369 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace hält seinen Katalog- und Installationspfad robuster:
  - installierte Plugin-Ordner werden jetzt eindeutig als normalisierte Slugs erfasst statt bei jedem Katalogeintrag linear gegen rohe Verzeichnisnamen verglichen.
  - kollidierende Manifest-Slugs werden verworfen, statt einen vorhandenen Registry-Slug stillschweigend umzubiegen.
  - doppelte Katalogeinträge pro Slug werden vor der weiteren URL-/Installationsauflösung dedupliziert.
  - Manifestdaten werden nur noch als skalare, allowlist-basierte Metadaten in den Katalog übernommen.
  - dadurch bleibt der Remote-Marketplace konsistenter zu seinem Slug-/Manifest-Vertrag und robuster gegen inkonsistente oder doppelte Katalogdaten.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Remote-Katalog-/Installationsvertrag weiter gehärtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (Wrapper-/Feedback-Pfade erneut gegenprüfen)
2. `CMS/admin/theme-marketplace.php` bzw. `CMS/admin/modules/themes/ThemeMarketplaceModule.php` (Theme-Marketplace an denselben Katalog-Vertrag angleichen)
3. `CMS/admin/landing-page.php` (Restpfade erneut gegenprüfen)
4. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade erneut gegenprüfen)
5. `CMS/admin/views/menus/editor.php` (Editor-Restpfade erneut gegenprüfen)
6. `CMS/admin/views/tables/edit.php` (Editor-Restpfade erneut gegenprüfen)

### Schritt 368 — 26.03.2026 — Error-Report-Vertrag weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **368 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/error-report.php`
- `CMS/core/Services/ErrorReportService.php`

**Ergebnis dieses Schritts**

- Der Error-Report hält Wrapper- und Service-Vertrag kompakter:
  - `error-report.php` übergibt an den Service jetzt nur noch gezielt normalisierte Payload-Felder statt rohe Request-Strukturen bis in den Servicefluss mitzunehmen.
  - Titel, Nachricht, Fehlercode und Source-URL werden bereits im Entry auf Steuerzeichen und Längen begrenzt.
  - `ErrorReportService::createReport()` zieht dieselbe Sanitierung zusätzlich service-seitig nach und normalisiert Status, Source-URL sowie verschachtelte `error_data`-/`context`-Payloads selbst.
  - dadurch bleibt der Fehlerreport robuster, selbst wenn künftige Aufrufer den Service außerhalb des Admin-Wrappers verwenden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/error-report.php`: Request-/Payload-Vertrag weiter vereinheitlicht
- `CMS/core/Services/ErrorReportService.php`: Service-seitige Trust-Boundary nachgezogen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (Remote-Katalog-/Manifest-Restpfade prüfen)
2. `CMS/admin/plugin-marketplace.php` (Wrapper-/Feedback-Pfade erneut gegenprüfen)
3. `CMS/admin/landing-page.php` (Restpfade erneut gegenprüfen)
4. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade erneut gegenprüfen)
5. `CMS/admin/views/menus/editor.php` (Editor-Restpfade erneut gegenprüfen)
6. `CMS/admin/views/tables/edit.php` (Editor-Restpfade erneut gegenprüfen)

### Schritt 367 — 26.03.2026 — Roles-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **367 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/users/roles.php`
- `CMS/assets/js/admin-users.js`

**Ergebnis dieses Schritts**

- Die Rollen-Ansicht reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/users/roles.php` nutzt für Gruppen-Toggles sowie Role-/Capability-Modals jetzt das gemeinsame Users-Admin-Asset statt eines lokalen Inline-Scripts.
  - `admin-users.js` übernimmt Modal-Befüllung, Modal-Öffnung und Checkbox-Gruppen-Toggle zentral.
  - Role- und Capability-Aktionen hängen dadurch sichtbarer an einem gemeinsamen Asset-Vertrag.
  - dadurch bleibt die Benutzerverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Modalzustand sowie Rechte-Interaktionen klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/users/roles.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-users.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade erneut prüfen)
2. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)
3. `CMS/admin/views/tables/edit.php` (Restpfade erneut gegenprüfen)
4. `CMS/admin/landing-page.php` (View-/Entry-Restpfade prüfen)
5. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)
6. `CMS/admin/plugin-marketplace.php` (Remote-/Wrapper-Restpfade erneut prüfen)

### Schritt 366 — 26.03.2026 — Roles-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **366 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/roles.php`

**Ergebnis dieses Schritts**

- Der Roles-Entry hält seinen POST-Pfad kompakter:
  - erlaubte Aktionen werden jetzt einmalig normalisiert statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - `cms_admin_roles_handle_action()` bündelt Rollen- und Capability-Mutationen in einer kleinen gemeinsamen Entry-Logik.
  - das gemeinsame Users-Admin-Asset wird sauber über `pageAssets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Admin-Entrys und trennt Aktionsprüfung, Payload-Normalisierung sowie Asset-Vertrag klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/roles.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/users/roles.php` (verbliebene Inline-Script-/Modal-Reste prüfen)
2. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade erneut prüfen)
3. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)
4. `CMS/admin/views/tables/edit.php` (Restpfade erneut gegenprüfen)
5. `CMS/admin/landing-page.php` (View-/Entry-Restpfade prüfen)
6. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)

### Schritt 365 — 26.03.2026 — Users-List-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **365 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/users/list.php`
- `CMS/assets/js/admin-users.js`

**Ergebnis dieses Schritts**

- Die Benutzer-Liste reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/users/list.php` nutzt für Rollen-/Status-Filter jetzt JS-Hooks statt lokaler `onchange`-Handler.
  - die Grid-Konfiguration wird als JSON an das gemeinsame Users-Admin-Asset übergeben statt als Inline-JavaScript im Entry zusammengebaut.
  - `admin-users.js` übernimmt Grid-Initialisierung und Filter-Redirects zentral.
  - dadurch bleibt die Benutzerverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Filterzustand sowie Grid-Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/users/list.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-users.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/roles.php` (Entry-/Asset-Vertrag prüfen)
2. `CMS/admin/views/users/roles.php` (verbliebene Inline-Script-/Modal-Reste prüfen)
3. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade erneut prüfen)
4. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)
5. `CMS/admin/views/tables/edit.php` (Restpfade erneut gegenprüfen)
6. `CMS/admin/landing-page.php` (View-/Entry-Restpfade prüfen)

### Schritt 364 — 26.03.2026 — Users-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **364 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/users.php`

**Ergebnis dieses Schritts**

- Der Users-Entry hält Listen- und POST-Pfad kompakter:
  - `cms_admin_users_normalize_payload()` bündelt Aktion, ID, Bulk-Aktion und Bulk-IDs jetzt in einem einmalig normalisierten Payload.
  - die Grid-Konfiguration wird über `cms_admin_users_grid_config()` strukturiert aufgebaut statt als größerer Inline-JavaScript-String im Entry gepflegt.
  - das gemeinsame Users-Admin-Asset wird zusammen mit Grid.js sauber über `page_assets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Admin-Entrys und trennt Listen-Konfiguration, Payload-Normalisierung sowie Asset-Vertrag klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/users.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/users/list.php` (verbliebene Grid-/Filter-Reste prüfen)
2. `CMS/admin/roles.php` (Entry-/Asset-Vertrag prüfen)
3. `CMS/admin/views/users/roles.php` (verbliebene Inline-Script-/Modal-Reste prüfen)
4. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade erneut prüfen)
5. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)
6. `CMS/admin/views/tables/edit.php` (Restpfade erneut gegenprüfen)

### Schritt 363 — 26.03.2026 — Tables-Edit-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **363 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/tables/edit.php`
- `CMS/assets/js/admin-site-tables.js`

**Ergebnis dieses Schritts**

- Die Tabellen-Bearbeitung reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/tables/edit.php` nutzt für Spalten-/Zeilen-Editor und Submit-Serialisierung jetzt JSON-Konfiguration statt lokalem Inline-Script.
  - generierte Inline-Handler im dynamischen Tabellen-Editor entfallen zugunsten echter Event-Listener im Asset.
  - `admin-site-tables.js` übernimmt Rendern, Mutationen und Hidden-JSON-Synchronisierung zentral.
  - dadurch bleibt die Tabellenverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Editorzustand sowie Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/tables/edit.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-site-tables.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
2. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)
3. `CMS/admin/views/tables/list.php` (Restpfade erneut gegenprüfen)
4. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)
5. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)
6. `CMS/admin/landing-page.php` (View-/Entry-Restpfade prüfen)

### Schritt 362 — 26.03.2026 — Tables-List-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **362 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/tables/list.php`
- `CMS/assets/js/admin-site-tables.js`

**Ergebnis dieses Schritts**

- Die Tabellen-Liste reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/tables/list.php` nutzt für Suche, Duplicate- und Delete-Aktionen jetzt Datenattribute statt lokaler Inline-Handler.
  - `admin-site-tables.js` übernimmt Such-Redirect und Hidden-Form-Dispatch zentral.
  - Listenzustand und Aktionspfade hängen dadurch sichtbarer an einem gemeinsamen Asset-Vertrag.
  - dadurch bleibt die Tabellenverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Suchzustand sowie Aktionslogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/tables/list.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-site-tables.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/tables/edit.php` (verbliebene Inline-Script-/Editor-Reste prüfen)
2. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
3. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)
4. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)
5. `CMS/admin/landing-page.php` (View-/Entry-Restpfade prüfen)
6. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)

### Schritt 361 — 26.03.2026 — Site-Tables-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **361 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/site-tables.php`

**Ergebnis dieses Schritts**

- Der Site-Tables-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_site_tables_normalize_payload()` bündelt Aktion und Tabellen-ID jetzt in einem einmalig normalisierten Payload.
  - Delete-/Duplicate-Pfade werden vor dem Modul-Dispatch enger auf gültige Kontrollwerte geprüft.
  - das gemeinsame Tabellen-UI-Asset wird für Listen- und Edit-Ansicht sauber über `pageAssets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Admin-Entrys und trennt Guard, Payload-Normalisierung sowie Asset-Vertrag klarer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/site-tables.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/tables/list.php` (verbliebene Inline-Script-/Search-Reste prüfen)
2. `CMS/admin/views/tables/edit.php` (verbliebene Inline-Script-/Editor-Reste prüfen)
3. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
4. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)
5. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)
6. `CMS/admin/views/menus/editor.php` (Restpfade erneut gegenprüfen)

### Schritt 360 — 26.03.2026 — Menu-Editor-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **360 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/menus/editor.php`
- `CMS/assets/js/admin-menu-editor.js`

**Ergebnis dieses Schritts**

- Die Menü-Editor-Ansicht reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/menus/editor.php` nutzt für Modal-Trigger und Menü-Item-Editor jetzt Datenattribute plus JSON-Konfiguration statt lokalem Inline-Script.
  - der Item-Editor hängt nicht länger an eingebettetem JavaScript im View.
  - `admin-menu-editor.js` übernimmt Modal-Befüllung, Item-Rendering, Parent-Optionen, Reordering und Delete-Confirm zentral.
  - dadurch bleibt die Menüverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Modalzustand sowie Item-Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/menus/editor.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-menu-editor.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/site-tables.php` (Entry-/Asset-Vertrag prüfen)
2. `CMS/admin/views/tables/list.php` (Inline-Script-/Action-Reste prüfen)
3. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
4. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
5. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)
6. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)

### Schritt 359 — 26.03.2026 — Menu-Editor-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **359 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/menu-editor.php`

**Ergebnis dieses Schritts**

- Der Menu-Editor-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_menu_editor_normalize_payload()` bündelt Aktion, Menü-ID und Items-JSON jetzt in einem einmalig normalisierten Payload.
  - die bisherige Handler-Map entfällt zugunsten von `cms_admin_menu_editor_handle_action()` mit direktem Dispatch.
  - das dedizierte UI-Asset wird sauber über `page_assets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Shared-Shell-Entrys und trennt Guard, Payload-Normalisierung sowie Asset-Vertrag klarer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/menu-editor.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/menus/editor.php` (verbliebene Inline-Script-/Modal-Reste prüfen)
2. `CMS/admin/site-tables.php` (Entry-/Asset-Vertrag prüfen)
3. `CMS/admin/views/tables/list.php` (Inline-Script-/Action-Reste prüfen)
4. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
5. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
6. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)

### Schritt 358 — 26.03.2026 — Hub-Templates-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **358 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/hub/templates.php`
- `CMS/assets/js/admin-hub-sites.js`

**Ergebnis dieses Schritts**

- Die Hub-Template-Ansicht reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/hub/templates.php` nutzt für Duplicate- und Delete-Aktionen jetzt Datenattribute statt lokaler `onclick`-Handler.
  - `admin-hub-sites.js` übernimmt Duplicate-/Delete-Flow für Templates zentral.
  - Template-Markup und Formularzustand hängen dadurch sichtbarer an einem gemeinsamen Asset-Vertrag.
  - dadurch bleibt die Hub-Verwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Template-Aktionen sowie Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/hub/templates.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-hub-sites.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
2. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
3. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
4. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)
5. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)
6. `CMS/admin/views/hub/list.php` (Restpfade erneut gegenprüfen)

### Schritt 357 — 26.03.2026 — Hub-List-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **357 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/hub/list.php`
- `CMS/assets/js/admin-hub-sites.js`

**Ergebnis dieses Schritts**

- Die Hub-Site-Liste reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/hub/list.php` nutzt für Suche, Copy-, Duplicate- und Delete-Aktionen jetzt Datenattribute statt lokaler Inline-Handler.
  - `admin-hub-sites.js` übernimmt Such-Redirect, Clipboard-Flow sowie Site-Duplicate-/Delete-Aktionen zentral.
  - Listenzustand und Aktionspfade hängen dadurch sichtbarer an einem gemeinsamen Asset-Vertrag.
  - dadurch bleibt die Hub-Verwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Suchzustand sowie Aktionslogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/hub/list.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-hub-sites.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/hub/templates.php` (verbliebene Inline-Script-/Action-Reste prüfen)
2. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
3. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
4. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
5. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)
6. `CMS/admin/views/comments/list.php` (Bulk-/Moderations-Restpfade gegenprüfen)

### Schritt 356 — 26.03.2026 — Hub-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **356 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/hub-sites.php`

**Ergebnis dieses Schritts**

- Der Hub-Sites-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_hub_sites_normalize_payload()` bündelt Aktion, Hub-Site-ID und Template-Key jetzt in einem einmalig normalisierten Payload.
  - Delete-/Duplicate- und Template-Aktionen werden vor dem Modul-Dispatch enger auf gültige Kontrollwerte geprüft.
  - das gemeinsame Hub-UI-Asset wird für Listen- und Template-Ansicht sauber über `pageAssets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Shared-Shell-Entrys und trennt Guard, Payload-Normalisierung sowie Asset-Vertrag klarer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/hub-sites.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/hub/list.php` (verbliebene Inline-Script-/Search-Reste prüfen)
2. `CMS/admin/views/hub/templates.php` (verbliebene Inline-Script-/Action-Reste prüfen)
3. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
4. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
5. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
6. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)

### Schritt 355 — 26.03.2026 — Comments-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **355 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/comments/list.php`
- `CMS/assets/js/admin-comments.js`

**Ergebnis dieses Schritts**

- Die Kommentar-Ansicht reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/comments/list.php` nutzt für Status- und Delete-Aktionen jetzt Datenattribute statt lokaler `onclick`-Dispatcher.
  - Bulk-Bar, Select-All und Dropdown-Overflow hängen nicht länger an einem lokalen Inline-Script.
  - `admin-comments.js` übernimmt Status-Dispatch, Delete-Confirm sowie Bulk-/Dropdown-Laufzeitlogik zentral.
  - dadurch bleibt die Kommentarverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Moderationsflow sowie Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/comments/list.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-comments.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
2. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
3. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
4. `CMS/admin/views/hub/templates.php` (Inline-Aktionspfade prüfen)
5. `CMS/admin/views/hub/list.php` (Inline-Delete-/Duplicate-Pfade prüfen)
6. `CMS/admin/users.php` (weitere Entry-/Grid-Reste prüfen)

### Schritt 354 — 26.03.2026 — Comments-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **354 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/comments.php`

**Ergebnis dieses Schritts**

- Der Comments-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_comments_normalize_payload()` bündelt Aktion, ID, Zielstatus, Bulk-Aktion und Bulk-IDs jetzt in einem einmalig normalisierten Payload.
  - Status-, Delete- und Bulk-Pfade werden vor dem Modul-Dispatch enger auf gültige Kontrollwerte geprüft.
  - das dedizierte UI-Asset wird sauber über `page_assets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Shared-Shell-Entrys und trennt Guard, Payload-Normalisierung sowie Asset-Vertrag klarer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/comments.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/comments/list.php` (verbliebene Inline-Script-/Moderationsreste prüfen)
2. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
3. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
4. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
5. `CMS/admin/views/hub/templates.php` (Inline-Aktionspfade prüfen)
6. `CMS/admin/views/hub/list.php` (Inline-Delete-/Duplicate-Pfade prüfen)

### Schritt 353 — 26.03.2026 — Groups-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **353 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/users/groups.php`
- `CMS/assets/js/admin-user-groups.js`

**Ergebnis dieses Schritts**

- Die Gruppen-Ansicht reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/users/groups.php` nutzt für Edit-/Create-Modal und Delete jetzt Datenattribute statt lokaler `onclick`-Handler.
  - `admin-user-groups.js` übernimmt Modal-Befüllung, Modal-Öffnung und Delete-Confirm zentral.
  - Kartendaten werden im View konsistenter vorbereitet, statt mehrfach implizit aus Objektzugriffen zu stammen.
  - dadurch bleibt die Gruppenverwaltung konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Modalzustand sowie Delete-Flow klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/users/groups.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-user-groups.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/comments.php` (Aktions-/Inline-UI prüfen)
2. `CMS/admin/views/comments/list.php` (verbliebene Inline-Script-/Moderationsreste prüfen)
3. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
4. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
5. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
6. `CMS/admin/views/hub/templates.php` (Inline-Aktionspfade prüfen)

### Schritt 352 — 26.03.2026 — Groups-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **352 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/groups.php`

**Ergebnis dieses Schritts**

- Der Gruppen-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_groups_normalize_payload()` bündelt Aktion und Gruppen-ID jetzt in einem einmalig normalisierten Payload.
  - die bisherige Handler-Map entfällt zugunsten von `cms_admin_groups_handle_action()` mit direktem Dispatch.
  - das dedizierte UI-Asset wird sauber über `page_assets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Shared-Shell-Entrys und trennt Guard, Payload-Normalisierung sowie Asset-Vertrag klarer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/groups.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/users/groups.php` (verbliebene Inline-Script-/Modal-Reste prüfen)
2. `CMS/admin/comments.php` (Aktions-/Inline-UI prüfen)
3. `CMS/admin/views/comments/list.php` (verbliebene Inline-Script-/Moderationsreste prüfen)
4. `CMS/admin/views/menus/editor.php` (größere Inline-Script-/Modal-Reste prüfen)
5. `CMS/admin/views/tables/edit.php` (dynamische Inline-JS-Erzeugung prüfen)
6. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)

### Schritt 351 — 26.03.2026 — Marketplace-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **351 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/plugins/marketplace.php`
- `CMS/assets/js/admin-plugin-marketplace.js`

**Ergebnis dieses Schritts**

- Die Plugin-Marketplace-Ansicht reduziert weiteren Inline-JavaScript- und Confirm-Boilerplate:
  - `views/plugins/marketplace.php` nutzt für Installationen jetzt den gemeinsamen `data-confirm-*`-Vertrag statt lokalem `confirm(...)`.
  - die Such-/Filter-Logik hängt nicht länger an einem lokalen Inline-Script, sondern an JSON-Konfiguration plus dediziertem Asset.
  - `admin-plugin-marketplace.js` übernimmt Suche und Kategorie-Filter der Plugin-Karten zentral.
  - dadurch bleibt der Marketplace konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Confirm-Flow sowie Filter-Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/plugins/marketplace.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-plugin-marketplace.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
2. `CMS/admin/pages.php` (weitere Restpfade im Entry-/Service-Vertrag prüfen)
3. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
4. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
5. `CMS/admin/comments.php` (Aktionen/Inline-UI prüfen)
6. `CMS/admin/users.php` (Grid-/Inline-Reste prüfen)

### Schritt 350 — 26.03.2026 — Marketplace-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **350 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_plugin_marketplace_normalize_payload()` bündelt Aktion und Slug jetzt in einem gemeinsamen Payload.
  - `cms_admin_plugin_marketplace_handle_action()` arbeitet direkt mit dem vorbereiteten Payload statt Slug-Daten erneut im Dispatch zu normalisieren.
  - das neue UI-Asset wird sauber über `page_assets` eingebunden.
  - dadurch bleibt der Entry lesbarer, konsistenter zu anderen modernisierten Shared-Shell-Entrys und trennt Guard, Payload-Normalisierung sowie Asset-Vertrag klarer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/plugins/marketplace.php` (verbliebene Inline-Script-/Confirm-Reste prüfen)
2. `CMS/admin/pages.php` (weitere Restpfade im Entry-/Service-Vertrag prüfen)
3. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
4. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
5. `CMS/admin/comments.php` (Aktionen/Inline-UI prüfen)
6. `CMS/admin/users.php` (Grid-/Inline-Reste prüfen)

### Schritt 349 — 26.03.2026 — Pages-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **349 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/pages/list.php`
- `CMS/assets/js/admin-pages.js`

**Ergebnis dieses Schritts**

- Die Seitenliste reduziert weiteren Inline-JavaScript-Boilerplate:
  - `views/pages/list.php` nutzt für Grid- und Bulk-Laufzeitlogik jetzt JSON-Konfiguration statt lokalem Inline-Script.
  - Status- und Kategorie-Filter hängen nicht länger an lokalem `onchange`, sondern an JS-Hooks im dedizierten Asset.
  - `admin-pages.js` übernimmt Grid-Initialisierung, Bulk-Bar, Bulk-IDs und Filter-Auto-Submit zentral.
  - dadurch bleibt die Pages-Ansicht konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Filter-/Bulk-Flow sowie Grid-Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/pages/list.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-pages.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
2. `CMS/admin/views/plugins/marketplace.php` (verbliebene Inline-Script-/Confirm-Reste prüfen)
3. `CMS/admin/pages.php` (weitere Restpfade im Entry-/Service-Vertrag prüfen)
4. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
5. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
6. `CMS/admin/comments.php` (Aktionen/Inline-UI prüfen)

### Schritt 348 — 26.03.2026 — Pages-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **348 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/pages.php`

**Ergebnis dieses Schritts**

- Der Pages-Entry hält seinen Listenpfad kompakter:
  - `cms_admin_pages_grid_config()` liefert die Grid-Konfiguration jetzt strukturiert statt als großen Inline-JavaScript-String.
  - der Listenpfad bindet zusätzlich `admin-pages.js` über `page_assets` ein.
  - die View bekommt damit nur noch Daten- und Konfigurationswerte statt vorgerendertes Laufzeit-JavaScript aus dem Entry.
  - dadurch bleibt der Entry lesbarer, der Asset-Vertrag konsistenter und Listen-/Edit-Pfad klarer getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/pages.php`: Entry-/Asset-Vertrag weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/pages/list.php` (verbliebene Inline-Script-/Filter-Reste prüfen)
2. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
3. `CMS/admin/views/plugins/marketplace.php` (verbliebene Inline-Script-/Confirm-Reste prüfen)
4. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
5. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)
6. `CMS/admin/comments.php` (Aktionen/Inline-UI prüfen)

### Schritt 347 — 26.03.2026 — Dokumentations-View weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **347 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-Ansicht reduziert weiteren Render-Boilerplate:
  - Dokument-Metadaten werden jetzt kompakter über `cms_admin_documentation_view_document_view_model()` vorbereitet.
  - Bereichsdaten wie Slug, Titel, Beschreibung, GitHub-URL, Dokumente und Active-State laufen gebündelt über `cms_admin_documentation_view_section_view_model()`.
  - der Haupt-Renderfluss greift dadurch weniger verstreut auf viele kleine Einzelhelper zu.
  - dadurch bleibt der View lesbarer, stärker auf den eigentlichen Renderpfad fokussiert und konsistenter zu anderen bereits verdichteten Admin-Views.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: View-/Rendervertrag weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php` (weitere Settings-/Fehlerpfade gegenprüfen)
2. `CMS/admin/landing-page.php` (Restpfade im View-/Entry-Vertrag prüfen)
3. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)
4. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
5. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
6. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)

### Schritt 346 — 26.03.2026 — Mail-Settings-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **346 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Settings-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_mail_settings_normalize_payload()` bündelt Tab, Aktion und POST-Daten in einem einmalig normalisierten Payload.
  - die bisherige Handler-Map entfällt zugunsten von `cms_admin_mail_settings_handle_action()` mit direktem `match`-Dispatch.
  - Guard, Tab-Validierung, Aktionsprüfung und Redirect-Ziel bleiben dadurch näher an einer kleinen gemeinsamen Entry-Logik.
  - dadurch bleibt der Entry lesbarer und konsistenter zu anderen bereits modernisierten Shared-Shell-Entrys.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
2. `CMS/admin/landing-page.php` (Restpfade im View-/Entry-Vertrag prüfen)
3. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)
4. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
5. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
6. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)

### Schritt 345 — 26.03.2026 — Landing-Page-Entry weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **345 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/landing-page.php`

**Ergebnis dieses Schritts**

- Der Landing-Page-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_landing_page_normalize_payload()` bündelt Aktion, Feature-ID und POST-Daten in einem einmalig normalisierten Payload.
  - die bisherige Closure-basierte Handler-Map entfällt zugunsten von `cms_admin_landing_page_handle_action()` mit direktem `match`-Dispatch.
  - Delete-Feature validiert seine ID damit nicht länger außerhalb eines gemeinsamen Kontroll-Payloads.
  - dadurch bleibt der Entry lesbarer, näher an anderen modernisierten Admin-Entrys und fokussiert stärker auf Guard, Payload-Normalisierung und Modul-Dispatch.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/landing-page.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)
2. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
3. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)
4. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
5. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)
6. `CMS/admin/error-report.php` (Trust-Boundaries/Fehlerpfade prüfen)

### Schritt 344 — 26.03.2026 — Cookie-Manager-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **344 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/legal/cookies.php`
- `CMS/assets/js/admin-cookie-manager.js`
- `CMS/admin/cookie-manager.php`

**Ergebnis dieses Schritts**

- Die Cookie-Manager-Ansicht reduziert weiteren Inline-JavaScript- und Confirm-Boilerplate:
  - `views/legal/cookies.php` nutzt für Kategorie- und Service-Löschungen jetzt den gemeinsamen `data-confirm-*`-Vertrag statt lokaler `confirm(...)`-Handler.
  - Bearbeiten-/Hinzufügen-Aktionen für Kategorien und Services hängen nicht länger an lokalen `onclick`-Funktionen, sondern an Datenattributen.
  - `admin-cookie-manager.js` übernimmt Reset, Formularbefüllung und Modal-Öffnung für beide Modale zentral.
  - dadurch bleibt der Legal-Bereich konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Confirm-Flow sowie Modal-Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/legal/cookies.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-cookie-manager.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut
- `CMS/admin/cookie-manager.php`: Asset-/Wrapper-Vertrag weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
2. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)
3. `CMS/admin/landing-page.php` (View-/Entry-Reste prüfen)
4. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)
5. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Restpfade gegenprüfen)
6. `CMS/admin/views/media/library.php` (Rest-UI-/Filterpfade prüfen)

### Schritt 343 — 26.03.2026 — Cookie-Manager-Entry weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **343 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/cookie-manager.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager-Entry hält seinen POST-Pfad kompakter:
  - `cms_admin_cookie_manager_normalize_payload()` bündelt Action, ID, Service-Slug und Self-Hosted-Flag in einem einmalig normalisierten Payload.
  - der `post_handler` validiert Delete- und Curated-Import-Pfade damit nicht länger über mehrere verstreute kleine Hilfsaufrufe.
  - `cms_admin_cookie_manager_handle_action()` dispatcht auf Basis des vorbereiteten Payloads direkter in das Modul.
  - dadurch bleibt der Entry lesbarer, näher an anderen modernisierten Admin-Entrys und fokussiert stärker auf Guard, Payload-Normalisierung und Modul-Dispatch.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/cookie-manager.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/legal/cookies.php` (verbliebene Inline-Script-/Confirm-Reste prüfen)
2. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
3. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)
4. `CMS/admin/landing-page.php` (View-/Entry-Reste prüfen)
5. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)
6. `CMS/admin/modules/plugins/PluginMarketplaceModule.php` (weitere Restpfade gegenprüfen)

### Schritt 342 — 26.03.2026 — Plugins-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **342 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/plugins/list.php`
- `CMS/assets/js/admin-plugins.js`
- `CMS/admin/plugins.php`

**Ergebnis dieses Schritts**

- Die Plugin-Liste reduziert weiteren Inline-UI-Boilerplate:
  - `views/plugins/list.php` nutzt für Löschaktionen jetzt den gemeinsamen `data-confirm-*`-Vertrag statt lokaler `confirm(...)`-Buttons.
  - die Aktivieren-/Deaktivieren-Switches hängen nicht länger an lokalem `onchange`, sondern an einer kleinen dedizierten JS-Datei.
  - `admin-plugins.js` übernimmt das Auto-Submit der Toggle-Formulare zentral.
  - dadurch bleibt die Plugin-Ansicht konsistenter zu anderen modernisierten Admin-Views und trennt Markup, Confirm-Flow sowie Toggle-Laufzeitlogik klarer voneinander.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/plugins/list.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-plugins.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut
- `CMS/admin/plugins.php`: Asset-/Wrapper-Vertrag weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/cookie-manager.php` (mittleren Settings-/Entry-Pfad erneut prüfen)
2. `CMS/admin/views/legal/cookies.php` (verbliebene Inline-Script-/Confirm-Reste prüfen)
3. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
4. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)
5. `CMS/admin/landing-page.php` (View-/Entry-Reste prüfen)
6. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)

### Schritt 341 — 26.03.2026 — Plugins-Vertrag weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **341 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugins.php`
- `CMS/admin/modules/plugins/PluginsModule.php`

**Ergebnis dieses Schritts**

- Die Plugin-Verwaltung hält ihren Entry-/Modulpfad kompakter:
  - `plugins.php` normalisiert Action und Slug jetzt einmalig über `cms_admin_plugins_normalize_payload()`.
  - der Entry dispatcht dadurch nicht länger mehrere kleine Einzel-Normalisierungsschritte pro Aktion.
  - `PluginsModule.php` bündelt Slug-, Hauptdatei- und Plugin-Pfadlogik in gemeinsamen Hilfsmethoden.
  - dadurch bleibt der Plugins-Pfad konsistenter, Delete-/Pfadauflösung klarer lesbar und der Modulvertrag näher an einer zentralen Hilfslogik statt an verstreuten String-Resten.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugins.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht
- `CMS/admin/modules/plugins/PluginsModule.php`: Pfad-/Normalisierungsvertrag weiter verdichtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/plugins/list.php` (verbliebene Inline-UI-Reste prüfen)
2. `CMS/admin/cookie-manager.php` (mittleren Settings-/Entry-Pfad erneut prüfen)
3. `CMS/admin/views/legal/cookies.php` (verbliebene Inline-Script-/Confirm-Reste prüfen)
4. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
5. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)
6. `CMS/admin/landing-page.php` (View-/Entry-Reste prüfen)

### Schritt 340 — 26.03.2026 — Font-Manager-Vertrag weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **340 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`
- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Der Font-Manager reduziert weiteren Entry- und View-Boilerplate:
  - `font-manager.php` normalisiert Action, Font-ID und Google-Font-Familie jetzt einmalig über `cms_admin_font_manager_normalize_payload()`.
  - der Dispatch läuft damit kompakter über den bereits normalisierten Payload statt mehrere kleine Einzelwerte separat zu pflegen.
  - `views/themes/fonts.php` verzichtet in der Preview auf statische `font-family`-Inline-Styles und bleibt beim bestehenden JS-Preview-Vertrag.
  - dadurch bleibt der Font-Manager klarer zwischen Guard, Normalisierung, Dispatch und JS-gesteuerter Vorschau getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht
- `CMS/admin/views/themes/fonts.php`: View-Markup-/UX-Pfad weiter verschlankt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginsModule.php` (Fehler-/Remote-Reste gegenprüfen)
2. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
3. `CMS/admin/cookie-manager.php` (mittleren Settings-/Entry-Pfad erneut prüfen)
4. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)
5. `CMS/admin/landing-page.php` (View-/Entry-Reste prüfen)
6. `CMS/admin/pages.php` (View-/Service-Restpfade prüfen)

### Schritt 339 — 26.03.2026 — Media-Settings-Vertrag weiter verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **339 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/media/MediaModule.php`
- `CMS/admin/views/media/settings.php`

**Ergebnis dieses Schritts**

- Die Media-Einstellungen begrenzen lokalen View-Boilerplate weiter:
  - `MediaModule.php` liefert Defaultwerte, Dateityp-Optionen und Thumbnail-Metadaten jetzt zentral für den Settings-View.
  - Größenwerte wie `max_upload_size` und `member_max_upload_size` werden für das Formular bereits als MB-Zahlen vorbereitet.
  - `views/media/settings.php` rendert Typ- und Thumbnail-Blöcke aus den vom Modul gelieferten Optionen statt aus lokalen Hardcode-Listen.
  - dadurch bleibt der Media-Settings-Pfad näher an einem klaren Modulvertrag und hält Mapping-, Default- sowie Optionslogik aus dem Template heraus.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/media/MediaModule.php`: Modul-/View-Vertrag weiter ausgebaut
- `CMS/admin/views/media/settings.php`: View-Boilerplate-/UX-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
2. `CMS/admin/views/themes/fonts.php` (weitere Restpfade prüfen)
3. `CMS/admin/modules/plugins/PluginsModule.php` (Fehler-/Remote-Reste gegenprüfen)
4. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
5. `CMS/admin/cookie-manager.php` (mittleren Settings-/Entry-Pfad erneut prüfen)
6. `CMS/admin/mail-settings.php` (Settings-Vertrag/Fehlerpfade prüfen)

### Schritt 338 — 26.03.2026 — Dokumentations-Entry weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **338 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Entry hält seinen POST-Pfad kompakter:
  - POST-Daten werden einmalig über `cms_admin_documentation_normalize_post_payload()` normalisiert.
  - der Aktionspfad läuft über `cms_admin_documentation_handle_action()` statt über eine kleine Handler-Map für nur einen Sync-Case.
  - der `post_handler` nutzt damit den vom Shared-Shell-Vertrag gelieferten Payload statt eigene direkte `$_POST`-Zugriffe fortzusetzen.
  - dadurch bleibt der Entry lesbarer, näher an anderen modernisierten Admin-Entrys und fokussiert auf Guard, Normalisierung und direkten Modul-Dispatch.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Entry-/Dispatch-Pfad weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/settings.php` (lokale Restpfade/vereinheitlichbare Form-Details prüfen)
2. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
3. `CMS/admin/views/themes/fonts.php` (weitere Restpfade prüfen)
4. `CMS/admin/modules/plugins/PluginsModule.php` (Fehler-/Remote-Reste gegenprüfen)
5. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)
6. `CMS/admin/cookie-manager.php` (mittleren Settings-/Entry-Pfad erneut prüfen)

### Schritt 337 — 26.03.2026 — Data-Requests-View weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **337 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/data-requests.php`
- `CMS/admin/views/legal/data-requests.php`
- `CMS/assets/js/admin-data-requests.js`

**Ergebnis dieses Schritts**

- Die DSGVO-Data-Requests-Ansicht begrenzt Inline-JavaScript- und Confirm-Sonderpfade weiter:
  - `data-requests.php` bindet ein dediziertes Admin-Asset jetzt sauber über `page_assets` ein.
  - `views/legal/data-requests.php` nutzt für kritische Lösch- und Ausführungsaktionen den gemeinsamen `data-confirm-*`-Vertrag statt lokaler `confirm(...)`-Handler.
  - `admin-data-requests.js` übernimmt das Öffnen des Reject-Modals über Datenattribute statt über eine lokale globale View-Funktion.
  - dadurch bleibt der Legal-/DSGVO-Bereich konsistenter zu anderen modernisierten Admin-Views und hält Markup, Confirm-Flow sowie Modal-Logik klarer getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/data-requests.php`: Asset-/Wrapper-Vertrag weiter vereinheitlicht
- `CMS/admin/views/legal/data-requests.php`: Inline-JavaScript-/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-data-requests.js`: dedizierter Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/settings.php` (lokale Restpfade/vereinheitlichbare Form-Details prüfen)
2. `CMS/admin/documentation.php` (Entry-/Sync-Restpfade erneut prüfen)
3. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
4. `CMS/admin/views/themes/fonts.php` (weitere Restpfade prüfen)
5. `CMS/admin/modules/plugins/PluginsModule.php` (Fehler-/Remote-Reste gegenprüfen)
6. `CMS/admin/views/system/documentation.php` (kleine Restpfade/Helper-Dichte erneut prüfen)

### Schritt 335 — 26.03.2026 — Legal-Sites-Cluster weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **335 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`
- `CMS/admin/views/legal/sites.php`
- `CMS/assets/js/admin-legal-sites.js`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Cluster begrenzt Request- und Inline-JavaScript-Sonderpfade weiter:
  - `legal-sites.php` normalisiert `save`- und `save_profile`-Payloads jetzt allowlist-basiert pro Aktion.
  - `sites.php` liefert für den DOM-/Template-Pfad nur noch JSON-Konfiguration statt eines größeren lokalen Inline-Scripts.
  - `admin-legal-sites.js` übernimmt Requirement-Toggles, Privacy-Feature-Details und Template-Einfügen zentral.
  - dadurch bleibt der Legal-Bereich kompakter, der Wrapper-/Asset-Vertrag konsistenter und die Aktionslogik klarer vom PHP-Markup getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Request-/Wrapper-Pfad weiter gehärtet
- `CMS/admin/views/legal/sites.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-legal-sites.js`: Admin-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
2. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
3. `CMS/admin/views/legal/data-requests.php` (weitere Inline-Confirm-Reste prüfen)
4. `CMS/admin/views/media/settings.php` (lokale Restpfade/vereinheitlichbare Form-Details prüfen)
5. `CMS/admin/documentation.php` (Entry-/Sync-Restpfade erneut prüfen)
6. `CMS/admin/views/themes/fonts.php` (weitere Restpfade prüfen)

### Schritt 336 — 26.03.2026 — Marketplace-Remote-Pfad weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **336 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace begrenzt Request- und Remote-Sonderpfade weiter:
  - `plugin-marketplace.php` normalisiert den Installations-POST-Pfad auf ein minimales Slug-Payload, bevor die Aktion dispatcht.
  - `PluginMarketplaceModule.php` nutzt eine gemeinsame Slug-Normalisierung für Installations- und Katalogdaten.
  - Registry-, Manifest- und Katalog-URLs werden vor Remote-Zugriffen zentral kanonisiert und auf erlaubte Hosts, HTTPS, Standard-Port sowie fehlende Credentials geprüft.
  - dadurch bleibt der Marketplace-Remote-Vertrag klarer, SSRF-/Host-Sonderfälle werden enger begrenzt und relative Catalog-Pfade laufen konsistenter über denselben URL-Validator.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Wrapper-/Request-Pfad weiter gehärtet
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Remote-URL- und Katalogpfad weiter gehärtet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/legal/data-requests.php` (weitere Inline-Confirm-Reste prüfen)
2. `CMS/admin/views/media/settings.php` (lokale Restpfade/vereinheitlichbare Form-Details prüfen)
3. `CMS/admin/documentation.php` (Entry-/Sync-Restpfade erneut prüfen)
4. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
5. `CMS/admin/views/themes/fonts.php` (weitere Restpfade prüfen)
6. `CMS/admin/modules/plugins/PluginsModule.php` (Fehler-/Remote-Reste gegenprüfen)

### Schritt 334 — 26.03.2026 — Dokumentations-View weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **334 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-Ansicht pflegt ihre verbliebenen Render-Werkzeuge nicht länger als lokale Inline-Closures:
  - Metric-Cards, Card-Header, Dokument-Listen, Bereichs-Akkordeons und der Dokument-Inhaltsblock laufen jetzt über benannte, lokal präfixierte View-Funktionen.
  - kleine Aufbereitungsreste wie Quellen-Text und Metric-Card-Konfiguration wurden ebenfalls aus dem Hauptfluss in Helper verlagert.
  - dadurch bleibt der View kompakter, lesbarer und näher an einem klaren Rendervertrag statt mehrere kleine Render-Closures im Dateikopf zu bündeln.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – System/Documentation`: View-Struktur weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php`
2. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
3. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
4. `CMS/admin/views/legal/data-requests.php` (weitere Inline-Confirm-Reste prüfen)
5. `CMS/admin/views/media/settings.php` (lokale Restpfade/vereinheitlichbare Form-Details prüfen)
6. `CMS/admin/documentation.php` (Entry-/Sync-Restpfade erneut prüfen)

### Schritt 333 — 26.03.2026 — Media-Cluster weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **333 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`
- `CMS/admin/views/media/categories.php`
- `CMS/assets/js/admin-media-integrations.js`

**Ergebnis dieses Schritts**

- Der Media-Cluster begrenzt Request- und Inline-JavaScript-Sonderpfade weiter:
  - `media.php` normalisiert Aktions-Payloads jetzt pro Aktion auf erlaubte Felder und validiert kritische Pfade/Namen bereits im Wrapper.
  - `categories.php` nutzt für Kategorie-Löschungen nur noch Datenattribute und JSON-Konfiguration statt `onclick` plus lokalem `<script>`.
  - `admin-media-integrations.js` übernimmt den Kategorie-Delete-Confirm und Submit jetzt zentral.
  - dadurch bleibt der Media-Bereich kompakter, der Wrapper-/Asset-Vertrag konsistenter und die Aktionslogik klarer vom PHP-Markup getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Request-/Wrapper-Pfad weiter gehärtet
- `CMS/admin/views/media/categories.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-media-integrations.js`: Media-Asset-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
4. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
5. `CMS/admin/views/legal/data-requests.php` (weitere Inline-Confirm-Reste prüfen)
6. `CMS/admin/views/media/settings.php` (lokale Restpfade/vereinheitlichbare Form-Details prüfen)

### Schritt 332 — 26.03.2026 — Updates-View weiter vereinheitlicht

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **332 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/updates.php`
- `CMS/assets/js/admin.js`

**Ergebnis dieses Schritts**

- Der Updates-View pflegt Core- und Plugin-Installationen nicht länger über lokale `confirm(...)`-Sonderpfade:
  - `updates.php` nutzt für Update-Formulare jetzt `data-confirm-*`-Attribute statt Inline-`confirm(...)`.
  - `admin.js` übernimmt Formular-Bestätigungen jetzt wiederverwendbar über `form[data-confirm-message]` inklusive Modal- und Fallback-Submit.
  - dadurch bleibt der View kompakter, der Admin-Confirm-Vertrag konsistenter und Aktions-Markup klarer von der Laufzeitlogik getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/updates.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin.js`: gemeinsamer Admin-Confirm-Vertrag weiter ausgebaut
- `Admin – System/Updates`: View-/Asset-Struktur konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/views/system/documentation.php`
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
5. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)
6. `CMS/admin/views/legal/data-requests.php` (weitere Inline-Confirm-Reste prüfen)

### Schritt 331 — 26.03.2026 — Media-Library-View weiter entschlackt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **331 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/library.php`
- `CMS/assets/js/admin-media-integrations.js`

**Ergebnis dieses Schritts**

- Die Media-Library-View pflegt ihre verbleibenden Member-Ordner-Confirm- und Delete-Aktionen nicht länger als lokale Inline-Aktionspfade:
  - `library.php` nutzt für Ordnerbestätigung und Delete-Buttons jetzt Datenattribute statt `onclick`-Handler.
  - die lokale `<script>`-Insel am View-Ende entfällt zugunsten von JSON-Konfiguration für den bereits geladenen Media-Asset-Pfad.
  - `admin-media-integrations.js` übernimmt Member-Folder-Confirm und Delete-Submit jetzt zentral.
  - dadurch bleibt die View kompakter, der Media-Asset-Vertrag konsistenter und die Aktionslogik klarer vom PHP-Markup getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/library.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `CMS/assets/js/admin-media-integrations.js`: Media-Asset-Vertrag weiter ausgebaut
- `Admin – Media`: View-/Asset-Struktur konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)
2. `CMS/admin/media.php`
3. `CMS/admin/views/system/documentation.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)
6. `CMS/admin/plugin-marketplace.php` (kritischen Pfad erneut prüfen)

### Schritt 330 — 26.03.2026 — Font-Manager-View weiter entschlackt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **330 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/themes/fonts.php`
- `CMS/admin/font-manager.php`
- `CMS/assets/js/admin-font-manager.js`

**Ergebnis dieses Schritts**

- Der Font-Manager-View pflegt Preview- und Lösch-Confirm-Logik nicht länger als lokales Inline-Script:
  - die Laufzeitlogik wurde in `CMS/assets/js/admin-font-manager.js` ausgelagert.
  - `themes/fonts.php` liefert dafür nur noch JSON-Konfiguration und Markup statt ein eigenes DOMContentLoaded-Script am Dateiende.
  - `font-manager.php` bindet das neue Asset konsistent über `pageAssets` ein.
  - dadurch bleibt der View kompakter, der Admin-JS-Vertrag konsistenter und der Preview-/Confirm-Pfad klarer von der PHP-Ausgabe getrennt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/themes/fonts.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `CMS/admin/font-manager.php`, `CMS/assets/js/admin-font-manager.js`: Admin-Asset-Vertrag weiter ausgebaut
- `Admin – Themes/Fonts`: View-/Asset-Struktur konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)
2. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)
3. `CMS/admin/media.php`
4. `CMS/admin/views/system/documentation.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/font-manager.php` (Restpfade nachziehen, falls nötig)

### Schritt 329 — 26.03.2026 — Font-Manager-Entry weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **329 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Der Font-Manager-Entry pflegt seinen Action-Dispatch nicht länger als Closure-basiertes Handler-Mapping:
  - der POST-Dispatch läuft jetzt direkt über einen `match`-basierten Helper statt über ein separates Handler-Array mit kleinen Action-Closures.
  - Font-ID und Google-Font-Familie werden im POST-Pfad nur noch einmal normalisiert und danach direkt weitergereicht.
  - dadurch bleibt der Entry kompakter, lesbarer und näher an einem klaren Guard-/Normalisierungs-/Dispatch-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – Themes/Fonts`: Entry-Struktur weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (Preview-/Inline-Script-Pfade weiter prüfen)
2. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)
3. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)
4. `CMS/admin/media.php`
5. `CMS/admin/views/system/documentation.php`
6. `CMS/admin/legal-sites.php`

### Schritt 328 — 26.03.2026 — Legal-Sites-Entry weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **328 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry pflegt seinen Action-Dispatch nicht länger als Closure-basiertes Handler-Mapping:
  - der POST-Dispatch läuft jetzt direkt über einen `match`-basierten Helper statt über ein separates Handler-Array mit kleinen Action-Closures.
  - der Vorlagentyp für `generate` und `create_page` wird im POST-Pfad nur noch einmal normalisiert und danach direkt weitergereicht.
  - dadurch bleibt der Entry kompakter, lesbarer und näher an einem klaren Guard-/Normalisierungs-/Dispatch-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – Legal`: Entry-Struktur weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (weitere komplexe Hinweiscluster prüfen)
2. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)
3. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)
4. `CMS/admin/font-manager.php`
5. `CMS/admin/media.php`
6. `CMS/admin/views/system/documentation.php`

### Schritt 327 — 26.03.2026 — Dokumentations-View weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **327 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View pflegt mehrere kleine Daten- und Status-Helfer nicht länger als anonyme Inline-Closures:
  - Dokument-Metadaten, Active-State-Checks und Bereichs-Metadaten laufen jetzt über benannte, lokal präfixierte View-Funktionen.
  - die Render-Blöcke für Listen- und Accordion-Markup bleiben erhalten, hängen aber an klarer benannten Helfern statt an einer größeren anonymen Helper-Sammlung im Dateikopf.
  - dadurch sinkt die Helper-/Closure-Dichte sichtbar, ohne die Ausgabe oder den bestehenden Flash-/Rendervertrag zu verändern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – System/Documentation`: View-Struktur weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php`
2. `CMS/admin/views/themes/fonts.php` (weitere komplexe Hinweiscluster prüfen)
3. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)
4. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)
5. `CMS/admin/font-manager.php`
6. `CMS/admin/media.php`

### Schritt 326 — 26.03.2026 — Media-Entry-/Modulvertrag weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **326 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`
- `CMS/admin/modules/media/MediaModule.php`

**Ergebnis dieses Schritts**

- Der Media-Entry pflegt zwei kleine Sonderpfade nicht länger lose neben dem Modulvertrag:
  - die Parent-Path-Auflösung bei Aktionspfaden läuft jetzt zentral über `MediaModule::resolveParentPathFromActionPath()` statt lokal über String-Ersatz plus `dirname()`.
  - Upload-Fehlermeldungen bauen Dateinamen wieder als rohe Textdaten statt vorzeitig HTML-escaped zusammen.
  - dadurch bleibt die Pfadnormalisierung näher am Modul, und der gemeinsame Flash-/View-Vertrag vermeidet doppelte Escaping-Pfade bei Dateinamen in Fehlermeldungen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – Media`: Entry-/Modulvertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Helper-/Closure-Dichte prüfen)
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/views/themes/fonts.php` (weitere komplexe Hinweiscluster prüfen)
4. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)
5. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)
6. `CMS/admin/font-manager.php`

### Schritt 325 — 26.03.2026 — System-Update-Warnboxen weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **325 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/updates.php`

**Ergebnis dieses Schritts**

- Die Updates-View pflegt auch ihre komplexeren Core- und Theme-Warnboxen nicht länger als lokale Sonderpfade:
  - der Core-Update-Hinweis rendert jetzt über `CMS/admin/views/partials/flash-alert.php`; der Installationsbutton bleibt als separater Folgeblock erhalten.
  - der Theme-Update-Hinweis nutzt dasselbe Partial jetzt ebenfalls für Versions-, Kompatibilitäts- und Manual-Reason-Informationen.
  - dadurch bleibt die Update-Ansicht konsistenter zum bereits harmonisierten Flash-Vertrag, während Aktions- und Hinweisdarstellung sauberer getrennt werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/updates.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – System`: Shared-View-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/views/system/documentation.php` (weitere Helper-/Closure-Dichte prüfen)
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/views/themes/fonts.php` (weitere komplexe Hinweiscluster prüfen)
5. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)
6. `CMS/admin/views/system/updates.php` (Confirm-/Aktionspfade weiter prüfen)

### Schritt 324 — 26.03.2026 — Media-Library-Alert weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **324 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/media/library.php`

**Ergebnis dieses Schritts**

- Die Media-Library-View pflegt im elFinder-Zweig keinen eigenen einfachen Hinweisblock mehr:
  - der verbliebene Infohinweis zum Admin-Kontext des Datei-Managers rendert jetzt über `CMS/admin/views/partials/flash-alert.php`.
  - die View nutzt dafür denselben zentralen Alert-Vertrag wie die zuletzt harmonisierten Legal-, System-, Theme- und Member-Ansichten.
  - dadurch bleibt auch der Finder-Zweig konsistenter zum bereits standardisierten Media-Entry statt lokale Alert-Sonderpfade parallel zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/media/library.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – Media`: Shared-View-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/views/system/updates.php` (komplexe Update-Warnboxen prüfen)
3. `CMS/admin/views/system/documentation.php` (weitere Helper-/Closure-Dichte prüfen)
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/views/themes/fonts.php` (weitere komplexe Hinweiscluster prüfen)
6. `CMS/admin/views/media/library.php` (Inline-Aktionspfade weiter prüfen)

### Schritt 323 — 26.03.2026 — Legal-Sites-Alerts weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **323 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/legal/sites.php`

**Ergebnis dieses Schritts**

- Die Legal-Sites-View pflegt mehrere lokale Hinweisboxen nicht länger als eigene Sonderpfade:
  - die zentrale Intro-Hinweisbox rendert jetzt über `CMS/admin/views/partials/flash-alert.php`.
  - die einfachen featurebezogenen Datenschutz-Hinweise für Kontaktformular, Registrierung, Kommentare und Shop-/Zahlungsabwicklung nutzen jetzt ebenfalls dasselbe Partial.
  - dadurch hängen Intro-, Detail- und Sekundärhinweise sichtbarer am gemeinsamen Admin-Alert-Vertrag statt als verteilte lokale Bootstrap-Alerts im View zu verbleiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/legal/sites.php`: PHP-BP/UX-Pfad weiter vereinheitlicht
- `Admin – Legal`: Shared-View-Vertrag weiter ausgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/media/library.php`
2. `CMS/admin/media.php`
3. `CMS/admin/views/system/updates.php` (komplexe Update-Warnboxen prüfen)
4. `CMS/admin/views/system/documentation.php` (weitere Helper-/Closure-Dichte prüfen)
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/views/themes/fonts.php` (weitere komplexe Hinweiscluster prüfen)

### Schritt 322 — 26.03.2026 — Font-Manager-Vertrag weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **322 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`
- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Der hoch priorisierte Font-Manager pflegt keine rohen Fehler- und Hinweis-Sonderpfade mehr an den kritischsten Stellen:
  - `FontManagerModule.php` loggt Fehler beim Speichern der Einstellungen und beim Löschen lokaler Fonts jetzt strukturiert über `Logger::instance()->withChannel('admin.font-manager')`.
  - Die Admin-Oberfläche erhält dabei generische, UI-sichere Fehlermeldungen statt roher Exception-Texte.
  - `themes/fonts.php` rendert zusätzlich auch die verbliebene Self-Hosting-Hinweisbox im Bibliotheksbereich über `CMS/admin/views/partials/flash-alert.php`.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Security/PHP-BP verbessert
- `CMS/admin/views/themes/fonts.php`: Shared-View-Vertrag weiter vereinheitlicht
- `Admin – Themes/Fonts`: Fehlerpfade und Hinweise konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/views/system/updates.php` (komplexe Update-Warnboxen prüfen)
4. `CMS/admin/views/system/documentation.php` (weitere Helper-/Closure-Dichte prüfen)
5. `CMS/admin/views/legal/sites.php`
6. `CMS/admin/views/media/library.php`

### Schritt 321 — 26.03.2026 — Diagnose- und Member-Hinweise weiter harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **321 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/diagnose.php`
- `CMS/admin/views/member/general.php`

**Ergebnis dieses Schritts**

- Ein weiterer Restcluster einfacher fachlicher Hinweisboxen pflegt keine eigenen View-Sonderpfade mehr:
  - `diagnose.php` rendert Vendor-Registry- und Debug-Hinweise jetzt über `CMS/admin/views/partials/flash-alert.php`.
  - `member/general.php` nutzt dasselbe Partial jetzt auch für den zentralen Hinweis zur Benutzer-/Authentifizierungsverwaltung.
  - Diagnose- und Member-Ansicht hängen damit sichtbarer am selben Shared-View-Vertrag wie die zuletzt harmonisierten System-, Theme- und Abo-Views.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/diagnose.php`, `CMS/admin/views/member/general.php`: PHP-BP/UX-Pfad vereinheitlicht
- `Admin – Shared Views`: Flash-Ausgabe weiter konsolidiert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/themes/fonts.php` (komplexe Self-Hosting-Hinweisbox prüfen)
2. `CMS/admin/font-manager.php`
3. `CMS/admin/media.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/views/system/updates.php` (komplexe Update-Warnboxen prüfen)
6. `CMS/admin/views/system/documentation.php` (weitere Helper-/Closure-Dichte prüfen)

### Schritt 320 — 26.03.2026 — Lokale Admin-Hinweise weiter auf Flash-Partial harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **320 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/updates.php`
- `CMS/admin/views/system/documentation.php`
- `CMS/admin/views/themes/fonts.php`
- `CMS/admin/views/subscriptions/settings.php`

**Ergebnis dieses Schritts**

- Ein weiterer Cluster lokaler Alert- und Hinweisblöcke pflegt keine eigenen View-Sonderpfade mehr:
  - `updates.php` rendert einfache Erfolgs- und Fehlerhinweise für Core-/Theme-Status jetzt über `CMS/admin/views/partials/flash-alert.php`.
  - `documentation.php` nutzt dasselbe Partial jetzt auch für Verfügbarkeits-, Sync- und Excerpt-Hinweise und enthält keinen lokalen `$renderAlertBlock`-Renderer mehr.
  - `themes/fonts.php` und `subscriptions/settings.php` hängen ihre einfachen Status-/Info-Hinweise ebenfalls an denselben zentralen Alert-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/updates.php`, `CMS/admin/views/system/documentation.php`: PHP-BP/UX-Pfad vereinheitlicht
- `CMS/admin/views/themes/fonts.php`, `CMS/admin/views/subscriptions/settings.php`: Shared-View-Vertrag ausgebaut
- `Admin – Shared Views`: Flash-Ausgabe konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/views/system/diagnose.php` (verbleibende fachliche Inline-Hinweise prüfen)
5. `CMS/admin/views/member/general.php` (verbleibende fachliche Inline-Hinweise prüfen)
6. `CMS/admin/views/themes/fonts.php` (verbleibende komplexe Hinweisboxen prüfen)

### Schritt 319 — 26.03.2026 — Plugins-/Themes-Entrys auf Shared-Shell verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **319 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugins.php`
- `CMS/admin/themes.php`
- `CMS/admin/modules/plugins/PluginsModule.php`
- `CMS/admin/modules/themes/ThemesModule.php`

**Ergebnis dieses Schritts**

- Die Verwaltungs-Entrys für installierte Plugins und Themes pflegen keine eigenen Entry-Sonderpfade mehr:
  - `plugins.php` und `themes.php` laufen jetzt über `CMS/admin/partials/section-page-shell.php` statt eigene Redirect-/Flash-/POST-Logik parallel zur Shared-Shell zu halten.
  - Beide Entrys prüfen Lese-/Schreibzugriff jetzt gezielt über `manage_settings`, bevor Aktivierungs-, Deaktivierungs- oder Löschaktionen ausgelöst werden.
  - `PluginsModule` loggt Aktivierungs- und Deaktivierungsfehler jetzt strukturiert über `Logger::instance()->withChannel('admin.plugins')`, statt rohe Exception-Texte an die Admin-Oberfläche zurückzugeben.
  - `ThemesModule` normalisiert Theme-Slugs konsequent vor Aktivierungs-/Löschaktionen und liefert Erfolgsnachrichten wieder als rohe Daten für den View-Vertrag statt vorzeitig HTML-escaped Strings aus dem Modul.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugins.php`, `CMS/admin/themes.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/modules/plugins/PluginsModule.php`: Fehlerpfade gehärtet, UI-Leaks reduziert
- `CMS/admin/modules/themes/ThemesModule.php`: PHP-BP/UI-Vertrag vereinheitlicht
- `Admin – Extensions`: Plugin-/Theme-Verwaltung konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/views/system/updates.php`
5. `CMS/admin/views/system/documentation.php`
6. `CMS/admin/views/themes/fonts.php`

### Schritt 318 — 26.03.2026 — Marketplace-Entrys und Installationspfade gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **318 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`
- `CMS/admin/theme-marketplace.php`
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der kritische Marketplace-Bereich pflegt keine eigenen Entry-Sonderpfade mehr:
  - `plugin-marketplace.php` und `theme-marketplace.php` laufen jetzt über `CMS/admin/partials/section-page-shell.php` statt eigene Redirect-/Flash-/POST-Logik parallel zur Shared-Shell zu halten.
  - Beide Entrys prüfen Lese-/Schreibzugriff jetzt gezielt über `manage_settings`, bevor Installationen ausgelöst werden.
  - Relative Remote-Manifest- und Paketpfade werden in Plugin- und Theme-Marketplace nur noch über bereinigte Relative-Pfade zu erlaubten HTTPS-Marketplace-Hosts aufgelöst.
  - `requires_cms` und `requires_php` blockieren Auto-Installationen jetzt zusätzlich bei inkompatiblen Laufzeitvoraussetzungen, statt nur als Info-Badge im UI sichtbar zu bleiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Remote-/Installationspfad gehärtet
- `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Schwesterpfad an denselben Sicherheits- und Shell-Vertrag angeglichen
- `Admin – Marketplace`: Remote-Registry-, Manifest- und Auto-Install-Flow konsistenter und robuster aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugins.php`
2. `CMS/admin/themes.php`
3. `CMS/admin/media.php`
4. `CMS/admin/font-manager.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/views/system/updates.php`

### Schritt 317 — 26.03.2026 — Flash-Ausgabe in 14 weiteren Admin-Views harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **317 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/seo/technical.php`
- `CMS/admin/views/seo/social.php`
- `CMS/admin/views/seo/meta.php`
- `CMS/admin/views/seo/sitemap.php`
- `CMS/admin/views/seo/schema.php`
- `CMS/admin/views/seo/redirects.php`
- `CMS/admin/views/seo/not-found.php`
- `CMS/admin/views/seo/dashboard.php`
- `CMS/admin/views/seo/audit.php`
- `CMS/admin/views/security/audit.php`
- `CMS/admin/views/plugins/marketplace.php`
- `CMS/admin/views/themes/marketplace.php`
- `CMS/admin/views/themes/list.php`
- `CMS/admin/views/toc/settings.php`
- `CMS/admin/views/partials/flash-alert.php`

**Ergebnis dieses Schritts**

- Ein größerer Restcluster lokaler Alert-Blöcke wurde auf das gemeinsame Flash-Partial gezogen:
  - 9 SEO-Views nutzen Erfolg-/Fehlerhinweise jetzt über `CMS/admin/views/partials/flash-alert.php` statt über eigene Alert-Blöcke vor ihrer Subnav.
  - 5 weitere Views aus Security, Plugins, Themes und TOC hängen jetzt ebenfalls am selben Partial-Vertrag.
  - Das gemeinsame Partial unterstützt zusätzlich generische `details`-Listen, sodass Redirect- und 404-Views ihre Zusatzhinweise nicht länger lokal als Sondermarkup pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/seo/*`: PHP-BP/UX-Pfad breit vereinheitlicht
- `CMS/admin/views/security/audit.php`, `plugins/marketplace.php`, `themes/marketplace.php`, `themes/list.php`, `toc/settings.php`: Flash-Ausgabe vereinheitlicht
- `Admin – Shared Views`: Alert-Vertrag konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php`
2. `CMS/admin/media.php`
3. `CMS/admin/font-manager.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/views/system/updates.php`
6. `CMS/admin/views/system/documentation.php`

### Schritt 316 — 26.03.2026 — Member- und System-Flash-Ausgabe breit harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **316 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/member/dashboard.php`
- `CMS/admin/views/member/general.php`
- `CMS/admin/views/member/design.php`
- `CMS/admin/views/member/frontend-modules.php`
- `CMS/admin/views/member/widgets.php`
- `CMS/admin/views/member/plugin-widgets.php`
- `CMS/admin/views/member/profile-fields.php`
- `CMS/admin/views/member/notifications.php`
- `CMS/admin/views/member/onboarding.php`
- `CMS/admin/views/system/email-alerts.php`
- `CMS/admin/views/system/response-time.php`
- `CMS/admin/views/system/cron-status.php`
- `CMS/admin/views/system/disk-usage.php`
- `CMS/admin/views/system/health-check.php`
- `CMS/admin/views/system/scheduled-tasks.php`
- `CMS/admin/views/system/info.php`
- `CMS/admin/views/system/diagnose.php`

**Ergebnis dieses Schritts**

- Ein größerer Restcluster lokaler Seiten-Alerts wurde auf das gemeinsame Flash-Partial gezogen:
  - 9 Member-Views nutzen Erfolg-/Fehlerhinweise jetzt über `CMS/admin/views/partials/flash-alert.php` statt über eigene Alert-Blöcke vor der Subnav.
  - 8 System-/Monitoring-Views hängen jetzt ebenfalls am selben Partial-Vertrag.
  - Dismiss-Verhalten, Alert-Typ-Mapping und optionale Fehlerreport-Payloads bleiben damit zentral statt dateiweise verteilt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/member/*`: PHP-BP/UX-Pfad vereinheitlicht
- `CMS/admin/views/system/*`: PHP-BP/UX-Pfad vereinheitlicht
- `Admin – Member/System`: Flash-Ausgabe konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/updates.php`
2. `CMS/admin/views/system/documentation.php`
3. `CMS/admin/views/system/diagnose.php` (verbleibende fachliche Inline-Hinweise prüfen)
4. `CMS/admin/views/member/general.php` (verbleibende fachliche Inline-Hinweise prüfen)
5. `CMS/admin/views/themes/fonts.php`
6. `CMS/admin/views/subscriptions/settings.php`

### Schritt 315 — 26.03.2026 — SEO-Subnav auf gemeinsames Section-Partial standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **315 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/seo/subnav.php`

**Ergebnis dieses Schritts**

- Der SEO-View pflegt seine Navigationsstruktur nicht länger als lokalen Sonderpfad:
  - `seo/subnav.php` nutzt jetzt das gemeinsame `section-subnav.php` wie verwandte System-, Performance- und Member-Views.
  - Active-State, Link-Markup und Button-Styling kommen damit aus demselben Partial-Vertrag.
  - Nur die beiden echten SEO-Aktionsbuttons für Sitemaps und `robots.txt` bleiben als separater Action-Block zurück.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/seo/subnav.php`: Security/Speed/PHP-BP verbessert
- `Admin – SEO`: Navigation konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-media.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/views/member/dashboard.php`
4. `CMS/admin/performance.php`
5. `CMS/admin/monitor-response-time.php`
6. `CMS/admin/views/system/email-alerts.php`

### Schritt 314 — 26.03.2026 — Flash-Ausgabe in Analytics- und Performance-Media-View harmonisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **314 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/seo/analytics.php`
- `CMS/admin/views/performance/media.php`

**Ergebnis dieses Schritts**

- Zwei weitere Admin-Views pflegen keinen eigenen Alert-Sonderpfad mehr:
  - `analytics.php` nutzt jetzt das gemeinsame `flash-alert.php` statt eines lokalen Alert-Blocks.
  - `performance/media.php` rendert Shell-basiertes Feedback jetzt ebenfalls über dasselbe Partial.
  - Dismiss-Verhalten, Alert-Typ-Mapping und optionale Fehlerreport-Payloads laufen dadurch zentral über denselben View-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/seo/analytics.php`: Security/PHP-BP verbessert
- `CMS/admin/views/performance/media.php`: PHP-BP/UX-Pfad vereinheitlicht
- `Admin – SEO/Performance`: View-Feedback konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/seo/subnav.php`
2. `CMS/admin/performance-media.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/views/member/dashboard.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 313 — 26.03.2026 — System-Monitor-Wrapper und Fehlerpfade weiter gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **313 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/system-monitor-page.php`
- `CMS/admin/modules/system/SystemInfoModule.php`

**Ergebnis dieses Schritts**

- Der System-Monitor-Wrapper pflegt keinen eigenen Access-/Redirect-Sonderpfad mehr neben der Shared-Shell:
  - `system-monitor-page.php` baut seine Shell-Konfiguration jetzt über einen benannten Helper auf.
  - Der Zugriff wird zentral über `section-page-shell.php` geprüft statt vorab mit eigenem Header-Redirect.
  - Das `SystemInfoModule` gibt bei Diagnose- und Monitoring-Fehlern nur noch generische UI-Meldungen aus.
  - Technische Exception-Details werden gekürzt im Audit-Log gehalten statt an Alerts im Admin weitergereicht.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/system-monitor-page.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/modules/system/SystemInfoModule.php`: Security/PHP-BP verbessert
- `Admin – System/Monitoring`: Wrapper- und Fehlerpfade kompakter und robuster aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-media.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/views/seo/subnav.php`
4. `CMS/admin/views/member/dashboard.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 312 — 26.03.2026 — Performance-Wrapper weiter auf Shared-Shell verdichtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **312 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-page.php`

**Ergebnis dieses Schritts**

- Der Performance-Wrapper pflegt keinen eigenen Access-/Redirect-Sonderpfad mehr neben der Shared-Shell:
  - `performance-page.php` baut seine Shell-Konfiguration jetzt über einen benannten Helper auf.
  - Der Zugriff wird zentral über `section-page-shell.php` geprüft statt vorab mit eigenem Header-Redirect.
  - Section-, Modul- und Datenlade-Kontexte bleiben in einem kanonischen Konfigurationsblock zusammengefasst.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Wrapper kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/system-monitor-page.php`
2. `CMS/admin/performance-media.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/views/seo/subnav.php`
5. `CMS/admin/views/member/dashboard.php`
6. `CMS/admin/performance.php`

### Schritt 311 — 26.03.2026 — Member-Dashboard-Overview-Entry weiter verschlankt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **311 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard.php`

**Ergebnis dieses Schritts**

- Der Overview-Entry des Member-Dashboards pflegt jetzt keinen eigenen Redirect-Sonderpfad mehr:
  - Legacy-Sektionen laufen über `redirect-alias-shell.php` statt über einen lokalen Header-Redirect-Helfer.
  - Die kanonische Overview-Konfiguration wird direkt an `member-dashboard-page.php` übergeben.
  - Der eigentliche Overview-Guard bleibt damit zentral in der vorhandenen Shared-Schicht statt doppelt im Entry.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard.php`: Security/Speed/PHP-BP verbessert
- `Admin – Member Dashboard`: Overview-/Legacy-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-page.php`
2. `CMS/admin/system-monitor-page.php`
3. `CMS/admin/performance-media.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/views/seo/subnav.php`
6. `CMS/admin/views/member/dashboard.php`

### Schritt 310 — 26.03.2026 — SEO-Wrapper auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **310 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame SEO-/Analytics-Schicht läuft jetzt nicht mehr über einen eigenen Wrapper-Sonderpfad:
  - `seo-page.php` definiert nur noch Section-Registry, Capability-Helfer, Redirect-Normalisierung und den Shell-Vertrag.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Dashboard-, Audit-, Meta-, Social-, Schema-, Sitemap-, Technical- und Analytics-Pfade hängen damit an derselben Shared-Laufzeitschicht.
  - Der dünne Alias `analytics.php` profitiert automatisch mit, ohne eigene Änderungen nachziehen zu müssen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – SEO`: gemeinsamer Wrapper kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard.php`
2. `CMS/admin/performance-page.php`
3. `CMS/admin/system-monitor-page.php`
4. `CMS/admin/performance-media.php`
5. `CMS/admin/analytics.php`
6. `CMS/admin/views/seo/subnav.php`

### Schritt 309 — 26.03.2026 — Users-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **309 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/users.php`
- `CMS/admin/modules/users/UsersModule.php`

**Ergebnis dieses Schritts**

- Die Benutzerverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `users.php` definiert nur noch Action-Allowlist, View-Auflösung, Redirect-Ziel und Dispatch.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Listen- und Edit-Kontext werden zentral über denselben Shell-Vertrag aufgebaut, und fehlgeschlagene Save-Aktionen können auf der Edit-Ansicht inline weiter rendern.
  - Der Save-Fehlerpfad im `UsersModule` gibt zusätzlich keine rohen Exception-Texte mehr an die Admin-Oberfläche weiter.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/users.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/modules/users/UsersModule.php`: Security/PHP-BP verbessert
- `Admin – Users`: Users-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-page.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/member-dashboard.php`
4. `CMS/admin/performance-page.php`
5. `CMS/admin/system-monitor-page.php`
6. `CMS/admin/performance-media.php`

### Schritt 308 — 26.03.2026 — Posts-Entry auf die gemeinsame Section-Shell standardisiert

### Schritt 307 — 26.03.2026 — Pages-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **308 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/posts.php`
- `CMS/admin/views/posts/list.php`
- `CMS/admin/views/posts/edit.php`

**Ergebnis dieses Schritts**

- Die Beitragsverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `posts.php` definiert nur noch Action-Allowlist, View-Auflösung, Redirect-Ziel und Dispatch.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Listen- und Edit-Kontext werden zentral über denselben Shell-Vertrag aufgebaut, und fehlgeschlagene Save-Aktionen können auf der Edit-Ansicht inline weiter rendern.
  - Sowohl Listen- als auch Edit-View zeigen Shell-basiertes Feedback jetzt konsistent über das gemeinsame Flash-Partial an.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/posts.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/edit.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Posts`: Posts-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-media.php`
2. `CMS/admin/users.php`
3. `CMS/admin/seo-page.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/member-dashboard.php`
6. `CMS/admin/performance-page.php`

### Schritt 307 — 26.03.2026 — Pages-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **307 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/pages.php`
- `CMS/admin/partials/section-page-shell.php`
- `CMS/admin/views/pages/list.php`
- `CMS/admin/views/pages/edit.php`

**Ergebnis dieses Schritts**

- Die Seitenverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `pages.php` definiert nur noch Action-Allowlist, View-Auflösung, Redirect-Ziel und Dispatch.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Die Shared-Shell kann jetzt zusätzlich optional nach POST-Fehlern inline weiter rendern, und die Seiten-Views zeigen Shell-basiertes Feedback über das gemeinsame Flash-Partial an.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/pages.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/partials/section-page-shell.php`: Shared-Entry-Vertrag für komplexe Edit-Flows erweitert
- `CMS/admin/views/pages/list.php`, `CMS/admin/views/pages/edit.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Pages`: Pages-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/posts.php`
2. `CMS/admin/performance-media.php`
3. `CMS/admin/users.php`
4. `CMS/admin/seo-page.php`
5. `CMS/admin/analytics.php`
6. `CMS/admin/member-dashboard.php`

### Schritt 306 — 26.03.2026 — Packages-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **306 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/packages.php`
- `CMS/admin/views/subscriptions/packages.php`

**Ergebnis dieses Schritts**

- Die Paketverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `packages.php` definiert nur noch Action-Allowlist, ID-Normalisierung, Dispatch und den Shell-Vertrag.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Die View rendert Shell-basiertes Feedback jetzt ebenfalls über das gemeinsame Flash-Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/packages.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/subscriptions/packages.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Packages`: Packages-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/pages.php`
2. `CMS/admin/performance-media.php`
3. `CMS/admin/posts.php`
4. `CMS/admin/users.php`
5. `CMS/admin/seo-page.php`
6. `CMS/admin/analytics.php`

### Schritt 305 — 26.03.2026 — Media-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **305 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Die Medienverwaltung läuft jetzt nicht mehr über einen eigenen Tab-Sonderpfad:
  - `media.php` definiert nur noch Action-Allowlist, Pfadnormalisierung, Redirect-Ziel und Dispatch.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Bibliothek, Kategorien und Einstellungen erhalten ihren View-/Asset-Kontext sowie Upload-/Connector-Token jetzt zentral aus demselben Shell-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Security/Speed/PHP-BP verbessert
- `Admin – Media`: Medien-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/packages.php`
2. `CMS/admin/pages.php`
3. `CMS/admin/performance-media.php`
4. `CMS/admin/posts.php`
5. `CMS/admin/users.php`
6. `CMS/admin/seo-page.php`

### Schritt 304 — 26.03.2026 — Orders-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **304 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Die Bestellverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `orders.php` definiert nur noch Action-Allowlist, Normalisierung, Statusfilter und Dispatch.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Der Statusfilter bleibt näher am PRG-Flow und muss nicht länger als manueller Redirect-Sonderpfad gepflegt werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/orders.php`: Security/Speed/PHP-BP verbessert
- `Admin – Orders`: Bestell-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/packages.php`
3. `CMS/admin/pages.php`
4. `CMS/admin/performance-media.php`
5. `CMS/admin/posts.php`
6. `CMS/admin/users.php`

### Schritt 303 — 26.03.2026 — Menü-Editor-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **303 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/menu-editor.php`
- `CMS/admin/views/menus/editor.php`

**Ergebnis dieses Schritts**

- Der Menü-Editor läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `menu-editor.php` definiert nur noch Action-Allowlist, Redirect-Ziel und Handler-Mapping.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Die View rendert Shell-basiertes Feedback jetzt ebenfalls über das gemeinsame Flash-Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/menu-editor.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/menus/editor.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Menus`: Menü-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/orders.php`
2. `CMS/admin/media.php`
3. `CMS/admin/packages.php`
4. `CMS/admin/pages.php`
5. `CMS/admin/performance-media.php`
6. `CMS/admin/posts.php`

### Schritt 302 — 26.03.2026 — Hub-Sites-Entry auf die erweiterte Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **302 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/hub-sites.php`
- `CMS/admin/partials/section-page-shell.php`
- `CMS/admin/views/hub/edit.php`
- `CMS/admin/views/hub/template-edit.php`
- `CMS/admin/views/hub/templates.php`

**Ergebnis dieses Schritts**

- Die Hub-Sites laufen jetzt nicht mehr über einen eigenen Multi-View-Entry-Sonderpfad:
  - `hub-sites.php` definiert nur noch View-Normalisierung, Aktions-Dispatch, Redirect-Zielberechnung und den Shell-Vertrag.
  - Die gemeinsame Section-Shell kann jetzt zusätzlich dynamische View-, Titel-, Asset- und Template-Kontexte zur Laufzeit auflösen.
  - Listen-, Edit- und Template-Views erhalten Flash-Feedback jetzt konsistent über das gemeinsame Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/hub-sites.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/partials/section-page-shell.php`: Shared-Entry-Vertrag für Multi-View-Entrys erweitert
- `Admin – Hub`: Hub-Sites-Entry konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/marketing.php`
2. `CMS/admin/media.php`
3. `CMS/admin/menu-editor.php`
4. `CMS/admin/orders.php`
5. `CMS/admin/packages.php`
6. `CMS/admin/pages.php`

### Schritt 301 — 26.03.2026 — Mail-Settings-Entry auf die erweiterte Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **301 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`
- `CMS/admin/partials/section-page-shell.php`

**Ergebnis dieses Schritts**

- Die Mail-/OAuth2-Verwaltung läuft jetzt nicht mehr über einen eigenen Tab-Sonderpfad:
  - `mail-settings.php` definiert nur noch Tab-/Action-Normalisierung, Redirect-Ziel und Handler-Mapping.
  - Redirect, Flash, CSRF, Guard und Rendering laufen zentral über `section-page-shell.php`.
  - `currentTab` und `apiCsrfToken` werden als gemeinsamer Template-Kontext in den View gereicht.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security/Speed/PHP-BP verbessert
- `Admin – System`: Mail-Settings-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/hub-sites.php`
2. `CMS/admin/marketing.php`
3. `CMS/admin/media.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/orders.php`
6. `CMS/admin/packages.php`

### Schritt 300 — 26.03.2026 — Legal-Sites-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **300 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`
- `CMS/admin/views/legal/sites.php`

**Ergebnis dieses Schritts**

- Die Legal-Sites laufen jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `legal-sites.php` definiert nur noch Action-Allowlist, Profil-Helfer, Dispatch und den Shell-Vertrag.
  - Redirect, Flash, CSRF, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Die View rendert Shell-basiertes Feedback jetzt ebenfalls über das gemeinsame Flash-Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/legal/sites.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Legal`: Legal-Sites-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/marketing.php`
4. `CMS/admin/media.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/orders.php`

### Schritt 299 — 26.03.2026 — Landing-Page-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **299 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/landing-page.php`
- `CMS/admin/views/landing/page.php`

**Ergebnis dieses Schritts**

- Der Landing-Page-Editor läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `landing-page.php` definiert nur noch Tab-/Action-Normalisierung, Validierung, Dispatch und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - der aktive Tab bleibt über den PRG-Flow stabil und die View rendert Shell-basierte Flash-Meldungen jetzt ebenfalls über das gemeinsame Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/landing-page.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/landing/page.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Landing`: Landing-Page-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/hub-sites.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/marketing.php`
5. `CMS/admin/media.php`
6. `CMS/admin/monitoring.php`

### Schritt 298 — 26.03.2026 — Gruppen-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **298 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/groups.php`

**Ergebnis dieses Schritts**

- Die Gruppenverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `groups.php` definiert nur noch Action-Allowlist, ID-Normalisierung, Dispatch und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - der User-Entry bleibt damit schlanker und übernimmt künftige Shell-Verbesserungen automatisch mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/groups.php`: Security/Speed/PHP-BP verbessert
- `Admin – Users`: Gruppen-Entry konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/hub-sites.php`
2. `CMS/admin/landing-page.php`
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/marketing.php`
6. `CMS/admin/media.php`

### Schritt 297 — 26.03.2026 — Font-Manager-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **297 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`
- `CMS/admin/views/themes/fonts.php`

**Ergebnis dieses Schritts**

- Der Font-Manager läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `font-manager.php` definiert nur noch Capability-Helfer, Allowlist, Aktions-Dispatch und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - die View rendert Shell-basierte Flash-Meldungen jetzt ebenfalls über das gemeinsame Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/themes/fonts.php`: Feedback-/UX-Pfad vereinheitlicht
- `Admin – Themes`: Font-Manager-Entry kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/groups.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/landing-page.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/marketing.php`

### Schritt 296 — 26.03.2026 — Firewall-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **296 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/firewall.php`

**Ergebnis dieses Schritts**

- Die Firewall läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `firewall.php` definiert nur noch Allowlists, Capability-Gates, kleine Normalisierungshilfen und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - der Security-Entry bleibt damit schlanker und übernimmt künftige Shell-Verbesserungen automatisch mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/firewall.php`: Security/Speed/PHP-BP verbessert
- `Admin – Security`: Firewall-Entry konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php`
2. `CMS/admin/groups.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/landing-page.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 295 — 26.03.2026 — Error-Report-Endpunkt auf gemeinsamen POST-Action-Wrapper standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **295 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/error-report.php`
- `CMS/admin/partials/post-action-shell.php`

**Ergebnis dieses Schritts**

- Der Error-Report-Endpunkt läuft jetzt nicht mehr über eigenen POST-Boilerplate:
  - `error-report.php` konzentriert sich nur noch auf Redirect-Normalisierung, Payload-Bereinigung und den Service-Aufruf.
  - CSRF-, Flash- und Redirect-Ausführung laufen zentral über `post-action-shell.php`.
  - damit gibt es jetzt einen kleinen Shared-Vertrag für weitere Admin-POST-Endpunkte ohne eigene View.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/error-report.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/partials/post-action-shell.php`: Shared-POST-Vertrag robuster aufgebaut
- `Admin – System`: Error-Report-Endpunkt kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/firewall.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/groups.php`
4. `CMS/admin/hub-sites.php`
5. `CMS/admin/landing-page.php`
6. `CMS/admin/legal-sites.php`

### Schritt 294 — 26.03.2026 — Design-Settings-Alias auf gemeinsamen Redirect-Wrapper standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **294 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/design-settings.php`

**Ergebnis dieses Schritts**

- Der Design-Settings-Alias läuft jetzt nicht mehr über eigene Redirect-Helfer:
  - `design-settings.php` definiert nur noch Zugriffsgate und Zielroute.
  - Guard- und Redirect-Ausführung laufen zentral über `redirect-alias-shell.php`.
  - der Alias bleibt damit schlanker und übernimmt künftige Redirect-/Guard-Anpassungen automatisch mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/design-settings.php`: Security/Speed/PHP-BP verbessert
- `Admin – Design`: Alias-Vertrag kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/error-report.php`
2. `CMS/admin/firewall.php`
3. `CMS/admin/font-manager.php`
4. `CMS/admin/groups.php`
5. `CMS/admin/hub-sites.php`
6. `CMS/admin/landing-page.php`

### Schritt 293 — 26.03.2026 — Deletion-Requests-Alias auf gemeinsamen Redirect-Wrapper standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **293 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/deletion-requests.php`

**Ergebnis dieses Schritts**

- Der Löschantrags-Alias läuft jetzt nicht mehr über eigene Redirect-Helfer:
  - `deletion-requests.php` definiert nur noch Zugriffsgate und Zielroute.
  - Guard- und Redirect-Ausführung laufen zentral über `redirect-alias-shell.php`.
  - im selben Zug wurde auch `privacy-requests.php` an denselben Wrapper angeglichen, damit verwandte Legal-Alias-Seiten denselben kleinen Vertrag teilen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/deletion-requests.php`: Security/Speed/PHP-BP verbessert
- `Admin – Legal`: Alias-Vertrag kompakter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/design-settings.php`
2. `CMS/admin/error-report.php`
3. `CMS/admin/firewall.php`
4. `CMS/admin/font-manager.php`
5. `CMS/admin/groups.php`
6. `CMS/admin/hub-sites.php`

### Schritt 292 — 26.03.2026 — Data-Requests-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **292 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/data-requests.php`

**Ergebnis dieses Schritts**

- Die zentrale DSGVO-Arbeitsseite läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `data-requests.php` definiert nur noch Scope-Allowlist, Aktions-Normalisierung und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Auskunfts- und Löschanträge hängen damit an demselben Shared-Request-Flow statt separater Entry-Boilerplate.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/data-requests.php`: Security/Speed/PHP-BP verbessert
- `Admin – Legal`: DSGVO-Arbeitsseite konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/deletion-requests.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/error-report.php`
4. `CMS/admin/firewall.php`
5. `CMS/admin/font-manager.php`
6. `CMS/admin/groups.php`

### Schritt 291 — 26.03.2026 — Cookie-Manager-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **291 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/cookie-manager.php`
- `CMS/admin/views/legal/cookies.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `cookie-manager.php` definiert nur noch Allowlists, Normalisierung, Validierung und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - die View rendert Shell-basierte Flash-Meldungen jetzt sichtbar, sodass Save-, Scan-, Import- und Delete-Aktionen sofort Feedback liefern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/cookie-manager.php`: Security/Speed/PHP-BP verbessert
- `CMS/admin/views/legal/cookies.php`: UX-/Feedback-Pfad robuster aufgebaut
- `Admin – Legal`: Cookie-Consent-Entry kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/data-requests.php`
2. `CMS/admin/deletion-requests.php`
3. `CMS/admin/design-settings.php`
4. `CMS/admin/error-report.php`
5. `CMS/admin/firewall.php`
6. `CMS/admin/font-manager.php`

### Schritt 290 — 26.03.2026 — Documentation-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **290 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Die Admin-Dokumentation läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `documentation.php` konfiguriert nur noch Dokument-Normalisierung, Sync-Aktion und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - die Dokumentauswahl bleibt dabei auch nach POST-Redirects stabil erhalten, weil die Shell query-fähige Redirect-Ziele mitträgt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Security/Speed/PHP-BP verbessert
- `Admin – System`: Documentation-Entry konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/cookie-manager.php`
2. `CMS/admin/data-requests.php`
3. `CMS/admin/deletion-requests.php`
4. `CMS/admin/design-settings.php`
5. `CMS/admin/error-report.php`
6. `CMS/admin/firewall.php`

### Schritt 289 — 26.03.2026 — Comments-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **289 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/comments.php`

**Ergebnis dieses Schritts**

- Die Kommentarverwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `comments.php` definiert nur noch Allowlists, Normalisierung, Rechte-Gates und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - Statusfilter bleiben über den PRG-Flow stabil erhalten, sodass Moderationsaktionen wieder in derselben Listenansicht landen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/comments.php`: Security/Speed/PHP-BP verbessert
- `Admin – Content`: Kommentar-Entry kompakter und robuster aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/documentation.php`
2. `CMS/admin/cookie-manager.php`
3. `CMS/admin/data-requests.php`
4. `CMS/admin/deletion-requests.php`
5. `CMS/admin/design-settings.php`
6. `CMS/admin/error-report.php`

### Schritt 288 — 26.03.2026 — Backup-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **288 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/backups.php`

**Ergebnis dieses Schritts**

- Die Backup-Verwaltung läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `backups.php` konfiguriert nur noch Read-/Write-Zugriff, Aktionen und View-Vertrag.
  - Redirect, Flash, Datenladung und Rendering laufen zentral über `section-page-shell.php`.
  - der Entry bleibt damit schlanker und übernimmt künftige Shell-Verbesserungen automatisch mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/backups.php`: Security/Speed/PHP-BP verbessert
- `Admin – System`: Backup-Entry konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/comments.php`
2. `CMS/admin/documentation.php`
3. `CMS/admin/cookie-manager.php`
4. `CMS/admin/data-requests.php`
5. `CMS/admin/deletion-requests.php`
6. `CMS/admin/design-settings.php`

### Schritt 287 — 26.03.2026 — AntiSpam-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **287 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/antispam.php`

**Ergebnis dieses Schritts**

- Der AntiSpam-Entry läuft jetzt nicht mehr über einen eigenen Entry-Sonderpfad:
  - `antispam.php` definiert nur noch Actions, Capability-Gates und den Shell-Vertrag.
  - Redirect, Flash, Datenladung und Rendering liegen zentral in `section-page-shell.php`.
  - dadurch bleibt die Datei näher an derselben Shared-Infrastruktur wie andere standardisierte Admin-Entrys.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/antispam.php`: Security/Speed/PHP-BP verbessert
- `Admin – Security`: Entry-Vertrag kompakter und robuster aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/backups.php`
2. `CMS/admin/comments.php`
3. `CMS/admin/documentation.php`
4. `CMS/admin/cookie-manager.php`
5. `CMS/admin/data-requests.php`
6. `CMS/admin/deletion-requests.php`

### Schritt 286 — 26.03.2026 — Dashboard-Entry auf die gemeinsame Section-Shell standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **286 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/index.php`

**Ergebnis dieses Schritts**

- Das Dashboard läuft jetzt nicht mehr über einen separaten Sonderpfad:
  - `index.php` konfiguriert nur noch seinen Shell-Vertrag.
  - Guard, Modul-Initialisierung, Datenladung und Rendering laufen über `section-page-shell.php`.
  - der Entry bleibt damit schlanker und übernimmt künftige Shell-Verbesserungen automatisch mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/index.php`: Security/Speed/PHP-BP verbessert
- `Admin – Dashboard`: Entry-Vertrag konsistenter und wartbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-response-time.php`
2. `CMS/admin/monitor-disk-usage.php`
3. `CMS/admin/diagnose.php`
4. `CMS/admin/info.php`
5. `CMS/admin/antispam.php`
6. `CMS/admin/backups.php`

### Schritt 285 — 26.03.2026 — Gemeinsame Section-Shell bei Access-, Flash- und Asset-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **285 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/partials/section-page-shell.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Shell übernimmt jetzt mehr Standardarbeit zentral:
  - Flash-Payloads werden einheitlich geschrieben und gelesen.
  - CSS-/JS-Assets werden vor dem Rendern normalisiert.
  - optionale Access-Checks laufen über einen expliziten gemeinsamen Konfigurationspunkt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/partials/section-page-shell.php`: Security/Speed/PHP-BP verbessert
- `Admin – Shared Entry`: Boilerplate und Access-/Asset-Logik zentraler aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/index.php`
2. `CMS/admin/monitor-response-time.php`
3. `CMS/admin/monitor-disk-usage.php`
4. `CMS/admin/diagnose.php`
5. `CMS/admin/info.php`
6. `CMS/admin/antispam.php`

### Schritt 284 — 26.03.2026 — Redirect- und 404-Entries auf section-spezifische Admin-Datensichten umgestellt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **284 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/redirect-manager.php`
- `CMS/admin/not-found-monitor.php`

**Ergebnis dieses Schritts**

- Die beiden SEO-Entrys laden jetzt nicht mehr denselben Voll-Datensatz:
  - `redirect-manager.php` zieht nur noch die Redirect-Manager-Datensicht.
  - `not-found-monitor.php` zieht nur noch die 404-Monitor-Datensicht.
  - beide Seiten bleiben damit näher an ihrem tatsächlichen Render-Scope statt Regeln, Logs, Targets und Sites immer vollständig gemeinsam zu transportieren.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/redirect-manager.php`: Speed/PHP-BP verbessert
- `CMS/admin/not-found-monitor.php`: Speed/PHP-BP verbessert
- `Admin – SEO`: Redirect- und 404-Renderpfade datenärmer und expliziter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/index.php`
2. `CMS/admin/partials/section-page-shell.php`
3. `CMS/admin/monitor-response-time.php`
4. `CMS/admin/monitor-disk-usage.php`
5. `CMS/admin/diagnose.php`
6. `CMS/admin/info.php`

### Schritt 283 — 26.03.2026 — Redirect-Manager-Modul um section-spezifische Datenzugriffe ergänzt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **283 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/seo/RedirectManagerModule.php`

**Ergebnis dieses Schritts**

- Das Modul trennt seine Datensichten jetzt explizit auf:
  - `getRedirectManagerData()` liefert den Redirect-Scope.
  - `getNotFoundMonitorData()` liefert den 404-Scope.
  - der bisherige pauschale Voll-Dump bleibt damit nicht länger der einzige Vertrag für beide Views.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/seo/RedirectManagerModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – SEO`: Modulvertrag klarer und datenärmer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/redirect-manager.php`
2. `CMS/admin/not-found-monitor.php`
3. `CMS/admin/index.php`
4. `CMS/admin/partials/section-page-shell.php`
5. `CMS/admin/monitor-response-time.php`
6. `CMS/admin/monitor-disk-usage.php`

### Schritt 282 — 26.03.2026 — Redirect-Service auf getrennte Admin-Datensichten aufgeteilt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **282 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/core/Services/RedirectService.php`

**Ergebnis dieses Schritts**

- Der Redirect-Service bietet jetzt kleinere Admin-Datenpfade:
  - gemeinsame Hilfsfunktionen kapseln Redirect-Regeln, 404-Logs, Targets und Stats zentral.
  - `getRedirectManagerData()` liefert nur noch Redirect-Manager-relevante Daten.
  - `getNotFoundMonitorData()` liefert nur noch 404-Monitor-relevante Daten.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/RedirectService.php`: Speed/PHP-BP verbessert
- `Core – SEO/Redirects`: Admin-Datenpfade granularer und wiederverwendbarer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/seo/RedirectManagerModule.php`
2. `CMS/admin/redirect-manager.php`
3. `CMS/admin/not-found-monitor.php`
4. `CMS/admin/index.php`
5. `CMS/admin/partials/section-page-shell.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 281 — 26.03.2026 — Dashboard-Modul reicht vorhandene Stats an Attention-Items weiter

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **281 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/dashboard/DashboardModule.php`

**Ergebnis dieses Schritts**

- Das Dashboard-Modul vermeidet jetzt eine unnötige zweite Stats-Runde:
  - die bereits geladenen Dashboard-Stats werden direkt an die Attention-Items weitergereicht.
  - KPIs, Highlights und Attention-Items arbeiten damit aus derselben Stats-Basis.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/dashboard/DashboardModule.php`: Speed/PHP-BP verbessert
- `Admin – Dashboard`: Renderpfad kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/RedirectService.php`
2. `CMS/admin/modules/seo/RedirectManagerModule.php`
3. `CMS/admin/redirect-manager.php`
4. `CMS/admin/not-found-monitor.php`
5. `CMS/admin/index.php`
6. `CMS/admin/partials/section-page-shell.php`

### Schritt 280 — 26.03.2026 — DashboardService akzeptiert vorhandene Stats für Attention-Items

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **280 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/core/Services/DashboardService.php`

**Ergebnis dieses Schritts**

- `getAttentionItems()` kann nun mit bereits berechneten Stats arbeiten:
  - vorhandene Dashboard-Kennzahlen werden optional übernommen.
  - eine zweite Komplettaggregation ist dafür nicht mehr zwingend nötig.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/DashboardService.php`: Speed/PHP-BP verbessert
- `Core – Dashboard`: Attention-Logik stärker an denselben Datenvertrag gebunden

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/dashboard/DashboardModule.php`
2. `CMS/core/Services/RedirectService.php`
3. `CMS/admin/modules/seo/RedirectManagerModule.php`
4. `CMS/admin/redirect-manager.php`
5. `CMS/admin/not-found-monitor.php`
6. `CMS/admin/index.php`

### Schritt 279 — 26.03.2026 — DashboardService cached Komplett-Stats pro Request

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **279 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/core/Services/DashboardService.php`

**Ergebnis dieses Schritts**

- Der Dashboard-Service hält aggregierte Komplett-Stats jetzt request-lokal vor:
  - wiederholte Aufrufe von `getAllStats()` liefern denselben bereits aufgebauten Datenbestand.
  - identische User-, Page-, Media-, Session-, Security-, Performance- und Order-Aggregationen werden pro Request nur einmal erzeugt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/DashboardService.php`: Speed/PHP-BP verbessert
- `Core – Dashboard`: Service-State und Wiederverwendung robuster aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/dashboard/DashboardModule.php`
2. `CMS/core/Services/RedirectService.php`
3. `CMS/admin/modules/seo/RedirectManagerModule.php`
4. `CMS/admin/redirect-manager.php`
5. `CMS/admin/not-found-monitor.php`
6. `CMS/admin/index.php`

### Schritt 278 — 26.03.2026 — Performance-Settings-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **278 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-settings.php`

**Ergebnis dieses Schritts**

- Der Performance-Settings-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `settings`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Performance-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-settings.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/partials/section-page-shell.php`
2. `CMS/admin/index.php`
3. `CMS/admin/monitor-response-time.php`
4. `CMS/admin/monitor-disk-usage.php`
5. `CMS/admin/diagnose.php`
6. `CMS/admin/info.php`

### Schritt 277 — 26.03.2026 — Performance-Sessions-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **277 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-sessions.php`

**Ergebnis dieses Schritts**

- Der Performance-Sessions-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `sessions`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Performance-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-sessions.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-settings.php`
2. `CMS/admin/partials/section-page-shell.php`
3. `CMS/admin/index.php`
4. `CMS/admin/monitor-response-time.php`
5. `CMS/admin/monitor-disk-usage.php`
6. `CMS/admin/diagnose.php`

### Schritt 276 — 26.03.2026 — Performance-Media-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **276 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-media.php`

**Ergebnis dieses Schritts**

- Der Performance-Media-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `media`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Performance-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-media.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-sessions.php`
2. `CMS/admin/performance-settings.php`
3. `CMS/admin/partials/section-page-shell.php`
4. `CMS/admin/index.php`
5. `CMS/admin/monitor-response-time.php`
6. `CMS/admin/monitor-disk-usage.php`

### Schritt 275 — 26.03.2026 — Performance-Datenbank-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **275 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-database.php`

**Ergebnis dieses Schritts**

- Der Performance-Datenbank-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `database`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Performance-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-database.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-media.php`
2. `CMS/admin/performance-sessions.php`
3. `CMS/admin/performance-settings.php`
4. `CMS/admin/partials/section-page-shell.php`
5. `CMS/admin/index.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 274 — 26.03.2026 — Performance-Cache-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **274 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-cache.php`

**Ergebnis dieses Schritts**

- Der Performance-Cache-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `cache`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Performance-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-cache.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-database.php`
2. `CMS/admin/performance-media.php`
3. `CMS/admin/performance-sessions.php`
4. `CMS/admin/performance-settings.php`
5. `CMS/admin/partials/section-page-shell.php`
6. `CMS/admin/index.php`

### Schritt 273 — 26.03.2026 — Monitoring-Cron-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **273 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-cron-status.php`

**Ergebnis dieses Schritts**

- Der Monitoring-Cron-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `cron`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-cron-status.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-cache.php`
2. `CMS/admin/performance-database.php`
3. `CMS/admin/performance-media.php`
4. `CMS/admin/performance-sessions.php`
5. `CMS/admin/performance-settings.php`
6. `CMS/admin/partials/section-page-shell.php`

### Schritt 272 — 26.03.2026 — Monitoring-E-Mail-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **272 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-email-alerts.php`

**Ergebnis dieses Schritts**

- Der Monitoring-E-Mail-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `email-alerts`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-email-alerts.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-cron-status.php`
2. `CMS/admin/performance-cache.php`
3. `CMS/admin/performance-database.php`
4. `CMS/admin/performance-media.php`
5. `CMS/admin/performance-sessions.php`
6. `CMS/admin/performance-settings.php`

### Schritt 271 — 26.03.2026 — Monitoring-Health-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **271 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-health-check.php`

**Ergebnis dieses Schritts**

- Der Monitoring-Health-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `health-check`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-health-check.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-email-alerts.php`
2. `CMS/admin/monitor-cron-status.php`
3. `CMS/admin/performance-cache.php`
4. `CMS/admin/performance-database.php`
5. `CMS/admin/performance-media.php`
6. `CMS/admin/performance-sessions.php`

### Schritt 270 — 26.03.2026 — Monitoring-Scheduled-Tasks-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **270 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-scheduled-tasks.php`

**Ergebnis dieses Schritts**

- Der Monitoring-Scheduled-Tasks-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `scheduled-tasks`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-scheduled-tasks.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-health-check.php`
2. `CMS/admin/monitor-email-alerts.php`
3. `CMS/admin/monitor-cron-status.php`
4. `CMS/admin/performance-cache.php`
5. `CMS/admin/performance-database.php`
6. `CMS/admin/performance-media.php`

### Schritt 269 — 26.03.2026 — Monitoring-Disk-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **269 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-disk-usage.php`

**Ergebnis dieses Schritts**

- Der Monitoring-Disk-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `disk`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-disk-usage.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-scheduled-tasks.php`
2. `CMS/admin/monitor-health-check.php`
3. `CMS/admin/monitor-email-alerts.php`
4. `CMS/admin/monitor-cron-status.php`
5. `CMS/admin/performance-cache.php`
6. `CMS/admin/performance-database.php`

### Schritt 268 — 26.03.2026 — Monitoring-Response-Time-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **268 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-response-time.php`

**Ergebnis dieses Schritts**

- Der Monitoring-Response-Time-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `response-time`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-response-time.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-disk-usage.php`
2. `CMS/admin/monitor-scheduled-tasks.php`
3. `CMS/admin/monitor-health-check.php`
4. `CMS/admin/monitor-email-alerts.php`
5. `CMS/admin/monitor-cron-status.php`
6. `CMS/admin/performance-cache.php`

### Schritt 267 — 26.03.2026 — Performance-Overview-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **267 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance.php`

**Ergebnis dieses Schritts**

- Der Performance-Overview-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `overview`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Performance-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-response-time.php`
2. `CMS/admin/monitor-disk-usage.php`
3. `CMS/admin/monitor-scheduled-tasks.php`
4. `CMS/admin/monitor-health-check.php`
5. `CMS/admin/monitor-email-alerts.php`
6. `CMS/admin/monitor-cron-status.php`

### Schritt 266 — 26.03.2026 — Diagnose-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **266 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/diagnose.php`

**Ergebnis dieses Schritts**

- Der Diagnose-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `diagnose`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/diagnose.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance.php`
2. `CMS/admin/monitor-response-time.php`
3. `CMS/admin/monitor-disk-usage.php`
4. `CMS/admin/monitor-scheduled-tasks.php`
5. `CMS/admin/monitor-health-check.php`
6. `CMS/admin/monitor-email-alerts.php`

### Schritt 265 — 26.03.2026 — Info-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **265 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/info.php`

**Ergebnis dieses Schritts**

- Der Info-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `info`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen Monitoring-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/info.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/diagnose.php`
2. `CMS/admin/performance.php`
3. `CMS/admin/monitor-response-time.php`
4. `CMS/admin/monitor-disk-usage.php`
5. `CMS/admin/monitor-scheduled-tasks.php`
6. `CMS/admin/monitor-health-check.php`

### Schritt 264 — 26.03.2026 — Analytics-Alias auf reine Section-Konfiguration reduziert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **264 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/analytics.php`

**Ergebnis dieses Schritts**

- Der Analytics-Alias bleibt jetzt bewusst schlank:
  - die Datei übergibt nur noch die kanonische `analytics`-Section an den Shared-Wrapper.
  - Route-, View-, Titel- und Active-Page-Werte kommen nur noch aus der zentralen SEO-Registry.
  - der Entry schleppt damit keine eigenen Konfigurationsduplikate mehr parallel zum Wrapper mit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/analytics.php`: Security/Speed/PHP-BP verbessert
- `Admin – SEO`: Alias-Konfiguration und Shared-Wrapper-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/info.php`
2. `CMS/admin/diagnose.php`
3. `CMS/admin/performance.php`
4. `CMS/admin/monitor-response-time.php`
5. `CMS/admin/monitor-disk-usage.php`
6. `CMS/admin/monitor-scheduled-tasks.php`

### Schritt 263 — 26.03.2026 — Performance-Wrapper auf kanonische Section-Seitenregistries gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **263 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Performance-Schicht zieht ihre Seitenmetadaten jetzt expliziter zentral zusammen:
  - Overview-, Cache-, Datenbank-, Medien-, Session- und Settings-Unterseiten hängen jetzt an einer kanonischen Section-Matrix für Route, View, Titel und Active-Page.
  - Alias-Dateien können damit keine divergierenden Metadaten mehr lose in denselben Wrapper hineinreichen.
  - der Wrapper bleibt näher an einem kleinen Section-Vertrag statt verteiltem Konfigurationsduplikat in jeder Unterseite.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Shared-Wrapper- und Alias-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/info.php`
3. `CMS/admin/diagnose.php`
4. `CMS/admin/performance.php`
5. `CMS/admin/monitor-response-time.php`
6. `CMS/admin/monitor-disk-usage.php`

### Schritt 262 — 26.03.2026 — Monitoring-Wrapper auf kanonische Section-Seitenregistries gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **262 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/system-monitor-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Monitoring-Schicht zieht ihre Seitenmetadaten jetzt expliziter zentral zusammen:
  - Info-, Diagnose-, Response-Time-, Disk-, Scheduled-Tasks-, Health-, E-Mail-Alert- und Cron-Unterseiten hängen jetzt an einer kanonischen Section-Matrix für Route, View, Titel und Active-Page.
  - Alias-Dateien können damit keine divergierenden Metadaten mehr lose in denselben Wrapper hineinreichen.
  - der Wrapper bleibt näher an einem kleinen Section-Vertrag statt verteiltem Konfigurationsduplikat in jeder Unterseite.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/system-monitor-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – Monitoring`: Shared-Wrapper- und Alias-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-page.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/info.php`
4. `CMS/admin/diagnose.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 261 — 25.03.2026 — gemeinsame Section-Shell bei Route-/View-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **261 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/partials/section-page-shell.php`

**Ergebnis dieses Schritts**

- Die generische Section-Shell zieht ihre Route-, View- und Redirect-Gates jetzt wieder expliziter zentral zusammen:
  - `route_path` wird serverseitig auf einen kanonischen internen Pfad zurückgeführt, statt rohe Wrapper-Werte blind in Redirects zu übernehmen.
  - `view_file` muss als vorhandene Datei vorliegen, damit Shared-Wrapper keine leeren oder ungültigen View-Ziele stillschweigend bis in den Renderpfad mitschleppen.
  - Redirects laufen über kleine Shell-Helfer statt mehrfach duplizierten Header-/Exit-Pfaden in der Sammelschicht.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/partials/section-page-shell.php`: Security/Speed/PHP-BP verbessert
- `Admin – Shared Section Shell`: Route-, View- und Redirect-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/info.php`
3. `CMS/admin/diagnose.php`
4. `CMS/admin/performance.php`
5. `CMS/admin/monitor-response-time.php`
6. `CMS/admin/monitor-disk-usage.php`

### Schritt 260 — 25.03.2026 — SEO-Suite-Modul auf section-spezifische Datensichten umgestellt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **260 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/seo/SeoSuiteModule.php`

**Ergebnis dieses Schritts**

- Das SEO-Suite-Modul liefert seine Daten jetzt abschnittsspezifisch statt pauschal als Vollpaket aus:
  - Dashboard-, Audit-, Analytics-, Meta-, Social-, Schema-, Sitemap- und Technical-Unterseiten ziehen nur noch ihre jeweils benötigten Daten statt denselben Sammelabruf für alle SEO-Bereiche zu verwenden.
  - Analytics-, Schema-, Sitemap- und Technical-Unterseiten behalten ihre benötigten Hilfsdaten, ohne unnötig fremde Audit-, Tracking- oder Redirect-Daten mitzuladen.
  - der SEO-Scope bleibt damit näher an einem kleinen section-gebundenen Datenvertrag und vermeidet unnötige Sammelarbeit auf jeder Unterseite.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/seo/SeoSuiteModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – SEO`: Daten-Scope und Unterseiten-Last kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/partials/section-page-shell.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/info.php`
4. `CMS/admin/diagnose.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 259 — 25.03.2026 — gemeinsame SEO-Schicht auf section-spezifische Datenladung gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **259 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame SEO-Schicht zieht ihre Datenladung jetzt wieder expliziter im Wrapper:
  - die Shared-Schicht lädt pro Unterseite nur noch section-spezifische Daten statt pauschal denselben Voll-Datensatz über `getData()`.
  - Dashboard-, Audit-, Analytics-, Meta-, Social-, Schema-, Sitemap- und Technical-Pfade ziehen damit nur noch ihren eigenen Scope.
  - unnötige Voll-Ladepfade scheitern damit sichtbar früher und bleiben näher am kleinen Shared-Wrapper-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – SEO`: gemeinsamer Unterseiten-Dispatch und Data-Scope kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/seo/SeoSuiteModule.php`
2. `CMS/admin/partials/section-page-shell.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/info.php`
5. `CMS/admin/diagnose.php`
6. `CMS/admin/performance.php`

### Schritt 258 — 25.03.2026 — Member-Dashboard-Modul auf section-spezifische Datensichten umgestellt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **258 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/member/MemberDashboardModule.php`

**Ergebnis dieses Schritts**

- Das Member-Dashboard-Modul liefert seine Daten jetzt abschnittsspezifisch statt pauschal als Vollpaket aus:
  - Overview-, General-, Widget-, Profilfeld-, Design-, Frontend-, Notification-, Onboarding- und Plugin-Widget-Unterseiten ziehen nur noch ihre jeweils benötigten Daten statt denselben Sammelabruf für alle Member-Bereiche zu verwenden.
  - Notifications, Widgets, Profilfelder und Plugin-Widgets behalten ihre benötigten Hilfsdaten, ohne unnötig fremde Stats-, Overview- oder Plugin-Scans mitzuladen.
  - der Member-Scope bleibt damit näher an einem kleinen section-gebundenen Datenvertrag und vermeidet unnötige Sammelarbeit auf jeder Unterseite.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/member/MemberDashboardModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – Member Dashboard`: Daten-Scope und Unterseiten-Last kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/partials/section-page-shell.php`
3. `CMS/admin/info.php`
4. `CMS/admin/diagnose.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 257 — 25.03.2026 — gemeinsame Member-Dashboard-Schicht auf section-spezifische Datenladung gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **257 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Member-Dashboard-Schicht zieht ihre Datenladung jetzt wieder expliziter im Wrapper:
  - die Shared-Schicht lädt pro Unterseite nur noch section-spezifische Daten statt pauschal denselben Voll-Datensatz über `getData()`.
  - Overview-, General-, Widget-, Profilfeld-, Design-, Frontend-, Notification-, Onboarding- und Plugin-Widget-Pfade ziehen damit nur noch ihren eigenen Scope.
  - unnötige Voll-Ladepfade scheitern damit sichtbar früher und bleiben näher am kleinen Shared-Wrapper-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – Member Dashboard`: gemeinsamer Unterseiten-Dispatch und Data-Scope kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/member/MemberDashboardModule.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/partials/section-page-shell.php`
4. `CMS/admin/info.php`
5. `CMS/admin/diagnose.php`
6. `CMS/admin/performance.php`

### Schritt 256 — 25.03.2026 — Performance-Modul auf section-spezifische Datensichten umgestellt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **256 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/seo/PerformanceModule.php`

**Ergebnis dieses Schritts**

- Das Performance-Modul liefert seine Daten jetzt abschnittsspezifisch statt pauschal als Vollpaket aus:
  - Cache-, Datenbank-, Medien-, Session- und Settings-Unterseiten ziehen nur noch ihre jeweils benötigten Daten statt denselben Sammelabruf für alle Performance-Bereiche zu verwenden.
  - Medien- und Session-Unterseiten behalten ihre benötigten Settings bei, ohne unnötig fremde Cache-, DB- oder PHP-Infos mitzuladen.
  - der Performance-Scope bleibt damit näher an einem kleinen section-gebundenen Datenvertrag und vermeidet unnötige Telemetrie- und Scan-Arbeit auf jeder Unterseite.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/seo/PerformanceModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Daten-Scope und Unterseiten-Last kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/system-monitor-page.php`
2. `CMS/admin/modules/system/SystemInfoModule.php`
3. `CMS/admin/performance-page.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/info.php`
6. `CMS/admin/diagnose.php`

### Schritt 255 — 25.03.2026 — gemeinsame Performance-Schicht bei Section-Daten und Read-Gate nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **255 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Performance-Schicht zieht Read-Gate und Datenladung jetzt wieder expliziter im Wrapper:
  - der Shared-Wrapper verlangt auch für reine Ansichten jetzt explizit `manage_settings`, statt sich nur auf den generischen Admin-Guard der Section-Shell zu verlassen.
  - jede Unterseite lädt nur noch ihren section-spezifischen Datensatz statt pauschal denselben Voll-Performance-Dump.
  - capability-fremde oder unnötig teure Renderpfade scheitern damit sichtbar früher und bleiben näher am kleinen Shared-Wrapper-Vertrag.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-page.php`: Security/Speed/PHP-BP verbessert
- `Admin – Performance`: Read-/Write- und Data-Scope-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/seo/PerformanceModule.php`
2. `CMS/admin/system-monitor-page.php`
3. `CMS/admin/modules/system/SystemInfoModule.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/info.php`
6. `CMS/admin/diagnose.php`

### Schritt 254 — 25.03.2026 — SystemInfo-Modul auf section-spezifische Datensichten umgestellt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **254 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/SystemInfoModule.php`

**Ergebnis dieses Schritts**

- Das SystemInfo-Modul liefert Info-, Diagnose- und Monitoring-Daten jetzt abschnittsspezifisch statt pauschal als Vollpaket aus:
  - Info-, Diagnose-, Cron-, Disk-, Response-Time-, Health-, Scheduled-Task- und Alert-Unterseiten ziehen nur noch die Daten, die ihre Views tatsächlich benötigen.
  - insbesondere Runtime-/Query-Telemetrie, Monitoring-Scans und Cron-/Disk-Checks werden nicht mehr blind auf jeder System-Unterseite mitgeladen.
  - der System-Scope bleibt damit näher an einem kleinen section-gebundenen Datenvertrag statt losem Vertrauen auf `getData()` für alle Unterseiten.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/SystemInfoModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – System`: Daten-Scope und Monitoring-Last kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-page.php`
2. `CMS/admin/modules/seo/PerformanceModule.php`
3. `CMS/admin/system-monitor-page.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/info.php`
6. `CMS/admin/diagnose.php`

### Schritt 253 — 25.03.2026 — gemeinsame System-Monitor-Schicht bei Section-/Action-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **253 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/system-monitor-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame System-Monitor-Schicht zieht ihre Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - Unterseiten werden serverseitig auf eine kleine kanonische Section-Matrix zurückgeführt, statt lose beliebige Section-Werte in denselben Shared-Wrapper zu übernehmen.
  - POST-Aktionen akzeptieren nur noch die erwarteten Diagnose- bzw. Alert-Mutationen, statt lose `action`-Werte durch Info-, Diagnose- und Monitoring-Unterseiten mitzuschleppen.
  - Info-, Diagnose-, Cron-, Disk-, Response-Time-, Health-, Scheduled-Task- und Alert-Pfade sind zusätzlich explizit an `manage_settings` gebunden und verlassen sich nicht mehr nur auf den pauschalen Admin-Guard der generischen Section-Shell.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/system-monitor-page.php`: Security/PHP-BP verbessert
- `Admin – System`: gemeinsamer Unterseiten-Dispatch und Capability-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/SystemInfoModule.php`
2. `CMS/admin/performance-page.php`
3. `CMS/admin/modules/seo/PerformanceModule.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/info.php`
6. `CMS/admin/diagnose.php`

### Schritt 252 — 25.03.2026 — gemeinsame SEO-/Analytics-Schicht bei section-spezifischen Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **252 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-page.php`

**Ergebnis dieses Schritts**

- Der gemeinsame SEO-Wrapper zieht seine Read-/Write-Gates jetzt wieder expliziter im Einstieg:
  - SEO-Unterseiten werden serverseitig an eine section-spezifische Read-Capability-Matrix gebunden, statt sich nur auf den pauschalen Admin-Guard des Sammel-Wrappers zu verlassen.
  - der Analytics-Pfad akzeptiert Lesezugriffe jetzt nur noch über `manage_settings` oder `view_analytics`, während Mutationen kontrolliert an `manage_settings` gebunden bleiben.
  - capability-fremde Render- oder POST-Requests scheitern damit sichtbar früher, bevor `SeoSuiteModule` oder View-Pfade unnötig angesprungen werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-page.php`: Security/PHP-BP verbessert
- `Admin – SEO/Analytics`: gemeinsamer Unterseiten-Dispatch und Capability-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/backups.php`
3. `CMS/admin/font-manager.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/member-dashboard.php`

### Schritt 251 — 25.03.2026 — Member-Dashboard-Entry bei Legacy-Sections und Overview-Capabilities nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **251 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Entry zieht Legacy-Routing und Overview-Zugriff jetzt wieder expliziter im Alias-Wrapper:
  - Legacy-`section`-Werte werden serverseitig auf eine kleine kanonische Routenmap zurückgeführt, statt lose Query-Werte in Redirect-Ziele zu übernehmen.
  - die Overview verlässt sich nicht mehr nur auf `isAdmin()`, sondern verlangt jetzt wieder passende Read-Capabilities aus dem Member-Dashboard-Kontext.
  - section-fremde oder capability-fremde Requests scheitern damit sichtbar früher, bevor sie in nachgelagerte Unterseiten weitergeleitet werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard.php`: Security/PHP-BP verbessert
- `Admin – Member Dashboard`: Legacy-Routing und Overview-Access kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-page.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/backups.php`
4. `CMS/admin/font-manager.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 250 — 25.03.2026 — Mail-Settings-Entry bei Read-/Write-Capability-Matrix nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **250 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Settings-Entry zieht seine Rechte-Gates jetzt wieder expliziter im Wrapper:
  - Lesepfade akzeptieren die Mail-Admin-Bereiche nur noch mit passender Capability-Matrix aus `manage_settings` bzw. `manage_system`.
  - Mutationen sind zusätzlich klar an `manage_settings` gebunden und scheitern sichtbar früher, bevor CSRF- oder Modulpfade unnötig angesprungen werden.
  - der Wrapper bleibt damit näher am vorhandenen Tab-/Action-Vertrag statt bloßem Vertrauen auf den pauschalen Admin-Guard.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security/PHP-BP verbessert
- `Admin – Mail Settings`: Read-/Write-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard.php`
2. `CMS/admin/seo-page.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/backups.php`
5. `CMS/admin/font-manager.php`
6. `CMS/admin/legal-sites.php`

### Schritt 249 — 25.03.2026 — Legal-Sites-Entry bei Capability- und Template-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **249 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry zieht seine Rechte- und Request-Gates jetzt wieder expliziter im Wrapper:
  - Read- und Write-Zugriffe hängen jetzt klar an `manage_settings`, statt sich nur auf den breiten Admin-Zugriff zu verlassen.
  - POST-Aktionen werden zentral normalisiert, und `template_type` wird bei Generate-/Create-Page-Pfaden früher im Wrapper validiert.
  - capability-fremde oder template-fremde Requests scheitern damit sichtbar früher, bevor Generator- oder Seitenerstellungslogik unnötig angesprungen wird.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Security/PHP-BP verbessert
- `Admin – Legal Sites`: Capability- und Template-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/member-dashboard.php`
3. `CMS/admin/seo-page.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/backups.php`
6. `CMS/admin/font-manager.php`

### Schritt 248 — 25.03.2026 — Font-Manager-Entry bei Read-/Write-Capabilities nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **248 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Der Font-Manager-Entry zieht seine Rechte-Gates jetzt wieder expliziter im Wrapper:
  - Ansichts- und Mutationspfade hängen jetzt explizit an `manage_settings`, statt sich nur auf `isAdmin()` zu verlassen.
  - capability-fremde POST-Requests scheitern sichtbar früher, bevor CSRF- oder Handlerpfade unnötig angesprungen werden.
  - der Wrapper bleibt damit näher am vorhandenen Action-Vertrag statt pauschalem Admin-Vertrauen im Einstieg.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Security/PHP-BP verbessert
- `Admin – Font Manager`: Read-/Write-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/member-dashboard.php`
4. `CMS/admin/seo-page.php`
5. `CMS/admin/analytics.php`
6. `CMS/admin/backups.php`

### Schritt 247 — 25.03.2026 — Backup-Entry bei Read-/Write-Capability-Split nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **247 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/backups.php`

**Ergebnis dieses Schritts**

- Der Backup-Entry zieht seine Rechte-Gates jetzt wieder expliziter im Wrapper:
  - Listen- und View-Pfade hängen jetzt klar an den vorhandenen Read-Capabilities aus `manage_settings` und `manage_system`.
  - Mutationen sind zusätzlich explizit an die Write-Capability gebunden und scheitern sichtbar früher, bevor CSRF- oder Handlerpfade unnötig angesprungen werden.
  - der Wrapper spiegelt damit den bestehenden Capability-Split des `BackupsModule` jetzt wieder sauber im Einstieg.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/backups.php`: Security/PHP-BP verbessert
- `Admin – Backups`: Read-/Write-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/member-dashboard.php`
5. `CMS/admin/seo-page.php`
6. `CMS/admin/analytics.php`

### Schritt 246 — 25.03.2026 — gemeinsame Member-Dashboard-Schicht bei Section- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **246 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Member-Dashboard-Schicht zieht ihre Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - Unterseiten werden serverseitig auf eine kleine kanonische Section-Matrix zurückgeführt, statt lose beliebige Section-Werte in denselben Shared-Wrapper zu übernehmen.
  - POST-Aktionen akzeptieren nur noch die erwartete Save-Mutation, statt lose `action`-Werte durch mehrere Member-Unterseiten mitzuschleppen.
  - General-, Design-, Widget-, Profilfeld-, Notification-, Onboarding- und Plugin-Widget-Pfade sind zusätzlich section-gebunden an ihre passenden Capabilities geknüpft und verlassen sich nicht mehr nur auf den pauschalen Admin-Guard des generischen Section-Shell-Wrappers.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-page.php`: Security/PHP-BP verbessert
- `Admin – Member Dashboard`: gemeinsamer Unterseiten-Dispatch und Capability-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/member-dashboard.php`
6. `CMS/admin/seo-page.php`

### Schritt 245 — 25.03.2026 — Media-Entry bei Capability- und Action-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **245 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Der Media-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen die kleine vorhandene Action-Menge geprüft, statt lose direkt aus `$_POST['action']` in Upload-, Folder-, Delete-, Kategorie- oder Settings-Pfade zu laufen.
  - alle Medien-Mutationen sind zusätzlich explizit an `manage_media` gebunden und verlassen sich nicht mehr nur auf einen pauschalen Admin-Zugriff im Einstieg.
  - unbekannte oder capability-fremde Aktionen scheitern damit sichtbar früher, bevor Modul- oder Servicepfade unnötig angesprungen werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Security/PHP-BP verbessert
- `Admin – Media`: Entry-Dispatch und Rechte-/Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-page.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/font-manager.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/member-dashboard.php`

### Schritt 244 — 25.03.2026 — Landing-Page-Entry bei Tab-, Action- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **244 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/landing-page.php`

**Ergebnis dieses Schritts**

- Der Landing-Page-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - Tabs und POST-Aktionen werden serverseitig normalisiert und gegen kleine Allowlists geprüft, statt lose direkte Request-Werte in Header-, Content-, Footer-, Design-, Feature- oder Plugin-Pfade zu übernehmen.
  - Delete-Pfade begrenzen `feature_id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - der gesamte Entry ist zusätzlich explizit an `manage_settings` gebunden, sodass capability-fremde Admin-Zugriffe früher scheitern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/landing-page.php`: Security/PHP-BP verbessert
- `Admin – Landing Page`: Entry-Dispatch, ID-Normalisierung und Capability-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/member-dashboard-page.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/font-manager.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 243 — 25.03.2026 — Menü-Editor-Entry bei Action-, ID- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **243 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/menu-editor.php`

**Ergebnis dieses Schritts**

- Der Menü-Editor-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in Save-, Delete- oder Item-Save-Pfade zu laufen.
  - `menu_id` wird bereits im Einstieg auf gültige positive Werte begrenzt, bevor Modulmethoden angesprungen werden.
  - der gesamte Entry ist zusätzlich explizit an `manage_settings` gebunden, sodass capability-fremde Admin-Zugriffe früher scheitern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/menu-editor.php`: Security/PHP-BP verbessert
- `Admin – Theme/Menü`: Entry-Dispatch, ID-Normalisierung und Capability-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/landing-page.php`
2. `CMS/admin/media.php`
3. `CMS/admin/member-dashboard-page.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/font-manager.php`
6. `CMS/admin/legal-sites.php`

### Schritt 242 — 25.03.2026 — gemeinsame Performance-Wrapper-Schicht auf section-gebundene Action-Gates gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **242 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-page.php`

**Ergebnis dieses Schritts**

- Die gemeinsame Performance-Schicht zieht ihre Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig sectionspezifisch normalisiert und gegen kleine Allowlists geprüft, statt lose direkt aus `$_POST['action']` quer durch alle Performance-Unterseiten in den Sammel-Dispatch zu laufen.
  - Cache-, Datenbank-, Medien- und Session-Mutationen sind zusätzlich explizit an `manage_settings` gebunden und verlassen sich nicht mehr nur auf einen pauschalen Admin-Zugriff im darunterliegenden Shell-Wrapper.
  - section-fremde oder unbekannte Aktionen scheitern damit sichtbar früher, bevor das Performance-Modul unnötig angesprungen wird.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-page.php`: Security/PHP-BP verbessert
- `Admin – Performance`: gemeinsamer Unterseiten-Dispatch und Capability-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/performance-cache.php`
3. `CMS/admin/performance-sessions.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/landing-page.php`
6. `CMS/admin/media.php`

### Schritt 241 — 25.03.2026 — 404-Monitor-Entry bei Action- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **241 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/not-found-monitor.php`

**Ergebnis dieses Schritts**

- Der 404-Monitor-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Save-Redirect- und Log-Clear-Pfade sind zusätzlich explizit an `manage_settings` gebunden und verlassen sich nicht mehr nur auf einen pauschalen Admin-Zugriff.
  - unbekannte oder capability-fremde Aktionen scheitern damit sichtbar früher, bevor das Redirect-Modul unnötig angesprungen wird.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/not-found-monitor.php`: Security/PHP-BP verbessert
- `Admin – SEO/404 Monitor`: Entry-Dispatch und Rechte-/Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/performance-cache.php`
3. `CMS/admin/performance-sessions.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/landing-page.php`
6. `CMS/admin/media.php`

### Schritt 240 — 25.03.2026 — Users-Entry bei Action-, ID-, Bulk- und View-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **240 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/users.php`

**Ergebnis dieses Schritts**

- Der Users-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Delete- und Bulk-Pfade begrenzen `id` sowie positive `ids[]` bereits im Einstieg auf gültige Werte, bevor das Modul angesprungen wird.
  - View-Routing akzeptiert nur noch die kanonischen Modi `list` und `edit`, und der gesamte Entry ist jetzt zusätzlich explizit an `manage_users` gebunden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/users.php`: Security/PHP-BP verbessert
- `Admin – Users`: Entry-Dispatch, Bulk-/View-Normalisierung und RBAC-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/not-found-monitor.php`
3. `CMS/admin/performance-cache.php`
4. `CMS/admin/performance-sessions.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/landing-page.php`

### Schritt 239 — 25.03.2026 — Pages-Entry bei Action-, Bulk-, View- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **239 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/pages.php`

**Ergebnis dieses Schritts**

- Der Pages-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Delete- und Bulk-Pfade begrenzen `id`, `ids[]` und `bulk_ids` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - View-Routing akzeptiert nur noch die kanonischen Modi `list` und `edit`, und der Entry ist zusätzlich explizit an `manage_pages` gebunden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/pages.php`: Security/PHP-BP verbessert
- `Admin – Pages`: Entry-Dispatch, Bulk-/View-Normalisierung und Capability-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/users.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/not-found-monitor.php`
4. `CMS/admin/performance-cache.php`
5. `CMS/admin/performance-sessions.php`
6. `CMS/admin/menu-editor.php`

### Schritt 238 — 25.03.2026 — Posts-Entry bei Action-, Bulk-, Kategorie- und View-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **238 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/posts.php`

**Ergebnis dieses Schritts**

- Der Posts-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen kleine Action- und Bulk-Allowlists geprüft, statt lose direkt aus `$_POST['action']` oder `$_POST['bulk_action']` in den Dispatch zu laufen.
  - Delete-, Bulk- und Kategoriepfade begrenzen Beitrags-, Kategorie- und Replacement-IDs bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - View-Routing akzeptiert nur noch die kanonischen Modi `list` und `edit`, sodass unbekannte `action`-Query-Werte kontrolliert auf die Listenansicht zurückfallen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/posts.php`: Security/PHP-BP verbessert
- `Admin – Posts`: Entry-Dispatch, Bulk-/Kategorie-Normalisierung und View-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/pages.php`
2. `CMS/admin/users.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/not-found-monitor.php`
5. `CMS/admin/performance-cache.php`
6. `CMS/admin/menu-editor.php`

### Schritt 237 — 25.03.2026 — Privacy-Requests-Alias auf echten Redirect-Zweck zurückgebaut

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **237 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/privacy-requests.php`

**Ergebnis dieses Schritts**

- Der Privacy-Requests-Alias bildet jetzt wieder nur noch seinen tatsächlichen Redirect-Zweck ab:
  - der Entry kapselt Guard-, Ziel- und Redirect-Pfad sichtbar in kleinen Helfern statt toten Legacy-Code hinter einem sofortigen Redirect mitzuführen.
  - unerreichbarer POST-, Modul- und View-Code ist entfernt, weil der Alias fachlich nur auf die zentrale DSGVO-Sammelseite weiterleitet.
  - der Pfad hängt damit wieder näher am bereits bereinigten Redirect-Muster anderer Alias-Entrys und bleibt klarer wartbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/privacy-requests.php`: Security/PHP-BP verbessert
- `Admin – Legal/Privacy Requests`: Alias- und Redirect-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/posts.php`
2. `CMS/admin/pages.php`
3. `CMS/admin/users.php`
4. `CMS/admin/redirect-manager.php`
5. `CMS/admin/not-found-monitor.php`
6. `CMS/admin/privacy-requests.php`

### Schritt 236 — 25.03.2026 — Redirect-Manager-Entry bei Action-, ID-, Slug- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **236 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/redirect-manager.php`

**Ergebnis dieses Schritts**

- Der Redirect-Manager-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Delete- und Toggle-Pfade begrenzen `id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - Slug-Cleanup-Pfade normalisieren `slug_filter` und blocken leere Bereinigungsanfragen sichtbar früh; zusätzlich ist jede Mutation jetzt explizit an `manage_settings` gebunden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/redirect-manager.php`: Security/PHP-BP verbessert
- `Admin – SEO/Redirects`: Entry-Dispatch und Rechte-/Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/privacy-requests.php`
2. `CMS/admin/posts.php`
3. `CMS/admin/pages.php`
4. `CMS/admin/users.php`
5. `CMS/admin/redirect-manager.php`
6. `CMS/admin/not-found-monitor.php`

### Schritt 235 — 25.03.2026 — Firewall-Entry bei Action-, ID- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **235 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/firewall.php`

**Ergebnis dieses Schritts**

- Der Firewall-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Toggle- und Delete-Pfade begrenzen `id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - jede Mutation ist jetzt zusätzlich an eine explizite Capability gebunden, sodass capability-fremde Admin-Zugriffe vor dem eigentlichen Security-Pfad kontrolliert scheitern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/firewall.php`: Security/PHP-BP verbessert
- `Admin – Security/Firewall`: Entry-Dispatch und Rechte-/Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/site-tables.php`
3. `CMS/admin/orders.php`
4. `CMS/admin/backups.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/antispam.php`

### Schritt 234 — 25.03.2026 — Packages-Entry bei Action-/ID-Gates nachgezogen und Logger-Fatal entschärft

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **234 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/packages.php`
- `CMS/admin/modules/subscriptions/PackagesModule.php`

**Ergebnis dieses Schritts**

- Der Packages-Pfad zieht seine Request- und Fehler-Gates jetzt wieder expliziter im Wrapper und Modul:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Delete- und Toggle-Pfade begrenzen `id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - das Modul nutzt seinen Logger in Fehlerpfaden jetzt wieder über den vorhandenen Core-Channel-Vertrag statt über einen statischen Nicht-API-Aufruf, der im Ausnahmefall selbst fatal werden konnte.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/packages.php`: Security/PHP-BP verbessert
- `CMS/admin/modules/subscriptions/PackagesModule.php`: PHP-BP verbessert
- `Admin – Subscriptions/Packages`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/site-tables.php`
3. `CMS/admin/orders.php`
4. `CMS/admin/backups.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/antispam.php`

### Schritt 233 — 25.03.2026 — Design-Settings-Redirect an manage_settings gebunden

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **233 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/design-settings.php`

**Ergebnis dieses Schritts**

- Der Design-Settings-Entry zieht seine Rechte-Gates jetzt wieder expliziter im Wrapper:
  - der Redirect auf den Theme-Editor hängt nicht mehr nur an `isAdmin()`, sondern explizit an `manage_settings`.
  - Access- und Fallback-Pfade liegen sichtbar in kleinen Helfern statt lose direkt im Redirect-Entry.
  - capability-fremde Admin-Zugriffe scheitern damit bereits vor der Weiterleitung in nachgelagerte Theme-Editor-Pfade.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/design-settings.php`: Security/PHP-BP verbessert
- `Admin – Themes/Design`: Redirect-Entry und Access-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/analytics.php`
2. `CMS/admin/site-tables.php`
3. `CMS/admin/orders.php`
4. `CMS/admin/backups.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/antispam.php`

### Schritt 232 — 25.03.2026 — AntiSpam-Entry bei Action-, ID- und Capability-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **232 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/antispam.php`

**Ergebnis dieses Schritts**

- Der AntiSpam-Entry zieht seine Request- und Rechte-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Delete-Pfade begrenzen `id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - jede Mutation ist jetzt zusätzlich an eine explizite Capability gebunden und Alert-/Redirect-Pfade liegen sichtbar in kleinen Helfern statt teilweise losem Session-Handling im Einstieg.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/antispam.php`: Security/PHP-BP verbessert
- `Admin – Security/AntiSpam`: Entry-Dispatch und Rechte-/Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/design-settings.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/site-tables.php`
4. `CMS/admin/orders.php`
5. `CMS/admin/backups.php`
6. `CMS/admin/comments.php`

### Schritt 231 — 25.03.2026 — Kommentar-Entry bei Action-, Status- und Bulk-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **231 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/comments.php`

**Ergebnis dieses Schritts**

- Der Kommentar-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Statuswechsel- und Delete-Pfade begrenzen `id` und `new_status` bereits im Einstieg auf gültige Werte, bevor das Modul angesprungen wird.
  - Bulk-Pfade normalisieren `bulk_action` und deduplizierte positive `ids[]` serverseitig vor dem Modulaufruf und kappen ausufernde Listen früh auf einen kleinen, kontrollierten Rahmen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/comments.php`: Security/PHP-BP verbessert
- `Admin – Comments`: Entry-Dispatch und Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/antispam.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/site-tables.php`
5. `CMS/admin/orders.php`
6. `CMS/admin/backups.php`

### Schritt 230 — 25.03.2026 — gemeinsame SEO-Wrapper-Schicht auf Registry- und Action-Gates gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **230 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-page.php`

**Ergebnis dieses Schritts**

- Der gemeinsame SEO-Sammel-Wrapper zieht seine Section-, Render- und POST-Gates jetzt wieder expliziter im Einstieg:
  - `section` wird nur noch gegen eine kanonische Registry erlaubter SEO-Unterseiten aufgelöst, statt freie Kombinationen aus Route, View und Titel aus variablen Konfigurationswerten zu übernehmen.
  - POST-Aktionen sind jetzt seitengebunden per Allowlist begrenzt, sodass z. B. Audit-, Sitemap- oder Analytics-Mutationen nicht mehr lose über beliebige SEO-Unterseiten in den zentralen Dispatch laufen.
  - `return_to` wird nur noch auf bekannte SEO-Admin-Routen zurückgeführt und Flash-/Redirect-Pfade liegen sichtbar in kleinen Helfern statt lose im Sammel-Entry verteilt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-page.php`: Security/PHP-BP verbessert
- `Admin – SEO`: gemeinsamer Unterseiten-Dispatch, Render- und Redirect-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/antispam.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/comments.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/site-tables.php`
6. `CMS/admin/orders.php`

### Schritt 229 — 25.03.2026 — Orders-Entry bei Action-, Status- und Billing-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **229 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Der Orders-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen die vorhandene Allowlist geprüft, statt lose direkt aus `$_POST['action']` in den Dispatch zu laufen.
  - Statuswechsel-, Delete- und Zuweisungspfade begrenzen `id`, `user_id`, `plan_id`, `status` und `billing_cycle` bereits im Einstieg auf den erwarteten Wertebereich.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch statt rohe Request-Casts direkt in Modulmutationen zu tragen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/orders.php`: Security/PHP-BP verbessert
- `Admin – Orders`: Entry-Dispatch und Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/antispam.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/comments.php`
5. `CMS/admin/seo-page.php`
6. `CMS/admin/site-tables.php`

### Schritt 228 — 25.03.2026 — Site-Tables-Entry bei Action-, ID- und View-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **228 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/site-tables.php`

**Ergebnis dieses Schritts**

- Der Site-Tables-Entry zieht seine Request- und View-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden serverseitig normalisiert und gegen eine kleine Allowlist geprüft, statt lose in den Switch-Dispatch zu fallen.
  - Delete-, Duplicate- und Save-Redirect-Pfade begrenzen `id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen oder ein Edit-Redirect gebaut wird.
  - View-Routing akzeptiert nur noch die kanonischen Modi `list`, `settings` und `edit`, sodass unbekannte `action`-Werte kontrolliert auf die Listenansicht zurückfallen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/site-tables.php`: Security/PHP-BP verbessert
- `Admin – Tables`: Entry-Dispatch, Redirect- und View-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/antispam.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/orders.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/seo-page.php`

### Schritt 227 — 25.03.2026 — Kommentar-Post-Links auf zentralen Permalink-Service gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **227 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/comments/CommentsModule.php`
- `CMS/core/Services/CommentService.php`

**Ergebnis dieses Schritts**

- Die Kommentar-Moderation hängt ihre Beitrag-Links jetzt wieder an den zentralen Core-Vertrag:
  - `CommentsModule` baut `post_url` nicht mehr hart als `/blog/{slug}`, sondern über den konfigurierbaren `PermalinkService`.
  - `CommentService` liefert `published_at` und `created_at` der referenzierten Beiträge direkt im Listenquery mit, damit der Admin-Pfad die korrekten Beitrag-URLs ohne zusätzliche Nachschläge aufbauen kann.
  - die Kommentarliste bleibt damit konsistent zu Routing-, Redirect- und SEO-Pfaden des Cores, auch wenn die Post-Permalink-Struktur angepasst wurde.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/comments/CommentsModule.php`: Security/PHP-BP verbessert
- `CMS/core/Services/CommentService.php`: PHP-BP verbessert
- `Admin – Comments`: Routing- und Link-Vertrag kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/antispam.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/orders.php`
5. `CMS/admin/site-tables.php`
6. `CMS/admin/comments.php`

### Schritt 226 — 25.03.2026 — Error-Report-Entry bei Payload-Gates und Source-URL-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **226 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/error-report.php`

**Ergebnis dieses Schritts**

- Der Error-Report-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - Report-Felder werden serverseitig bereits im Einstieg auf die erwarteten Längen begrenzt.
  - `source_url` wird nur noch als interne/same-site Quelle akzeptiert, statt lose Fremd- oder Sonderwerte weiterzureichen.
  - `error_data_json` und `context_json` werden in Größe, Tiefe, Item-Anzahl und Stringlängen gedeckelt und vor der Weitergabe als kleine, normalisierte Strukturen aufbereitet.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/error-report.php`: Security/PHP-BP verbessert
- `Admin – System`: Error-Report-Dispatch und Payload-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/comments.php`
2. `CMS/admin/antispam.php`
3. `CMS/admin/design-settings.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/orders.php`
6. `CMS/admin/site-tables.php`

### Schritt 225 — 25.03.2026 — Gruppen-Entry bei Action-Gates und ID-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **225 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/groups.php`

**Ergebnis dieses Schritts**

- Der Gruppen-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - Gruppen-Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen eine kleine Allowlist geprüft.
  - Delete-Pfade begrenzen `id` bereits im Einstieg auf gültige positive Werte, bevor das Modul angesprungen wird.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch statt lose Delete-Anfragen nur indirekt im Modulfallback zu behandeln.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/groups.php`: Security/PHP-BP verbessert
- `Admin – Users/Groups`: Entry-Dispatch und Delete-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/error-report.php`
2. `CMS/admin/comments.php`
3. `CMS/admin/antispam.php`
4. `CMS/admin/design-settings.php`
5. `CMS/admin/analytics.php`
6. `CMS/admin/orders.php`

### Schritt 224 — 25.03.2026 — DSGVO-Module bei Request-Typ-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **224 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/legal/PrivacyRequestsModule.php`
- `CMS/admin/modules/legal/DeletionRequestsModule.php`

**Ergebnis dieses Schritts**

- Die DSGVO-Module prüfen Mutationen jetzt explizit gegen den erwarteten Request-Typ:
  - Auskunfts- und Löschanträge werden vor Update-, Delete- oder Hook-Pfaden zuerst typgebunden über die angegebene ID geladen.
  - bereichsfremde IDs schlagen jetzt kontrolliert mit einer passenden Fehlermeldung fehl, statt Datensätze des anderen DSGVO-Pfads mitzubewegen.
  - beide Module bleiben damit näher an einem kleinen, expliziten Request-Vertrag statt rohe IDs stillschweigend als ausreichend zu akzeptieren.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/legal/PrivacyRequestsModule.php`: Security/PHP-BP verbessert
- `CMS/admin/modules/legal/DeletionRequestsModule.php`: Security/PHP-BP verbessert
- `Admin – Legal`: DSGVO-Mutationen und Typ-Bindung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/design-settings.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/error-report.php`
4. `CMS/admin/comments.php`
5. `CMS/admin/antispam.php`
6. `CMS/admin/groups.php`

### Schritt 223 — 25.03.2026 — Cookie-Manager-Entry bei Request-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **223 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/cookie-manager.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen die vorhandene Allowlist gezogen.
  - Delete-Pfade prüfen positive IDs bereits im Einstieg, bevor Kategorien oder Services gelöscht werden.
  - Import-Pfade normalisieren `service_slug` und Self-Hosted-Flags bereits im Wrapper, bevor kuratierte Services weiterverarbeitet werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/cookie-manager.php`: Security/PHP-BP verbessert
- `Admin – Legal`: Cookie-Manager-Dispatch und Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/design-settings.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/modules/legal/PrivacyRequestsModule.php`
4. `CMS/admin/modules/legal/DeletionRequestsModule.php`
5. `CMS/admin/error-report.php`
6. `CMS/admin/comments.php`

### Schritt 222 — 25.03.2026 — Backup-Entry bei Action-Gates und Backup-Namen nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **222 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/backups.php`

**Ergebnis dieses Schritts**

- Der Backup-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - Backup-Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen eine kleine Allowlist geprüft.
  - `backup_name` wird bereits im Einstieg auf einen gültigen Backup-Dateinamen begrenzt, bevor Delete-Pfade angesprungen werden.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch statt lose Aktionen oder rohe Backup-Namen nur indirekt im Modulfallback zu behandeln.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/backups.php`: Security/PHP-BP verbessert
- `Admin – Backups`: Entry-Dispatch und Delete-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/cookie-manager.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/modules/legal/PrivacyRequestsModule.php`
5. `CMS/admin/modules/legal/DeletionRequestsModule.php`
6. `CMS/admin/error-report.php`

### Schritt 221 — 25.03.2026 — DSGVO-Requests-Entry bei Scope-/Action-Gates nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **221 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/data-requests.php`

**Ergebnis dieses Schritts**

- Der DSGVO-Sammel-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - `scope`-gebundene Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen kleine Allowlists geprüft.
  - `id` und Ablehnungsgründe werden bereits im Einstieg bereinigt, bevor Auskunfts- oder Löschpfade sie weiterverarbeiten.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch statt scope-fremde Aktionen nur indirekt im Fallback zu behandeln.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/data-requests.php`: Security/PHP-BP verbessert
- `Admin – Legal`: DSGVO-Dispatch und Request-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/backups.php`
2. `CMS/admin/cookie-manager.php`
3. `CMS/admin/modules/legal/PrivacyRequestsModule.php`
4. `CMS/admin/modules/legal/DeletionRequestsModule.php`
5. `CMS/admin/design-settings.php`
6. `CMS/admin/analytics.php`

### Schritt 220 — 25.03.2026 — Theme-Marketplace auf staging-basierten Update-Service gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **220 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der Theme-Marketplace nutzt für Installationen jetzt denselben zentralen Installationspfad wie reguläre Updates:
  - `ThemeMarketplaceModule` delegiert Marketplace-Theme-Installationen an den gemeinsamen `UpdateService`, statt ZIP-Dateien separat direkt im Modul herunterzuladen, zu prüfen, zu entpacken und ins Theme-Verzeichnis zu verschieben.
  - Zielordner für neue Themes werden weiter kontrolliert aus dem Marketplace-Eintrag abgeleitet, bevor der zentrale staging-basierte Installationspfad übernimmt.
  - der Modulpfad bleibt dadurch näher an seinem eigentlichen Katalog- und Marketplace-Zweck statt parallel eine zweite Archiv-Installationspipeline neben dem Update-Lifecycle zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – Theme Marketplace`: Installationspfad kompakter und vertragsnäher aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/backups.php`
2. `CMS/admin/cookie-manager.php`
3. `CMS/admin/data-requests.php`
4. `CMS/admin/deletion-requests.php`
5. `CMS/admin/design-settings.php`
6. `CMS/admin/analytics.php`

### Schritt 219 — 25.03.2026 — Documentation-Entry bei Action-Allowlist nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **219 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Der Documentation-Entry zieht seinen Sync-Dispatch jetzt wieder expliziter im Wrapper:
  - Sync-Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen eine kleine Allowlist geprüft.
  - unzulässige Aktionen landen nicht mehr bloß implizit über die Handler-Map im Fehlerpfad, sondern scheitern sichtbar früher im Einstieg.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Security/PHP-BP verbessert
- `Admin – Documentation`: Sync-Dispatch und Request-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 218 — 25.03.2026 — Font-Manager-Entry bei Action-Allowlist und Wrapper-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **218 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Der Font-Manager-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen eine kleine Allowlist geprüft.
  - `font_id` wird im Wrapper auf einen gültigen positiven Integer begrenzt, bevor Delete-Pfade angesprungen werden.
  - Google-Font-Familien werden bereits im Einstieg von Kontrollzeichen und auffälligen Leerraumfolgen bereinigt, bevor der Download-Pfad sie weiterverarbeitet.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Security/PHP-BP verbessert
- `Admin – Font Manager`: Entry-Dispatch und Request-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/documentation.php`
2. `CMS/admin/theme-marketplace.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
5. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
6. `CMS/admin/legal-sites.php`

### Schritt 217 — 25.03.2026 — Mail-Settings-Entry bei tab-gebundenem Action-Dispatch nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **217 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Settings-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden vor dem Dispatch serverseitig normalisiert und gegen eine kleine, tab-gebundene Allowlist geprüft.
  - tab-fremde Aktionen werden sichtbar früh blockiert und nach dem PRG-Redirect in ihren kanonischen Bereich zurückgeführt.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch statt bloß auf die Existenz irgendeines Handlers zu vertrauen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security/PHP-BP verbessert
- `Admin – Mail Settings`: Entry-Dispatch und Bereichsbindung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/documentation.php`
2. `CMS/admin/theme-marketplace.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
5. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
6. `CMS/admin/font-manager.php`

### Schritt 216 — 25.03.2026 — Legal-Sites-Entry bei Action-Allowlist und Template-Typ-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **216 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry zieht seine Request-Gates jetzt wieder expliziter im Wrapper:
  - POST-Aktionen werden vor dem Dispatch gegen eine kleine Allowlist geprüft.
  - `template_type` wird serverseitig auf die erlaubten Rechtstext-Typen normalisiert, bevor Generierung oder Seitenerstellung aufgerufen wird.
  - der Wrapper bleibt damit näher am gemeinsamen Admin-Muster aus Allowlist, Flash und kontrolliertem Dispatch.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Security/PHP-BP verbessert
- `Admin – Legal Sites`: Entry-Dispatch und Request-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/documentation.php`

### Schritt 215 — 25.03.2026 — Font-Download-Pfad bei Remote-Dateinamen und Dateimenge gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **215 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Der Font-Manager behandelt Google-Font-Downloads jetzt restriktiver:
  - Remote-CSS mit ungewöhnlich vielen referenzierten Font-Dateien wird früh abgewiesen.
  - lokal gespeicherte Dateinamen werden aus Remote-URL-Pfaden nicht mehr lose übernommen, sondern hart normalisiert und bei Bedarf gekürzt.
  - nur erlaubte Font-Dateiendungen werden in den lokalen Downloadpfad übernommen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Security/PHP-BP verbessert
- `Admin – Font Manager`: Download- und Dateipfade restriktiver aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 214 — 25.03.2026 — Font-Delete-Pfad auf verwalteten Fonts-Root begrenzt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **214 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Der Font-Manager behandelt lokale Delete-Pfade jetzt restriktiver:
  - gespeicherte Font- und CSS-Pfade werden vor dem Löschen explizit auf das verwaltete Verzeichnis `uploads/fonts/` begrenzt.
  - Pfade mit Traversal-Mustern, Kontrollzeichen oder außerhalb des Fonts-Roots werden nicht mehr blind in Dateilöschungen übersetzt.
  - problematische Pfadangaben bleiben als Hinweis in der Rückmeldung sichtbar, statt stillschweigend im Dateisystempfad zu landen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Security/PHP-BP verbessert
- `Admin – Font Manager`: Delete- und Dateipfade restriktiver aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 213 — 25.03.2026 — Media-Upload-Wrapper bei Multi-File-Payloads und Fehleraggregation gehärtet

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **213 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Der Media-Entry reagiert im Upload-Pfad jetzt robuster auf kaputte Requests:
  - Multi-Upload-Payloads werden vor dem Upload-Loop in eine konsistente Liste normalisiert, statt die Struktur von `$_FILES['files']` implizit direkt vorauszusetzen.
  - unvollständige oder inkonsistente Upload-Daten werden früh mit einer klaren Fehlermeldung verworfen.
  - aggregierte Upload-Fehler werden auf eine kleine, kompaktere Ausgabe begrenzt, damit Flash-Meldungen bei vielen fehlerhaften Dateien nicht unnötig ausufern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Security/Speed/PHP-BP verbessert
- `Admin – Media`: Upload-Entry und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/font-manager.php`
6. `CMS/admin/legal-sites.php`

### Schritt 212 — 25.03.2026 — Plugins-/Themes-Entrys bei Action-Allowlist und Plugin-Slug-Gate nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **212 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugins.php`
- `CMS/admin/themes.php`

**Ergebnis dieses Schritts**

- Die Admin-Entrys für Plugins und Themes ziehen ihre Request-Gates jetzt wieder expliziter im Wrapper:
  - `plugins.php` und `themes.php` prüfen POST-Aktionen vor dem Dispatch gegen kleine Allowlists, statt ungültige Werte nur indirekt im `match`-Fallback enden zu lassen.
  - `plugins.php` normalisiert den angeforderten Plugin-Slug serverseitig vor dem Modulaufruf, damit keine rohen Request-Werte direkt in Aktivierungs-, Deaktivierungs- oder Löschpfade laufen.
  - beide Wrapper bleiben damit näher am strengeren Muster aus `updates.php`, `plugin-marketplace.php` und `theme-marketplace.php`.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugins.php`: Security/PHP-BP verbessert
- `CMS/admin/themes.php`: Security/PHP-BP verbessert
- `Admin – Plugins/Themes`: Entry-Dispatch und Request-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/media.php`
3. `CMS/admin/theme-marketplace.php`
4. `CMS/admin/plugin-marketplace.php`
5. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
6. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

### Schritt 211 — 25.03.2026 — Theme-Marketplace bei lokalen Manifestpfaden und ZIP-Gates nachgeschärft

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **211 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der Theme-Marketplace prüft lokale Katalog- und Archivdaten jetzt deutlich restriktiver:
  - lokale `manifest`-Pfade werden traversal-sicher normalisiert und zusätzlich per `realpath` auf den erlaubten Katalog-Root begrenzt, bevor Theme-Manifeste gelesen werden.
  - übergroße Manifest-Dateien werden früh verworfen, statt ungefiltert in den Marketplace-Datenpfad zu laufen.
  - Theme-ZIPs werden vor dem Entpacken jetzt zusätzlich auf maximale Eintragsanzahl, Kontrollzeichen in Einträgen und unkomprimierte Gesamtgröße geprüft.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – Theme Marketplace`: Katalog- und Archivpfad kompakter und restriktiver aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/media.php`
3. `CMS/admin/plugins.php`
4. `CMS/admin/themes.php`
5. `CMS/admin/theme-marketplace.php`
6. `CMS/admin/plugin-marketplace.php`

### Schritt 210 — 25.03.2026 — Plugin-Marketplace-Installation auf zentralen staging-basierten Update-Service gezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **210 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace nutzt für Installationen jetzt denselben zentralen Installationspfad wie reguläre Updates:
  - `PluginMarketplaceModule` delegiert Marketplace-Installationen an den gemeinsamen `UpdateService`, statt ZIP-Dateien separat direkt ins Plugins-Verzeichnis zu laden, zu prüfen und zu entpacken.
  - Marketplace-Plugins profitieren damit ebenfalls vom staging-basierten Installationsablauf mit integriertem Integritätscheck, kontrolliertem Rollback und gemeinsamem Zielpfad-Guard.
  - der Modulpfad bleibt dadurch näher an seinem eigentlichen Registry- und Marketplace-Zweck statt parallel eine zweite Archiv-Installationspipeline neben dem Update-Lifecycle zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Security/Speed/PHP-BP verbessert
- `Admin – Plugin Marketplace`: Installationspfad kompakter und vertragsnäher aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/media.php`
3. `CMS/admin/plugins.php`
4. `CMS/admin/themes.php`
5. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
6. `CMS/admin/theme-marketplace.php`

### Schritt 209 — 25.03.2026 — Marketplace-Entrys bei Action-Allowlist und Plugin-Slug-Normalisierung nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **209 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-marketplace.php`
- `CMS/admin/plugin-marketplace.php`

**Ergebnis dieses Schritts**

- Die Marketplace-Entrys ziehen ihre Request-Gates jetzt wieder sauberer im Wrapper selbst:
  - `theme-marketplace.php` und `plugin-marketplace.php` prüfen POST-Aktionen explizit gegen eine kleine Allowlist, statt ungültige Aktionen nur indirekt im Dispatch-Fallback zu behandeln.
  - der Plugin-Marketplace normalisiert den angeforderten Slug serverseitig vor dem Modulaufruf, damit keine losen Request-Werte direkt in den Installationspfad durchgereicht werden.
  - beide Wrapper bleiben damit näher am gemeinsamen Admin-Pattern aus Allowlist, Flash und kontrolliertem Dispatch statt bloßem Match-Fallback.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-marketplace.php`: Security/PHP-BP verbessert
- `CMS/admin/plugin-marketplace.php`: Security/PHP-BP verbessert
- `Admin – Marketplace`: Entry-Dispatch und Request-Gates kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/media.php`
3. `CMS/admin/plugins.php`
4. `CMS/admin/themes.php`
5. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
6. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

### Schritt 208 — 25.03.2026 — Updates-Pfad bei Snapshot-Wiederverwendung und PRG-Weitergabe nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **208 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/UpdatesModule.php`
- `CMS/admin/updates.php`

**Ergebnis dieses Schritts**

- Der Updates-Pfad vermeidet direkte Doppelprüfungen nach einer manuellen Update-Abfrage jetzt deutlich sauberer:
  - `UpdatesModule` hält Core-, Plugin- und Theme-Prüfstände pro Modulinstanz als kleinen Snapshot zusammen, statt denselben Stand innerhalb desselben Bedienablaufs erneut aufzubauen.
  - `checkAllUpdates()` liefert diesen Snapshot jetzt explizit aus, und `updates.php` übernimmt ihn per Session kurz über den PRG-Redirect in den Folge-Request.
  - die GET-Ansicht kann nach einer expliziten „Update prüfen“-Aktion damit denselben eben ermittelten Stand direkt weiterverwenden, statt unmittelbar wieder dieselben Remote-Checks auszulösen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/UpdatesModule.php`: Speed/PHP-BP verbessert
- `CMS/admin/updates.php`: Speed/PHP-BP verbessert
- `Admin – System/Updates`: Prüfpfad kompakter und request-näher aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/theme-marketplace.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/media.php`
5. `CMS/admin/plugins.php`
6. `CMS/admin/themes.php`

### Schritt 207 — 25.03.2026 — Theme-Verwaltung bei Katalog-Cache und Delete-Delegation nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **207 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/core/ThemeManager.php`
- `CMS/admin/modules/themes/ThemesModule.php`

**Ergebnis dieses Schritts**

- Die Theme-Verwaltung arbeitet bei Listen- und Delete-Pfaden jetzt deutlich zentraler:
  - verfügbare Themes werden im `ThemeManager` pro Instanz inklusive `theme.json`- und Screenshot-Metadaten gecached, statt dieselben Dateisystemdaten im `ThemesModule` zusätzlich noch einmal anzureichern.
  - Theme-Wechsel, Rollback und Löschpfade invalidieren den zentralen Theme-Bestand jetzt gezielt, damit Folgelesewege im selben Lifecycle keinen veralteten Status behalten.
  - Delete-Aktionen delegieren im Admin-Modul wieder an den gemeinsamen `ThemeManager`, sodass Theme-Löschungen nicht länger als parallele Modul-Dateisystemlogik neben dem zentralen Theme-Lifecycle existieren.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/ThemeManager.php`: Speed/PHP-BP verbessert
- `CMS/admin/modules/themes/ThemesModule.php`: Speed/PHP-BP verbessert
- `Admin – Themes`: Listen- und Delete-Pfade kompakter und vertragsnäher aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/updates.php`
2. `CMS/admin/theme-editor.php`
3. `CMS/admin/theme-marketplace.php`
4. `CMS/admin/plugin-marketplace.php`
5. `CMS/admin/media.php`
6. `CMS/admin/plugins.php`

### Schritt 206 — 25.03.2026 — Plugins-Modul bei Aktivstatus-Lookups und Delete-Delegation nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **206 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginsModule.php`

**Ergebnis dieses Schritts**

- Das Plugins-Modul arbeitet bei Aktivstatus und Löschpfaden jetzt deutlich sauberer:
  - aktive Plugin-Slugs werden pro Modulinstanz einmal als Lookup gecached, statt im Listenpfad pro Plugin erneut über Settings- oder Manager-Zugriffe aufgelöst zu werden.
  - Fallback-Aktivierungs- und Deaktivierungspfade bündeln das Persistieren von `active_plugins` jetzt über einen kleinen gemeinsamen Helfer statt dieselbe Settings-Struktur mehrfach inline zu schreiben.
  - Delete-Aktionen delegieren bei vorhandenem `PluginManager` wieder an dessen zentralen Lifecycle, sodass Uninstall- und Delete-Hooks nicht an der Modul-Löschlogik vorbeigeführt werden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginsModule.php`: Speed/PHP-BP verbessert
- `Admin – Plugins`: Aktivstatus- und Delete-Pfade kompakter und vertragsnäher aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/themes.php`
2. `CMS/admin/updates.php`
3. `CMS/admin/theme-editor.php`
4. `CMS/admin/theme-marketplace.php`
5. `CMS/admin/plugin-marketplace.php`
6. `CMS/admin/media.php`

### Schritt 203 — 25.03.2026 — Plugin-Marketplace-Modul beim Registry-Pfad und HTTP-Zugriff entschlackt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **203 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Das Plugin-Marketplace-Modul lädt seine Registry jetzt schlanker:
  - die normalisierte Plugin-Registry wird pro Modulinstanz gecached, statt bei erneutem Zugriff wieder komplett neu geladen und aufbereitet zu werden.
  - Remote- und Manifest-Reads nutzen denselben vorbereiteten HTTP-Client statt wiederholt neue Client-Zugriffe aufzubauen.
  - der Modulpfad bleibt dadurch näher an seinem eigentlichen Registry- und Installationszweck statt Transport- und Registry-Initialisierung unnötig zu wiederholen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Speed/PHP-BP verbessert
- `Admin – Plugin Marketplace`: Registry-Zugriff und HTTP-Nutzung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/theme-editor.php`
4. `CMS/admin/themes.php`
5. `CMS/admin/updates.php`
6. `CMS/admin/plugins.php`

### Schritt 204 — 25.03.2026 — Media-Modul bei Kategorien-Cache und kanonischen Settings-Schlüsseln nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **204 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/media/MediaModule.php`

**Ergebnis dieses Schritts**

- Das Media-Modul arbeitet bei Kategorien und Settings jetzt näher am eigentlichen Service-Vertrag:
  - Kategorien werden pro Modulinstanz gecached, statt bei Bibliothek, Kategorien-Tab und Kategorievalidierung mehrfach identisch über den Medienservice geladen zu werden.
  - erfolgreiche Kategorie-Mutationen invalidieren den Modul-Cache wieder sauber, damit Folgelesewege nicht auf veralteten Daten sitzen bleiben.
  - Dateinamen-Flags und Thumbnail-Felder werden beim Speichern wieder auf die kanonischen Media-Service-Keys (`sanitize_filenames`, `unique_filenames`, `lowercase_filenames`, `thumb_*`) abgebildet, statt auf tolerierte Alias-Namen zu vertrauen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/media/MediaModule.php`: Speed/PHP-BP verbessert
- `Admin – Media`: Kategorien- und Settings-Pfade kompakter und vertragsnäher aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/hub-sites.php`
2. `CMS/admin/theme-editor.php`
3. `CMS/admin/themes.php`
4. `CMS/admin/updates.php`
5. `CMS/admin/plugins.php`
6. `CMS/admin/theme-marketplace.php`

### Schritt 205 — 25.03.2026 — Hub-Sites-Modul bei Zusatzdomain-Prüfungen ohne wiederholten Vollscan nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **205 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/hub/HubSitesModule.php`

**Ergebnis dieses Schritts**

- Das Hub-Sites-Modul arbeitet bei Zusatzdomain-Prüfungen jetzt deutlich schlanker:
  - normalisierte Zusatzdomain-Zuordnungen aller Hub-Sites werden pro Modulinstanz einmal als kleiner Zuordnungsindex aufgebaut.
  - `hubDomainExists()` prüft Domain-Konflikte danach gegen diesen gemeinsamen Cache statt pro Domain erneut alle Hub-Sites und ihre `settings_json`-Payloads vollständig zu lesen.
  - Save-, Delete- und Duplicate-Pfade invalidieren den Cache nach Mutationen gezielt, damit Folgeprüfungen im selben Lebenszyklus konsistent bleiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/hub/HubSitesModule.php`: Speed/PHP-BP verbessert
- `Admin – Hub Sites`: Zusatzdomain-Validierung kompakter und datenärmer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/themes.php`
3. `CMS/admin/updates.php`
4. `CMS/admin/plugins.php`
5. `CMS/admin/theme-marketplace.php`
6. `CMS/admin/plugin-marketplace.php`

### Schritt 202 — 25.03.2026 — Theme-Marketplace-Modul beim Katalogpfad und Installationslookup entschlackt

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **202 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Das Theme-Marketplace-Modul baut seinen Katalogpfad jetzt schlanker auf:
  - der normalisierte Marketplace-Katalog wird pro Modulinstanz gecached, statt bei erneutem Zugriff wieder komplett neu geladen und aufbereitet zu werden.
  - `findCatalogTheme()` sucht Installationsziele jetzt direkt im schlanken Katalog statt indirekt den kompletten angereicherten `getData()`-Pfad zu triggern.
  - der Modulpfad bleibt dadurch näher an seinem eigentlichen Such- und Installationszweck statt View-spezifische Statusanreicherung unnötig mitzutragen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`: Speed/PHP-BP verbessert
- `Admin – Theme Marketplace`: Katalogzugriff und Installationslookup kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/plugin-marketplace.php`
2. `CMS/admin/media.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/theme-editor.php`
5. `CMS/admin/themes.php`
6. `CMS/admin/updates.php`

### Schritt 201 — 25.03.2026 — Font-Manager-Modul beim Settings-Save-Pfad ohne N+1-Existenzchecks nachgezogen

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **201 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/themes/FontManagerModule.php`

**Ergebnis dieses Schritts**

- Der Font-Manager speichert seine Font-Settings jetzt ohne wiederholte Existenz-Checks pro Einzeloption:
  - vorhandene Setting-Namen werden einmal gesammelt vorgeladen, statt für jeden Schlüssel erneut `COUNT(*)` gegen `settings` auszuführen.
  - `saveSettings()` persistiert die normalisierten Font-Werte jetzt über einen kleinen gemeinsamen Helfer für `UPDATE`/`INSERT`.
  - der Modulpfad bleibt dadurch näher an der eigentlichen Font-Input-Normalisierung statt Existenzprüfung und Persistenz mehrfach inline zu mischen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/themes/FontManagerModule.php`: Speed/PHP-BP verbessert
- `Admin – Font Manager`: Settings-Persistenz kompakter und datenbankärmer aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/media.php`
4. `CMS/admin/hub-sites.php`
5. `CMS/admin/theme-editor.php`
6. `CMS/admin/themes.php`

### Schritt 200 — 25.03.2026 — Cookie-Manager-Entry bei Ziel-, Redirect-, Allowlist- und Dispatch-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **200 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/cookie-manager.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager-Entry bündelt Ziel-URL, Redirect-, Allowlist- und Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Cookie-Manager-Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - Ziel-URL, Allowlist-Prüfung und Dispatch-Auflösung bleiben sichtbar in kleinen Helfern statt lose direkt im Hauptfluss zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Ziel-, Allowlist- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/cookie-manager.php`: Security/Speed/BP verbessert
- `Admin – Cookie Manager`: Entry-Dispatch und Redirect-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php`
2. `CMS/admin/theme-marketplace.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/media.php`
5. `CMS/admin/hub-sites.php`
6. `CMS/admin/theme-editor.php`

### Schritt 199 — 25.03.2026 — Legal-Sites-Entry bei Ziel-, Redirect-, Profil-State- und Template-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **199 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry bündelt Ziel-URL, Redirect-, Profil-Session-State und Template-Aufbau jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Profil-, Template- und Mutationsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - Redirect-Ziel, Profil-State und Template-Aufbau bleiben sichtbar in kleinen Helfern statt lose direkt im Hauptfluss zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Session-, Ziel- und Template-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Security/Speed/BP verbessert
- `Admin – Legal Sites`: Entry-Dispatch und Redirect-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/cookie-manager.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/theme-marketplace.php`
4. `CMS/admin/plugin-marketplace.php`
5. `CMS/admin/media.php`
6. `CMS/admin/hub-sites.php`

### Schritt 198 — 25.03.2026 — Error-Report-Entry bei Default-, Redirect-, Payload- und Dispatch-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **198 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/error-report.php`

**Ergebnis dieses Schritts**

- Der Error-Report-Entry bündelt Default-/Redirect-Ziel, JSON-Payload-Normalisierung und Report-Dispatch jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Fehlerreport-Rückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - Redirect-Auflösung, JSON-Dekodierung und Report-Payload bleiben sichtbar in kleinen Helfern statt lose direkt im Hauptfluss zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Service-Aufruf statt Ziel-, Payload- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/error-report.php`: Security/Speed/BP verbessert
- `Admin – Error Report`: Entry-Dispatch und Redirect-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/cookie-manager.php`
4. `CMS/admin/theme-marketplace.php`
5. `CMS/admin/plugin-marketplace.php`
6. `CMS/admin/media.php`

### Schritt 197 — 25.03.2026 — Theme-Explorer-Entry bei Ziel-, Allowlist-, Redirect-, Alert- und Dispatch-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **197 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-explorer.php`

**Ergebnis dieses Schritts**

- Der Theme-Explorer-Entry bündelt Ziel-URL, Action-Allowlist, Redirect-, Flash-/Pull-Alert-Pfade und Save-Dispatch jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Datei-Save-Rückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - Redirect-Ziel, Action-Allowlist und Session-Alert-Pfade bleiben sichtbar in kleinen Helfern statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Session-, Ziel- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Security/Speed/BP verbessert
- `Admin – Theme Explorer`: Entry-Dispatch und Redirect-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/error-report.php`
4. `CMS/admin/cookie-manager.php`
5. `CMS/admin/theme-marketplace.php`
6. `CMS/admin/plugin-marketplace.php`

### Schritt 196 — 25.03.2026 — Hub-Sites-Entry bei Allowlist-, View-, Redirect-, Alert- und Render-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **196 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/hub-sites.php`

**Ergebnis dieses Schritts**

- Der Hub-Sites-Entry bündelt Action-Allowlist, View-Normalisierung, Redirect-Abgänge, Flash-/Pull-Alert-Pfade, Action-Dispatch und Render-Konfiguration jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Listen-, Edit-, Template- und Mutationspfade laufen konsistenter über denselben kleinen Entry-Pfad.
  - View-Auflösung, Asset-Konfiguration und Redirect-Ziele bleiben sichtbar in kleinen Helfern statt lose in mehreren `if`-/`switch`-Blöcken zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt View-, Session- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/hub-sites.php`: Security/Speed/BP verbessert
- `Admin – Hub Sites`: Entry-Dispatch und Render-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/plugin-marketplace.php`
3. `CMS/admin/plugins.php`
4. `CMS/admin/themes.php`
5. `CMS/admin/updates.php`
6. `CMS/admin/theme-editor.php`

### Schritt 195 — 25.03.2026 — Media-Entry bei Allowlist-, Pfad-, Redirect- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **195 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Der Media-Entry bündelt Action-Allowlist, Pfadauflösung, Redirect-Parameter, Upload-Rückmeldungen und Action-Dispatch jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Library-, Kategorie- und Settings-Aktionen laufen konsistenter über denselben kleinen Entry-Pfad.
  - Redirect-Query-Aufbau, Action-Zielpfade und die Ableitung des aktuellen Medienpfads bleiben sichtbar in kleinen Helfern statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Upload-, Query- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Security/Speed/BP verbessert
- `Admin – Media`: Entry-Dispatch und Redirect-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/hub-sites.php`
2. `CMS/admin/theme-marketplace.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/plugins.php`
5. `CMS/admin/themes.php`
6. `CMS/admin/updates.php`

### Schritt 194 — 25.03.2026 — Theme-Editor-Entry bei Layout- und Fallback-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **194 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-editor.php`

**Ergebnis dieses Schritts**

- Der Theme-Editor-Entry bündelt Layout- und Fallback-Pfade jetzt klarer über kleine Helfer:
  - Customizer-Einbettung und Fallback-Ansicht laufen konsistenter über denselben kleinen Entry-Pfad.
  - Layout-Defaults und Fallback-Links bleiben sichtbar in kleinen Helfern statt lose über zwei Zweige verteilt zu sein.
  - der Einstieg bleibt dadurch näher an seinem eigentlichen Routing-Zweck statt Header-, Sidebar- und Fallback-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-editor.php`: Security/Speed/BP verbessert
- `Admin – Theme Editor`: Layout- und Fallback-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/theme-marketplace.php`
4. `CMS/admin/plugin-marketplace.php`
5. `CMS/admin/plugins.php`
6. `CMS/admin/themes.php`

### Schritt 193 — 25.03.2026 — Updates-Entry bei Redirect-, Alert-, Allowlist- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **193 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/updates.php`

**Ergebnis dieses Schritts**

- Der Updates-Entry bündelt Redirect-, Flash-, Pull-Alert-, Allowlist- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler, Update-Prüfung sowie Core- und Plugin-Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Action-Allowlist und Plugin-Slug-Normalisierung bleiben sichtbar in kleinen Helfern statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/updates.php`: Security/Speed/BP verbessert
- `Admin – Updates`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/media.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/theme-marketplace.php`
5. `CMS/admin/plugin-marketplace.php`
6. `CMS/admin/plugins.php`

### Schritt 192 — 25.03.2026 — Themes-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **192 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/themes.php`

**Ergebnis dieses Schritts**

- Der Themes-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Theme-Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Theme-Slug-Normalisierung bleibt sichtbar in einem kleinen Helper statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/themes.php`: Security/Speed/BP verbessert
- `Admin – Themes`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/updates.php`
2. `CMS/admin/media.php`
3. `CMS/admin/theme-editor.php`
4. `CMS/admin/hub-sites.php`
5. `CMS/admin/theme-marketplace.php`
6. `CMS/admin/plugin-marketplace.php`

### Schritt 191 — 25.03.2026 — Plugins-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **191 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugins.php`

**Ergebnis dieses Schritts**

- Der Plugins-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler sowie Aktivierungs-, Deaktivierungs- und Löschrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Aktionsauflösung bleibt sichtbar in einem kleinen Dispatch-Helper statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugins.php`: Security/Speed/BP verbessert
- `Admin – Plugins`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/themes.php`
2. `CMS/admin/updates.php`
3. `CMS/admin/media.php`
4. `CMS/admin/theme-editor.php`
5. `CMS/admin/hub-sites.php`
6. `CMS/admin/theme-marketplace.php`

### Schritt 190 — 25.03.2026 — Theme-Marketplace-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **190 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/theme-marketplace.php`

**Ergebnis dieses Schritts**

- Der Theme-Marketplace-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Installationsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Theme-Slug-Normalisierung bleibt sichtbar in einem kleinen Helper statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-marketplace.php`: Security/Speed/BP verbessert
- `Admin – Theme Marketplace`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/theme-editor.php`
4. `CMS/admin/updates.php`
5. `CMS/admin/plugins.php`
6. `CMS/admin/themes.php`

### Schritt 189 — 25.03.2026 — Plugin-Marketplace-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **189 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Installationsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Installationsaktion bleibt sichtbar in einem kleinen Dispatch-Helper statt lose direkt im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Security/Speed/BP verbessert
- `Admin – Plugin Marketplace`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/media.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/theme-editor.php`
5. `CMS/admin/updates.php`
6. `CMS/admin/plugins.php`

### Schritt 188 — 25.03.2026 — Landing-Page-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **188 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/landing-page.php`

**Ergebnis dieses Schritts**

- Der Landing-Page-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Action-Handler bleiben sichtbar in einer kompakten Handler-Map statt als lange `if`-Kette im Einstieg zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert-, Tab- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/landing-page.php`: Security/Speed/BP verbessert
- `Admin – Landing Page`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/theme-editor.php`
5. `CMS/admin/theme-marketplace.php`
6. `CMS/admin/updates.php`

### Schritt 187 — 25.03.2026 — Firewall-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **187 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/firewall.php`

**Ergebnis dieses Schritts**

- Der Firewall-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - Flash- und Pull-Alert-Pfade sind sichtbar zentralisiert statt lose über Session-Zugriffe verteilt.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/firewall.php`: Security/Speed/BP verbessert
- `Admin – Firewall`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/landing-page.php`
2. `CMS/admin/media.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/plugin-marketplace.php`
5. `CMS/admin/theme-editor.php`
6. `CMS/admin/theme-marketplace.php`

### Schritt 186 — 25.03.2026 — Font-Manager-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **186 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/font-manager.php`

**Ergebnis dieses Schritts**

- Der Font-Manager-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Action-Handler bleiben sichtbar in einer kompakten Handler-Map statt als lange `if`-/`elseif`-Kette im Einstieg zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/font-manager.php`: Security/Speed/BP verbessert
- `Admin – Font Manager`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/media.php`
2. `CMS/admin/firewall.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/landing-page.php`
5. `CMS/admin/plugin-marketplace.php`
6. `CMS/admin/theme-editor.php`

### Schritt 185 — 25.03.2026 — Backups-Entry bei Redirect-, Alert- und Handler-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **185 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/backups.php`

**Ergebnis dieses Schritts**

- Der Backups-Entry bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Action-Handler bleiben sichtbar in einer kompakten Handler-Map statt als lose Closures im POST-Block zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert- und Dispatch-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/backups.php`: Security/Speed/BP verbessert
- `Admin – System/Backups`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/font-manager.php`
2. `CMS/admin/media.php`
3. `CMS/admin/firewall.php`
4. `CMS/admin/hub-sites.php`
5. `CMS/admin/landing-page.php`
6. `CMS/admin/plugin-marketplace.php`

### Schritt 184 — 25.03.2026 — Cookie-Manager-Entry bei Allowlist- und Alert-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **184 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/cookie-manager.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager-Entry bündelt Action-Allowlist und Session-Alert-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Allowlist bleibt sichtbar an einer Stelle definiert statt als lose Werteliste im Einstieg zu hängen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert- und Allowlist-Details mehrfach zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/cookie-manager.php`: Security/Speed/BP verbessert
- `Admin – Cookie Manager`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/backups.php`
2. `CMS/admin/font-manager.php`
3. `CMS/admin/media.php`
4. `CMS/admin/firewall.php`
5. `CMS/admin/hub-sites.php`
6. `CMS/admin/landing-page.php`

### Schritt 183 — 25.03.2026 — Packages-Entry bei Handler- und Alert-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **183 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/packages.php`

**Ergebnis dieses Schritts**

- Der Packages-Entry bündelt Action-Dispatch und Session-Alert-Pfade jetzt klarer über kleine Handler-Helfer:
  - Paket- und Settings-Aktionen laufen über dieselbe sichtbare Handler-Map.
  - Switch-Duplikat und verstreute Redirect-Pfade entfallen aus dem Einstieg.
  - der Einstieg bleibt dadurch näher an den eigentlichen Modulen statt Flash- und Dispatch-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/packages.php`: Security/Speed/BP verbessert
- `Admin – Pakete`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/cookie-manager.php`
2. `CMS/admin/backups.php`
3. `CMS/admin/font-manager.php`
4. `CMS/admin/media.php`
5. `CMS/admin/firewall.php`
6. `CMS/admin/hub-sites.php`

### Schritt 182 — 25.03.2026 — Orders-Entry bei Allowlist- und Alert-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **182 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Der Orders-Entry bündelt Action-Allowlist und Session-Alert-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben kleinen Entry-Pfad.
  - die Allowlist bleibt sichtbar an einer Stelle definiert statt erst im POST-Block geladen zu werden.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Alert- und Allowlist-Details lose zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/orders.php`: Security/Speed/BP verbessert
- `Admin – Orders`: Entry-Dispatch und Alert-Pfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/packages.php`
2. `CMS/admin/cookie-manager.php`
3. `CMS/admin/backups.php`
4. `CMS/admin/font-manager.php`
5. `CMS/admin/media.php`
6. `CMS/admin/firewall.php`

### Schritt 181 — 25.03.2026 — Error-Report-Entry bei Redirect- und Payload-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **181 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/error-report.php`

**Ergebnis dieses Schritts**

- Der Error-Report-Entry bündelt Redirect-, Flash- und JSON-Normalisierung jetzt klarer über kleine Helfer:
  - Token-Fehler und Service-Rückgaben laufen konsistenter über denselben kleinen Alert-Pfad.
  - JSON-Payloads werden jetzt sichtbar zentral normalisiert, statt mehrfach indirekt geprüft zu werden.
  - der Einstieg bleibt dadurch näher am eigentlichen Service-Aufruf statt Request-Normalisierung und Session-Details lose zu verteilen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/error-report.php`: Security/Speed/BP verbessert
- `Admin – Error Report`: Entry-Dispatch und Payload-Normalisierung kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/orders.php`
2. `CMS/admin/packages.php`
3. `CMS/admin/cookie-manager.php`
4. `CMS/admin/backups.php`
5. `CMS/admin/font-manager.php`
6. `CMS/admin/media.php`

### Schritt 180 — 25.03.2026 — Deletion-Requests-Entry beim Redirect-Ziel weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **180 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/deletion-requests.php`

**Ergebnis dieses Schritts**

- Der Löschanträge-Entry bündelt sein Redirect-Ziel jetzt klarer über einen kleinen Ziel-Helfer:
  - das Ziel zur Sammelseite bleibt sichtbar an einer Stelle definiert.
  - der Einstieg bleibt dadurch näher an seinem eigentlichen Routing-Zweck statt Ziel-URLs lose auszuschreiben.
  - Weiterleitungen für Guard- und Standardpfad bleiben konsistenter lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/deletion-requests.php`: Security/Speed/BP verbessert
- `Admin – DSGVO`: Redirect-Ziele kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/error-report.php`
2. `CMS/admin/orders.php`
3. `CMS/admin/packages.php`
4. `CMS/admin/cookie-manager.php`
5. `CMS/admin/backups.php`
6. `CMS/admin/font-manager.php`

### Schritt 179 — 25.03.2026 — Design-Settings-Entry beim Redirect-Ziel weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **179 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/design-settings.php`

**Ergebnis dieses Schritts**

- Der Design-Settings-Entry bündelt sein Redirect-Ziel jetzt klarer über einen kleinen Ziel-Helfer:
  - das Ziel zum Theme-Editor bleibt sichtbar an einer Stelle definiert.
  - der Einstieg bleibt dadurch näher an seinem eigentlichen Routing-Zweck statt Ziel-URLs lose auszuschreiben.
  - Weiterleitungen für Guard- und Standardpfad bleiben konsistenter lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/design-settings.php`: Security/Speed/BP verbessert
- `Admin – Design`: Redirect-Ziele kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/deletion-requests.php`
2. `CMS/admin/error-report.php`
3. `CMS/admin/orders.php`
4. `CMS/admin/packages.php`
5. `CMS/admin/cookie-manager.php`
6. `CMS/admin/backups.php`

### Schritt 178 — 25.03.2026 — Legal-Sites-Entry bei Dispatch- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **178 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry bündelt seine POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - Action-Handler bleiben als kleine Entry-Allowlist sichtbar zusammengezogen.
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben PRG-Pfad.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Security/Speed/BP verbessert
- `Admin – Legal Sites`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/design-settings.php`
2. `CMS/admin/error-report.php`
3. `CMS/admin/orders.php`
4. `CMS/admin/packages.php`
5. `CMS/admin/cookie-manager.php`
6. `CMS/admin/deletion-requests.php`

### Schritt 177 — 25.03.2026 — Documentation-Entry bei Handler- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **177 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Der Documentation-Entry bündelt Dokumentauswahl, Aktionshandler, Flash-Meldungen und Redirects jetzt klarer über kleine Helfer:
  - Dokumentauswahl und Redirect-Aufbau laufen konsistenter über denselben Entry-Pfad.
  - der Sync-Handler bleibt als kleine Entry-Allowlist sichtbar zusammengezogen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Redirect- und Session-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Security/Speed/BP verbessert
- `Admin – Dokumentation`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/error-report.php`
4. `CMS/admin/orders.php`
5. `CMS/admin/packages.php`
6. `CMS/admin/cookie-manager.php`

### Schritt 176 — 25.03.2026 — Mail-Settings-Entry bei Handler-Definitionen weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **176 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Settings-Entry koppelt seine Actions jetzt enger an dieselbe Handler-Map:
  - doppelte Aktionsdefinitionen laufen nicht mehr parallel als Allowlist und Handler-Liste.
  - Handler konsumieren den POST-Input jetzt explizit über denselben kleinen Entry-Pfad.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Handler-Details doppelt zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – Mail Settings`: Entry-Dispatch und Handler-Definitionen kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/documentation.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/design-settings.php`
4. `CMS/admin/error-report.php`
5. `CMS/admin/orders.php`
6. `CMS/admin/packages.php`

### Schritt 175 — 25.03.2026 — Gruppen-Entry bei Dispatch- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **175 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/groups.php`

**Ergebnis dieses Schritts**

- Der Gruppen-Entry bündelt seine POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - Action-Handler bleiben als kleine Entry-Allowlist sichtbar zusammengezogen.
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben PRG-Pfad.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/groups.php`: Security/Speed/BP verbessert
- `Admin – Gruppen`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/documentation.php`
4. `CMS/admin/error-report.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/orders.php`

### Schritt 174 — 25.03.2026 — Data-Requests-Entry bei Dispatch- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **174 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/data-requests.php`

**Ergebnis dieses Schritts**

- Der Data-Requests-Entry bündelt seine POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Scope-Aktionen laufen konsistenter über denselben PRG-Pfad.
  - Session-Alerts werden nicht mehr separat im Fehler- und Erfolgsfall behandelt.
  - der Einstieg bleibt dadurch näher an den eigentlichen Privacy- und Deletion-Modulen statt Redirect-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/data-requests.php`: Security/Speed/BP verbessert
- `Admin – DSGVO`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/groups.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/design-settings.php`
4. `CMS/admin/documentation.php`
5. `CMS/admin/error-report.php`
6. `CMS/admin/legal-sites.php`

### Schritt 173 — 25.03.2026 — Settings-Entry bei Tab- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **173 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/settings.php`

**Ergebnis dieses Schritts**

- Der Settings-Entry bündelt seine Tab-Normalisierung, POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - Tab- und Redirect-Aufbau laufen konsistenter über denselben Entry-Pfad.
  - Action-Handler bleiben als kleine Entry-Allowlist sichtbar zusammengezogen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Redirect- und Session-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/settings.php`: Security/Speed/BP verbessert
- `Admin – Settings`: Tab-/Dispatch- und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/data-requests.php`
2. `CMS/admin/groups.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/design-settings.php`
5. `CMS/admin/documentation.php`
6. `CMS/admin/error-report.php`

### Schritt 172 — 25.03.2026 — Comments-Entry bei Dispatch- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **172 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/comments.php`

**Ergebnis dieses Schritts**

- Der Kommentare-Entry bündelt seine POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - die eigentlichen Modul-Aufrufe bleiben vom Session- und Redirect-Handling getrennt.
  - Fehlerfälle und Erfolgsrückgaben laufen jetzt konsistenter über denselben Entry-Pfad.
  - der PRG-Flow bleibt sichtbar zentralisiert, statt Redirects und Dispatch-Details mehrfach im Einstieg zu verteilen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/comments.php`: Security/Speed/BP verbessert
- `Admin – Kommentare`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/languages.php`
3. `CMS/admin/settings.php`
4. `CMS/admin/design-settings.php`
5. `CMS/admin/groups.php`
6. `CMS/admin/data-requests.php`

### Schritt 171 — 25.03.2026 — 404-Monitor-Entry bei Dispatch- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **171 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/not-found-monitor.php`

**Ergebnis dieses Schritts**

- Der 404-Monitor-Entry bündelt seine POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben PRG-Pfad.
  - Action-Handler bleiben über eine kleine Entry-Allowlist besser sichtbar zusammengezogen.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/not-found-monitor.php`: Security/Speed/BP verbessert
- `Admin – SEO/Redirects`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/comments.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/languages.php`
4. `CMS/admin/settings.php`
5. `CMS/admin/design-settings.php`
6. `CMS/admin/groups.php`

### Schritt 170 — 25.03.2026 — Menü-Editor-Entry bei Dispatch- und Redirect-Helfern weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **170 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/menu-editor.php`

**Ergebnis dieses Schritts**

- Der Menü-Editor-Entry bündelt seine POST-Dispatch-, Flash- und Redirect-Pfade jetzt klarer über kleine Helfer:
  - Action-Handler bleiben als kleine Entry-Allowlist sichtbar zusammengezogen.
  - Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben PRG-Pfad.
  - der Einstieg bleibt dadurch näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/menu-editor.php`: Security/Speed/BP verbessert
- `Admin – Menüs`: Entry-Dispatch und Fehlerpfade kompakter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/not-found-monitor.php`
2. `CMS/admin/comments.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/languages.php`
5. `CMS/admin/settings.php`
6. `CMS/admin/design-settings.php`

### Schritt 169 — 25.03.2026 — Diagnose-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **169 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/diagnose.php`

**Ergebnis dieses Schritts**

- Der Diagnose-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/diagnose.php`: Security/Speed/BP verbessert
- `Admin – System/Monitoring`: Wrapper-Familie weiter konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/menu-editor.php`
3. `CMS/admin/not-found-monitor.php`
4. `CMS/admin/comments.php`
5. `CMS/admin/languages.php`
6. `CMS/admin/settings.php`

### Schritt 168 — 25.03.2026 — Performance-Overview-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **168 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance.php`

**Ergebnis dieses Schritts**

- Der Performance-Overview-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `performance-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Wrapper-Familie weiter konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/diagnose.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/menu-editor.php`
4. `CMS/admin/not-found-monitor.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/languages.php`

### Schritt 167 — 25.03.2026 — SEO-Schema-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **167 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-schema.php`

**Ergebnis dieses Schritts**

- Der SEO-Schema-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-schema.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Familie weiter konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/menu-editor.php`
3. `CMS/admin/not-found-monitor.php`
4. `CMS/admin/performance.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/language-editor.php`

### Schritt 166 — 25.03.2026 — SEO-Sitemap-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **166 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-sitemap.php`

**Ergebnis dieses Schritts**

- Der SEO-Sitemap-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-sitemap.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-schema.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/menu-editor.php`
4. `CMS/admin/not-found-monitor.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/comments.php`

### Schritt 165 — 25.03.2026 — SEO-Technical-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **165 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-technical.php`

**Ergebnis dieses Schritts**

- Der SEO-Technical-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-technical.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-sitemap.php`
2. `CMS/admin/seo-schema.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/not-found-monitor.php`
6. `CMS/admin/performance.php`

### Schritt 164 — 25.03.2026 — SEO-Audit-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **164 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-audit.php`

**Ergebnis dieses Schritts**

- Der SEO-Audit-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-audit.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-technical.php`
2. `CMS/admin/seo-sitemap.php`
3. `CMS/admin/seo-schema.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/not-found-monitor.php`

### Schritt 163 — 25.03.2026 — SEO-Page-Konfigurationspfad weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **163 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-page.php`

**Ergebnis dieses Schritts**

- Das gemeinsame SEO-Seitengerüst normalisiert seine Seitenkonfiguration jetzt über einen kleinen Helper:
  - Section-, Route-, View-, Titel- und Active-Page-Fallbacks werden nicht mehr lose direkt im Einstieg verteilt.
  - Wrapper-Konfigurationen und Default-Werte laufen jetzt sichtbar über denselben kleinen Normalisierungspfad.
  - die Shell bleibt dadurch näher an ihrem eigentlichen Seitenvertrag statt verteilte Fallback-Logik mitzuschleppen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-page.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Shell-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-audit.php`
2. `CMS/admin/seo-technical.php`
3. `CMS/admin/seo-sitemap.php`
4. `CMS/admin/seo-schema.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/menu-editor.php`

### Schritt 162 — 25.03.2026 — SEO-Social-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **162 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-social.php`

**Ergebnis dieses Schritts**

- Der SEO-Social-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-social.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-page.php`
2. `CMS/admin/seo-audit.php`
3. `CMS/admin/seo-technical.php`
4. `CMS/admin/seo-sitemap.php`
5. `CMS/admin/seo-schema.php`
6. `CMS/admin/mail-settings.php`

### Schritt 161 — 25.03.2026 — SEO-Meta-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **161 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-meta.php`

**Ergebnis dieses Schritts**

- Der SEO-Meta-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-meta.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-social.php`
2. `CMS/admin/seo-page.php`
3. `CMS/admin/seo-audit.php`
4. `CMS/admin/seo-technical.php`
5. `CMS/admin/seo-sitemap.php`
6. `CMS/admin/seo-schema.php`

### Schritt 160 — 25.03.2026 — SEO-Dashboard-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **160 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/seo-dashboard.php`

**Ergebnis dieses Schritts**

- Der SEO-Dashboard-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/seo-dashboard.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-meta.php`
2. `CMS/admin/seo-social.php`
3. `CMS/admin/seo-page.php`
4. `CMS/admin/seo-audit.php`
5. `CMS/admin/seo-technical.php`
6. `CMS/admin/seo-sitemap.php`

### Schritt 159 — 25.03.2026 — Analytics-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **159 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/analytics.php`

**Ergebnis dieses Schritts**

- Der Analytics-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `seo-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/analytics.php`: Security/Speed/BP verbessert
- `Admin – SEO`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/seo-dashboard.php`
2. `CMS/admin/seo-meta.php`
3. `CMS/admin/seo-social.php`
4. `CMS/admin/seo-page.php`
5. `CMS/admin/seo-audit.php`
6. `CMS/admin/seo-technical.php`

### Schritt 158 — 25.03.2026 — Info-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **158 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/info.php`

**Ergebnis dieses Schritts**

- Der Info-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/info.php`: Security/Speed/BP verbessert
- `Admin – System/Monitoring`: Wrapper-Familie vollständig kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/menu-editor.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/not-found-monitor.php`
5. `CMS/admin/performance.php`
6. `CMS/admin/comments.php`

### Schritt 157 — 25.03.2026 — Monitor-Email-Alerts-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **157 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-email-alerts.php`

**Ergebnis dieses Schritts**

- Der Monitor-Email-Alerts-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-email-alerts.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/info.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/menu-editor.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/not-found-monitor.php`
6. `CMS/admin/performance.php`

### Schritt 156 — 25.03.2026 — Monitor-Health-Check-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **156 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-health-check.php`

**Ergebnis dieses Schritts**

- Der Monitor-Health-Check-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-health-check.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-email-alerts.php`
2. `CMS/admin/info.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/analytics.php`
6. `CMS/admin/not-found-monitor.php`

### Schritt 155 — 25.03.2026 — Monitor-Cron-Status-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **155 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-cron-status.php`

**Ergebnis dieses Schritts**

- Der Monitor-Cron-Status-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-cron-status.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-health-check.php`
2. `CMS/admin/monitor-email-alerts.php`
3. `CMS/admin/info.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/analytics.php`

### Schritt 154 — 25.03.2026 — System-Monitor-Page-Konfigurationspfad weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **154 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/system-monitor-page.php`

**Ergebnis dieses Schritts**

- Das gemeinsame System-Monitor-Seitengerüst normalisiert seine Seitenkonfiguration jetzt über einen kleinen Helper:
  - Section-, Route-, View-, Titel-, Active-Page- und Asset-Fallbacks werden nicht mehr lose direkt im Einstieg verteilt.
  - Wrapper-Konfigurationen und Default-Werte laufen jetzt sichtbar über denselben kleinen Normalisierungspfad.
  - die Shell bleibt dadurch näher an ihrem eigentlichen Seitenvertrag statt verteilte Fallback-Logik mitzuschleppen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/system-monitor-page.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Shell-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-cron-status.php`
2. `CMS/admin/monitor-health-check.php`
3. `CMS/admin/monitor-email-alerts.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/info.php`

### Schritt 153 — 25.03.2026 — Monitor-Scheduled-Tasks-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **153 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-scheduled-tasks.php`

**Ergebnis dieses Schritts**

- Der Monitor-Scheduled-Tasks-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-scheduled-tasks.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/system-monitor-page.php`
2. `CMS/admin/monitor-cron-status.php`
3. `CMS/admin/monitor-health-check.php`
4. `CMS/admin/monitor-email-alerts.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/menu-editor.php`

### Schritt 152 — 25.03.2026 — Monitor-Disk-Usage-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **152 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-disk-usage.php`

**Ergebnis dieses Schritts**

- Der Monitor-Disk-Usage-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-disk-usage.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-scheduled-tasks.php`
2. `CMS/admin/system-monitor-page.php`
3. `CMS/admin/monitor-cron-status.php`
4. `CMS/admin/monitor-health-check.php`
5. `CMS/admin/monitor-email-alerts.php`
6. `CMS/admin/mail-settings.php`

### Schritt 151 — 25.03.2026 — Monitor-Response-Time-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **151 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/monitor-response-time.php`

**Ergebnis dieses Schritts**

- Der Monitor-Response-Time-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `system-monitor-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/monitor-response-time.php`: Security/Speed/BP verbessert
- `Admin – Monitoring`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/monitor-disk-usage.php`
2. `CMS/admin/monitor-scheduled-tasks.php`
3. `CMS/admin/system-monitor-page.php`
4. `CMS/admin/monitor-cron-status.php`
5. `CMS/admin/monitor-health-check.php`
6. `CMS/admin/monitor-email-alerts.php`

### Schritt 150 — 25.03.2026 — Performance-Sessions-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **150 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-sessions.php`

**Ergebnis dieses Schritts**

- Der Performance-Sessions-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `performance-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-sessions.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Wrapper-Familie vollständig kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/menu-editor.php`
3. `CMS/admin/system-monitor-page.php`
4. `CMS/admin/monitor-response-time.php`
5. `CMS/admin/monitor-disk-usage.php`
6. `CMS/admin/monitor-scheduled-tasks.php`

### Schritt 149 — 25.03.2026 — Performance-Page-Konfigurationspfad weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **149 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-page.php`

**Ergebnis dieses Schritts**

- Das gemeinsame Performance-Seitengerüst normalisiert seine Seitenkonfiguration jetzt über einen kleinen Helper:
  - Section-, Route-, View-, Titel-, Active-Page- und Asset-Fallbacks werden nicht mehr lose direkt im Einstieg verteilt.
  - Wrapper-Konfigurationen und Default-Werte laufen jetzt sichtbar über denselben kleinen Normalisierungspfad.
  - die Shell bleibt dadurch näher an ihrem eigentlichen Seitenvertrag statt verteilte Fallback-Logik mitzuschleppen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-page.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Shell-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-sessions.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/menu-editor.php`
4. `CMS/admin/system-monitor-page.php`
5. `CMS/admin/monitor-response-time.php`
6. `CMS/admin/monitor-disk-usage.php`

### Schritt 148 — 25.03.2026 — Performance-Settings-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **148 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-settings.php`

**Ergebnis dieses Schritts**

- Der Performance-Settings-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `performance-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-settings.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-page.php`
2. `CMS/admin/performance-sessions.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/system-monitor-page.php`
6. `CMS/admin/monitor-response-time.php`

### Schritt 147 — 25.03.2026 — Performance-Media-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **147 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-media.php`

**Ergebnis dieses Schritts**

- Der Performance-Media-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `performance-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-media.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-settings.php`
2. `CMS/admin/performance-page.php`
3. `CMS/admin/performance-sessions.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/system-monitor-page.php`

### Schritt 146 — 25.03.2026 — Performance-Database-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **146 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-database.php`

**Ergebnis dieses Schritts**

- Der Performance-Database-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `performance-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-database.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-media.php`
2. `CMS/admin/performance-settings.php`
3. `CMS/admin/performance-page.php`
4. `CMS/admin/performance-sessions.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/menu-editor.php`

### Schritt 145 — 25.03.2026 — Performance-Cache-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **145 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/performance-cache.php`

**Ergebnis dieses Schritts**

- Der Performance-Cache-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `performance-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/performance-cache.php`: Security/Speed/BP verbessert
- `Admin – Performance`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/performance-database.php`
2. `CMS/admin/performance-media.php`
3. `CMS/admin/performance-settings.php`
4. `CMS/admin/performance-page.php`
5. `CMS/admin/performance-sessions.php`
6. `CMS/admin/mail-settings.php`

### Schritt 144 — 25.03.2026 — Member-Dashboard-Page-Konfigurationspfad weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **144 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-page.php`

**Ergebnis dieses Schritts**

- Das gemeinsame Member-Dashboard-Seitengerüst normalisiert seine Seitenkonfiguration jetzt über einen kleinen Helper:
  - Section-, Route-, View-, Titel-, Active-Page- und Asset-Fallbacks werden nicht mehr lose direkt im Einstieg verteilt.
  - Wrapper-Konfigurationen und Default-Werte laufen jetzt sichtbar über denselben kleinen Normalisierungspfad.
  - die Shell bleibt dadurch näher an ihrem eigentlichen Seitenvertrag statt verteilte Fallback-Logik mitzuschleppen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-page.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Shell-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/menu-editor.php`
3. `CMS/admin/performance-cache.php`
4. `CMS/admin/performance-database.php`
5. `CMS/admin/performance-media.php`
6. `CMS/admin/performance-page.php`

### Schritt 143 — 25.03.2026 — Member-Dashboard-Widgets-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **143 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-widgets.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Widgets-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-widgets.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-page.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/menu-editor.php`
4. `CMS/admin/performance-cache.php`
5. `CMS/admin/performance-database.php`
6. `CMS/admin/performance-media.php`

### Schritt 142 — 25.03.2026 — Member-Dashboard-Profile-Fields-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **142 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-profile-fields.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Profile-Fields-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-profile-fields.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-widgets.php`
2. `CMS/admin/member-dashboard-page.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/menu-editor.php`
5. `CMS/admin/performance-cache.php`
6. `CMS/admin/performance-database.php`

### Schritt 141 — 25.03.2026 — Member-Dashboard-Plugin-Widgets-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **141 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-plugin-widgets.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Plugin-Widgets-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-plugin-widgets.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-profile-fields.php`
2. `CMS/admin/member-dashboard-widgets.php`
3. `CMS/admin/member-dashboard-page.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/menu-editor.php`
6. `CMS/admin/performance-cache.php`

### Schritt 140 — 25.03.2026 — Member-Dashboard-Onboarding-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **140 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-onboarding.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Onboarding-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-onboarding.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-plugin-widgets.php`
2. `CMS/admin/member-dashboard-profile-fields.php`
3. `CMS/admin/member-dashboard-widgets.php`
4. `CMS/admin/member-dashboard-page.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/menu-editor.php`

### Schritt 139 — 25.03.2026 — Member-Dashboard-Notifications-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **139 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-notifications.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Notifications-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-notifications.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-onboarding.php`
2. `CMS/admin/member-dashboard-plugin-widgets.php`
3. `CMS/admin/member-dashboard-profile-fields.php`
4. `CMS/admin/member-dashboard-widgets.php`
5. `CMS/admin/member-dashboard-page.php`
6. `CMS/admin/mail-settings.php`

### Schritt 138 — 25.03.2026 — Member-Dashboard-General-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **138 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-general.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-General-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-general.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-notifications.php`
2. `CMS/admin/member-dashboard-onboarding.php`
3. `CMS/admin/member-dashboard-plugin-widgets.php`
4. `CMS/admin/member-dashboard-profile-fields.php`
5. `CMS/admin/member-dashboard-widgets.php`
6. `CMS/admin/member-dashboard-page.php`

### Schritt 137 — 25.03.2026 — Member-Dashboard-Frontend-Modules-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **137 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-frontend-modules.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Frontend-Modules-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-frontend-modules.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-general.php`
2. `CMS/admin/member-dashboard-notifications.php`
3. `CMS/admin/member-dashboard-onboarding.php`
4. `CMS/admin/member-dashboard-plugin-widgets.php`
5. `CMS/admin/member-dashboard-profile-fields.php`
6. `CMS/admin/member-dashboard-widgets.php`

### Schritt 136 — 25.03.2026 — Member-Dashboard-Design-Entry auf Konfigurations-Wrapper weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **136 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard-design.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Design-Entry übergibt seine Seitenmetadaten jetzt über einen kleinen Konfigurations-Wrapper:
  - Section-, Route- und View-Metadaten werden nicht mehr als loses Variablenset im Wrapper verteilt.
  - `member-dashboard-page.php` kann dieselbe Konfigurationsstruktur jetzt direkt konsumieren und bleibt dabei rückwärtskompatibel.
  - der Entry bleibt dadurch näher an seinem eigentlichen Seitenzweck statt wiederholt dieselbe Wrapper-Struktur auszuschreiben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard-design.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Wrapper-Metadaten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-frontend-modules.php`
2. `CMS/admin/member-dashboard-general.php`
3. `CMS/admin/member-dashboard-notifications.php`
4. `CMS/admin/member-dashboard-onboarding.php`
5. `CMS/admin/member-dashboard-plugin-widgets.php`
6. `CMS/admin/member-dashboard-profile-fields.php`

### Schritt 135 — 25.03.2026 — Member-Dashboard-Entry als Redirect-Alias weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **135 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/member-dashboard.php`

**Ergebnis dieses Schritts**

- Der Member-Dashboard-Entry nutzt seinen Alias-Redirect jetzt über einen kleinen lokalen Helper:
  - Legacy-Section-Weiterleitungen laufen nicht mehr über rohe Header-/Exit-Aufrufe direkt im Entry.
  - der Admin-Guard folgt jetzt demselben kompakten Redirect-Muster wie andere Alias-Entrys.
  - der Entry bleibt dadurch näher am tatsächlichen Redirect-Zweck statt an verstreuter Header-Orchestrierung.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/member-dashboard.php`: Security/Speed/BP verbessert
- `Admin – Member Dashboard`: Alias-Entry kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard-design.php`
2. `CMS/admin/member-dashboard-frontend-modules.php`
3. `CMS/admin/member-dashboard-general.php`
4. `CMS/admin/member-dashboard-notifications.php`
5. `CMS/admin/member-dashboard-onboarding.php`
6. `CMS/admin/member-dashboard-page.php`

### Schritt 134 — 25.03.2026 — Media-Entry Redirect- und Alert-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **134 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/media.php`

**Ergebnis dieses Schritts**

- Der Media-Entry bündelt wiederkehrende Redirect- und Session-Alert-Orchestrierung jetzt über kleine lokale Helper:
  - Library-, Category- und Settings-Aktionen bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Upload-, Folder-, Rename-, Delete- und Kategorie-Pfade nutzen jetzt denselben kompakten Abschlussrahmen statt verteilter Inline-Abgänge.
  - der Entry bleibt dadurch näher an Token-Prüfung und Aktionsresultaten statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Security/Speed/BP verbessert
- `Admin – Media`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/member-dashboard.php`
2. `CMS/admin/member-dashboard-design.php`
3. `CMS/admin/member-dashboard-frontend-modules.php`
4. `CMS/admin/member-dashboard-general.php`
5. `CMS/admin/member-dashboard-notifications.php`
6. `CMS/admin/member-dashboard-onboarding.php`

### Schritt 133 — 25.03.2026 — Legal-Sites-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **133 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/legal-sites.php`

**Ergebnis dieses Schritts**

- Der Legal-Sites-Entry bündelt wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung jetzt über kleine lokale Helper:
  - Save-, Profile-, Generate- und Page-Aktionen bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Save-Profile-spezifische Session-Behandlung bleibt erhalten, hängt aber jetzt an kompakterem Abschlussrahmen.
  - der Entry bleibt dadurch näher an Token-Prüfung und Aktionsauflösung statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/legal-sites.php`: Security/Speed/BP verbessert
- `Admin – Legal/Legal Sites`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/media.php`
3. `CMS/admin/member-dashboard.php`
4. `CMS/admin/member-dashboard-design.php`
5. `CMS/admin/member-dashboard-frontend-modules.php`
6. `CMS/admin/member-dashboard-general.php`

### Schritt 132 — 25.03.2026 — Landing-Page-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **132 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/landing-page.php`

**Ergebnis dieses Schritts**

- Der Landing-Page-Entry bündelt wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung jetzt über kleine lokale Helper:
  - tabbezogene Save- und Delete-Aktionen bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - die Tab-Weiterleitung folgt jetzt demselben kompakten Muster statt verteilter Inline-Orchestrierung.
  - der Entry bleibt dadurch näher an Token-Prüfung und Action-Handling statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/landing-page.php`: Security/Speed/BP verbessert
- `Admin – Landing Page`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/legal-sites.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/media.php`
4. `CMS/admin/member-dashboard.php`
5. `CMS/admin/member-dashboard-design.php`
6. `CMS/admin/member-dashboard-frontend-modules.php`

### Schritt 131 — 25.03.2026 — Hub-Sites-Entry Redirect- und Alert-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **131 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/hub-sites.php`

**Ergebnis dieses Schritts**

- Der Hub-Sites-Entry bündelt wiederkehrende Redirect- und Session-Alert-Orchestrierung jetzt über kleine lokale Helper:
  - Save-, Template-, Duplicate- und Delete-Aktionen bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - resultatspezifische Weiterleitungen für Edit-, Template- und Public-Open-Pfade folgen jetzt demselben kompakten Muster.
  - der Entry bleibt dadurch näher an Token-Prüfung und Result-Verarbeitung statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/hub-sites.php`: Security/Speed/BP verbessert
- `Admin – Hub Sites`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/landing-page.php`
2. `CMS/admin/legal-sites.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/media.php`
5. `CMS/admin/member-dashboard.php`
6. `CMS/admin/member-dashboard-design.php`

### Schritt 130 — 25.03.2026 — Groups-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **130 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/groups.php`

**Ergebnis dieses Schritts**

- Der Groups-Entry bündelt wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung jetzt über kleine lokale Helper:
  - Save- und Delete-Aktionen bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - der Gruppen-Entry nutzt für beide Aktionen denselben kompakten Abschlussrahmen statt verteilter Inline-Abgänge.
  - der Entry bleibt dadurch näher an Token-Prüfung und Aktionsauflösung statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/groups.php`: Security/Speed/BP verbessert
- `Admin – Users/Gruppen`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/hub-sites.php`
2. `CMS/admin/landing-page.php`
3. `CMS/admin/legal-sites.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/media.php`
6. `CMS/admin/member-dashboard.php`

### Schritt 129 — 25.03.2026 — Firewall-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **129 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/firewall.php`

**Ergebnis dieses Schritts**

- Der Firewall-Entry bündelt wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung jetzt über kleine lokale Helper:
  - Save-, Add-, Delete- und Toggle-Aktionen bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Supported-Action- und Token-Prüfung laufen jetzt mit demselben kompakten Abschlussrahmen statt verteilter Inline-Orchestrierung.
  - der Entry bleibt dadurch näher an Guard- und Result-Verarbeitung statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/firewall.php`: Security/Speed/BP verbessert
- `Admin – Security/Firewall`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/groups.php`
2. `CMS/admin/hub-sites.php`
3. `CMS/admin/landing-page.php`
4. `CMS/admin/legal-sites.php`
5. `CMS/admin/mail-settings.php`
6. `CMS/admin/media.php`

### Schritt 128 — 25.03.2026 — Error-Report-Entry Redirect- und Alert-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **128 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/error-report.php`

**Ergebnis dieses Schritts**

- Der Error-Report-Entry bündelt wiederkehrende Redirect- und Session-Alert-Orchestrierung jetzt über kleine lokale Helper:
  - GET-Abgang, ungültige CSRF-Tokens und verarbeitete Report-Resultate bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Report-Erstellung bleibt beim bestehenden Service-Aufruf, aber mit kompakterem Abschlussrahmen.
  - der Entry bleibt dadurch näher an Redirect-Normalisierung und Report-Erstellung statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/error-report.php`: Security/Speed/BP verbessert
- `Admin – System/Error Report`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/firewall.php`
2. `CMS/admin/groups.php`
3. `CMS/admin/hub-sites.php`
4. `CMS/admin/landing-page.php`
5. `CMS/admin/legal-sites.php`
6. `CMS/admin/mail-settings.php`

### Schritt 127 — 25.03.2026 — Documentation-Entry Alert- und Redirect-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **127 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Der Documentation-Entry bündelt wiederkehrende Alert-Speicherung und Redirect-Auflösung jetzt über kleine lokale Helper:
  - Doku-Sync-Aktionen bauen Alert-Payloads nicht mehr mehrfach direkt im POST-Flow auf.
  - Redirects mit optionalem `doc`-Parameter folgen jetzt demselben kompakten Muster statt verteilter Inline-Orchestrierung.
  - der Entry bleibt dadurch näher an Token-Prüfung und Action-Dispatch statt an wiederholtem Alert- und Redirect-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/error-report.php`
2. `CMS/admin/firewall.php`
3. `CMS/admin/groups.php`
4. `CMS/admin/hub-sites.php`
5. `CMS/admin/landing-page.php`
6. `CMS/admin/legal-sites.php`

### Schritt 126 — 25.03.2026 — Backups-Entry Redirect- und Alert-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **126 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/backups.php`

**Ergebnis dieses Schritts**

- Der Backups-Entry bündelt wiederkehrende Redirect- und Session-Alert-Orchestrierung jetzt über kleine lokale Helper:
  - ungültige CSRF-Tokens und verarbeitete Backup-Resultate bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Full-, DB- und Delete-Aktionen bleiben beim bestehenden Handler-Muster, aber mit kompakterem Abschlussrahmen.
  - der Entry bleibt dadurch näher an Token-Prüfung und Action-Handler statt an wiederholtem Redirect- und Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/backups.php`: Security/Speed/BP verbessert
- `Admin – System/Backups`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/documentation.php`
2. `CMS/admin/error-report.php`
3. `CMS/admin/firewall.php`
4. `CMS/admin/groups.php`
5. `CMS/admin/hub-sites.php`
6. `CMS/admin/landing-page.php`

### Schritt 125 — 25.03.2026 — AntiSpam-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **125 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/antispam.php`

**Ergebnis dieses Schritts**

- Der AntiSpam-Entry bündelt wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung jetzt über kleine lokale Helper:
  - ungültige CSRF-Tokens, unbekannte Aktionen und verarbeitete Resultate bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Settings- und Blacklist-Aktionen laufen nicht mehr als gewachsener Inline-Switch mit wiederholter Abschluss-Orchestrierung.
  - der Entry bleibt dadurch näher an Token-/Allowlist-Prüfung statt an wiederholtem Redirect-, Alert- und Dispatch-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/antispam.php`: Security/Speed/BP verbessert
- `Admin – Security/AntiSpam`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/backups.php`
2. `CMS/admin/documentation.php`
3. `CMS/admin/error-report.php`
4. `CMS/admin/firewall.php`
5. `CMS/admin/groups.php`
6. `CMS/admin/hub-sites.php`

### Schritt 124 — 25.03.2026 — 404-Monitor-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **124 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/not-found-monitor.php`

**Ergebnis dieses Schritts**

- Der 404-Monitor-Entry bündelt wiederkehrende Redirect-, Session-Alert-, Details- und Aktionsauflösung jetzt über kleine lokale Helper:
  - Redirect-Speicherung und Log-Bereinigung bauen Alert-Payloads nicht mehr mehrfach direkt im POST-Flow auf.
  - Redirect-Weiterleitung und Resultatdetails folgen jetzt demselben kompakten Muster statt verteilter Inline-Orchestrierung.
  - der Entry bleibt dadurch näher an Token-Prüfung und Result-Verarbeitung statt an wiederholtem Redirect-, Alert- und Dispatch-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/not-found-monitor.php`: Security/Speed/BP verbessert
- `Admin – SEO/404-Monitor`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/antispam.php`
2. `CMS/admin/backups.php`
3. `CMS/admin/documentation.php`
4. `CMS/admin/error-report.php`
5. `CMS/admin/firewall.php`
6. `CMS/admin/groups.php`

### Schritt 123 — 25.03.2026 — Design-Settings-Entry als Redirect-Alias weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **123 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/design-settings.php`

**Ergebnis dieses Schritts**

- Der Design-Settings-Entry nutzt seinen Alias-Redirect jetzt über einen kleinen lokalen Helper:
  - der Admin-Guard leitet nicht mehr über rohe Header-/Exit-Aufrufe direkt im Entry um.
  - die Weiterleitung zum Theme-Editor folgt jetzt demselben kompakten Muster wie andere Alias-Entrys.
  - der Entry bleibt dadurch näher am tatsächlichen Redirect-Zweck statt an verstreuter Header-Orchestrierung.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/design-settings.php`: Security/Speed/BP verbessert
- `Admin – Themes/Design`: Alias-Entry kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/not-found-monitor.php`
2. `CMS/admin/analytics.php`
3. `CMS/admin/antispam.php`
4. `CMS/admin/backups.php`
5. `CMS/admin/documentation.php`
6. `CMS/admin/error-report.php`

### Schritt 122 — 25.03.2026 — Deletion-Requests-Entry auf Redirect-Alias weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **122 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/deletion-requests.php`

**Ergebnis dieses Schritts**

- Der Deletion-Requests-Entry bildet jetzt nur noch seinen tatsächlichen Redirect-Zweck ab:
  - der Admin-Entry leitet nach dem Guard direkt zur Sammelansicht `data-requests` weiter.
  - unerreichbarer Modul-, POST- und View-Altcode hinter dem sofortigen Redirect wurde entfernt.
  - der Entry bleibt dadurch näher am realen Alias-Verhalten statt an nie ausgeführter DSGVO-Orchestrierung.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/deletion-requests.php`: Security/Speed/BP verbessert
- `Admin – Legal/Deletion Requests`: Alias-Entry kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/design-settings.php`
2. `CMS/admin/not-found-monitor.php`
3. `CMS/admin/analytics.php`
4. `CMS/admin/antispam.php`
5. `CMS/admin/backups.php`
6. `CMS/admin/documentation.php`

### Schritt 121 — 25.03.2026 — Data-Requests-Entry Alert- und Scope-Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **121 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/data-requests.php`

**Ergebnis dieses Schritts**

- Der Data-Requests-Entry bündelt wiederkehrende Alert-Normalisierung und Scope-Aktionsauflösung jetzt über kleine lokale Helper:
  - Auskunfts- und Löschanträge bauen gemeinsame Alert-Struktur nicht mehr mehrfach separat auf.
  - Privacy- und Deletion-Scope laufen nicht mehr als gewachsene Inline-Matches direkt im POST-Block zusammen.
  - der Entry bleibt dadurch näher an Token-/Scope-Prüfung statt an wiederholtem Alert- und Dispatch-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/data-requests.php`: Security/Speed/BP verbessert
- `Admin – Legal/Data Requests`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/deletion-requests.php`
2. `CMS/admin/design-settings.php`
3. `CMS/admin/not-found-monitor.php`
4. `CMS/admin/analytics.php`
5. `CMS/admin/antispam.php`
6. `CMS/admin/backups.php`

### Schritt 120 — 25.03.2026 — Cookie-Manager-Entry Redirect-, Alert- und Dispatch-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **120 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/cookie-manager.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager-Entry bündelt wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung jetzt über kleine lokale Helper:
  - ungültige CSRF-Tokens, unbekannte Aktionen und verarbeitete Cookie-Resultate bauen Redirects und Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Save-, Delete-, Import- und Scan-Aktionen laufen nicht mehr als gewachsener Inline-Switch mit wiederholter Abschluss-Orchestrierung.
  - der Entry bleibt dadurch näher an Token-/Allowlist-Prüfung statt an wiederholtem Redirect-, Alert- und Dispatch-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/cookie-manager.php`: Security/Speed/BP verbessert
- `Admin – Legal/Cookies`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/data-requests.php`
2. `CMS/admin/deletion-requests.php`
3. `CMS/admin/design-settings.php`
4. `CMS/admin/not-found-monitor.php`
5. `CMS/admin/analytics.php`
6. `CMS/admin/antispam.php`

### Schritt 119 — 25.03.2026 — Comments-Entry Session-Alert-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **119 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/comments.php`

**Ergebnis dieses Schritts**

- Der Comments-Entry bündelt wiederkehrenden Session-Alert-Aufbau jetzt über kleine lokale Helper:
  - unbekannte Aktionen, ungültige CSRF-Tokens und verarbeitete Kommentar-Resultate bauen Session-Alerts nicht mehr mehrfach direkt im POST-Flow auf.
  - Änderungen an gemeinsamer Alert-Struktur bleiben dadurch an einer Stelle pflegbar.
  - der Entry bleibt dadurch näher an Aktionsverarbeitung und Redirect-Flow statt an wiederholtem Session-Alert-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/comments.php`: Security/Speed/BP verbessert
- `Admin – Comments`: POST-Alerts kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/cookie-manager.php`
2. `CMS/admin/data-requests.php`
3. `CMS/admin/deletion-requests.php`
4. `CMS/admin/design-settings.php`
5. `CMS/admin/not-found-monitor.php`
6. `CMS/admin/analytics.php`

### Schritt 118 — 25.03.2026 — Packages-Entry Flash- und Redirect-Pfade weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **118 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/packages.php`

**Ergebnis dieses Schritts**

- Der Packages-Entry bündelt wiederkehrende Flash- und Redirect-Orchestrierung jetzt über kleine lokale Helper:
  - Save-, Seed-, Delete-, Toggle- und Package-Settings-Aktionen bauen Session-Alerts und Redirect-Abgänge nicht mehr mehrfach direkt im POST-Flow auf.
  - Änderungen an gemeinsamer Alert-/Redirect-Logik bleiben dadurch an einer Stelle pflegbar.
  - der Entry bleibt dadurch näher an Token-/Action-Handling statt an wiederholtem Session- und Redirect-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/packages.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: POST-Flow kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/comments.php`
2. `CMS/admin/cookie-manager.php`
3. `CMS/admin/data-requests.php`
4. `CMS/admin/deletion-requests.php`
5. `CMS/admin/design-settings.php`
6. `CMS/admin/not-found-monitor.php`

### Schritt 117 — 25.03.2026 — Orders-Entry Aktionsauflösung weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **117 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Der Orders-Entry bündelt wiederkehrende Aktionsauflösung jetzt über einen kleinen lokalen Dispatch-Helper:
  - `assign_subscription`, `update_status` und `delete` werden nicht mehr als gestaffelter Inline-Block mit wiederholter Result-Orchestrierung aufgelöst.
  - Änderungen an gemeinsamer Aktionsverdrahtung bleiben dadurch an einer Stelle pflegbar.
  - der Entry bleibt dadurch näher an Token-/Allowlist-Prüfung und Redirect-Flow statt an wiederholter Aktionsverzweigung.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: POST-Dispatch kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/packages.php`
2. `CMS/admin/comments.php`
3. `CMS/admin/cookie-manager.php`
4. `CMS/admin/data-requests.php`
5. `CMS/admin/deletion-requests.php`
6. `CMS/admin/design-settings.php`

### Schritt 116 — 25.03.2026 — Mail-Settings-View Button-Aktionen weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **116 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View bündelt wiederkehrende Submit-Button-Strukturen jetzt über einen kleinen lokalen Renderer:
  - Transport-, Azure-, Graph-, Log- und Queue-Bereiche nutzen dieselbe kompakte Button-Logik statt mehrfacher identischer Submit-Blöcke mit `name="action"`.
  - Änderungen an gemeinsamer Button-Struktur bleiben dadurch an einer Stelle pflegbar.
  - die View bleibt dadurch näher an ihren eigentlichen Feldgruppen und Aktionen statt an wiederholtem Button-Markup.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Aktionsbuttons kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/orders.php`
2. `CMS/admin/packages.php`
3. `CMS/admin/comments.php`
4. `CMS/admin/cookie-manager.php`
5. `CMS/admin/data-requests.php`
6. `CMS/admin/deletion-requests.php`

### Schritt 115 — 25.03.2026 — Dokumentations-View Listen weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **115 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View bündelt wiederkehrende Dokumentlisten jetzt über einen kleinen lokalen Renderer:
  - Schnellstart- und Bereichslisten nutzen dieselbe kompakte Listenlogik statt identischer `foreach`-Blöcke mit wiederholtem `is_array`-Guard.
  - Änderungen an gemeinsamer Listeniteration bleiben dadurch an einer Stelle pflegbar.
  - die View bleibt dadurch näher an ihrer Karten- und Bereichsstruktur statt an wiederholter Listenorchestrierung.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Dokumentlisten kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
2. `CMS/admin/orders.php`
3. `CMS/admin/packages.php`
4. `CMS/admin/comments.php`
5. `CMS/admin/cookie-manager.php`
6. `CMS/admin/data-requests.php`

### Schritt 114 — 25.03.2026 — Dokumentations-Sync-Service Abschluss-Kontexte weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **114 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Sync-Service bündelt wiederkehrende Sync-Ausführungs-Kontexte jetzt über einen kleinen lokalen Helper:
  - Erfolgs- und Fehlerpfade nutzen denselben kompakten Kontext für `mode` und `capabilities` statt diese Metadaten mehrfach separat aufzubauen.
  - Änderungen an gemeinsamen Abschluss-Metadaten bleiben dadurch an einer Stelle pflegbar.
  - der Service bleibt dadurch näher an Result-Verarbeitung und Dispatch statt an wiederholtem Kontextaufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Sync-Abschluss-Logging kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
3. `CMS/admin/orders.php`
4. `CMS/admin/packages.php`
5. `CMS/admin/comments.php`
6. `CMS/admin/cookie-manager.php`

### Schritt 113 — 25.03.2026 — Dokumentations-Downloader Log-Kontexte weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **113 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Downloader bündelt wiederkehrende URL-bezogene Download-Log-Kontexte jetzt über einen kleinen lokalen Helper:
  - Response-, Validierungs- und Erfolgslogs nutzen denselben kompakten URL-Kontext statt URL-Metadaten mehrfach separat aufzubauen.
  - Änderungen an URL-bezogenen Logging-Feldern bleiben dadurch an einer Stelle pflegbar.
  - der Downloader bleibt dadurch näher an Download-, Persistenz- und Validierungslogik statt an wiederholtem Kontextaufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Downloader-Logs kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php`
2. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
4. `CMS/admin/orders.php`
5. `CMS/admin/packages.php`
6. `CMS/admin/comments.php`

### Schritt 112 — 25.03.2026 — Subscription-Settings-Modul Save-Orchestrierung weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **112 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`

**Ergebnis dieses Schritts**

- Das Subscription-Settings-Modul bündelt wiederkehrende Save-Orchestrierung jetzt über kleine lokale Helfer:
  - beide Speicherpfade nutzen denselben kleinen Guard für Admin-Zugriff statt dieselbe Failure-Antwort doppelt zu tragen.
  - Persistierung, Audit-Logging und Success-Resultat laufen über einen gemeinsamen Helper statt in beiden Save-Methoden separat orchestriert zu werden.
  - das Modul bleibt dadurch näher an seinen eigentlichen Settings-Payloads statt an wiederholtem Erfolgsablauf.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: Settings-Speicherpfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
2. `CMS/admin/modules/system/DocumentationSyncService.php`
3. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
5. `CMS/admin/orders.php`
6. `CMS/admin/packages.php`

### Schritt 111 — 25.03.2026 — Orders-View Formular-Kontexte weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **111 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-View bündelt wiederkehrende Formular-Kontexte jetzt über einen kleinen lokalen Renderer:
  - Hidden-Felder für `csrf_token`, `action` und optionale `id`-Werte werden nicht mehr mehrfach direkt in Statuswechsel-, Delete- und Zuweisungsformularen ausgeschrieben.
  - Änderungen am gemeinsamen Formular-Kontext bleiben dadurch an einer Stelle pflegbar.
  - das Template bleibt näher an den eigentlichen Bestellaktionen statt wiederholtes Hidden-Input-Markup mitzutragen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: Bestellaktions-Formulare kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
3. `CMS/admin/modules/system/DocumentationSyncService.php`
4. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
6. `CMS/admin/orders.php`

### Schritt 110 — 25.03.2026 — Orders-Modul Guard- und Audit-Kontexte weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **110 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/subscriptions/OrdersModule.php`

**Ergebnis dieses Schritts**

- Das Orders-Modul bündelt wiederkehrende Mutations-Vorbedingungen und Audit-Kontexte jetzt über kleine lokale Helfer:
  - Status- und Delete-Pfade nutzen denselben kleinen Guard für Snapshot-Existenz statt dieselbe Vorbedingungslogik doppelt zu tragen.
  - gemeinsame Audit-Kontexte für Order-Nummer und Kundenmail werden zentral aufgebaut statt in mehreren Mutationszweigen separat maskiert.
  - das Modul bleibt dadurch näher an seiner eigentlichen Bestellmutation statt an wiederholtem Guard- und Logging-Aufbau.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/subscriptions/OrdersModule.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: Mutationspfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php`
2. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
4. `CMS/admin/modules/system/DocumentationSyncService.php`
5. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)

### Schritt 109 — 25.03.2026 — Mail-Settings-View Formular-Kontext weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **109 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View bündelt wiederkehrende Formular-Kontexte jetzt über einen kleinen lokalen Renderer:
  - Hidden-Felder für `csrf_token` und `tab` werden nicht mehr mehrfach direkt in Transport-, Azure-, Graph-, Log- und Queue-Formularen ausgeschrieben.
  - Änderungen am gemeinsamen Formular-Kontext bleiben dadurch an einer Stelle pflegbar.
  - das Template bleibt näher an den eigentlichen Feldgruppen statt wiederholtes Hidden-Input-Markup mitzutragen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Formular-Kontexte kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
2. `CMS/admin/views/subscriptions/orders.php`
3. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
5. `CMS/admin/modules/system/DocumentationSyncService.php`
6. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)

### Schritt 001 — 24.03.2026 — Admin-Startblock `analytics` / `antispam` / `backups`

**Status:** in Arbeit / erster Batch umgesetzt

**Geprüfter Scope**

- `CMS/admin/analytics.php`
- `CMS/admin/antispam.php`
- `CMS/admin/backups.php`
- `CMS/admin/modules/seo/AnalyticsModule.php`
- `CMS/admin/modules/security/AntispamModule.php`
- `CMS/admin/modules/system/BackupsModule.php`
- zugehörige Views unter `CMS/admin/views/seo/`, `CMS/admin/views/security/`, `CMS/admin/views/system/`

**Ergebnis dieses Schritts**

- `analytics.php` geprüft: aktiver Entry-Point delegiert bereits sauber an `seo-page.php`; der operative Hotspot liegt heute primär in `SeoSuiteModule`, nicht im schlanken Entry.
- `antispam.php` gehärtet:
  - POST-Actions werden nur noch über eine Allowlist verarbeitet.
  - CSRF-Fehler und unbekannte Actions laufen konsistent über Redirect + Flash-Alert.
  - bestehende reCAPTCHA-Secrets werden nicht mehr im Formular offengelegt.
- `views/security/antispam.php` verbessert:
  - gemeinsame Flash-Alerts eingebunden
  - Secret-Feld maskiert statt vorbelegt
  - Inline-`confirm()` durch `cmsConfirm(...)` ersetzt
- `modules/security/AntispamModule.php` verbessert:
  - Settings-Laden zentralisiert
  - Zeichen-/Kontrollzeichen-Bereinigung für Eingaben
  - bestehendes Secret bleibt erhalten, wenn das Feld leer bleibt
  - Fehler werden generisch an die UI zurückgegeben statt rohe Exception-Texte zu leaken
  - fehlgeschlagene Mutationen werden sauber behandelt und auditierbar protokolliert
- `backups.php` verifiziert:
  - der zuvor eingebaute `CMS_ADMIN_SYSTEM_VIEW`-Guard ist aktiv
  - Flash-Alerts werden bereits über den gemeinsamen Partial gerendert
  - der nächste Hotspot bleibt das Modul/der Service, nicht mehr die leere Entry-Page

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/antispam.php`: Security/BP verbessert
- `CMS/admin/views/security/antispam.php`: Security/BP verbessert
- `CMS/admin/backups.php`: vorheriger Entry-Fix bestätigt
- `CMS/admin/analytics.php`: Entry-Standardisierung bestätigt, kein Sofortfix nötig

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/BackupsModule.php`
2. `CMS/core/Services/BackupService.php`
3. `CMS/admin/theme-editor.php`
4. `CMS/admin/theme-marketplace.php`
5. `CMS/admin/plugin-marketplace.php`
6. `CMS/admin/modules/seo/SeoSuiteModule.php`
7. `CMS/admin/modules/seo/PerformanceModule.php`

**Hinweis zur Reihenfolge**

Die Abarbeitung folgt ab jetzt strikt nach:

1. **kritisch**
2. **hoch**
3. **mittel**
4. **niedrig**

innerhalb der Datei-Gruppen mit Fokus auf reale Hotpaths (Entry-Points, Module, Services, JS-Dateien) statt kosmetischer Doku-Korrekturen allein.

### Schritt 002 — 24.03.2026 — Backup-Pfad `BackupsModule` / `BackupService`

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/BackupsModule.php`
- `CMS/core/Services/BackupService.php`

**Ergebnis dieses Schritts**

- `BackupsModule` gibt in der UI keine rohen Exception-Texte mehr aus.
- `BackupService` wurde auf Dateisystem-/Pfad-Ebene gehärtet:
  - Backup-Verzeichnis wird robust erzeugt und mit `.htaccess` + `index.html` abgesichert.
  - Zielverzeichnisse für Datenbank-Backups werden nur noch innerhalb des Backup-Roots akzeptiert.
  - Backup-Namen werden per Pattern validiert; Traversal-/Sonderpfade werden auf Service-Ebene blockiert.
  - Löschpfade prüfen nun den Backup-Root explizit und folgen keinen Symlinks blind.
  - Manifest-Dateien werden größenbegrenzt und defensiv eingelesen.
  - rekursive Größen- und ZIP-Läufe überspringen Symlinks und Dot-Dateien sauberer.
  - der bekannte Mail-Backup-Fehler mit `filesize()` nach `unlink()` ist beseitigt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/BackupsModule.php`: Security/BP verbessert
- `CMS/core/Services/BackupService.php`: Security deutlich verbessert, I/O-/Fehlerpfade robuster

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-editor.php`
2. `CMS/admin/modules/themes/ThemeEditorModule.php`
3. `CMS/admin/theme-marketplace.php`
4. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
5. `CMS/admin/plugin-marketplace.php`
6. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
7. `CMS/admin/modules/seo/SeoSuiteModule.php`

### Schritt 003 — 24.03.2026 — Theme-Dateieditor `theme-explorer` / `ThemeEditorModule`

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/theme-editor.php`
- `CMS/admin/theme-explorer.php`
- `CMS/admin/modules/themes/ThemeEditorModule.php`
- `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- `theme-editor.php` verifiziert: der frühere Risiko-Fokus liegt heute nicht mehr primär im Entry selbst, sondern im separaten Dateieditor unter `theme-explorer.php`.
- `theme-explorer.php` wurde gehärtet:
  - nur noch bekannte `POST`-Aktion `save_file`
  - konsistente Redirect-/Flash-Behandlung
  - GET-/POST-Dateiangaben werden als Strings normalisiert
- `ThemeEditorModule` wurde deutlich defensiver gemacht:
  - relative Pfade werden normalisiert und per Pattern eingeschränkt
  - Traversal-/Sonderpfade werden sauber blockiert
  - nur reale Dateien innerhalb des Theme-Roots dürfen gelesen/geschrieben werden
  - nicht beschreibbare oder zu große Dateien werden nicht im Browser editiert
  - Symlinks werden in Baum, Lesen und Schreiben ignoriert
  - Speichern nutzt `LOCK_EX`
- `views/themes/editor.php` nutzt jetzt gemeinsame Flash-Alerts und zeigt Dateigrößenwarnungen kontrolliert an.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Security/BP verbessert
- `CMS/admin/modules/themes/ThemeEditorModule.php`: Security deutlich verbessert
- `CMS/admin/views/themes/editor.php`: UI-/Security-Kontrakt verbessert
- `CMS/admin/theme-editor.php`: aktueller Risikofokus neu eingeordnet

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/theme-marketplace.php`
2. `CMS/admin/modules/themes/ThemeMarketplaceModule.php`
3. `CMS/admin/plugin-marketplace.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/modules/seo/SeoSuiteModule.php`
6. `CMS/admin/modules/seo/PerformanceModule.php`
7. `CMS/admin/modules/settings/SettingsModule.php`

### Schritt 004 — 24.03.2026 — Marketplace-Entrypoints `plugin-marketplace` / `theme-marketplace`

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/plugin-marketplace.php`
- `CMS/admin/theme-marketplace.php`
- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
- `CMS/admin/modules/themes/ThemeMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Die beiden Marketplace-Module wurden geprüft und sind bereits deutlich weiter als der ursprüngliche Audit-Stand:
  - HTTPS-/Host-Allowlist aktiv
  - SHA-256-/Integritätsprüfung aktiv
  - ZIP-Einträge werden validiert
  - UI kennt `Nur manuell`-/Manual-only-Fälle
- Die Entry-Points wurden zusätzlich nachgezogen:
  - nur noch bekannte `install`-Aktion
  - konsistenter Redirect-/Flash-Flow bei CSRF- und Aktionsfehlern
  - kein lokales Sonderverhalten mehr nur in einem der beiden Wrapper

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/plugin-marketplace.php`: Security/BP verbessert
- `CMS/admin/theme-marketplace.php`: Security/BP verbessert
- `PluginMarketplaceModule` und `ThemeMarketplaceModule`: bestehende Härtungen verifiziert, Audit-Stand fachlich präzisiert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/seo/SeoSuiteModule.php`
2. `CMS/admin/modules/seo/PerformanceModule.php`
3. `CMS/admin/modules/settings/SettingsModule.php`
4. `CMS/admin/modules/member/MemberDashboardModule.php`
5. `CMS/admin/modules/legal/CookieManagerModule.php`
6. `CMS/admin/modules/legal/LegalSitesModule.php`

### Schritt 005 — 24.03.2026 — SEO-/Performance-/Settings-Hotspots

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/seo/SeoSuiteModule.php`
- `CMS/admin/modules/seo/PerformanceModule.php`
- `CMS/admin/modules/settings/SettingsModule.php`
- `CMS/admin/settings.php`
- `CMS/admin/views/settings/general.php`

**Ergebnis dieses Schritts**

- `SeoSuiteModule` wurde auf Eingabe- und Routing-Ebene nachgezogen:
  - Indexing-Submissions akzeptieren nur noch gültige, hostgebundene Site-URLs
  - URL-Listen werden dedupliziert und in ihrer Größe begrenzt
  - Sitemap-Prioritäten und `changefreq` werden normalisiert
  - Social-/Schema-/Canonical-/Tracking-Felder werden defensiver bereinigt
  - der Broken-Link-Scan nutzt die konfigurierbare Permalink-Struktur via `PermalinkService` statt harter `/blog/`-Annahmen
  - Sitemap-Fehler leaken keine Rohdetails mehr in die UI
- `PerformanceModule` wurde im Dateisystem-Hotpath weiter gehärtet:
  - Symlinks werden bei Cache-, Session- und Medienläufen ignoriert
  - TTL-/Timeout-Settings werden konsistent begrenzt
  - Save-Fehler im Performance-Settings-Flow bleiben generisch und werden auditierbar geloggt
- `SettingsModule` wurde im Konfigurationspfad robuster gemacht:
  - Logo-/Favicon-Werte werden auf relative Pfade oder gültige HTTP(S)-URLs begrenzt
  - `config/app.php` und `config/.htaccess` werden kontrollierter über temporäre Dateien geschrieben
  - Save-, URL-Migrations- und Import-Slug-Repair-Fehler leaken keine rohen Exceptions mehr
  - erfolgreiche Änderungen werden zusätzlich im Audit-Log vermerkt
- `CMS/admin/settings.php` verarbeitet nur noch bekannte Aktionen; `views/settings/general.php` nutzt den gemeinsamen Flash-Alert-Partial.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/seo/SeoSuiteModule.php`: Security deutlich verbessert, Speed leicht verbessert, da falsche/duplizierte Submission-Payloads reduziert werden
- `CMS/admin/modules/seo/PerformanceModule.php`: Security verbessert, Speed/BP leicht verbessert durch defensivere Dateipfade und klarere Settings-Grenzen
- `CMS/admin/modules/settings/SettingsModule.php`: Security/BP verbessert, weil Konfig-Schreibpfade, URL-/Asset-Normalisierung und Fehlerpfade sauberer abgesichert sind
- `CMS/admin/settings.php`: Entry-Flow verbessert durch Action-Allowlist und konsistenteren Redirect-Pfad

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/member/MemberDashboardModule.php`
2. `CMS/admin/modules/legal/CookieManagerModule.php`
3. `CMS/admin/modules/legal/LegalSitesModule.php`
4. `CMS/admin/modules/system/DocumentationGitSync.php`
5. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php`

### Schritt 006 — 24.03.2026 — Member-/Legal-Hotspots

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/member/MemberDashboardModule.php`
- `CMS/admin/modules/legal/CookieManagerModule.php`
- `CMS/admin/modules/legal/LegalSitesModule.php`

**Ergebnis dieses Schritts**

- `MemberDashboardModule` wurde auf Settings- und Aktionspfaden defensiver gemacht:
  - Save-Fehler laufen nun über ein zentrales, auditierbares Fehler-Result statt rohe Exceptions in die UI zu geben
  - Dashboard-Logo wird auf relative Pfade oder gültige HTTP(S)-Referenzen begrenzt
  - Onboarding-CTA-URLs werden normalisiert und auf sichere Fallbacks zurückgeführt
- `CookieManagerModule` wurde auf Eingabe- und Scan-Ebene nachgezogen:
  - Cookie-Policy-URLs werden normalisiert statt beliebige Werte unverändert zu speichern
  - Matomo-Site-IDs und Textfelder werden sauber begrenzt
  - Kategorie-/Service-Slugs werden defensiver sanitisiert
  - der Scanner ignoriert Symlinks, um ungewollte Dateisystem-Ausreißer zu vermeiden
  - Save-Fehler bleiben generisch und auditierbar
- `LegalSitesModule` leakt in Save-/Profil-/Seitengenerierungs-Pfaden keine rohen Exceptions mehr in die Admin-UI, sondern nutzt einen zentralisierten, auditierbaren Fehlerpfad.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/member/MemberDashboardModule.php`: Security/BP verbessert
- `CMS/admin/modules/legal/CookieManagerModule.php`: Security deutlich verbessert, BP verbessert
- `CMS/admin/modules/legal/LegalSitesModule.php`: Security/BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationGitSync.php`
2. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
4. `CMS/admin/modules/system/DocumentationSyncService.php`
5. `CMS/admin/modules/media/MediaModule.php`
6. `CMS/admin/modules/themes/ThemeEditorModule.php` (Restpunkte nach Hauptfix)

### Schritt 007 — 24.03.2026 — Doku-Sync-Hotspots

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/documentation.php`
- `CMS/admin/modules/system/DocumentationGitSync.php`
- `CMS/admin/modules/system/DocumentationGithubZipSync.php`
- `CMS/admin/modules/system/DocumentationSyncDownloader.php`
- `CMS/admin/modules/system/DocumentationSyncFilesystem.php`
- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Git-basierte Doku-Sync wurde defensiver gemacht:
  - Repository-Root, `/DOC`-Ziel und Git-Ref-Teile werden vor dem Lauf validiert
  - Fetch-/Checkout-Fehler leaken keine rohen Kommandoausgaben mehr ins Admin-UI
  - interne Details werden stattdessen begrenzt geloggt und auditierbar protokolliert
- Der GitHub-ZIP-Sync wurde auf Arbeitsverzeichnis- und Fehlerpfaden gehärtet:
  - temporäre ZIP-/Extract-Pfade und Repo-interne Staging-/Backup-Pfade werden auf erlaubte Roots begrenzt
  - symbolische Links als Arbeitsziele werden blockiert
  - Sync-Fehler liefern nur noch generische UI-Meldungen mit internem Logging/Audit-Log
- Downloader und Dateisystem-Helfer wurden nachgezogen:
  - Download-Ziele dürfen nur noch als neue `.zip`-Dateien im Temp-Root angelegt werden
  - ZIP-Inhalte ohne Body werden nicht mehr blind gespeichert
  - Integritätsprüfung, DOC-Finder, Kopier-, Zähl- und Löschroutinen behandeln symbolische Links nicht mehr als normale Dateien/Verzeichnisse
  - rekursive Löschfehler werden korrekt nach oben propagiert
- `CMS/admin/documentation.php` akzeptiert nur noch die erwartete Aktion `sync_docs`.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Security/BP verbessert
- `CMS/admin/modules/system/DocumentationGitSync.php`: Security/BP deutlich verbessert
- `CMS/admin/modules/system/DocumentationGithubZipSync.php`: Security deutlich verbessert
- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security verbessert
- `CMS/admin/modules/system/DocumentationSyncFilesystem.php`: Security/BP deutlich verbessert
- `CMS/admin/modules/system/DocumentationSyncService.php`: BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/media/MediaModule.php`
2. `CMS/admin/modules/themes/ThemeEditorModule.php` (Restpunkte nach Hauptfix)
3. `CMS/admin/modules/system/UpdatesModule.php`
4. `CMS/admin/modules/posts/PostsModule.php`
5. `CMS/admin/modules/pages/PagesModule.php`

### Schritt 008 — 24.03.2026 — Media-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/media.php`
- `CMS/admin/modules/media/MediaModule.php`
- Kontext geprüft: `CMS/core/Services/MediaService.php`, `CMS/core/Services/Media/UploadHandler.php`, `CMS/core/Services/Media/MediaRepository.php`

**Ergebnis dieses Schritts**

- `CMS/admin/media.php` wurde im Entry-Flow nachgezogen:
  - nur noch bekannte POST-Aktionen werden akzeptiert
  - Redirect-Parameter (`tab`, `path`, `view`, `category`, `q`) werden vor der Wiederverwendung normalisiert
  - der Member-Ordner-Guard arbeitet mit normalisiertem Pfadmaterial statt mit rohen Query-Werten
- `MediaModule` wurde auf Eingabe- und Fehlerpfaden deutlich defensiver gemacht:
  - Pfade, Tabs, Views, Kategorie-Slugs und Suchbegriffe werden zentral normalisiert
  - Upload-Dateien werden vor Übergabe an den Service auf Vollständigkeit und Grundform geprüft
  - Ordner-/Datei-/Kategorie-Namen werden defensiver sanitisiert
  - System-Kategorien werden vor Löschen/Neuanlage serverseitig abgeblockt
  - Service-`WP_Error`s landen nicht mehr als Detailmeldung direkt im UI, sondern werden generisch beantwortet und intern geloggt/auditierbar protokolliert
  - Medien-Settings begrenzen Uploadgrößen sowie Qualitäts-/Dimensionsfelder konsistenter vor dem Persistieren

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/media.php`: Security/BP verbessert
- `CMS/admin/modules/media/MediaModule.php`: Security deutlich verbessert, BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` (Restpunkte nach Hauptfix)
2. `CMS/admin/modules/system/UpdatesModule.php`
3. `CMS/admin/modules/posts/PostsModule.php`
4. `CMS/admin/modules/pages/PagesModule.php`
5. `CMS/admin/modules/hub/HubSitesModule.php`

### Schritt 009 — 24.03.2026 — Update-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/updates.php`
- `CMS/admin/modules/system/UpdatesModule.php`
- `CMS/core/Services/UpdateService.php`
- Kontext geprüft: `CMS/admin/views/system/updates.php`

**Ergebnis dieses Schritts**

- `CMS/admin/updates.php` wurde im Entry-Flow nachgezogen:
  - nur noch bekannte POST-Aktionen werden akzeptiert
  - CSRF- und Aktionsfehler laufen konsistent über Redirect + Flash-Alert
  - Plugin-Slugs werden vor der Modulübergabe defensiver normalisiert
- `UpdatesModule` wurde auf Fehler- und Installationspfaden vereinheitlicht:
  - Plugin-Slugs werden zentral normalisiert
  - direkte Plugin-Installationen werden nur noch für wirklich unterstützte Auto-Install-Fälle zugelassen
  - fehlgeschlagene Prüf- und Installationsläufe leaken keine rohen Exception-Texte mehr ins Admin-UI
  - stattdessen werden Fehler intern geloggt und auditierbar als System-Ereignisse protokolliert
- `UpdateService` wurde im eigentlichen Installationspfad zusätzlich gehärtet:
  - Download-Quellen müssen jetzt neben der Host-Allowlist auch den SSRF-/DNS-Sicherheitscheck bestehen
  - Installationsziele werden auf erlaubte Core-/Plugin-/Theme-Roots begrenzt und gegen Symlink-Ziele abgesichert
  - leere Download-Bodies werden verworfen und ZIP-Dateien mit `LOCK_EX` geschrieben
  - Installationsfehler liefern nach außen nur noch generische Meldungen; technische Details bleiben im Log-/Audit-Kontext

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/updates.php`: Security/BP verbessert
- `CMS/admin/modules/system/UpdatesModule.php`: Security/BP deutlich verbessert
- `CMS/core/Services/UpdateService.php`: Security deutlich verbessert, BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` (Restpunkte nach Hauptfix)
2. `CMS/admin/modules/posts/PostsModule.php`
3. `CMS/admin/modules/pages/PagesModule.php`
4. `CMS/admin/modules/hub/HubSitesModule.php`
5. `CMS/core/Services/FileUploadService.php`

### Schritt 010 — 24.03.2026 — Posts-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/posts.php`
- `CMS/admin/modules/posts/PostsModule.php`
- Kontext geprüft: `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/edit.php`

**Ergebnis dieses Schritts**

- `CMS/admin/posts.php` wurde im Entry-Flow nachgezogen:
  - nur noch bekannte POST-Aktionen werden akzeptiert
  - View-Routing wird auf bekannte Ansichten begrenzt
  - Bulk-/Kategorie-Parameter werden vor der Modulübergabe defensiver typisiert
  - ungültige Mutationen laufen konsistent über Redirect + Flash-Alert
- `PostsModule` wurde auf Eingabe-, Listen- und Fehlerpfaden vereinheitlicht:
  - Listenfilter für Status, Kategorie und Suche werden zentral normalisiert
  - Bulk-Aktionen akzeptieren nur noch bekannte Aktionen und deduplizieren ungültige IDs serverseitig
  - Titel-, Excerpt-, Meta- und Medienreferenzfelder werden vor Persistenz defensiver sanitisiert
  - Kategorie-/Tag-Löschpfade hängen nicht mehr verdeckt direkt an `$_POST`, sondern arbeiten mit expliziten Ersatz-IDs
  - Exception- und DB-Fehler leaken im Admin-UI keine Rohdetails mehr, sondern werden generisch beantwortet und intern geloggt sowie auditierbar protokolliert

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/posts.php`: Security/BP verbessert
- `CMS/admin/modules/posts/PostsModule.php`: Security deutlich verbessert, BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` (Restpunkte nach Hauptfix)
2. `CMS/admin/modules/pages/PagesModule.php`
3. `CMS/admin/modules/hub/HubSitesModule.php`
4. `CMS/core/Services/FileUploadService.php`
5. `CMS/admin/modules/comments/CommentsModule.php`

### Schritt 011 — 24.03.2026 — Pages-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/pages.php`
- `CMS/admin/modules/pages/PagesModule.php`
- Kontext geprüft: `CMS/admin/views/pages/list.php`, `CMS/admin/views/pages/edit.php`

**Ergebnis dieses Schritts**

- `CMS/admin/pages.php` wurde im Entry-Flow nachgezogen:
  - nur noch bekannte POST-Aktionen werden akzeptiert
  - View-Routing wird auf bekannte Ansichten begrenzt
  - CSRF- und Aktionsfehler laufen konsistent über Redirect + Flash-Alert
  - Bulk-Parameter werden vor der Modulübergabe defensiver typisiert
- `PagesModule` wurde auf Listen-, Eingabe- und Fehlerpfaden vereinheitlicht:
  - Listenfilter für Status, Kategorie und Suche werden zentral normalisiert
  - Bulk-Aktionen akzeptieren nur noch bekannte Aktionen und bereinigen ungültige IDs serverseitig
  - Titel-, Meta- und Medienreferenzfelder werden vor Persistenz defensiver sanitisiert
  - Delete-/Bulk-/Save-Fehler leaken keine rohen DB- oder Exception-Details mehr ins Admin-UI
  - technische Fehlerdetails landen stattdessen im internen Logging und Audit-Kontext

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/pages.php`: Security/BP verbessert
- `CMS/admin/modules/pages/PagesModule.php`: Security deutlich verbessert, BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/themes/ThemeEditorModule.php` (Restpunkte nach Hauptfix)
2. `CMS/admin/modules/hub/HubSitesModule.php`
3. `CMS/core/Services/FileUploadService.php`
4. `CMS/admin/modules/comments/CommentsModule.php`
5. `CMS/admin/modules/system/DocumentationSyncService.php`

### Schritt 012 — 24.03.2026 — Theme-Editor-Resthärtung

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/theme-explorer.php`
- `CMS/admin/modules/themes/ThemeEditorModule.php`
- Kontext geprüft: `CMS/admin/views/themes/editor.php`

**Ergebnis dieses Schritts**

- `CMS/admin/theme-explorer.php` wurde im Entry-Flow konsolidiert:
  - POST-Aktionen laufen jetzt explizit über eine kleine Allowlist
  - der Redirect-/Flash-Flow bleibt konsistent und enger an bekannten Mutationen
- `ThemeEditorModule` wurde auf Warn-, Lade- und Save-Pfaden nachgeschärft:
  - unsichere oder nicht auflösbare Dateianfragen enden jetzt in kontrollierten Warnungen statt stillen Leerzuständen
  - Binärdaten und zu große neue Datei-Inhalte werden vor dem Schreiben serverseitig abgefangen
  - PHP-Syntax- und Schreibfehler leaken keine Rohdetails mehr ins UI, sondern werden generisch beantwortet und intern geloggt/auditierbar protokolliert
  - Dateibäume werden stabiler sortiert, wodurch die Browser-Ansicht deterministischer wird
- `views/themes/editor.php` wurde defensiv nachgezogen:
  - der Tree-Renderer ist gegen doppelte Funktionsdefinitionen abgesichert
  - Einzelwerte aus dem Dateibaum werden typisiert/escaped verarbeitet

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/theme-explorer.php`: Security/BP verbessert
- `CMS/admin/modules/themes/ThemeEditorModule.php`: Security deutlich verbessert, PHP/BP verbessert
- `CMS/admin/views/themes/editor.php`: BP leicht verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/hub/HubSitesModule.php`
2. `CMS/core/Services/FileUploadService.php`
3. `CMS/admin/modules/comments/CommentsModule.php`
4. `CMS/admin/modules/system/DocumentationSyncService.php`
5. `CMS/admin/modules/security/FirewallModule.php`

### Schritt 013 — 24.03.2026 — Hub-Sites-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/hub-sites.php`
- `CMS/admin/modules/hub/HubSitesModule.php`
- Kontext geprüft: `CMS/admin/views/hub/list.php`, `CMS/admin/views/hub/edit.php`

**Ergebnis dieses Schritts**

- `CMS/admin/hub-sites.php` wurde im Entry-Flow nachgezogen:
  - nur noch bekannte POST-Aktionen werden akzeptiert
  - View-Routing ist auf bekannte Ansichten begrenzt
  - Fallback-Meldungen für Save-/Delete-/Template-Aktionen bleiben konsistent und nicht leer
- `HubSitesModule` wurde auf Eingabe-, Listen- und Fehlerpfaden defensiver gemacht:
  - Suchbegriffe, zentrale Plaintext-Felder und mehrere URL-Felder werden jetzt kontrollierter normalisiert
  - CTA-, Karten-, Bild- und Link-URLs fallen bei unsicheren oder ungültigen Werten auf sichere Defaults zurück
  - Save-/Delete-/Duplicate-Fehler leaken keine rohen Exception-Details mehr ins Admin-UI, sondern werden generisch beantwortet und intern geloggt sowie auditierbar protokolliert
  - wiederkehrende Layout-/Media-Settings arbeiten mit expliziten Allowlists statt losen Freitextwerten

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/hub-sites.php`: Security/BP verbessert
- `CMS/admin/modules/hub/HubSitesModule.php`: Security deutlich verbessert, BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/FileUploadService.php`
2. `CMS/admin/modules/comments/CommentsModule.php`
3. `CMS/admin/modules/system/DocumentationSyncService.php`
4. `CMS/admin/modules/security/FirewallModule.php`
5. `CMS/admin/modules/hub/HubTemplateProfileManager.php`

### Schritt 014 — 24.03.2026 — FileUploadService-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/FileUploadService.php`
- Kontext geprüft: `CMS/core/Services/MediaService.php`, `CMS/core/Routing/ApiRouter.php`, `CMS/core/Routing/PublicRouter.php`

**Ergebnis dieses Schritts**

- `FileUploadService` wurde auf Request- und Upload-Ebene nachgezogen:
  - der Service akzeptiert nur noch echte `POST`-Upload-Requests
  - Dateipayloads werden auf Einzeldatei-Form, Pflichtfelder und tatsächliche Upload-/Datei-Existenz geprüft
  - Zielpfade werden segmentweise normalisiert und gegen Traversal-, Dotfile- und Steuerzeichen-Pfade abgesichert
- Die Fehlerpfade wurden vereinheitlicht:
  - rohe Validierungs- und Persistenzdetails aus `MediaService`/`WP_Error` leaken nicht mehr direkt an den Client
  - stattdessen liefert der Service generische Fehlermeldungen und protokolliert technische Details intern über Logging und Audit-Log
- Member-Upload-Grenzen bleiben enger am erlaubten Benutzerbereich und reagieren auf ungültige Zielpfade jetzt kontrollierter statt still bereinigt weiterzulaufen

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/FileUploadService.php`: Security deutlich verbessert, PHP/BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/comments/CommentsModule.php`
2. `CMS/admin/modules/system/DocumentationSyncService.php`
3. `CMS/admin/modules/security/FirewallModule.php`
4. `CMS/admin/modules/hub/HubTemplateProfileManager.php`
5. `CMS/core/Services/GraphApiService.php`

### Schritt 015 — 24.03.2026 — Kommentar-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/comments.php`
- `CMS/admin/modules/comments/CommentsModule.php`
- `CMS/admin/views/comments/list.php`
- Kontext geprüft: `CMS/core/Services/CommentService.php`, `CMS/includes/functions/redirects-auth.php`, `CMS/core/AuditLogger.php`

**Ergebnis dieses Schritts**

- `CMS/admin/comments.php` wurde im Entry-Flow nachgezogen:
  - Zugriff orientiert sich jetzt an der vorhandenen Capability `comments.view` statt pauschal nur an der Admin-Rolle
  - POST-Requests akzeptieren nur noch bekannte Kommentar-Aktionen und bleiben beim Redirect konsequent am validierten Statusfilter
  - CSRF- und Aktionsfehler laufen weiter über Flash-Alerts, aber ohne lose Sonderpfade
- `CommentsModule` wurde auf Mutations- und Bulk-Pfaden gehärtet:
  - View-, Moderations- und Löschrechte nutzen die vorhandenen Capabilities `comments.view`, `comments.moderate` und `comments.delete`
  - Statuswechsel und Löschpfade prüfen IDs, Zielstatus und tatsächlich vorhandene Kommentare serverseitig, bevor der Service mutiert
  - Bulk-Aktionen deduplizieren IDs, begrenzen die Gesamtmenge, unterscheiden Moderations- von Delete-Rechten und protokollieren Teil-/Fehlschläge intern über Logging und Audit-Log
  - Listen-Daten werden vor der View-Übergabe typisiert und normalisiert statt als loser Array-/Objekt-Mix weitergereicht
- `views/comments/list.php` wurde passend nachgezogen:
  - Bulk-Bar, Checkboxen und Row-Actions richten sich jetzt an den tatsächlich vorhandenen Rechten aus
  - Post-Links nutzen das vom Modul vorbereitete Ziel statt rohe Slug-Verkettung direkt in der View
  - die Tabellenstruktur bleibt auch ohne Moderations-/Delete-Rechte konsistent

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/comments.php`: Security/BP verbessert
- `CMS/admin/modules/comments/CommentsModule.php`: Security deutlich verbessert, BP verbessert
- `CMS/admin/views/comments/list.php`: Security/BP leicht verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php`
2. `CMS/admin/modules/security/FirewallModule.php`
3. `CMS/admin/modules/hub/HubTemplateProfileManager.php`
4. `CMS/core/Services/GraphApiService.php`
5. `CMS/core/Services/CommentService.php`

### Schritt 016 — 24.03.2026 — Doku-Sync-Orchestrator

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationModule.php`, `DocumentationSyncEnvironment.php`, `DocumentationGitSync.php`, `DocumentationGithubZipSync.php`, `DocumentationSyncDownloader.php`, `DocumentationSyncFilesystem.php`

**Ergebnis dieses Schritts**

- `DocumentationSyncService` wurde als zentraler Orchestrator nachgezogen:
  - Repository-Root, `/DOC`-Ziel, Git-Ref-Teile, ZIP-Quelle und Integritätsprofil werden jetzt vor dem eigentlichen Sync zentral validiert
  - inkonsistente oder nicht verfügbare Sync-Capabilities laufen über einen generischen, auditierbaren Fehlerpfad statt losem Roh-Return aus dem Environment
  - erfolgreiche Git-/GitHub-ZIP-Synchronisationen werden zusätzlich zentral geloggt und im Audit-Log festgehalten
  - Capability-Daten werden vor Weitergabe/Logging normalisiert und in ihrer Textform begrenzt
- Der Orchestrator verhält sich damit robuster gegenüber fehlerhafter Konfiguration:
  - kaputte Repo-/DOC-Layouts werden früh geblockt
  - unsaubere ZIP-Konfiguration oder ungültige Branch-/Remote-Werte werden nicht mehr erst tief in Unterservices sichtbar
  - der Sync-Modus muss jetzt konsistent zu den ermittelten Capabilities passen, bevor ein Fallback-Pfad angesprungen wird

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security deutlich verbessert, PHP/BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/security/FirewallModule.php`
2. `CMS/admin/modules/hub/HubTemplateProfileManager.php`
3. `CMS/core/Services/GraphApiService.php`
4. `CMS/core/Services/CommentService.php`
5. `CMS/admin/modules/system/DocumentationRenderer.php`

### Schritt 017 — 24.03.2026 — Firewall-Hotspot

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/firewall.php`
- `CMS/admin/modules/security/FirewallModule.php`
- `CMS/admin/views/security/firewall.php`
- Kontext geprüft: `CMS/includes/functions/roles.php`, `CMS/admin/modules/security/SecurityAuditModule.php`, `CMS/admin/modules/security/AntispamModule.php`

**Ergebnis dieses Schritts**

- `CMS/admin/firewall.php` wurde im Entry-Flow nachgezogen:
  - POST-Mutationen akzeptieren nur noch bekannte Firewall-Aktionen
  - CSRF- und Aktionsfehler laufen konsistent über Session-Flash + Redirect statt über lokales Sonderverhalten
  - der Wrapper bleibt damit enger an den übrigen gehärteten Admin-Entrys
- `FirewallModule` wurde auf Mutations- und Validierungspfaden deutlich robuster gemacht:
  - Settings werden über eine zentrale Key-Liste geladen statt doppelte Inline-Abfragen zu pflegen
  - rohe Exception-Texte leaken bei Save-/Add-/Delete-/Toggle-Fehlern nicht mehr ins Admin-UI
  - neue Regeln validieren IP-, CIDR-, Country- und User-Agent-Werte strenger, prüfen Dubletten serverseitig und normalisieren Ablaufdaten
  - Delete-/Toggle-Pfade prüfen Regel-Existenz und behandeln fehlgeschlagene DB-Mutationen kontrolliert mit internem Logging/Audit
  - Listenzeilen werden vor der View-Übergabe typisiert und vereinheitlicht
- `views/security/firewall.php` wurde passend nachgezogen:
  - gemeinsame Flash-Alerts sind eingebunden
  - das Ablaufdatum rendert kein unescaped Inline-HTML mehr im Echo-Pfad
  - das Löschen nutzt jetzt `cmsConfirm(...)` statt rohem Browser-`confirm()`

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/firewall.php`: Security/BP verbessert
- `CMS/admin/modules/security/FirewallModule.php`: Security deutlich verbessert, PHP/BP verbessert
- `CMS/admin/views/security/firewall.php`: Security/BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/hub/HubTemplateProfileManager.php`
2. `CMS/core/Services/GraphApiService.php`
3. `CMS/core/Services/CommentService.php`
4. `CMS/admin/modules/system/DocumentationRenderer.php`
5. `CMS/admin/modules/security/SecurityAuditModule.php`

### Schritt 018 — 24.03.2026 — Hub-Template-Profilmanager

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/hub/HubTemplateProfileManager.php`
- Kontext geprüft: `CMS/admin/hub-sites.php`, `CMS/admin/modules/hub/HubSitesModule.php`, `CMS/admin/views/hub/template-edit.php`, `CMS/admin/views/hub/templates.php`

**Ergebnis dieses Schritts**

- `HubTemplateProfileManager` wurde auf Persistenz-, Validierungs- und Performance-Pfaden nachgezogen:
  - Template-Listen laden die Nutzungszähler jetzt gesammelt per Aggregat-Abfrage statt per N+1-Query je Profil
  - Link-, Section- und Starter-Card-Payloads werden serverseitig begrenzt, Textfelder strenger bereinigt und URL-Ziele auf relative Pfade bzw. `http`/`https` reduziert
  - Speichern, Duplizieren und Löschen laufen über generische Fehlerpfade mit internem Logging/Audit statt auf lose DB-Rückgaben oder rohe Laufzeitfehler zu vertrauen
  - das zentrale Settings-Payload erhält ein Größenlimit; fehlgeschlagene `insert`-/`update`-Operationen werden explizit erkannt statt still weiterzulaufen
  - vererbte Hub-Sites werden nur noch synchronisiert, wenn sich geerbte Links oder Starter-Cards tatsächlich geändert haben; fehlgeschlagene Sync-Updates werden sauber protokolliert
- Damit sinkt sowohl das Risiko unsauberer Template-Payloads als auch der Performance-Druck im Template-Listing.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/hub/HubTemplateProfileManager.php`: Security deutlich verbessert, Speed deutlich verbessert, PHP/BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/GraphApiService.php`
2. `CMS/core/Services/CommentService.php`
3. `CMS/admin/modules/system/DocumentationRenderer.php`
4. `CMS/admin/modules/security/SecurityAuditModule.php`
5. `CMS/admin/modules/legal/CookieManagerModule.php`

### Schritt 019 — 24.03.2026 — Microsoft-Graph-Service

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/GraphApiService.php`
- Kontext geprüft: `CMS/core/Http/Client.php`, `CMS/core/Services/AzureMailTokenProvider.php`, `CMS/admin/modules/system/MailSettingsModule.php`, `CMS/core/Routing/ApiRouter.php`

**Ergebnis dieses Schritts**

- `GraphApiService` wurde auf Konfigurations-, Request- und Fehlerpfaden deutlich robuster gemacht:
  - Tenant-ID, Client-ID, Scope, Base-URL und Token-Endpoint werden restriktiver normalisiert statt lose Rohwerte weiterzureichen
  - Token-Requests laufen jetzt korrekt als `application/x-www-form-urlencoded` über den HTTP-Client statt als nackter POST-Body ohne Form-Header
  - Graph- und Token-Responses erhalten ein Größenlimit und strengere JSON-/Content-Type-Prüfung
  - Basis-URL und Token-Endpoint akzeptieren nur noch erlaubte Hosts, sichere Pfade und keine Query-/Fragment-Ausreißer
  - rohe Remote-Fehlermeldungen werden serverseitig gekürzt/bereinigt; der nach außen sichtbare Test-Fehler bleibt generisch und leakt keine unnötigen Details mehr
  - Organisationsdaten aus `/organization` werden vor Rückgabe typisiert und auf ein erwartbares Schema reduziert
- Damit sinken sowohl SSRF-/Konfigurationsdrift-Risiken als auch Detail-Leaks im Mail-/Admin-Testpfad spürbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/GraphApiService.php`: Security deutlich verbessert, PHP/BP verbessert, Gesamt klar verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/CommentService.php`
2. `CMS/admin/modules/system/DocumentationRenderer.php`
3. `CMS/admin/modules/security/SecurityAuditModule.php`
4. `CMS/admin/modules/legal/CookieManagerModule.php`
5. `CMS/core/Services/AzureMailTokenProvider.php`

### Schritt 020 — 24.03.2026 — Kommentar-Service

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/CommentService.php`
- `CMS/core/Routing/PublicRouter.php`
- `CMS/admin/comments.php`
- `CMS/admin/modules/comments/CommentsModule.php`
- `CMS/core/SchemaManager.php`

**Ergebnis dieses Schritts**

- `CommentService` wurde im öffentlichen Schreibpfad nachgezogen:
  - Kommentarerstellung akzeptiert nur noch valide Autor-/Mail-/Content-Payloads mit harten Längenlimits.
  - Kommentare auf nicht veröffentlichten oder für Kommentare geschlossenen Beiträgen werden serverseitig verworfen.
  - einfache Flood-/Spam-Bremse über Mail/IP/User im 15-Minuten-Fenster ergänzt.
  - IP-Adressen werden defensiv validiert statt roh übernommen.
  - Persistenz- und Abbruchpfade laufen über internes Logging/Audit statt still auszufallen.
- Die öffentliche Kommentar-Ausgabe gibt keine `author_email`-Adresse mehr im Frontend-Datensatz mit aus.
- Die Admin-Liste wird im Service zusätzlich auf sinnvolle `limit`-/`offset`-Grenzen geklemmt, damit große Listenaufrufe nicht ausufern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/CommentService.php`: Security klar verbessert; kleinere Speed-/PHP/BP-Gewinne durch begrenzte List-Reads, klarere Helper und robustere Fehlerpfade.
- `Core – Allgemeine Services`: Aggregat leicht verbessert, da ein weiterer mittelstarker Service aus dem Trust-Boundary-Bereich angehoben wurde.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationRenderer.php`
2. `CMS/admin/modules/security/SecurityAuditModule.php`
3. `CMS/admin/modules/legal/CookieManagerModule.php`
4. `CMS/core/Services/AzureMailTokenProvider.php`
5. `CMS/core/Services/FeedService.php`

### Schritt 021 — 24.03.2026 — Dokumentations-Renderer

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationRenderer.php`
- `CMS/admin/modules/system/DocumentationModule.php`
- `CMS/admin/modules/system/DocumentationCatalog.php`

**Ergebnis dieses Schritts**

- `DocumentationRenderer` wurde gegen riskante Render-Payloads und lockere Linkziele nachgezogen:
  - Markdown-/CSV-Inhalte werden auf maximale Größe und Zeilenzahl begrenzt.
  - Tabellen und CSV-Zeilen werden auf sinnvolle Spalten-, Zeilen- und Zellgrößen geklemmt.
  - `currentDocument` wird vor Nutzung normalisiert, damit Render-Kontext und Linkauflösung enger bleiben.
  - generierte `href`-Ziele werden serverseitig auf sichere interne Pfade, valide HTTP(S)-Links oder saubere Anchors begrenzt.
  - Limitierungen werden intern geloggt, statt große oder seltsame Doku-Artefakte still und unbegrenzt in HTML zu überführen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationRenderer.php`: Security, Speed und PHP/BP verbessert; der Renderer behandelt Trust-Boundaries und große Doku-Dateien robuster.
- `Admin – Module`: Aggregat leicht verbessert, weil ein weiterer HTML-generierender Modulpfad härter und kontrollierter rendert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/security/SecurityAuditModule.php`
2. `CMS/admin/modules/legal/CookieManagerModule.php`
3. `CMS/core/Services/AzureMailTokenProvider.php`
4. `CMS/core/Services/FeedService.php`
5. `CMS/admin/modules/system/DocumentationModule.php`

### Schritt 022 — 24.03.2026 — Security-Audit-Modul

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/security/SecurityAuditModule.php`
- `CMS/admin/security-audit.php`
- `CMS/admin/views/security/audit.php`

**Ergebnis dieses Schritts**

- `SecurityAuditModule` wurde im Lese-, Prüf- und Bereinigungspfad gehärtet:
  - Zugriff wird nun auch im Modul selbst abgesichert, nicht nur im Entry-Point.
  - Audit-Log-Reads holen nur noch relevante Security-/Auth-Felder statt rohe Komplettzeilen aus `audit_log`.
  - Audit-Details, Check-Namen und Check-Beschreibungen werden serverseitig begrenzt und normalisiert, damit große Metadaten oder ausufernde Texte die View nicht aufblasen.
  - `clearLog()` liefert keine rohen Exception-Texte mehr an die UI und protokolliert Fehlschläge intern nachvollziehbar.
  - `.htaccess`-Inspektion läuft nur noch lesbar/defensiv mit Größenlimit statt ungebremstem Dateiread.
  - fehlgeschlagene Teilprüfungen wie Passwort-Hash-Checks werden sauber intern geloggt, ohne das gesamte Audit unkontrolliert zu stören.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/security/SecurityAuditModule.php`: Security deutlich verbessert; Speed/BP leicht verbessert durch fokussiertere Log-Abfragen und klarere Helper-/Fehlerpfade.
- `Admin – Module`: Aggregat erneut leicht verbessert, da ein sicherheitsnaher Admin-Hotspot weniger rohe Daten und Fehlerdetails an die Oberfläche bringt.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/legal/CookieManagerModule.php`
2. `CMS/core/Services/AzureMailTokenProvider.php`
3. `CMS/core/Services/FeedService.php`
4. `CMS/admin/modules/system/DocumentationModule.php`
5. `CMS/admin/modules/legal/LegalSitesModule.php`

### Schritt 023 — 24.03.2026 — Cookie-Manager-Modul

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/legal/CookieManagerModule.php`
- `CMS/admin/cookie-manager.php`
- `CMS/admin/views/legal/cookies.php`

**Ergebnis dieses Schritts**

- `CookieManagerModule` wurde auf Zugriffs-, Persistenz- und Scanner-Pfaden deutlich nachgezogen:
  - Modulzugriff wird jetzt auch intern per Admin-Check abgesichert und nicht nur über den Entry-Point vorausgesetzt.
  - Cookie-Settings werden gesammelt gespeichert statt jede Option einzeln per Existenz-Check anzufassen.
  - Kategorie- und Service-Mutationen validieren Namen, Slugs, Beschreibungen, Cookie-Namen, Script-/Snippet-Längen und blockieren doppelte Slugs serverseitig.
  - Kategorien können nicht mehr gelöscht werden, solange noch Services auf sie zeigen.
  - alle Mutationen und Scanner-Läufe werden zusätzlich auditierbar protokolliert; Fehlpfade bleiben für die UI generisch.
- Der integrierte Scanner wurde defensiver und günstiger gemacht:
  - Dateiscans ignorieren Links weiterhin, lesen jetzt aber nur noch begrenzte Dateigrößen, stoppen nach einer festen Dateianzahl und begrenzen gefundene Resultate/Sources serverseitig.
  - DB-Scans für Seiten werden auf einen kleineren, erwartbaren Umfang geklemmt.
  - gespeicherte Scan-Ergebnisse werden beim Laden normalisiert und auf bekannte kuratierte Services beschränkt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/legal/CookieManagerModule.php`: Security deutlich verbessert; Speed, PHP/BP und Gesamt klar angehoben durch weniger N+1-Settings-Checks, bounded Scans und robustere Mutationspfade.
- `Admin – Module`: Aggregat erneut leicht verbessert, weil ein zuvor kritischer Legal-/Tracking-Hotspot kontrollierter speichert, scannt und auditiert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/AzureMailTokenProvider.php`
2. `CMS/core/Services/FeedService.php`
3. `CMS/admin/modules/system/DocumentationModule.php`
4. `CMS/admin/modules/legal/LegalSitesModule.php`
5. `CMS/admin/modules/landing/LandingPageModule.php`

### Schritt 024 — 24.03.2026 — Azure-Mail-Token-Provider

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/AzureMailTokenProvider.php`
- `CMS/core/Services/GraphApiService.php`
- `CMS/core/Services/MailService.php`
- `CMS/admin/modules/system/MailSettingsModule.php`

**Ergebnis dieses Schritts**

- `AzureMailTokenProvider` wurde an den kritischen Konfigurations-, Cache- und Response-Kanten nachgezogen:
  - Tenant-ID, Client-ID und Mailbox werden beim Laden restriktiver normalisiert und verworfen, wenn sie nicht zum erwarteten Azure-/Mail-Format passen.
  - zulässige Scopes sind jetzt auf bekannte Outlook-`.default`-Scopes begrenzt statt beliebige Werte ungeprüft weiterzureichen.
  - benutzerdefinierte Token-Endpunkte werden nur noch akzeptiert, wenn Host, Schema und Pfad exakt zur erwarteten Microsoft-Login-Struktur passen; Query-/Fragment-Anteile und ungültige Tenant-Pfade werden verworfen.
  - gecachte Tokens werden vor Wiederverwendung auf Form, Inhalt und Restlaufzeit geprüft; invalide oder fast abgelaufene Cache-Einträge werden aktiv entfernt.
  - Remote-Antworten laufen über eine zentrale JSON-/Fehler-Normalisierung mit Größenlimit, generischeren Fehlpfaden und bereinigten Remote-Meldungen.
  - Token-Typen werden konsistent auf `Bearer` normalisiert, damit nachgelagerte SMTP-/OAuth2-Pfade weniger Sonderfälle behandeln müssen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/AzureMailTokenProvider.php`: Security deutlich verbessert; Speed leicht verbessert durch kontrolliertere Cache-Nutzung; PHP/BP und Gesamt durch klarere Helper-/Response-Grenzen angehoben.
- `Core – Allgemeine Services`: Aggregat leicht verbessert, weil ein weiterer externer Token-/API-Hotspot restriktiver validiert und robuster auf Remote-Fehler reagiert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/FeedService.php`
2. `CMS/admin/modules/system/DocumentationModule.php`
3. `CMS/admin/modules/legal/LegalSitesModule.php`
4. `CMS/admin/modules/landing/LandingPageModule.php`
5. `CMS/core/Services/MailService.php`

### Schritt 025 — 24.03.2026 — Feed-Service

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/FeedService.php`
- `CMS/core/Http/Client.php`
- `CMS/core/Services/PurifierService.php`

**Ergebnis dieses Schritts**

- `FeedService` wurde an den Eingabe-, Ausgabe- und Cache-Kanten deutlich defensiver gemacht:
  - Feed-URLs werden jetzt vor der Verarbeitung auf erlaubte Schemes, Hostnamen ohne Credentials und auf private/reservierte Zielnetze geprüft.
  - Batch-Abrufe begrenzen Anzahl der Feed-URLs und deduplizieren ungültige oder doppelte Einträge früh.
  - rohe Parser-, Remote- und Exception-Fehler werden nicht mehr direkt an Aufrufer durchgereicht, sondern intern gekürzt geloggt und nach außen generisch beantwortet.
  - Feed-Metadaten und Item-Inhalte werden serverseitig bereinigt: Titel/Kategorien/Autoren/GUIDs werden normalisiert, HTML in Beschreibung und Content über den vorhandenen `PurifierService` gesäubert und URLs für Links/Bilder/Thumbnails auf öffentliche HTTP(S)-Ziele begrenzt.
  - der Cache-Cleanup läuft nicht mehr blind über `glob()`, sondern nur noch über reale Dateien innerhalb des Feed-Cache-Roots ohne Symlink-Folgen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/FeedService.php`: Security deutlich verbessert; Speed leicht verbessert durch begrenzte Batch-/Item-Mengen und kontrollierteren Cache-Cleanup; PHP/BP und Gesamt durch klarere Helper- und Sanitizer-Grenzen angehoben.
- `Core – Allgemeine Services`: Aggregat erneut leicht verbessert, weil ein weiterer externer XML-/Feed-Hotspot weniger vertrauensselig auf Remote-URLs, Parserfehler und Feed-HTML reagiert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php`
2. `CMS/admin/modules/legal/LegalSitesModule.php`
3. `CMS/admin/modules/landing/LandingPageModule.php`
4. `CMS/core/Services/MailService.php`
5. `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php`

### Schritt 026 — 24.03.2026 — Dokumentations-Modul

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`
- `CMS/admin/modules/system/DocumentationCatalog.php`
- `CMS/admin/modules/system/DocumentationSyncService.php`
- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- `DocumentationModule` wurde als Orchestrator an seinen eigenen Vertrauenskanten nachgezogen:
  - Modulzugriff wird nun auch intern abgesichert und nicht nur über den Entry-Point vorausgesetzt.
  - Repository-/`/DOC`-Layout wird vor Datenaufbau und Sync-Aufruf validiert, damit das Modul keine inkonsistenten oder aus dem Repo herausfallenden Pfade verarbeitet.
  - ausgewählte Dokumentpfade werden auf Länge und erlaubte Erweiterungen (`md`, `csv`) begrenzt.
  - Render- und Sync-Pfade laufen jetzt unter einem zentralen `try/catch`, loggen Details intern und geben nach außen nur noch begrenzte, generische Meldungen zurück.
  - Rückgabedaten für Fehlerzustände bleiben vollständig strukturiert, sodass die View auch bei Fehlkonfigurationen konsistent rendern kann.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security klar verbessert; Speed leicht verbessert durch frühere Layout-/Auswahlvalidierung; PHP/BP und Gesamt durch sauberere Guard- und Fehlergrenzen angehoben.
- `Admin – Module`: Aggregat leicht verbessert, weil der Doku-Orchestrator weniger lose Annahmen über Pfade, Auswahlparameter und Sync-Fehler trifft.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/legal/LegalSitesModule.php`
2. `CMS/admin/modules/landing/LandingPageModule.php`
3. `CMS/core/Services/MailService.php`
4. `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php`
5. `CMS/admin/modules/system/DocumentationSyncFilesystem.php`

### Schritt 027 — 24.03.2026 — Legal-Sites-Modul

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/legal/LegalSitesModule.php`
- `CMS/admin/legal-sites.php`
- `CMS/core/PageManager.php`

**Ergebnis dieses Schritts**

- `LegalSitesModule` wurde auf Mutations-, Template- und Seitenzuordnungs-Pfaden deutlich nachgezogen:
  - Modulzugriff wird jetzt auch intern per Admin-Guard abgesichert.
  - Settings-Reads für Inhalte, Profilwerte und Seitenzuordnungen laufen gebündelt statt über viele Einzelabfragen.
  - zugewiesene Rechtstext-Seiten werden vor dem Speichern gegen veröffentlichte Seiten validiert.
  - HTML- und Profilwerte werden stärker normalisiert und auf sinnvolle Längen begrenzt; Telefon, Website und Mail-Adressen werden restriktiver bereinigt.
  - Fehlerpfade loggen Details intern gekürzt, während Audit-Einträge keine rohen Exception-Texte mehr transportieren.
  - bestehende Persistenzpfade für generierte Rechtstexte und Seitensynchronisierung nutzen jetzt denselben konsistenten Settings-Writer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/legal/LegalSitesModule.php`: Security deutlich verbessert; Speed klar verbessert durch gebündelte Settings-Reads; PHP/BP und Gesamt durch zentralere Persistenz- und Fehlerpfade angehoben.
- `Admin – Module`: Aggregat erneut leicht verbessert, weil ein zuvor kritischer Legal-Hotspot weniger lose Eingaben, Seitenreferenzen und Fehlerdetails verarbeitet.

### Schritt 028 — 24.03.2026 — Landing-Page-Modul

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/landing/LandingPageModule.php`
- `CMS/admin/landing-page.php`
- `CMS/core/Services/LandingPageService.php`
- `CMS/core/Services/Landing/LandingSanitizer.php`

**Ergebnis dieses Schritts**

- `LandingPageModule` vertraut nicht mehr blind auf den Entry-Point:
  - Admin-Zugriff wird jetzt im Modul selbst geprüft.
  - der aktive Tab wird serverseitig normalisiert.
  - Header-, Content-, Footer-, Design-, Feature- und Plugin-Payloads werden per Whitelist gefiltert und vor dem Service-Aufruf auf erlaubte Werte begrenzt.
  - IDs, Sortierungen, Enums, Farben, URLs und Asset-Pfade werden restriktiver validiert.
  - UI-Fehlerpfade geben keine rohen Exception-Meldungen mehr an Administratoren zurück, sondern loggen intern kontrolliert und antworten generisch.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/landing/LandingPageModule.php`: Security klar verbessert; Speed leicht verbessert durch kleinere, gefilterte Payloads; PHP/BP und Gesamt durch konsistentere Eingangsvalidierung und Fehlerkapselung angehoben.
- `Admin – Module`: Aggregat nochmals leicht verbessert, weil ein weiterer häufig genutzter Admin-Writer keine freien POST-Arrays und keine ungefilterten Fehlermeldungen mehr durchreicht.

### Schritt 029 — 24.03.2026 — Mail-Service

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/MailService.php`
- `CMS/admin/modules/system/MailSettingsModule.php`
- `CMS/core/Services/MailQueueService.php`

**Ergebnis dieses Schritts**

- `MailService` wurde in den sicherheitskritischen Versandpfaden nachgeschärft:
  - Header-Namen und Header-Werte werden vor der Weitergabe konsequent normalisiert und gegen Header-Injection abgesichert.
  - Empfänger-, Absender-, Betreff- und Adresslisten werden restriktiver validiert, bevor Symfony Mailer oder das `mail()`-Fallback sie verwenden.
  - SMTP ohne Verschlüsselung wird für nicht-lokale Hosts und OAuth2-basierte Transporte nicht mehr stillschweigend ungeschützt gefahren, sondern auf TLS hochgezogen.
  - UI-Rückgaben aus den Detailed-Send-Pfaden geben keine rohen Provider- oder Exception-Texte mehr zurück, sondern klassifizierte, transportbezogen generische Fehlertexte.
  - Log- und Fehlertexte werden intern gekürzt und von Steuerzeichen bereinigt, damit weder UI noch Logs unnötig viele Transportdetails übernehmen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/MailService.php`: Security deutlich verbessert; Speed leicht verbessert; PHP/BP und Gesamt durch robustere Validierung, TLS-Absicherung und kontrolliertere Fehlerpfade klar angehoben.
- `Core – Allgemeine Services`: Aggregat verbessert, weil ein zentraler Versandservice keine freien Header mehr annimmt und Remote-SMTP nicht mehr locker ohne TLS weiterreicht.

### Schritt 030 — 24.03.2026 — EditorJs Remote-Media-Service

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php`
- `CMS/core/Http/Client.php`
- `CMS/core/Services/EditorJs/EditorJsUploadService.php`

**Ergebnis dieses Schritts**

- `EditorJsRemoteMediaService` wurde für externe Bilder und Link-Metadaten deutlich restriktiver gemacht:
  - Remote-URLs werden zentral normalisiert, auf HTTPS begrenzt und gegen eingebettete Zugangsdaten, Zeilenumbrüche und überlange Eingaben abgesichert.
  - Remote-Bild- und HTML-Fehler werden intern protokolliert, aber nicht mehr mit rohen Netzwerkdetails an Editor.js zurückgereicht.
  - Metadaten aus fremdem HTML werden stärker bereinigt und in Länge begrenzt; Vorschau-Bilder werden nur noch als validierte, sichere Remote-URLs übernommen.
  - die HTML-Verarbeitung nutzt begrenzte Payload-Größen und stellt den globalen libxml-Fehlerzustand sauber wieder her.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php`: Security deutlich verbessert; Speed leicht verbessert; PHP/BP und Gesamt wegen strengerer Remote-Gates und kontrollierterer Fehlerpfade klar angehoben.
- `Core – Allgemeine Services` und `Core – EditorJs Services`: beide Aggregate steigen, weil ein zentraler Remote-Eingang für Editor.js keine losen HTTP-/Metadatenpfade mehr offenlässt.

### Schritt 031 — 24.03.2026 — Documentation-Sync-Dateisystem

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncFilesystem.php`
- `CMS/admin/modules/system/DocumentationSyncService.php`
- `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der Dateisystem-Layer des Doku-Syncs arbeitet jetzt nur noch innerhalb explizit verwalteter Repo-/DOC-/Temp-Roots:
  - Integrity-, Find-, Count-, Copy-, Rename-, Delete- und Unlink-Pfade verwerfen Ziele außerhalb der erlaubten Sync-Roots.
  - auch nicht existente Zielpfade werden über ihren aufgelösten Elternpfad gegen die erlaubten Wurzeln geprüft.
  - der Sync-Service instanziiert den Filesystem-Dienst nun mit expliziten Root-Grenzen statt ohne Kontext.
  - fehlgeleitete Staging-, Backup-, Extract- oder Cleanup-Pfade werden konsistent geloggt und früh abgebrochen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncFilesystem.php`: Security deutlich verbessert; Speed leicht verbessert; PHP/BP und Gesamt durch konsistentere Guard-Pfade und Root-Isolation angehoben.
- `Admin – Module`: Aggregat steigt erneut leicht, weil ein sicherheitsrelevanter Helper für Rename/Copy/Delete nicht mehr außerhalb der verwalteten Doku-Arbeitsbereiche operiert.

### Schritt 032 — 24.03.2026 — Member-Dashboard-Modul

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/member/MemberDashboardModule.php`
- `CMS/admin/member-dashboard-page.php`
- `CMS/admin/partials/section-page-shell.php`

### Schritt 033 — 24.03.2026 — BackupsModule

**Datei:** `CMS/admin/modules/system/BackupsModule.php`

**Maßnahmen**

- Interne Lese- und Schreib-Gates per Admin-/Capability-Prüfung nachgezogen, damit das Modul nicht nur auf den äußeren Entry-Point vertraut.
- CSRF-Prüfung für Create-/Delete-Pfade zusätzlich im Modul selbst verankert.
- Backup-Namen, Dateiendungen, Typen und History-/UI-Metadaten serverseitig normalisiert und auf explizit erlaubte Muster reduziert.
- Erfolgreiche Backup-Erstellung bzw. -Löschung auditierbar gemacht; Fehlerpfade loggen nur noch gekürzte Kontextdaten über dedizierten Logger-Kanal.
- Listen- und History-Daten für die UI vor der Ausgabe sanitisiert, sodass lose Manifest-/Logeinträge nicht ungeprüft in den View-Kontext gelangen.

**Geänderte Dateien**

- `CMS/admin/modules/system/BackupsModule.php`

**Ergebnis dieses Schritts**

- Das Backup-Modul arbeitet nicht mehr blind als dünner Service-Pass-Through:
  - Mutationen verlangen jetzt auch intern gültige Admin-Rechte und ein passendes CSRF-Token.
  - Lösch- und Anzeige-Pfade akzeptieren nur noch validierte Backup-Namen, bekannte Typen und erlaubte Dateiendungen.
  - Erfolgreiche Create-/Delete-Aktionen werden explizit auditiert; Fehlerpfade streuen keine rohen Exception-Texte mehr.
  - Backup- und History-Daten werden vor der UI-Ausgabe normalisiert, gekürzt und auf erwartete Felder begrenzt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/BackupsModule.php`: Security deutlich verbessert; Speed leicht verbessert; PHP/BP und Gesamt durch interne Guards, validierte Backup-Metadaten und kontrollierte Audit-/Logpfade klar angehoben.
- `Admin – Module`: Aggregat steigt erneut, weil ein zuvor kritischer Dateisystem-/Backup-Hotspot nicht mehr ohne interne Access-/CSRF-Kontrollen und ohne validierte UI-Metadaten arbeitet.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/RemoteImageService.php`
2. `CMS/core/Services/RemoteFileService.php`
3. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
5. `CMS/admin/modules/system/DocumentationGitSync.php`

### Schritt 034 — 24.03.2026 — DocumentationGithubZipSync

**Datei:** `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Maßnahmen**

- GitHub-ZIP-Quelle zusätzlich im Modul selbst gegen erlaubte Hosts, HTTPS und erwartete ZIP-Pfade validiert.
- ZIP-Archiv-Einträge um harte Grenzen für Eintragsanzahl, Segment-Sauberkeit und gesamte unkomprimierte Archivgröße ergänzt.
- Integritätsprofil-Konfiguration vor dem Download explizit geprüft und Hash-Vergleich normiert.
- Erfolgreiche GitHub-ZIP-Syncs explizit auditiert; Fehlerpfade loggen nur noch Exception-Klasse und gekürzte, bereinigte Kontextwerte.
- Remote-URL- und Pfadkontexte für Logs/Audit auf sichere, verkürzte Form gebracht.

**Geänderte Dateien**

- `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der ZIP-basierte Doku-Sync verlässt sich nicht mehr nur auf Vorvalidierung im Orchestrator oder Downloader:
  - ZIP-Quelle und Integritätsprofil werden auch im Sync-Modul selbst hart geprüft.
  - Übermäßig große oder ungewöhnliche Archive werden vor dem Entpacken geblockt.
  - Erfolgs- und Fehlerpfade auditieren strukturierter und mit weniger sensiblen Rohdaten.
  - Logger-/Audit-Kontexte enthalten keine ungefilterten Exception-Texte oder kompletten Fremd-URLs mehr.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationGithubZipSync.php`: Security deutlich verbessert; Speed leicht verbessert; PHP/BP und Gesamt durch zusätzliche Archivgrenzen und sauberere Audit-Kontexte klar angehoben.
- `Admin – Module`: Aggregat steigt erneut, weil ein weiterer kritischer Remote-/Archiv-Sync-Pfad restriktiver validiert und kontrollierter protokolliert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
2. `CMS/admin/modules/system/DocumentationGitSync.php`
3. `CMS/admin/modules/seo/SeoSuiteModule.php`
4. `CMS/admin/modules/seo/PerformanceModule.php`
5. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

### Schritt 035 — 24.03.2026 — DocumentationSyncDownloader

**Datei:** `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Maßnahmen**

- Download-URLs zusätzlich gegen HTTPS, erlaubte GitHub-Hosts, erwartete ZIP-Pfade und fehlende Credentials abgesichert.
- Temporäre ZIP-Ziele auf das dedizierte `365cms_doc_sync_*`-Namensschema im Temp-Root begrenzt.
- Response-Bodies auf Mindestgröße, Maximalgröße und ZIP-Magic-Header geprüft, bevor Dateien lokal gespeichert werden.
- Erfolgs- und Fehlerpfade für Downloads explizit über Logger/Audit kanalisiert und UI-Fehlertexte generisch gehalten.
- Log-Kontexte für URLs, Pfade und Remote-Fehler bereinigt und gekürzt.

**Geänderte Dateien**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Ergebnis dieses Schritts**

- Der Doku-Downloader verarbeitet Remote-Antworten nicht mehr als „wird schon ein ZIP sein“:
  - nur erwartete GitHub-ZIP-URLs und dedizierte Temp-Ziele werden akzeptiert.
  - leere, zu kleine, zu große oder nicht-zipartige Bodies werden früh verworfen.
  - Erfolgs- und Fehlerpfade sind auditierbar und geben keine rohen Remote-Fehler mehr direkt weiter.
  - URL- und Pfadkontexte landen nur noch in sanitierter Kurzform in Logs/Audit.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security deutlich verbessert; Speed leicht verbessert; PHP/BP und Gesamt durch restriktivere Downloader-Policies und kontrollierte Fehlerpfade klar angehoben.
- `Admin – Module`: Aggregat steigt erneut, weil ein weiterer kritischer Remote-Download-Pfad nicht mehr lose auf HTTP- und Dateisystemantworten vertraut.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationGitSync.php`
2. `CMS/admin/modules/seo/SeoSuiteModule.php`
3. `CMS/admin/modules/seo/PerformanceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/modules/settings/SettingsModule.php`

### Schritt 036 — 24.03.2026 — Root-Cron-Entry

**Datei:** `CMS/cron.php`

**Maßnahmen**

- Web-Aufrufe auf `GET` und `HEAD` begrenzt; `HEAD`-Responses liefern keinen unnötigen JSON-Body mehr.
- `task`- und `limit`-Parameter serverseitig normalisiert und auf bekannte Task-Namen bzw. sichere Grenzen reduziert.
- Cron-Token zusätzlich über `X-CMS-CRON-TOKEN` bzw. `X-CRON-TOKEN` unterstützt, um Query-Leaks zu verringern.
- Parallele Cron-Läufe per nicht-blockierendem Lockfile im Temp-Root abgefangen.
- Öffentliche Fehlerantworten generisch gemacht; technische Fehlerdetails werden nur noch sanitisiert intern geloggt.
- Web-Cron-Antworten mit `X-Robots-Tag: noindex, nofollow, noarchive` markiert und unnötiger Session-Start entfernt.

**Geänderte Dateien**

- `CMS/cron.php`

**Ergebnis dieses Schritts**

- Der öffentliche Cron-Endpunkt (`CMS/cron.php` im Repo, deployed meist als `cron.php` im Webroot) vertraut nicht mehr blind auf rohe Web-Requests:
  - nur noch erwartete Web-Methoden werden akzeptiert.
  - Task- und Limit-Werte werden eingegrenzt statt lose übernommen.
  - parallele Ausführungen kollidieren nicht mehr still, sondern werden kontrolliert abgewiesen.
  - technische Fehlerdetails bleiben intern und tauchen nicht mehr ungefiltert in JSON-Antworten auf.
  - Monitoring-/Health-Checks via `HEAD` bleiben möglich, ohne unnötigen Body zu erzeugen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/cron.php`: Security deutlich verbessert; Speed verbessert; PHP/BP und Gesamt durch klarere Guards, Locking und sauberere Fehlerpfade angehoben.
- `Core – Entry- und Betriebs-Hotspots`: Aggregat steigt leicht, weil ein öffentlicher Cron-Entry nicht mehr lose auf Query-Parameter, Parallel-Läufe und rohe Exception-Ausgaben vertraut.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationGitSync.php`
2. `CMS/admin/modules/seo/SeoSuiteModule.php`
3. `CMS/admin/modules/seo/PerformanceModule.php`
4. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
5. `CMS/admin/modules/settings/SettingsModule.php`

### Schritt 037 — 24.03.2026 — DocumentationGitSync

**Datei:** `CMS/admin/modules/system/DocumentationGitSync.php`

**Maßnahmen**

- Parallele Git-Synchronisationen per nicht-blockierendem Lockfile serialisiert.
- Fetch auf `--no-tags --prune --no-recurse-submodules` begrenzt, um Nebenwirkungen zu reduzieren.
- Remote-Ref vor dem Checkout explizit per `rev-parse --verify --quiet` geprüft.
- Lokale Änderungen und untracked Dateien unter `/DOC` vor dem Checkout erkannt und als kontrollierter Abbruch behandelt.
- Status- und Runtime-Fehler über den bestehenden generischen Fehlerpfad mit sanitierten Pfad-/Ref-Kontexten kanalisiert.

**Geänderte Dateien**

- `CMS/admin/modules/system/DocumentationGitSync.php`

**Ergebnis dieses Schritts**

- Der Git-basierte Doku-Sync arbeitet jetzt deutlich kontrollierter:
  - parallele Läufe werden nicht mehr unkoordiniert gegeneinander ausgeführt.
  - ein fehlender oder inkonsistenter Remote-Ref wird vor dem Checkout abgefangen.
  - lokale `/DOC`-Änderungen werden nicht mehr still überschrieben.
  - Git-Status- und Runtime-Fehler laufen zuverlässig über den bekannten, auditierbaren Modul-Fehlerpfad.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationGitSync.php`: Security deutlich verbessert; Speed verbessert; PHP/BP und Gesamt klar angehoben.
- `Admin – Module`: Aggregat steigt erneut leicht, weil ein weiterer Shell-/Git-Hotspot nicht mehr parallel, ref-blind oder gegen lokale Arbeitsbaumänderungen arbeitet.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/seo/SeoSuiteModule.php`
2. `CMS/admin/modules/seo/PerformanceModule.php`
3. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
4. `CMS/admin/modules/settings/SettingsModule.php`
5. `CMS/admin/modules/security/SecurityAuditModule.php`

### Schritt 038 — 24.03.2026 — SeoSuiteModule

**Datei:** `CMS/admin/modules/seo/SeoSuiteModule.php`

**Maßnahmen**

- Submission-Targets für URL-Übermittlungen auf die bekannten Ziele `indexnow` und `google` begrenzt und dedupliziert.
- Social-Defaults für OG-Type und Twitter-Card auf definierte Allowlists reduziert.
- Matomo-Site-ID serverseitig auf positive Ganzzahlen normalisiert.
- Fehlerkontexte bei Sitemap-Generierung vor Audit-Logging sanitisiert und gekürzt.
- Sitemap-Dateistatusdaten ohne absolute Serverpfade ausgegeben.
- Settings-Persistenz auf vorgeladene Existenzprüfung umgestellt statt COUNT-Query pro Einzelwert.

**Geänderte Dateien**

- `CMS/admin/modules/seo/SeoSuiteModule.php`

**Ergebnis dieses Schritts**

- Die SEO-Suite arbeitet jetzt kontrollierter und effizienter:
  - lose Payload-Werte für Submission- und Social-Konfigurationen werden serverseitig eingegrenzt.
  - technische Fehlertexte landen bereinigt im Audit-Kontext statt roh weitergereicht zu werden.
  - Sitemap-Statusdaten verraten keine absoluten Serverpfade mehr.
  - Settings-Speicherpfade erzeugen deutlich weniger unnötige Existenz-Queries.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/seo/SeoSuiteModule.php`: Security klar verbessert; Speed deutlich verbessert; PHP/BP und Gesamt angehoben.
- `Admin – Module`: Aggregat steigt erneut leicht, weil ein großer SEO-Kernpfad weniger lose Input-Werte akzeptiert und seine Persistenz nicht mehr per N+1-Existenzcheck organisiert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/seo/PerformanceModule.php`
2. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
3. `CMS/admin/modules/settings/SettingsModule.php`
4. `CMS/admin/modules/security/SecurityAuditModule.php`
5. `CMS/admin/modules/system/DocumentationRenderer.php`

### Schritt 039 — 24.03.2026 — PerformanceModule

**Datei:** `CMS/admin/modules/seo/PerformanceModule.php`

**Maßnahmen**

- Save-Persistenz für Performance-Settings auf vorgeladene Existenzprüfung umgestellt statt COUNT-Query pro Einzelwert.
- Cache-Verzeichnisangaben und Medienlisten auf bereinigte relative Laufzeitpfade reduziert.
- Session-Listen maskieren IP-Adressen und bereinigen User-Agent-Werte vor der View-Ausgabe.
- OPcache-Warmup- und Cache-Clear-Audit-Kontexte auf kompakte, sanitierte Kurzfassungen reduziert.
- Save-Fehler im Settings-Flow werden mit gekürztem, bereinigtem Exception-Kontext auditierbar protokolliert.

**Geänderte Dateien**

- `CMS/admin/modules/seo/PerformanceModule.php`

**Ergebnis dieses Schritts**

- Das Performance-Modul arbeitet jetzt kontrollierter und etwas effizienter:
  - Settings-Speicherpfade vermeiden unnötige N+1-Existenzabfragen.
  - Session- und Pfaddaten verraten weniger unnötige Server- oder Personendetails.
  - Warmup- und Fehlerkontexte bleiben im Audit-Log kompakt statt ausufernd.
  - Medien- und Cache-Ansichten bekommen weiterhin brauchbare, aber defensiver aufbereitete Runtime-Daten.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/seo/PerformanceModule.php`: Security klar verbessert; Speed deutlich verbessert; PHP/BP und Gesamt angehoben.
- `Admin – Module`: Aggregat steigt erneut leicht, weil ein großer Betriebs-Hotspot effizienter speichert und weniger unnötige Kontextdaten preisgibt.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/plugins/PluginMarketplaceModule.php`
2. `CMS/admin/modules/settings/SettingsModule.php`
3. `CMS/admin/modules/security/SecurityAuditModule.php`
4. `CMS/admin/modules/system/DocumentationRenderer.php`
5. `CMS/admin/modules/legal/CookieManagerModule.php`

### Schritt 040 — 24.03.2026 — PluginMarketplaceModule

**Datei:** `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Maßnahmen**

- Registry- und Manifest-Downloads auf zentrale Größenlimits gelegt.
- Lokale Manifestpfade nur noch als sichere relative Katalogpfade ohne Traversal-Segmente zugelassen.
- Lokale Manifestdateien zusätzlich per `realpath()` gegen den erlaubten Katalog-Root eingegrenzt.
- Plugin-Zielverzeichnis vor Auto-Installationen auf Schreibbarkeit und Runtime-Root geprüft.
- ZIP-Archive zusätzlich auf Eintragsanzahl, unkomprimierte Gesamtgröße, Kontrollzeichen und segmentierte Pfadmanipulationen geprüft.
- Download-/Speicher-/Entpackpfade via `try/finally` sauberer aufgeräumt und Schreibfehler beim temporären Paket-Store explizit behandelt.

**Geänderte Dateien**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`

**Ergebnis dieses Schritts**

- Der Plugin-Marketplace arbeitet jetzt deutlich kontrollierter:
  - lokale Manifestpfade können nicht mehr lose aus dem Katalog-Root ausbrechen.
  - auffällige ZIP-Pakete werden vor dem Entpacken früher abgefangen.
  - Auto-Installationen prüfen ihr Zielverzeichnis robuster und räumen temporäre Dateien sauberer auf.
  - Registry- und Manifest-Antworten bleiben auf sinnvolle Größen begrenzt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/plugins/PluginMarketplaceModule.php`: Security deutlich verbessert; Speed verbessert; PHP/BP und Gesamt klar angehoben.
- `Admin – Module`: Aggregat steigt erneut leicht, weil ein weiterer Supply-Chain-/Installations-Hotspot weniger lose Pfade und Paketstrukturen akzeptiert.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/settings/SettingsModule.php`
2. `CMS/admin/modules/security/SecurityAuditModule.php`
3. `CMS/admin/modules/system/DocumentationRenderer.php`
4. `CMS/admin/modules/legal/CookieManagerModule.php`
5. `CMS/admin/modules/system/BackupsModule.php`

### Schritt 041 — 24.03.2026 — SettingsModule

**Datei:** `CMS/admin/modules/settings/SettingsModule.php`

**Maßnahmen**

- Settings-Persistenz auf ein vorgeladenes Set vorhandener Optionsnamen umgestellt, damit pro Save kein zusätzlicher `COUNT(*)`-Lookup je Schlüssel mehr nötig ist.
- Audit-Fehlerpfade für allgemeines Speichern, URL-Nachmigration und Import-Slug-Reparatur auf sanitierte Kurztexte begrenzt.
- URL-Nachmigrationen als eigene Audit-Aktion mit kompaktem Ergebnis-Summary nachgezogen.
- Test-Mail-Audits maskieren Empfängeradressen jetzt defensiv statt rohe Mailadressen vollständig mitzuschreiben.
- `writeFileAtomically()` auf robusteren Ersatzpfad mit Fallback-Restore umgestellt, damit `config/app.php` und `.htaccess` nicht unnötig in ein Löschfenster geraten.
- Tabellen- und Spaltenprüfungen für URL-Migrationsziele gecacht, damit wiederholte `SHOW TABLES`-/`SHOW COLUMNS`-Läufe nicht pro Durchgang neu aufschlagen.

**Geänderte Dateien**

- `CMS/admin/modules/settings/SettingsModule.php`

**Ergebnis dieses Schritts**

- Das Settings-Modul arbeitet jetzt kontrollierter und effizienter:
  - Konfigurationsschreibpfade verlieren ihr unnötiges Delete-before-replace-Risiko.
  - Audit-Logs enthalten weniger rohe Fehler- und Maildaten.
  - URL-Migrationen bleiben nachvollziehbar, ohne Detailmüll in den Audit-Kontext zu kippen.
  - wiederkehrende Settings- und Schema-Lookups laufen deutlich schlanker.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/settings/SettingsModule.php`: Security deutlich verbessert; Speed klar verbessert; PHP/BP und Gesamt weiter angehoben.
- `Admin – Module`: Aggregat steigt erneut leicht, weil ein kritischer Runtime-Konfigurationspfad weniger Detail-Leaks, weniger Redundanz und robustere Dateiersetzung mitbringt.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/security/SecurityAuditModule.php`
2. `CMS/admin/modules/system/DocumentationRenderer.php`
3. `CMS/admin/modules/legal/CookieManagerModule.php`
4. `CMS/admin/modules/system/BackupsModule.php`
5. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`

### Schritt 042 — 24.03.2026 — SecurityAuditModule

**Datei:** `CMS/admin/modules/security/SecurityAuditModule.php`

**Maßnahmen**

- Cleanup alter Audit-Logs auf die im Modul tatsächlich relevanten Kategorien `security` und `auth` begrenzt.
- Audit-Detailtexte, Metadaten und Fehlerkontexte über einen gemeinsamen Sanitize-Pfad defensiver gemacht.
- IP-Adressen in der Security-Audit-Ansicht maskiert statt roh auszugeben.
- `.htaccess`-Prüfpfade loggen keine unnötigen absoluten Dateisystempfade mehr.
- Security-Prüfungen um gezieltere Berechtigungschecks für `config/app.php` ergänzt.

**Geänderte Dateien**

- `CMS/admin/modules/security/SecurityAuditModule.php`

**Ergebnis dieses Schritts**

- Das Security-Audit-Modul arbeitet jetzt fokussierter und defensiver:
  - Log-Bereinigungen greifen nicht mehr fachfremd in andere Audit-Kategorien ein.
  - die UI zeigt weniger sensible Audit- und IP-Details.
  - die Runtime-Konfiguration wird näher an der realen Produktionsstruktur bewertet.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/security/SecurityAuditModule.php`: Security und Speed klar verbessert; Gesamt weiter angehoben.
- `Admin – Security`: Aggregat steigt leicht, weil ein Diagnose-Hotspot selbst weniger Daten preisgibt und seine Cleanup-Reichweite sauberer begrenzt.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationRenderer.php`
2. `CMS/admin/modules/legal/CookieManagerModule.php`
3. `CMS/admin/modules/system/BackupsModule.php`
4. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`
5. `CMS/admin/modules/system/MailSettingsModule.php`

### Schritt 043 — 24.03.2026 — DocumentationRenderer

**Datei:** `CMS/admin/modules/system/DocumentationRenderer.php`

**Maßnahmen**

- Markdown-Linkziele vor dem Resolver zusätzlich begrenzt, damit ausufernde Targets das Rendering nicht unnötig aufblasen.
- Resolver-Ausnahmen beim Inline-Link-Rendering abgefangen und auf sichere `#`-Fallbacks zurückgeführt.
- `sanitizeHref()` gegen protokollrelative `//`-Links, Backslashes und Steuerzeichen nachgeschärft.
- Große Markdown-Codeblöcke über ein eigenes Zeilenlimit mit Guard-Logging gedeckelt.

**Geänderte Dateien**

- `CMS/admin/modules/system/DocumentationRenderer.php`

**Ergebnis dieses Schritts**

- Der Documentation-Renderer bleibt jetzt stabiler und defensiver:
  - fehlerhafte Linkauflösungen reißen nicht mehr das gesamte Dokument mit.
  - problematische Hrefs werden früher auf sichere Platzhalter reduziert.
  - große Codefences laufen kontrollierter durch den Admin-Renderer.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationRenderer.php`: Security und Speed verbessert; Gesamt leicht angehoben.
- `Admin – System/Dokumentation`: Aggregat steigt leicht, weil ein weiterer Renderpfad weniger Sonderfälle und Fehlverhalten direkt an die Oberfläche weitergibt.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/legal/CookieManagerModule.php`
2. `CMS/admin/modules/system/BackupsModule.php`
3. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`
4. `CMS/admin/modules/system/MailSettingsModule.php`
5. `CMS/admin/documentation.php`

### Schritt 044 — 25.03.2026 — CookieManagerModule

**Datei:** `CMS/admin/modules/legal/CookieManagerModule.php`

**Maßnahmen**

- Matomo-Self-Hosted-URLs serverseitig auf gültige HTTP(S)-Ziele ohne Credentials begrenzt und bei ungültigen Eingaben mit klarer Admin-Fehlermeldung abgefangen.
- Default-Kategorien und Setting-Existenzprüfungen über interne Caches zusammengezogen, damit wiederholte DB-Existenzchecks im selben Request entfallen.
- Cookie-Scanner auf zusätzliche Skip-Pfade für Cache-, Upload-, Vendor-, Backup-, Test- und Staging-Verzeichnisse gestellt.
- Scan-Ergebnisse und Zeitstempel in einem gebündelten Settings-Write persistiert und DB-Seitenquellen auf slug-/ID-basierte, weniger plaudernde Quellenlabels reduziert.

**Geänderte Dateien**

- `CMS/admin/modules/legal/CookieManagerModule.php`

**Ergebnis dieses Schritts**

- Der Cookie-Manager arbeitet jetzt gezielter und robuster:
  - Tracking-Ziele für Matomo werden nicht mehr als lose Roh-URLs gespeichert.
  - Scanner-Läufe verschwenden weniger Zeit auf operative Nebenverzeichnisse.
  - wiederholte Settings-/Kategorie-Lookups werden im Request sauberer gebündelt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/legal/CookieManagerModule.php`: Security, Speed und PHP/BP verbessert; Gesamt angehoben.
- `Admin – Legal/Cookies`: Aggregat steigt leicht, weil Tracking-Konfiguration und Scan-Pfade defensiver sowie effizienter verarbeitet werden.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/BackupsModule.php`
2. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`
3. `CMS/admin/modules/system/MailSettingsModule.php`
4. `CMS/admin/documentation.php`
5. `CMS/admin/mail-settings.php`

### Schritt 045 — 25.03.2026 — SubscriptionSettingsModule

**Datei:** `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`

**Maßnahmen**

- Modulzugriff intern an `Auth::instance()->isAdmin()` gekoppelt, damit der Settings-Pfad nicht nur vom Entry-Point abhängt.
- Allgemeine und Paket-Settings über gemeinsame Bulk-Reads/Bulk-Writes mit internem Setting-Existenzcache zusammengezogen.
- Default-Plan-, AGB- und Widerrufsseiten-IDs gegen echte Datenbestände validiert statt rohe Integerwerte blind zu speichern.
- Billing-Felder wie Zahlungsmethode, Rechnungspräfix, Steuersatz und Benachrichtigungs-E-Mail restriktiver normalisiert.
- Erfolgs- und Fehlerpfade über Audit-/Logger-Ereignisse vereinheitlicht; rohe Exception-Texte gehen nicht mehr direkt an die UI.

**Geänderte Dateien**

- `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`

**Ergebnis dieses Schritts**

- Die Subscription-Settings arbeiten jetzt kontrollierter und effizienter:
  - Settings-Zugriffe laufen gesammelt statt pro Option einzeln.
  - Default-Zuweisungen zeigen nicht mehr auf beliebige oder nicht veröffentlichte IDs.
  - Billing-/Mail-Felder werden serverseitig konsistenter eingegrenzt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`: Security, Speed und PHP/BP verbessert; Gesamt angehoben.
- `Admin – Subscriptions`: Aggregat steigt leicht, weil Settings-Persistenz und Fehlerbehandlung weniger lose Trust-Boundaries offenlassen.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/MailSettingsModule.php`
2. `CMS/admin/modules/system/BackupsModule.php`
3. `CMS/admin/documentation.php`
4. `CMS/admin/mail-settings.php`
5. `CMS/admin/packages.php`

### Schritt 046 — 25.03.2026 — MailSettingsModule

**Dateien:** `CMS/admin/mail-settings.php`, `CMS/admin/modules/system/MailSettingsModule.php`

**Maßnahmen**

- Mail-Entry auf explizite Action-Allowlist und konsistente Redirect-Basis umgestellt, damit keine unbekannten POST-Mutationen mehr im Wrapper landen.
- Modulzugriff intern an `Auth::instance()->isAdmin()` gekoppelt, damit Mail-Konfigurationspfade nicht nur vom Entry-Point abgesichert werden.
- SMTP-Host, Azure-/Graph-Endpunkte sowie Test-/Queue-Empfänger restriktiver validiert und normalisiert.
- Tenant-/Client-Kennungen und Empfängeradressen in Audit-Kontexten maskiert statt roh mitzuschreiben.
- Queue-Läufe nur noch mit kompakten Summaries auditiert, nicht mehr mit kompletten Ergebnisarrays.
- Save-, Queue-, Graph- und Testpfade auf generischere Fehlermeldungen plus interne Logger-/Audit-Pfade umgestellt; Cache-Clears werden explizit auditiert.

**Geänderte Dateien**

- `CMS/admin/mail-settings.php`
- `CMS/admin/modules/system/MailSettingsModule.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings arbeiten jetzt kontrollierter und defensiver:
  - der Entry nimmt nur noch bekannte Mail-Aktionen entgegen.
  - unsaubere Host-/Endpoint-Werte werden früher abgefangen.
  - sensible Identitätsdaten werden im Audit-Log nicht mehr unnötig offengelegt.
  - operative Test- und Queue-Pfade liefern kompaktere, UI-tauglichere Ergebnisse.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security und PHP/BP verbessert; Gesamt leicht angehoben.
- `CMS/admin/modules/system/MailSettingsModule.php`: Security, Speed und PHP/BP verbessert; Gesamt angehoben.
- `Admin – System/Mail`: Aggregat steigt leicht, weil Konfigurations- und Testpfade weniger lose Trust-Boundaries und Detail-Leaks enthalten.

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/BackupsModule.php`
2. `CMS/admin/documentation.php`
3. `CMS/admin/mail-settings.php`
4. `CMS/admin/packages.php`
5. `CMS/admin/modules/system/DocumentationCatalog.php`

### Schritt 047 — 25.03.2026 — Backup-Entry / BackupsModule / BackupService

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/backups.php`
- `CMS/admin/modules/system/BackupsModule.php`
- `CMS/core/Services/BackupService.php`

**Ergebnis dieses Schritts**

- `backups.php` wurde weiter vereinheitlicht:
  - POST-Aktionen laufen jetzt über einen zentralen Allowlist-Dispatch.
  - unbekannte Aktionen liefern denselben generischen Flash-/Redirect-Pfad wie andere Admin-Entrys.
- `BackupsModule` wurde im operativen DB-Backup-Pfad nachgezogen:
  - reine Datenbank-Backups werden über einen verwaltbaren Service-Container erzeugt.
  - Listen lesen nur noch eine begrenzte Anzahl aktueller Backups aus dem Service.
  - Größen werden serverseitig bereits als formatierte Anzeigehilfe aufbereitet.
- `BackupService` wurde funktional und performanter vereinheitlicht:
  - neue Standalone-DB-Backups erhalten eigene Container mit `manifest.json` und sauberem Logeintrag.
  - ältere lose `database_*.sql(.gz)`-Dateien bleiben defensiv erkennbar und löschbar.
  - große Backup-Listen priorisieren relevante Kandidaten und begrenzen Manifest-Lesezugriffe früher.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/backups.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/system/BackupsModule.php`: Speed/BP verbessert, Security weiter vereinheitlicht
- `CMS/core/Services/BackupService.php`: Security und Speed verbessert; DB-Backups sind konsistenter verwaltbar

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/documentation.php`
2. `CMS/admin/mail-settings.php`
3. `CMS/admin/packages.php`
4. `CMS/admin/modules/system/DocumentationSyncService.php`
5. `CMS/admin/modules/system/MailSettingsModule.php`
6. `CMS/core/Services/BackupService.php` (weitere Service-Zerlegung / Download-Randfälle)

### Schritt 048 — 25.03.2026 — Documentation-Entry / Documentation-View

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/documentation.php`
- `CMS/admin/views/system/documentation.php`
- `CMS/admin/modules/system/DocumentationModule.php`

**Ergebnis dieses Schritts**

- `documentation.php` wurde weiter standardisiert:
  - `doc`-Parameter werden jetzt defensiver normalisiert und auf erwartete Markdown-/CSV-Ziele begrenzt.
  - Redirects verwenden dieselbe normalisierte Dokumentauswahl statt rohe Query-Werte zu spiegeln.
  - POST-Aktionen laufen über einen zentralen Allowlist-Dispatch mit generischem Fallback.
- `views/system/documentation.php` wurde an das Admin-UI-Muster angeglichen:
  - Statusmeldungen werden über den gemeinsamen `flash-alert`-Partial ausgegeben.
  - die View verlässt sich damit weniger auf eigenes Inline-Alert-Markup.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/documentation.php`: Security/Speed/BP verbessert
- `CMS/admin/views/system/documentation.php`: Security/BP leicht verbessert
- `Admin – System/Dokumentation`: Wrapper- und UI-Kontrakt weiter vereinheitlicht

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/mail-settings.php`
2. `CMS/admin/packages.php`
3. `CMS/admin/modules/system/DocumentationSyncService.php`
4. `CMS/admin/modules/system/MailSettingsModule.php`
5. `CMS/core/Services/BackupService.php` (weitere Service-Zerlegung / Download-Randfälle)
6. `CMS/admin/modules/system/DocumentationCatalog.php`

### Schritt 049 — 25.03.2026 — DocumentationCatalog

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationCatalog.php`

**Ergebnis dieses Schritts**

- Der Doku-Katalog wurde bei Dateisystem- und I/O-Grenzen weiter nachgeschärft:
  - Dokumente werden nur noch gelesen, wenn sie echte, lesbare Dateien innerhalb des `/DOC`-Roots sind.
  - rekursive Section-Scans überspringen Symlinks jetzt konsequent.
  - Metadaten nutzen begrenzte Preview-Reads statt bei jedem Katalogeintrag komplette Dokumente einzulesen.
  - Vollreads für die Admin-Ansicht werden serverseitig auf eine feste Maximalgröße begrenzt und Logpfade relativ statt absolut protokolliert.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationCatalog.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Katalog-Scan und Dateilesepfade sind berechenbarer und defensiver geworden

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/packages.php`
2. `CMS/admin/modules/system/DocumentationSyncService.php`
3. `CMS/admin/modules/system/MailSettingsModule.php`
4. `CMS/core/Services/BackupService.php` (weitere Service-Zerlegung / Download-Randfälle)
5. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
6. `CMS/admin/modules/subscriptions/PackagesModule.php`

### Schritt 050 — 25.03.2026 — Packages-Entry / PackagesModule

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/packages.php`
- `CMS/admin/modules/subscriptions/PackagesModule.php`

**Ergebnis dieses Schritts**

- Der Paket-Entry wurde auf den gemeinsamen Admin-Standard gezogen:
  - POST-Aktionen laufen jetzt nur noch über eine explizite Allowlist.
  - CSRF- und Aktionsfehler gehen konsistent über Session-Flash + Redirect zurück.
- Das Paket-Modul validiert restriktiver und auditierbarer:
  - Admin-Zugriff wird intern geprüft.
  - Namen, Beschreibungen und Slugs werden serverseitig stärker normalisiert.
  - Slugs müssen eindeutig sein und Save-/Delete-/Toggle-/Seed-Pfade laufen über Audit-Ereignisse.
  - rohe Exception-Texte werden nicht mehr direkt an die UI weitergereicht.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/packages.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/subscriptions/PackagesModule.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: Paket-Wrapper und Paket-CRUD sind enger am gemeinsamen Admin-Sicherheitsmuster

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php`
2. `CMS/admin/modules/system/MailSettingsModule.php`
3. `CMS/core/Services/BackupService.php` (weitere Service-Zerlegung / Download-Randfälle)
4. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
5. `CMS/admin/modules/subscriptions/OrdersModule.php`
6. `CMS/admin/subscription-settings.php`

### Schritt 051 — 25.03.2026 — DocumentationSyncService

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Doku-Sync-Orchestrator wurde bei Ablauf- und Zustandsgrenzen weiter nachgeschärft:
  - der Service prüft Admin-Zugriff jetzt auch intern und verlässt sich nicht nur auf den äußeren Documentation-Wrapper.
  - parallele Git-/GitHub-ZIP-Syncs werden über ein gemeinsames Lockfile zentral serialisiert.
  - Capability-Antworten berücksichtigen fehlerhafte Repo-/DOC-/ZIP-/Integritäts-Konfigurationen schon vor einem eigentlichen Sync-Lauf und zeigen den Modus dann nicht mehr als halb-bereit an.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Orchestrierung und Statusdarstellung des Doku-Syncs sind konsistenter und robuster gegen Parallelstarts geworden

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/MailSettingsModule.php`
2. `CMS/core/Services/BackupService.php` (weitere Service-Zerlegung / Download-Randfälle)
3. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
4. `CMS/admin/modules/subscriptions/OrdersModule.php`
5. `CMS/admin/subscription-settings.php`
6. `CMS/admin/modules/system/DocumentationSyncEnvironment.php`

### Schritt 052 — 25.03.2026 — MailSettingsModule

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/MailSettingsModule.php`

**Ergebnis dieses Schritts**

- Das Mail-Settings-Modul wurde in den operativen Admin-Pfaden weiter nachgeschärft:
  - Testmail-, Queue-, Graph- und Cache-/Log-Aktionen fangen Unterservice-Ausnahmen jetzt konsistenter über den gemeinsamen Generic-Error-Pfad ab.
  - Rückgaben aus MailService, GraphApiService und Queue-Läufen werden für die Admin-UI auf kompakte, sanitierte Message-/Error-Felder reduziert.
  - der Queue-Save-Pfad kapselt Konfigurationsspeicherung und optionale Cron-Token-Rotation gemeinsam und auditierbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/MailSettingsModule.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: operative Admin-Aktionen reagieren robuster auf Unterservice-Sonderfälle und bleiben im UI kontrollierter

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/core/Services/BackupService.php` (weitere Service-Zerlegung / Download-Randfälle)
2. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
3. `CMS/admin/modules/subscriptions/OrdersModule.php`
4. `CMS/admin/subscription-settings.php`
5. `CMS/admin/modules/system/DocumentationSyncEnvironment.php`
6. `CMS/admin/views/system/mail-settings.php`

### Schritt 053 — 25.03.2026 — BackupService

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/core/Services/BackupService.php`

**Ergebnis dieses Schritts**

- Der Backup-Service wurde auf Root-, Speicher- und Upload-Pfaden weiter nachgeschärft:
  - Backup-Zielpfade werden jetzt nicht mehr nur per String-Präfix, sondern realpath-basiert gegen den echten Backup-Root geprüft.
  - der SQL-Dump iteriert Tabellenzeilen speicherschonender, statt pro Tabelle alle Datensätze per `fetchAll()` auf einmal zu laden.
  - REST-S3-Uploads akzeptieren nur noch lesbare Dateien innerhalb des Backup-Roots, blockieren auffällige Bucket-/Endpoint-Werte und lehnen übergroße Upload-Dateien früh ab.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/core/Services/BackupService.php`: Security/Speed/BP verbessert
- `Core – Backups`: Dump- und Upload-Pfade reagieren robuster auf Pfad- und Speicher-Ausreißer

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationGithubZipSync.php`
2. `CMS/admin/modules/subscriptions/OrdersModule.php`
3. `CMS/admin/subscription-settings.php`
4. `CMS/admin/modules/system/DocumentationSyncEnvironment.php`
5. `CMS/admin/views/system/mail-settings.php`
6. `CMS/admin/orders.php`

### Schritt 054 — 25.03.2026 — DocumentationGithubZipSync

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der GitHub-ZIP-Sync wurde bei Quelle, Download-Artefakt und Cleanup weiter nachgeschärft:
  - ZIP-Quellen mit Query-, Fragment- oder Credential-Anteilen werden jetzt konsequent verworfen.
  - die geladene ZIP-Datei wird lokal nochmals auf sichere Dateiform und Größenlimit geprüft, bevor entpackt wird.
  - nach erfolgreicher Wiederherstellung werden verbliebene Backup-Reste gezielter entfernt und Logpfade kompakter relativ zum Repo-Root ausgegeben.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationGithubZipSync.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: ZIP-basierte Sync-Läufe bleiben bei Quelle und Cleanup kontrollierter

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/subscriptions/OrdersModule.php`
2. `CMS/admin/subscription-settings.php`
3. `CMS/admin/modules/system/DocumentationSyncEnvironment.php`
4. `CMS/admin/views/system/mail-settings.php`
5. `CMS/admin/orders.php`
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php`

### Schritt 055 — 25.03.2026 — Orders-Entry / OrdersModule

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/orders.php`
- `CMS/admin/modules/subscriptions/OrdersModule.php`

**Ergebnis dieses Schritts**

- Der Orders-Entry wurde auf den gemeinsamen Admin-Wrapper-Standard gezogen:
  - POST-Aktionen laufen jetzt nur noch über eine explizite Allowlist.
  - CSRF- und Aktionsfehler gehen konsistent über Flash + Redirect zurück.
  - Statusfilter werden serverseitig normalisiert, bevor sie in den Modulpfad zurückgespiegelt werden.
- Das Orders-Modul wurde bei Mutations- und Audit-Pfaden nachgeschärft:
  - Status- und Billing-Cycle-Werte werden zentral normalisiert.
  - Statuswechsel und Löschungen prüfen Zielbestellungen jetzt erst auf Existenz und vermeiden Blind-Updates auf leere IDs.
  - Statuswechsel, Löschungen und Abo-Zuweisungen schreiben strukturierte Audit-Ereignisse mit maskierten Bestell- und Mailkontexten.
  - Listenlimits und kleine Helper reduzieren doppelte Inline-Validierung im Modulpfad.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/orders.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/subscriptions/OrdersModule.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions`: Orders-Wrapper und Orders-Logik reagieren kontrollierter auf ungültige Mutationen und halten Audit-Kontexte datensparsamer

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/subscription-settings.php`
2. `CMS/admin/modules/system/DocumentationSyncEnvironment.php`
3. `CMS/admin/views/system/mail-settings.php`
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
5. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
6. `CMS/admin/views/subscriptions/orders.php`

### Schritt 056 — 25.03.2026 — Subscription-Settings-Entry / Settings-View

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/subscription-settings.php`
- `CMS/admin/views/subscriptions/settings.php`

**Ergebnis dieses Schritts**

- Der Subscription-Settings-Entry wurde an den gemeinsamen Admin-Wrapper-Standard angeglichen:
  - POST-Aktionen laufen jetzt über eine explizite Allowlist.
  - CSRF- und Aktionsfehler gehen konsistent über Flash + Redirect zurück.
  - Session-Alerts werden nur noch als Array in den View-Kontext übernommen.
- Die Settings-View wurde beim UI-Standard nachgezogen:
  - Flash-Meldungen nutzen jetzt den gemeinsamen `flash-alert`-Partial.
  - der Speichervorgang wird über ein explizites `action=save_settings` an den Wrapper gebunden.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/subscription-settings.php`: Security/Speed/BP verbessert
- `CMS/admin/views/subscriptions/settings.php`: Security/Speed/BP leicht verbessert
- `Admin – Subscriptions`: Entry- und View-Kontrakt bleiben konsistenter und weniger implizit

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncEnvironment.php`
2. `CMS/admin/views/system/mail-settings.php`
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
4. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
5. `CMS/admin/views/subscriptions/orders.php`
6. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)

### Schritt 057 — 25.03.2026 — DocumentationSyncEnvironment

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncEnvironment.php`

**Ergebnis dieses Schritts**

- Die Doku-Sync-Umgebung wurde an Root- und Shell-Kanten nachgeschärft:
  - Repository-Roots werden jetzt früh normalisiert und bei ungültigen oder symlinkartigen Pfaden als nicht nutzbar behandelt.
  - Shell-Aufrufe werden vor `exec()` sanitisiert, in der Länge begrenzt und auf erwartete Git-Subcommands eingeschränkt.
  - auffällige Kommando-Payloads mit fremden Metazeichen werden kontrolliert geblockt, statt lose bis in die Runtime zu rutschen.

### Schritt 058 — 25.03.2026 — Settings-Config-Writer / Permalink-Runtime-Schutz

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/settings/SettingsModule.php`
- `CMS/install/InstallerService.php`
- `CMS/config/app.php`

**Ergebnis dieses Schritts**

- Der Config-Writer im Settings-Modul wurde gegen globale Runtime-Ausfälle nach Settings-/Permalink-Saves nachgeschärft:
  - die generierte `config/app.php` übernimmt die Log-Pfad- sowie HTTPS-/HSTS-Defaults konsistenter aus der Installer-Vorlage,
  - der Writer validiert erzeugte PHP-Inhalte jetzt vor dem atomaren Schreiben auf erwartete Kernfragmente und gültige PHP-Syntax,
  - fehlerhafte Generierungen werden kontrolliert abgebrochen, statt eine kaputte Runtime-Konfiguration live zu schalten.
- Damit bleibt ein manuelles Rückspielen der alten `app.php` künftig hoffentlich eher Notfallarchäologie als Standard-Betrieb.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/settings/SettingsModule.php`: Security/Speed/BP verbessert
- `Admin – Settings`: kritischer Runtime-Konfigurationspfad reagiert robuster auf Generatorfehler und reduziert das Risiko globaler 500-Ausfälle nach Admin-Saves

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php`
2. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
3. `CMS/admin/views/subscriptions/orders.php`
4. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
6. `CMS/admin/modules/system/DocumentationSyncService.php`

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncEnvironment.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Capability- und Git-Umgebung reagieren kontrollierter auf kaputte Root- oder Command-Kontexte

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php`
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
3. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
4. `CMS/admin/views/subscriptions/orders.php`
5. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
6. `CMS/admin/modules/system/DocumentationSyncEnvironment.php` (weitere Entkopplung von Shell-/Capability-Layern)

### Schritt 058 — 25.03.2026 — Mail-Settings-Entry / Mail-Settings-View

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`
- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Settings-Entry wurde weiter an den gemeinsamen Admin-Wrapper-Standard angeglichen:
  - Tabs und POST-Aktionen laufen jetzt über kleine zentrale Allowlist-Helfer.
  - CSRF- und Aktionsfehler gehen konsistent über Flash + Redirect zurück.
  - Session-Alerts werden nur noch defensiv als Array in den View-Kontext übernommen.
- Die Mail-Settings-View wurde beim UI-Kontrakt nachgezogen:
  - Flash-Meldungen nutzen jetzt den gemeinsamen `flash-alert`-Partial.
  - Tab-Definitionen, API-Ziel und Queue-Status-Badge sind lokal gebündelt statt losem Inline-Sondermix.
  - das Template hält damit Alert- und Statuslogik enger am gemeinsamen Admin-Muster.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security/Speed/BP verbessert
- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Wrapper- und View-Kontrakt bleiben konsistenter, defensiver und weniger implizit verteilt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
2. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
3. `CMS/admin/views/subscriptions/orders.php`
4. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
5. `CMS/admin/modules/system/DocumentationSyncEnvironment.php` (weitere Entkopplung von Shell-/Capability-Layern)
6. `CMS/admin/modules/system/MailSettingsModule.php` (weitere Trennung von ViewModel-/Aktionslogik)

### Schritt 067 — 25.03.2026 — Dokumentations-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationModule.php`, `CMS/admin/views/partials/flash-alert.php`

**Ergebnis dieses Schritts**

- Die Doku-View trägt weniger verstreute Zustandslogik:
  - Flash-Daten werden defensiv als Array vorbereitet und immer über den gemeinsamen Alert-Partial gerendert.
  - aktive Dokumente, Bereichsstatus, Sync-Alert-Klasse und Default-Pfadlabel laufen über kleine lokale Helfer bzw. vorbereitete Werte statt über mehrfach wiederholte Inline-Bedingungen.
  - das Markup bleibt dadurch näher am eigentlichen Rendern und weniger mit UI-Zustandsentscheidungen vermischt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Speed/BP verbessert
- `Admin – System/Dokumentation`: Template-Logik kompakter und klarer vom Render-Markup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php`
2. `CMS/admin/views/system/mail-settings.php`
3. `CMS/admin/modules/system/DocumentationSyncService.php`
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
5. `CMS/admin/views/subscriptions/orders.php`
6. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 068 — 25.03.2026 — Dokumentationsmodul-Verträge geschärft

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`
- `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Das Doku-Modul spricht explizitere Read-/Write-Grenzen:
  - `getData()` liefert den View-Vertrag jetzt über `DocumentationViewData` statt über ein loses Array.
  - `syncDocsFromRepository()` kapselt Erfolgs-/Fehlerantworten über `DocumentationSyncActionResult`, sodass der Entry Flash-Typ und Meldung konsistent ableiten kann.
  - der Aufbau des aktiven Dokuments wurde in `buildSelectedDocumentPayload()` ausgelagert, wodurch `getData()` näher an der eigentlichen Orchestrierung bleibt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security/Speed/BP verbessert
- `CMS/admin/documentation.php`: PHP/BP verbessert
- `Admin – System/Dokumentation`: Modul- und Entry-Vertrag klarer getrennt, weniger impliziter Array-Mix zwischen Orchestrator und Wrapper

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php`
2. `CMS/admin/modules/system/DocumentationSyncService.php`
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
4. `CMS/admin/views/subscriptions/orders.php`
5. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)

### Schritt 069 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View trägt weniger verstreute UI-Entscheidungslogik:
  - Tabs, Select-Felder und Checkboxen laufen über kleine lokale Helfer statt über mehrfach wiederholte `active`-/`selected`-/`checked`-Bedingungen.
  - Konfigurations- und Secret-Status nutzen vorbereitete Badge-/Label-Helfer statt mehrfacher Inline-Abfragen.
  - das Markup bleibt dadurch näher am eigentlichen Rendern und leichter lesbar für weitere Formular- und Partial-Schritte.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Template-Logik kompakter und klarer vom Render-Markup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php`
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
3. `CMS/admin/views/subscriptions/orders.php`
4. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 070 — 25.03.2026 — Dokumentations-Sync-Service-Verträge geschärft

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationModule.php`, `DocumentationSyncEnvironment.php`

**Ergebnis dieses Schritts**

- Der Doku-Sync-Orchestrator spricht explizitere Verträge:
  - `syncDocsFromRepository()` liefert den Service-Write-Pfad jetzt über `DocumentationSyncServiceResult` statt über lose Erfolgs-/Fehler-Arrays.
  - normalisierte Sync-Capabilities bleiben als `DocumentationSyncCapabilities`-Objekt erhalten, statt im Service mehrfach in Arrays aufgelöst und neu zusammengesetzt zu werden.
  - das Dokumentationsmodul konsumiert die Service-Ergebnisse gezielt über `->toArray()`, wodurch die Grenze zwischen Modul- und Service-Layer klarer bleibt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/system/DocumentationModule.php`: PHP/BP verbessert
- `Admin – System/Dokumentation`: Orchestrator- und Modul-Vertrag klarer getrennt, weniger impliziter Array-Mix im Sync-Pfad

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
2. `CMS/admin/views/subscriptions/orders.php`
3. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Zerlegung bei Bedarf)

### Schritt 071 — 25.03.2026 — Dokumentations-Downloader weiter zerlegt

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der Doku-Downloader trägt weniger Inline-Lifecycle:
  - Host-/Ziel-/Client-/Verzeichnisfehler laufen über einen gemeinsamen Reject-/Failure-Flow statt über verstreute frühe Returns mit wiederholtem Logging.
  - Response-Payload-Prüfung und Datei-Persistenz sind in fokussierte Helfer getrennt, wodurch `downloadFile()` näher an der eigentlichen Orchestrierung bleibt.
  - `DocumentationDownloadResult` kapselt seine Metadaten jetzt über Methoden wie `isSuccess()`, `error()`, `bytes()` und `sha256()`, sodass der ZIP-Sync keine losen Public-Properties mehr kennen muss.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/system/DocumentationGithubZipSync.php`: PHP/BP verbessert
- `Admin – System/Dokumentation`: Downloader- und ZIP-Vertrag klarer getrennt, weniger impliziter Lifecycle-Mix im Downloadpfad

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php`
2. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Zerlegung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Trennung bei Bedarf)

### Schritt 072 — 25.03.2026 — Orders-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-View trägt weniger verstreute Anzeige- und UI-Entscheidungen:
  - Filterbutton-Klassen laufen über einen kleinen Helfer statt über wiederholte Inline-Abfragen.
  - Bestellnummer, Kundenname und Kundenmail werden zentral vorbereitet, statt mehrfach direkt aus dem Order-Array im Markup zusammengesetzt zu werden.
  - Benutzer- und Paketlabels für das Zuweisungsmodal werden über lokale Helfer gebaut, wodurch das Formular-Markup kompakter und lesbarer bleibt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions/Orders`: View-Logik kompakter und klarer vom Render-Markup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Zerlegung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Trennung bei Bedarf)
6. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 073 — 25.03.2026 — Dokumentations-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View trägt weniger verstreute Metadaten- und Anzeigeentscheidungen:
  - Dokumenttitel, Pfade, Extensions und Admin-URLs laufen über kleine lokale Helfer statt über wiederholte Inline-Ableitungen.
  - Bereichs-Slugs, Titel, Beschreibungen, Counts und GitHub-Links werden zentral vorbereitet, wodurch das Accordion-Markup kompakter bleibt.
  - die Listen- und Bereichsausgabe bleibt dadurch näher am eigentlichen Rendern und leichter lesbar für weitere Partial- oder Builder-Schritte.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: View-Logik kompakter und klarer vom Render-Markup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Zerlegung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Trennung bei Bedarf)
5. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 074 — 25.03.2026 — Mail-Entry weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/mail-settings.php`
- Kontext geprüft: `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Entry trägt weniger verstreute Dispatch- und Session-Logik:
  - POST-Aktionen laufen über eine zentrale Action-Map statt über einen langen `match`-Block im Hauptfluss.
  - Session-Alerts werden über einen kleinen Pull-Helfer defensiv übernommen und direkt bereinigt.
  - der Wrapper bleibt dadurch näher am eigentlichen Request-Flow und leichter lesbar für weitere Entry-/Wrapper-Schritte.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Entry-Logik kompakter und klarer vom Modul-Dispatch getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Zerlegung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Trennung bei Bedarf)
4. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)

### Schritt 075 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View trägt weniger wiederholte UI-Struktur im Template:
  - Log- und Queue-KPI-Karten laufen über vorbereitete Kartenlisten und einen kleinen Render-Helfer statt über mehrfach kopierte Card-Blöcke.
  - Readonly-Felder für Worker-/Cron-Infos werden zentral über einen kleinen Feld-Helfer gerendert.
  - der Queue-Last-Run-Text wird vorab aufgebaut, wodurch der Statusblock weniger Inline-Zusammensetzung im Markup trägt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: View-Logik kompakter und klarer vom Render-Markup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Zerlegung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Trennung bei Bedarf)
3. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 107 — 25.03.2026 — Dokumentations-Downloader weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **107 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Downloader bündelt wiederkehrende Response-Failure-Pfade jetzt über einen kleinen Helfer:
  - Download- und Persistenzfehler laufen nicht mehr separat mit ähnlichem Cleanup- und Failure-Abgang.
  - Änderungen an Cleanup, Logging und Result-Erzeugung bleiben dadurch zentraler pflegbar.
  - die eigentlichen Remote- und Persistenzpfade konzentrieren sich stärker auf ihren Ablauf statt auf wiederholte Failure-Logik.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Downloader-Failure-Pfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php` (weitere View-Helfer bei Bedarf)
2. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-/Guard-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Failure-/Context-Helfer bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Response-/Payload-Helfer bei Bedarf)

### Schritt 106 — 25.03.2026 — Dokumentations-Sync-Service weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **106 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Sync-Service bündelt wiederkehrende Capability-Fehlerpfade jetzt über einen kleinen Helfer:
  - unavailable- und invalid-capabilities laufen nicht mehr separat mit identischem Kontextaufbau durch den Orchestrator.
  - Änderungen an Failure-Kontexten, Logging und Fehlermeldungen bleiben dadurch zentraler pflegbar.
  - der eigentliche Sync-Auswahlpfad konzentriert sich stärker auf Dispatch statt auf wiederholte Failure-Helfer-Aufrufe.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Capability-Failure-Pfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
2. `CMS/admin/views/subscriptions/orders.php` (weitere View-Helfer bei Bedarf)
3. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-/Guard-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Failure-/Context-Helfer bei Bedarf)

### Schritt 105 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **105 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View bündelt wiederkehrende KPI-Kartenzeilen jetzt über einen kleinen Renderer:
  - Logs- und Queue-Metriken liegen nicht mehr als zwei fast identische Kartenreihen direkt im Template.
  - Änderungen an KPI-Karten, Klassen und Darstellungsstruktur bleiben dadurch zentraler pflegbar.
  - die betroffenen Tabs konzentrieren sich stärker auf ihren jeweiligen Inhalt statt auf wiederholte Karten-Wrapper.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: KPI-Kartenzeilen kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
3. `CMS/admin/views/subscriptions/orders.php` (weitere View-Helfer bei Bedarf)
4. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-/Guard-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere View-Helfer bei Bedarf)

### Schritt 104 — 25.03.2026 — Dokumentations-Modul weiter standardisiert

**Status:** umgesetzt und validiert

**Fortschritt gesamt**

- **104 von 444 Prüfplan-Punkten** erledigt

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`

**Ergebnis dieses Schritts**

- Das Dokumentations-Modul bündelt wiederkehrende Lese-Vorbedingungen jetzt über einen kleinen Guard:
  - Zugriff, Repository-Layout und DOC-Verfügbarkeit liegen nicht mehr direkt als gestaffelte Inline-Checks im Read-Pfad.
  - Änderungen an Vorbedingungen und Fehlertexten bleiben dadurch zentraler pflegbar.
  - der eigentliche Ladepfad konzentriert sich stärker auf Katalog- und Render-Aufbau statt auf Vorbedingungslogik.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Read-Orchestrierung kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
4. `CMS/admin/views/subscriptions/orders.php` (weitere View-Helfer bei Bedarf)
5. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-/Guard-Trennung bei Bedarf)

### Schritt 103 — 25.03.2026 — Dokumentations-Ansicht weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-Ansicht bündelt den gewachsenen Inhalt der ausgewählten Dokumentenkarte jetzt über einen kleinen Renderer:
  - Excerpt, Quellenhinweis, Leerzustand und CSV-Hinweis liegen nicht mehr direkt als kompletter Inhaltsblock im Hauptlayout.
  - Änderungen an Hinweisen, Zuständen und Panel-Struktur bleiben dadurch zentraler pflegbar.
  - das Hauptlayout konzentriert sich stärker auf Kartenstruktur und Aufteilung statt auf Detail-Markup der Zustandsanzeige.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Dokumentenpanel kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
5. `CMS/admin/views/subscriptions/orders.php` (weitere View-Helfer bei Bedarf)
6. `CMS/admin/views/system/documentation.php` (weitere View-Helfer bei Bedarf)

### Schritt 102 — 25.03.2026 — Bestellübersicht weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`

**Ergebnis dieses Schritts**

- Die Bestellübersicht bündelt ihr gewachsenes Aktionsmenü jetzt über einen kleinen lokalen Renderer:
  - Statuswechsel, Paketzuweisung und Löschaktion liegen nicht mehr als kompletter Dropdown-Block direkt im Tabellen-Loop.
  - Änderungen an Menüeinträgen, Datenattributen und Dropdown-Struktur bleiben dadurch zentraler pflegbar.
  - die Tabellenzeile konzentriert sich stärker auf Bestelldaten statt auf wiederkehrendes Aktions-Markup.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Abos/Bestellungen`: Dropdown-Aktionen kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
6. `CMS/admin/views/subscriptions/orders.php` (weitere View-Helfer bei Bedarf)

### Schritt 101 — 25.03.2026 — Dokumentations-Downloader weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Downloader bündelt wiederkehrende Persistenz-Fehlerpfade jetzt über einen kleinen Helfer:
  - Schreib- und Hash-Fehler behandeln Cleanup, Logging und Failure-Result nicht mehr jeweils separat über ähnliche Inline-Blöcke.
  - Änderungen an Archiv-Fehlerpfaden bleiben dadurch zentraler und konsistenter nachziehbar.
  - der Persistenzpfad bleibt näher am eigentlichen Ablauf und trägt weniger wiederholte Failure-Logik.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Persistenz-Fehlerpfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)

### Schritt 100 — 25.03.2026 — Dokumentations-Sync-Service weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Sync-Service bündelt wiederkehrende Capability-Verzweigungen jetzt über einen kleinen Helfer:
  - Verfügbarkeits-, Git- und GitHub-ZIP-Auswahl werden nicht mehr direkt im Sync-Einstieg als gestaffelter Inline-Block verteilt.
  - Änderungen an Capability- oder Moduspfaden bleiben dadurch zentraler und konsistenter nachziehbar.
  - der Orchestrator-Einstieg bleibt näher am eigentlichen Ablauf und trägt weniger wiederholte Auswahl-Logik.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Sync-Auswahl kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
2. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)

### Schritt 099 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View bündelt wiederkehrende Status-Kartenköpfe für Azure- und Graph-Konfiguration jetzt über einen kleinen lokalen Renderer:
  - Titel und Status-Badge werden nicht mehr zweimal leicht variiert direkt im Template zusammengesetzt.
  - Änderungen an Konfigurationskarten bleiben dadurch zentraler und konsistenter nachziehbar.
  - die View bleibt näher am eigentlichen Render-Flow und trägt weniger wiederholte Header-Struktur.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Kartenkopf-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
3. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 098 — 25.03.2026 — Dokumentations-Modul weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`

**Ergebnis dieses Schritts**

- Das Dokumentations-Modul bündelt wiederkehrende Sync-Vorbedingungen jetzt über einen kleinen Guard-Helfer:
  - Zugriffs- und Repository-Layout-Prüfungen werden nicht mehr direkt im Sync-Einstieg über leicht doppelte Failure-Pfade verteilt.
  - Änderungen an Sync-Gates bleiben dadurch zentraler und konsistenter nachziehbar.
  - der Orchestrator-Einstieg bleibt näher am eigentlichen Sync-Aufruf und trägt weniger wiederholte Guard-Logik.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Sync-Einstieg kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
4. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)

### Schritt 097 — 25.03.2026 — Dokumentations-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View bündelt wiederkehrende Accordion-Strukturen für Dokumentationsbereiche jetzt über einen kleinen lokalen Renderer:
  - Header, Collapse-Container und Dokumentlisten werden nicht mehr pro Bereich erneut direkt im Template zusammengesetzt.
  - Bereichsänderungen an Titel, Zähler, Intro oder Listenaufbau bleiben dadurch zentraler und konsistenter nachziehbar.
  - die View bleibt näher am eigentlichen Render-Flow und trägt weniger wiederholte Markup-Struktur im Section-Loop.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Bereichs-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
5. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 096 — 25.03.2026 — Orders-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-View bündelt wiederkehrende Statuswechsel- und Delete-Formulare jetzt über kleine lokale Renderer:
  - Dropdown-Mutationen bauen `csrf_token`, `action`, `id` und optional `status` nicht mehr jeweils separat mit leicht variierter Hidden-Field-Struktur auf.
  - das versteckte Delete-Formular wird nicht mehr pro Zeile roh im Template ausgeschrieben.
  - spätere Änderungen an Formularfeldern oder Aktionsparametern bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions/Orders`: Aktions-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
6. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 095 — 25.03.2026 — Dokumentations-Downloader weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Downloader bündelt wiederkehrende Response-basierte Failure-Result-Erzeugung jetzt über einen kleinen Builder:
  - Download-, Persistenz- und Validierungsfehler bauen Status-, Content-Type- und Byte-Angaben nicht mehr jeweils separat über leicht variierte Result-Aufrufe zusammen.
  - Fehlerpfade bleiben dadurch kompakter und näher an einem gemeinsamen Response-Failure-Vertrag.
  - spätere Änderungen an Failure-Metadaten bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Downloader-Fehlerpfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)

### Schritt 094 — 25.03.2026 — Dokumentations-Sync-Service weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Sync-Service bündelt wiederkehrende Konfigurations-Failure-Arrays jetzt über einen kleinen Builder:
  - Repo-, DOC-, Git- und Integritätsvalidierung bauen Action-/Message-/Context-Strukturen nicht mehr jeweils separat mit leicht variierter Array-Schreibweise auf.
  - Validierungsfehler bleiben dadurch kompakter und näher an einem gemeinsamen Konfigurationsvertrag.
  - spätere Änderungen an Meldungen oder Kontextfeldern bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Konfigurations-Validierung kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
2. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)

### Schritt 093 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View bündelt wiederkehrende einfache Kartenkopf-Strukturen jetzt über einen kleinen lokalen Renderer:
  - Transport-, Runtime-, Queue- und Worker-Karten bauen ihre schlichten Titelzeilen nicht mehr jeweils separat aus identischem Markup auf.
  - spätere Änderungen an einfachen Kartenüberschriften bleiben dadurch zentraler und konsistenter nachziehbar.
  - das Template bleibt in mehreren Bereichen kompakter und leichter lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Kartenkopf-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
3. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 092 — 25.03.2026 — Dokumentations-Modul weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`
- Kontext geprüft: `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Das Dokumentations-Modul bündelt wiederkehrende Repository-Layout-Warnings jetzt über einen kleinen Hilfsweg:
  - ungültiger Repo-Root, fehlendes `CMS`-Verzeichnis und ein ausbrechender DOC-Pfad loggen nicht mehr jeweils eigene leicht variierte Warning-Blöcke.
  - die Layout-Prüfung bleibt dadurch kompakter und näher an der eigentlichen Validierungslogik.
  - spätere Änderungen an Warning-Texten oder Log-Kontexten bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Layout-Validierungs-Logging kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
4. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)

### Schritt 091 — 25.03.2026 — Dokumentations-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View bündelt wiederkehrende Kartenkopf-Strukturen jetzt über einen kleinen lokalen Renderer:
  - Schnellstart-, Bereichs- und Dokumentkarten bauen ihren Header nicht mehr jeweils separat mit leicht variierter Struktur.
  - Titel und optionaler Untertitel/Pfad laufen über denselben kleinen Render-Baustein.
  - spätere Änderungen an Header-Markup oder Metatexten bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Kartenkopf-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
5. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 090 — 25.03.2026 — Orders-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-View bündelt wiederkehrende Select-Feld-Blöcke im Zuweisungsmodal jetzt über einen kleinen lokalen Renderer:
  - Benutzer-, Paket- und Abrechnungsintervall-Felder werden nicht mehr als drei leicht variierte Markup-Blöcke separat aufgebaut.
  - die Select-Optionen werden vorab als Listen vorbereitet statt direkt im Modal-Markup zusammengebaut.
  - spätere Änderungen an Feldstruktur, Beschriftungen oder Defaults bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions/Orders`: Assignment-Modal kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
6. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 089 — 25.03.2026 — Dokumentations-Downloader weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Downloader bündelt wiederkehrende Logging-Nachläufe jetzt über einen gemeinsamen Hilfsweg:
  - Erfolgs- und Fehlerpfade nutzen denselben Logger-/Audit-Unterbau.
  - Channel- und Audit-Initialisierung werden nicht mehr in zwei fast identischen Methoden separat ausgeschrieben.
  - spätere Änderungen an Severity, Channel oder Audit-Ausleitung bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Downloader-Logging kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)

### Schritt 088 — 25.03.2026 — Dokumentations-Sync-Service weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Sync-Service bündelt wiederkehrende Logging-Nachläufe jetzt über einen gemeinsamen Hilfsweg:
  - Erfolgs- und Fehlerpfade nutzen denselben Logger-/Audit-Unterbau.
  - Channel- und Audit-Initialisierung werden nicht mehr in zwei fast identischen Methoden separat ausgeschrieben.
  - spätere Änderungen an Severity, Channel oder Audit-Ausleitung bleiben dadurch zentraler und konsistenter nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Logging-Pfade kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
2. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)

### Schritt 087 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View bündelt wiederkehrende Secret-Feld-Nachläufe jetzt über einen kleinen lokalen Renderer:
  - der Statushinweis „Aktuell gespeichert: Ja/Nein“ ist für Transport-, Azure- und Graph-Secrets zentralisiert.
  - die jeweilige Lösch-/Reset-Checkbox wird nicht mehr dreifach leicht variiert inline aufgebaut.
  - spätere Text- oder Zustandsanpassungen an Secret-Feldern bleiben dadurch konsistenter und schneller nachziehbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Secret-Statusdarstellung und Reset-Optionen kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
3. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 086 — 25.03.2026 — Dokumentations-Modul weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`
- Kontext geprüft: `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Das Dokumentations-Modul trägt weniger leicht variierte Orchestrator-Fragmente:
  - Throwable-Warnings für Datenaufbau und Sync laufen jetzt über denselben kleinen Logging-Helfer.
  - der Default-Zustand für ausgewählte Dokumente kommt aus einem gemeinsamen Payload-Helfer statt aus einem erneut ausgeschriebenen Initial-Array.
  - Read- und Sync-Pfade bleiben dadurch näher an ihren Verträgen und klarer für weitere Result- oder Service-Schritte lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Orchestrator-Logging und Initial-Payloads kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
4. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)

### Schritt 085 — 25.03.2026 — Dokumentations-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`
- Kontext geprüft: `CMS/admin/documentation.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View trägt weniger leicht variierte Informations- und Alert-Blöcke:
  - Fehler-, Sync- und Hinweisboxen laufen jetzt über denselben kleinen Alert-Renderer.
  - Bereichs-Einleitungen im Accordion nutzen einen gemeinsamen Intro-Renderer statt eigener Inline-Blöcke.
  - der Quellhinweis für die lokale `/DOC`-Ansicht wird als vorbereiteter Text gebaut statt direkt im Markup zusammengesetzt.
  - die View bleibt dadurch näher am eigentlichen Rendern und klarer für weitere Partial- oder Builder-Schritte lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Sync-, Fehler- und Info-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
5. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 084 — 25.03.2026 — Orders-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`
- Kontext geprüft: `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-View trägt weniger leicht variierte UI-Fragmentwiederholungen:
  - Status-Badges für Bestellungen und Zuweisungen laufen jetzt über denselben kleinen Render-Helfer.
  - Kunden- und Assignment-Zeilen mit Primär-/Sekundärtext werden über einen gemeinsamen Text-Renderer aufgebaut.
  - die Billing-Cycle-Auswahl im Zuweisungs-Modal kommt aus einer vorbereiteten Optionsliste statt aus fest ausgeschriebenen Einzeloptionen.
  - die View bleibt dadurch näher am eigentlichen Rendern und klarer für weitere Partial- oder Builder-Schritte lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions/Orders`: Tabellen- und Modal-Markup kompakter und konsistenter aufgebaut

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
6. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 076 — 25.03.2026 — Dokumentations-Sync-Service weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`
- `CMS/admin/modules/system/DocumentationSyncEnvironment.php`

**Ergebnis dieses Schritts**

- Der Doku-Sync-Orchestrator bleibt näher am objektbasierten Capability-Vertrag:
  - Capability-Abfragen laufen jetzt über Getter wie `canSync()`, `hasGit()` und `hasGithubZip()` statt über frühe Array-Zerlegung im Service.
  - Erfolgs-, Fehler- und Unavailable-Pfade übernehmen Capability-Daten über einen kleinen `toLogContext()`-Helfer.
  - die Capability-Normalisierung bleibt dadurch klarer von Logging und Dispatch getrennt und lässt sich für weitere Service-Schritte leichter lesen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Orchestrator-Logik kompakter und klarer vom Capability- und Logging-Vertrag getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Trennung bei Bedarf)
2. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)

### Schritt 083 — 25.03.2026 — Dokumentations-Downloader weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Downloader trägt weniger verstreute Result-Erzeugung im Fehlerpfad:
  - Download-, Persistenz-, Hash- und Validierungsfehler nutzen jetzt denselben kleinen Failure-Helfer statt mehrfach separat `DocumentationDownloadResult::failure(...)` zusammenzusetzen.
  - der HTTP-Fehlerpfad wurde dabei zugleich auf dieselbe zentrale Result-Erzeugung gezogen.
  - der Downloader bleibt dadurch näher an seinem Result-Vertrag und leichter für weitere Payload- oder Lifecycle-Aufspaltungen lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Failure-Pfade kompakter und klarer vom Download-Lifecycle getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)

### Schritt 082 — 25.03.2026 — Dokumentations-Sync-Service weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncService.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationModule.php`

**Ergebnis dieses Schritts**

- Der Dokumentations-Sync-Service trägt weniger doppelte Result- und Failure-Logik:
  - Konfigurationsfehler ohne Direkt-Logging nutzen jetzt denselben Failure-Result-Helfer wie andere Service-Fehlpfade.
  - Finalize-Pfade konvertieren Sync-Ergebnisse zentral in `DocumentationSyncServiceResult`, statt Erfolg und Fehler separat erneut aus losen Arrays aufzubauen.
  - der Orchestrator bleibt dadurch näher an seinem Result-Vertrag und leichter für weitere Objekt- oder Result-Aufspaltungen lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncService.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Result- und Failure-Pfade kompakter und klarer vom Service-Lifecycle getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
2. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
5. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)

### Schritt 081 — 25.03.2026 — Mail-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/mail-settings.php`
- Kontext geprüft: `CMS/admin/modules/system/MailSettingsModule.php`

**Ergebnis dieses Schritts**

- Die Mail-Settings-View trägt weniger wiederholte UI-Struktur in Status-, Leerstands- und Sidebar-Blöcken:
  - Status-Badges für Transport, Azure/Graph-Konfiguration, Logs und Queue-Jobs werden jetzt über einen kleinen Badge-Helfer gerendert.
  - leere Tabellenzeilen für Logs und Queue-Jobs laufen über einen gemeinsamen Empty-State-Helfer.
  - die seitlichen Hinweis-Karten für Azure und Graph werden über einen kleinen Info-Card-Renderer aufgebaut statt leicht abweichende Card-Blöcke doppelt im Markup zu pflegen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/mail-settings.php`: Security/Speed/BP verbessert
- `Admin – System/Mail`: Badge-, Empty-State- und Sidebar-Markup kompakter und klarer vom Render-Kontext getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
3. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
6. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 080 — 25.03.2026 — Dokumentations-Modul weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationModule.php`
- Kontext geprüft: `CMS/admin/documentation.php`, `CMS/admin/views/system/documentation.php`

**Ergebnis dieses Schritts**

- Das Dokumentations-Modul trägt weniger gemischte Payload- und Fehlerlogik:
  - Sync-Resultate werden über kleine Hilfsmethoden für Sanitizing, Failure-Aufbau und Result-Erzeugung konsistenter aufgebaut.
  - ausgewählte Dokumente nutzen fokussierte Helfer für Pfadauflösung und Rendering statt dieselben Prüf- und Render-Schritte inline in `buildSelectedDocumentPayload()` zu mischen.
  - der Orchestrator bleibt dadurch näher an Read-/Write-Verträgen und leichter für weitere Service-Aufspaltungen lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationModule.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Modul-Lifecycle kompakter und klarer von View- und Sync-Helfern getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
4. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)

### Schritt 079 — 25.03.2026 — Dokumentations-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/system/documentation.php`
- Kontext geprüft: `CMS/admin/documentation.php`, `CMS/admin/modules/system/DocumentationModule.php`

**Ergebnis dieses Schritts**

- Die Dokumentations-View trägt weniger wiederholte UI-Struktur bei Karten und Dokument-Listen:
  - KPI-Karten für Dokumente, Bereiche, Quelle, aktuelle Auswahl und Sync-Status laufen jetzt über eine vorbereitete Kartenliste und einen kleinen Render-Helfer.
  - Schnellstart- und Bereichslisten nutzen denselben lokalen Dokument-Renderer statt zwei leicht unterschiedliche Listenblöcke inline im Markup zu pflegen.
  - die View bleibt dadurch näher am eigentlichen Rendern und leichter für weitere Partial- oder Builder-Schritte lesbar.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/system/documentation.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Karten- und Listen-Markup kompakter und klarer vom View-Kontext getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
2. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
5. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
6. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 078 — 25.03.2026 — Orders-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`
- Kontext geprüft: `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-View trägt weniger wiederholte UI-Struktur im Tabellen- und Dropdown-Markup:
  - KPI-Karten werden jetzt über eine kleine Datenliste und einen lokalen Render-Helfer aufgebaut.
  - leere Tabellenzustände für Orders und Zuweisungen laufen über einen gemeinsamen Empty-State-Helfer.
  - verfügbare Statuswechsel, JSON-Payloads für Zuweisungen und Assignment-Anzeige-Werte werden lokal vorbereitet statt mehrfach inline im Markup zusammengesetzt.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Speed/BP verbessert
- `Admin – Subscriptions/Orders`: Tabellen- und Dropdown-Markup kompakter und klarer vom Render-Kontext getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
3. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
4. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)
6. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)

### Schritt 077 — 25.03.2026 — Dokumentations-Downloader weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der Doku-Downloader nutzt schärfere Zwischenverträge im Lifecycle:
  - Fehl- und Erfolgsergebnisse werden jetzt über benannte Fabriken am Result-Objekt aufgebaut statt über wiederholte Konstruktor-Parameterketten.
  - validierte ZIP-Antworten laufen nach der Prüfung über ein kleines `DocumentationDownloadPayload`-DTO weiter.
  - Persistenz-, Hash- und Fehlerpfade tragen dadurch weniger lose Body-/Content-Type-Fragmente durch den Remote- und Filesystem-Lifecycle.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Downloader-Lifecycle kompakter und klarer von Response- und Result-Verträgen getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/orders.php` (weitere Partial-/Builder-Trennung bei Bedarf)
2. `CMS/admin/views/system/documentation.php` (weitere Partial-/Builder-Trennung bei Bedarf)
3. `CMS/admin/modules/system/DocumentationModule.php` (weitere Service-Trennung bei Bedarf)
4. `CMS/admin/views/system/mail-settings.php` (weitere Partial-/Builder-Trennung bei Bedarf)
5. `CMS/admin/modules/system/DocumentationSyncService.php` (weitere Objekt-/Result-Trennung bei Bedarf)
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php` (weitere Payload-/Result-Trennung bei Bedarf)

### Schritt 066 — 25.03.2026 — Subscription-Settings-View weiter standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/settings.php`
- Kontext geprüft: `CMS/admin/subscription-settings.php`

**Ergebnis dieses Schritts**

- Die Abo-Settings-View trägt weniger Inline-Entscheidungslogik:
  - Alert-Daten werden defensiv als Array vorbereitet und immer über den gemeinsamen Flash-Partial gerendert.
  - Checkbox-Zustände und die Standardpaket-Auswahl laufen über kleine lokale Template-Helfer statt über mehrfach wiederholte `checked`-/`selected`-Bedingungen.
  - vorbereitete Default- und Notice-Werte halten das Markup kompakter und näher am eigentlichen Rendern.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/settings.php`: Speed/BP verbessert
- `Admin – Subscriptions/Settings`: Template-Logik kompakter und klarer vom Render-Markup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/system/documentation.php`
2. `CMS/admin/modules/system/DocumentationModule.php`
3. `CMS/admin/views/system/mail-settings.php`
4. `CMS/admin/modules/system/DocumentationSyncService.php`
5. `CMS/admin/modules/system/DocumentationSyncDownloader.php`
6. `CMS/admin/views/subscriptions/orders.php`

### Schritt 065 — 25.03.2026 — DocumentationGithubZipSync weiter zerlegt

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationGithubZipSync.php`
- Kontext geprüft: `CMS/admin/modules/system/DocumentationSyncDownloader.php`, `DocumentationSyncService.php`, `DocumentationSyncFilesystem.php`

**Ergebnis dieses Schritts**

- Der GitHub-ZIP-Sync wurde entlang seines Lifecycle weiter entkoppelt:
  - ZIP-, Extract-, Staging- und Backup-Pfade laufen jetzt gebündelt über `DocumentationGithubZipWorkspace` statt als lose lokale Einzelvariablen.
  - `sync()` delegiert Entpacken, Snapshot-Staging, Aktivierung und Cleanup an fokussierte Helfer statt diese Schritte in einem großen Block zu mischen.
  - Der Top-Level-Ablauf bleibt dadurch lesbarer, während die eigentlichen Arbeitsphasen isolierter weitergehärtet oder getestet werden können.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationGithubZipSync.php`: Speed/BP verbessert
- `Admin – System/Dokumentation`: ZIP-Sync-Lifecycle klarer zwischen Workspace, Archivphase, Aktivierung und Cleanup getrennt

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/views/subscriptions/settings.php`
2. `CMS/admin/views/system/documentation.php`
3. `CMS/admin/modules/system/DocumentationModule.php`
4. `CMS/admin/views/system/mail-settings.php`
5. `CMS/admin/modules/system/DocumentationSyncService.php`
6. `CMS/admin/modules/system/DocumentationSyncDownloader.php`

### Schritt 064 — 25.03.2026 — MailSettingsModule weiter entkoppelt

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/MailSettingsModule.php`
- `CMS/admin/mail-settings.php`
- Kontext geprüft: `CMS/admin/views/system/mail-settings.php`

**Ergebnis dieses Schritts**

- Der Mail-Admin nutzt jetzt klarere Read-/Write-Verträge:
  - `getData()` liefert seine View-Daten über `MailSettingsViewData` statt über einen losen Sammel-Array.
  - Save-, Test-, Queue- und Cache-Aktionen sprechen jetzt über `MailSettingsActionResult` mit konsistenter Success-/Error-Form.
  - Transport-, Azure- und Graph-Datenaufbau wurden in fokussierte Builder zerlegt, statt alle View-Schlüssel inline in einem großen Block zusammenzumischen.
- Der Entry bleibt dadurch schlanker:
  - `mail-settings.php` leitet Flash-Meldungen zentral aus dem neuen Result-Vertrag ab.
  - die View-Daten werden kompatibel über `->toArray()` an das bestehende Template übergeben, ohne lose interne Modul-Arrays vorauszusetzen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/MailSettingsModule.php`: Security/Speed/BP verbessert
- `CMS/admin/mail-settings.php`: Modulkontrakt/Fehlerpfad vereinheitlicht
- `Admin – System/Mail`: klarere DTO-/Result-Grenzen zwischen Read-Pfad, Mutationen und Flash-Flow

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationGithubZipSync.php` (weitere Extraktion von Archiv-/Staging-Helfern)
2. `CMS/admin/views/subscriptions/settings.php`
3. `CMS/admin/views/system/documentation.php`
4. `CMS/admin/modules/system/DocumentationModule.php`
5. `CMS/admin/views/system/mail-settings.php`
6. `CMS/admin/modules/system/DocumentationSyncService.php`

### Schritt 063 — 25.03.2026 — DocumentationSyncEnvironment weiter entkoppelt

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncEnvironment.php`
- `CMS/admin/modules/system/DocumentationGitSync.php`
- `CMS/admin/modules/system/DocumentationSyncService.php`

**Ergebnis dieses Schritts**

- Die Environment-Schicht des Doku-Syncs spricht jetzt klarere interne Verträge:
  - Sync-Capabilities laufen über `DocumentationSyncCapabilities` statt über lose Capability-Arrays.
  - Shell-Ausführungen werden als `DocumentationShellCommandResult` gekapselt statt als loses `output`-/`exitCode`-Paar.
- Die direkten Konsumenten wurden daran angepasst:
  - `DocumentationGitSync.php` prüft Fetch-/Checkout-/Statusläufe jetzt über den Result-Vertrag des Environments.
  - `DocumentationSyncService.php` übernimmt die Capability-Daten kontrolliert über `->toArray()` und behält seinen UI-Vertrag dabei unverändert bei.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncEnvironment.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/system/DocumentationGitSync.php`: BP verbessert
- `CMS/admin/modules/system/DocumentationSyncService.php`: BP verbessert

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/MailSettingsModule.php` (weitere Trennung von ViewModel-/Aktionslogik)
2. `CMS/admin/modules/system/DocumentationGithubZipSync.php` (weitere Extraktion von Archiv-/Staging-Helfern)
3. `CMS/admin/views/subscriptions/settings.php`
4. `CMS/admin/views/system/documentation.php`
5. `CMS/admin/modules/system/DocumentationModule.php`
6. `CMS/admin/views/system/mail-settings.php`

### Schritt 062 — 25.03.2026 — SubscriptionSettingsModule weiter zerlegt

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`
- `CMS/admin/subscription-settings.php`
- `CMS/admin/packages.php`

**Ergebnis dieses Schritts**

- Die Abo-Settings-Logik wurde auf klarere Read-/Write-Kontrakte umgestellt:
  - `getData()` und `getPackageData()` liefern ihre Daten jetzt über `SubscriptionSettingsViewData` statt über lose Rückgabe-Arrays.
  - `saveSettings()` und `savePackageSettings()` sprechen jetzt über ein `SubscriptionSettingsActionResult` mit konsistenter Success-/Error-Form.
  - General- und Package-Settings werden über kleine fokussierte Payload-Helfer gebaut, statt Validierung und Persistenz vollständig in den Save-Methoden zu vermischen.
- Die konsumierenden Entries bleiben dadurch schlanker:
  - `subscription-settings.php` übernimmt Flash-Meldungen zentral aus dem neuen Result-Vertrag.
  - `packages.php` bindet Package-Settings-Daten und Save-Ergebnisse über `->toArray()` an, statt lose interne Array-Details des Moduls vorauszusetzen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`: Security/Speed/BP verbessert
- `CMS/admin/subscription-settings.php`: Modulkontrakt/Fehlerpfad vereinheitlicht
- `CMS/admin/packages.php`: Settings-Anbindung an den neuen Modulvertrag nachgezogen

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/system/DocumentationSyncEnvironment.php` (weitere Entkopplung von Shell-/Capability-Layern)
2. `CMS/admin/modules/system/MailSettingsModule.php` (weitere Trennung von ViewModel-/Aktionslogik)
3. `CMS/admin/modules/system/DocumentationGithubZipSync.php` (weitere Extraktion von Archiv-/Staging-Helfern)
4. `CMS/admin/views/subscriptions/settings.php`
5. `CMS/admin/views/system/documentation.php`
6. `CMS/admin/modules/system/DocumentationModule.php`

### Schritt 061 — 25.03.2026 — OrdersModule weiter zerlegt

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/subscriptions/OrdersModule.php`
- `CMS/admin/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-Modullogik wurde auf klarere Daten- und Mutationskontrakte umgestellt:
  - `getData()` liefert Dashboard-Daten jetzt über ein kleines `OrdersDashboardData`-DTO statt direkt über einen losen Sammel-Array.
  - Mutationen (`assignSubscription()`, `updateStatus()`, `delete()`) sprechen jetzt über ein `OrdersActionResult` mit konsistenter Success-/Error-Form.
  - Listen-, Nutzer-, Plan-, Zuweisungs- und Statistik-Ladevorgänge wurden in kleine fokussierte Helfer aus dem großen `getData()`-Block herausgezogen.
- Der Entry bleibt damit schlanker angebunden:
  - `CMS/admin/orders.php` leitet Flash-Meldungen jetzt zentral aus dem Action-Result ab,
  - und die Datenübergabe an die View bleibt trotz schärferem Modulvertrag kompatibel über `->toArray()`.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/subscriptions/OrdersModule.php`: Security/Speed/BP verbessert
- `CMS/admin/orders.php`: Modulkontrakt/Fehlerpfad vereinheitlicht
- `Admin – Subscriptions/Orders`: klarere DTO-/Result-Grenzen zwischen Read-Pfad, Mutationen und Entry-Flash-Flow

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
2. `CMS/admin/modules/system/DocumentationSyncEnvironment.php` (weitere Entkopplung von Shell-/Capability-Layern)
3. `CMS/admin/modules/system/MailSettingsModule.php` (weitere Trennung von ViewModel-/Aktionslogik)
4. `CMS/admin/modules/system/DocumentationGithubZipSync.php` (weitere Extraktion von Archiv-/Staging-Helfern)
5. `CMS/admin/views/subscriptions/settings.php`
6. `CMS/admin/views/system/documentation.php`

### Schritt 060 — 25.03.2026 — Orders-View standardisiert

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/views/subscriptions/orders.php`

**Ergebnis dieses Schritts**

- Die Orders-Admin-View folgt dem gemeinsamen UI-Muster jetzt enger:
  - Meldungen laufen über den zentralen Flash-Alert-Partial statt über einen eigenen Inline-Alert-Block.
  - Status-, Datums- und Betragsausgabe werden über kleine lokale Helper konsistenter formatiert.
  - Assign- und Delete-Aktionen hängen nicht mehr an verteilten `onclick`-Fragmenten, sondern an `data-*`-Attributen mit einem zentralen Scriptblock.
- Dadurch bleibt das Template dümmer und wartbarer:
  - wiederkehrende Ausgabe- und Statuslogik ist lokaler gebündelt,
  - UI-Aktionen sind sauberer verdrahtet,
  - und die View trägt weniger ad-hoc-Sonderlogik direkt in einzelnen Buttons.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/views/subscriptions/orders.php`: Security/Speed/BP verbessert
- `Admin – Subscriptions/Orders`: UI folgt dem gemeinsamen Flash-/Action-Muster enger und reduziert Template-Duplikate

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
2. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
3. `CMS/admin/modules/system/DocumentationSyncEnvironment.php` (weitere Entkopplung von Shell-/Capability-Layern)
4. `CMS/admin/modules/system/MailSettingsModule.php` (weitere Trennung von ViewModel-/Aktionslogik)
5. `CMS/admin/modules/system/DocumentationGithubZipSync.php` (weitere Extraktion von Archiv-/Staging-Helfern)
6. `CMS/admin/views/subscriptions/settings.php`

### Schritt 059 — 25.03.2026 — DocumentationSyncDownloader / DocumentationGithubZipSync

**Status:** umgesetzt und validiert

**Geprüfter Scope**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`
- `CMS/admin/modules/system/DocumentationGithubZipSync.php`

**Ergebnis dieses Schritts**

- Der Doku-Downloader wurde bei Response- und Ergebnislogik weiter entkoppelt:
  - Download-Ergebnisse laufen jetzt über ein kleines `DocumentationDownloadResult`-DTO statt nur über einen losen Array-Mix.
  - Content-Type und `content-length` werden vor dem Schreiben konsistenter gegen den tatsächlichen ZIP-Body geprüft.
  - erfolgreiche Downloads liefern zusätzlich SHA-256-, Größen- und Status-Metadaten für den nachgelagerten Sync-Pfad.
- Der GitHub-ZIP-Sync nutzt diese Metadaten jetzt explizit:
  - ZIP-Dateien werden vor dem Entpacken nicht nur auf Dateiexistenz, sondern auch gegen Downloader-Größe und Downloader-Hash geprüft.
  - inkonsistente Artefakte werden früher als kontrollierter Download-Fehler abgefangen.

**Bewertungswirkung (inkrementell, noch keine Komplett-Neuberechnung)**

- `CMS/admin/modules/system/DocumentationSyncDownloader.php`: Security/Speed/BP verbessert
- `CMS/admin/modules/system/DocumentationGithubZipSync.php`: Security/Speed/BP verbessert
- `Admin – System/Dokumentation`: Download- und ZIP-Sync-Pfade teilen sich konsistentere Metadaten und weniger lose Nachvalidierung

**Nächste Kandidaten aus dem Prüfplan**

1. `CMS/admin/modules/subscriptions/OrdersModule.php` (weitere Zerlegung / DTO-Ansatz)
2. `CMS/admin/views/subscriptions/orders.php`
3. `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` (weitere Zerlegung / DTO-Ansatz)
4. `CMS/admin/modules/system/DocumentationSyncEnvironment.php` (weitere Entkopplung von Shell-/Capability-Layern)
5. `CMS/admin/modules/system/MailSettingsModule.php` (weitere Trennung von ViewModel-/Aktionslogik)
6. `CMS/admin/modules/system/DocumentationGithubZipSync.php` (weitere Extraktion von Archiv-/Staging-Helfern)
