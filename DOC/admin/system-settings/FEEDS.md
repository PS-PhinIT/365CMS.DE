# 365CMS – Admin: Feeds (Feed-Aggregator und RSS-Ausgang)

> **Stand:** 2026-11-11 | **Version:** 4.0.0 | **Status:** Umgesetzt

## Deutsch

### Feed-Aggregator (ehemals Plugin cms-feed)

Der Aggregator sammelt Artikel aus fremden RSS- und Atom-Feeds und zeigt sie auf eigenen Seiten. Er ist seit
4.0.0 Teil des Cores; die Verwaltung liegt unter *Einstellungen → Feeds* (gleiche Capability `manage_feeds`).
Frühere Aufrufe von `/admin/feeds` leiten dorthin um.

| Unterseite | Inhalt |
|---|---|
| Übersicht | Kennzahlen, Abrufzustand, Quellen mit Handlungsbedarf, neueste Artikel; „Fällige Quellen jetzt abrufen“ und „Alle aktiven Quellen abrufen“ |
| Quellen | Feed-Adresse, Name, Bereich, Website, Abrufintervall (15 min – 24 h), Höchstzahl je Abruf; Sofortabruf, Massenaktionen (abrufen, aktivieren, deaktivieren, löschen) |
| Artikel | Filter nach Bereich, Quelle, Status und Text; hervorheben, ausblenden, löschen |
| Bereiche | Öffentliche Themenseiten mit Name (DE/EN), Adresse, Symbol, Layout (Raster, Liste, Magazin), Artikel je Seite, Sichtbarkeit |
| E-Mail-Digests | Zusammenfassungen ausgewählter Bereiche an feste Adressen, 1–4× täglich oder wöchentlich, DE/EN, Testversand |
| Mitglieder-Abos | Abos aus dem Mitgliederbereich ansehen und löschen |
| Abrufprotokoll | Aufträge der Abruf-Queue mit Auslöser, neuen Artikeln und Fehler; erneut einreihen, offene verwerfen, aufräumen |
| Katalog & OPML | 10 kuratierte Vorlagen (rund 300 Quellen) ganz oder teilweise übernehmen; OPML-Import (Gruppen werden Bereiche) und -Export |
| Einstellungen | Allgemein (Einschalten, Einwilligung, Titel/Beschreibung DE/EN, Adresse der Übersicht, Anzeige, Filter, Aufbewahrung, Quellen je Cron-Lauf), Design (Farben, Radius, Spalten), Digest (Absender, Betreff, Umfang) |
| RSS-Ausgang | Einstellungen von `/feed` (siehe unten) |

**Öffentliche Seiten** (nur bei eingeschaltetem Aggregator, jeweils auch unter `/en/…`):

- `/feeds` – alle öffentlichen Bereiche mit Suche und Seitenblättern (Adresse unter *Einstellungen* änderbar),
- `/feed/<bereich>` und `/feeds/<bereich>` – Bereichsseite im Layout des Bereichs,
- `/feeds/embed` – dieselbe Übersicht ohne Theme-Rahmen für ein `<iframe>` auf derselben Website
  (`X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'`, `noindex`).

`/feed` ohne weiteren Pfad bleibt der RSS-Ausgang. Ist „Einwilligung erforderlich“ aktiv, erscheinen die Artikel
erst nach Zustimmung zu „Externe Medien“ bzw. zum Dienst `cms_feed`, weil Vorschaubilder von fremden Servern geladen
werden. Links und Bilder werden nur als öffentliche `http(s)`-Adressen ausgegeben.

Templates liegen unter `CMS/views/feeds/` (`archive`, `category`, `card`, `consent`, `embed`) und lassen sich im
Theme unter `themes/<slug>/feeds/<name>.php` überschreiben. Die Farben aus *Design* werden als CSS-Variablen
`--fd-*` ausgegeben.

**Abruf:** Die Abruf-Queue läuft mit dem Cron (`cron.php` mit `task=mail-queue`, `hourly` oder `feeds`). Je Lauf
werden fällige Quellen eingereiht und höchstens „Quellen je Cron-Lauf“ abgerufen; parallele Läufe sind gesperrt.
Jede Adresse wird vor dem Abruf geprüft (nur `http(s)`, keine internen oder privaten Ziele nach DNS-Auflösung).
Quellen mit Fehler werden frühestens nach 30 Minuten erneut versucht. Filterlisten (Begriffe, Autoren, Domains)
verwerfen passende Artikel schon beim Abruf. Alte Artikel (außer hervorgehobenen) und Protokolleinträge werden nach
den eingestellten Fristen entfernt.

**E-Mails:** Digests und Mitglieder-Abos werden stündlich geprüft und über die Mail-Queue versendet. Mitglieder
verwalten ihr Abo im Mitgliederbereich unter *Feed-Abo* (`/member/plugin/feeds`): Quellen öffentlicher Bereiche,
täglich um 9 und/oder 15 Uhr oder wöchentlich an einem Wochentag, Sprache der E-Mails. Empfänger ist die Adresse
des Kontos. Abos gelöschter Konten werden täglich entfernt.

**Datenübernahme aus cms-feed:** Beim Update wird das Plugin deaktiviert; Bereiche, Kanäle, Artikel, Digests,
Mitglieder-Abos (auch aus der älteren Tabelle `feed_subscriptions`) und Einstellungen werden automatisch und
wiederholbar übernommen (Zuordnung über `legacy_id`, Artikel in Blöcken). War das Plugin aktiv, wird der
Aggregator eingeschaltet. Die Plugin-Tabellen bleiben unverändert erhalten.

Tabellen: `feeds_categories`, `feeds_sources`, `feeds_items`, `feeds_digests`, `feeds_subscriptions`,
`feeds_fetch_queue`; Einstellungen in `cms_settings`, Gruppe `feeds_aggregator`.

### RSS-Ausgang (/feed)

Die Einstellungen des RSS-Ausgangs liegen unter `/admin/settings?tab=feeds&section=rss` und benötigen die Capability
`manage_feeds`. Der vorhandene öffentliche Endpunkt `/feed` bleibt erhalten.

Unter *Einstellungen → Feeds* lassen sich der Feed aktivieren, die Zahl der Einträge (1–100),
Auszug oder Volltext, Beitragsbilder sowie Kategorie-, Schlagwort- und Sprach-Feeds konfigurieren.
Kategorie- und Schlagwort-Varianten verwenden `/feed?category=<slug>` bzw. `/feed?tag=<slug>`;
Sprachvarianten verwenden `/feed?lang=<locale>`. Bei aktivierter Sprachunterstützung ergänzt das
Standard-Theme `<link rel="alternate">` für die verfügbaren Sprachen.

`ThemeRouter` leitet `/feed` an den vorhandenen `FeedService` weiter. Volltext wird aus dem
gespeicherten Editor.js-Inhalt mit `EditorJsRenderer` erzeugt, durch `PurifierService` bereinigt und
als `content:encoded` ausgegeben; relative Medien- und Linkpfade werden auf die Site-URL bezogen.
Kategorie-Feeds berücksichtigen auch zusätzliche Zuordnungen und Unterkategorien. Beitragsbilder
werden als Media-RSS-Bildreferenz ausgegeben. RSS-Antworten unterstützen ETag und Last-Modified mit
`304 Not Modified`. Der Cache läuft nach fünf Minuten ab und wird bei Änderungen an Beiträgen,
Kategorien (einschließlich Zuordnungsrelation), Schlagwörtern und Feed-Einstellungen verworfen.

Die Einstellungen werden in `cms_settings` unter der Gruppe `feeds` gespeichert. Änderungen
erfordern CSRF und werden im Audit-Log protokolliert. Es wird keine Site-Zuordnung für Inhalte
eingeführt.

## English

**Feed aggregator** (formerly the cms-feed plugin): managed under *Settings → Feeds* (`manage_feeds`) with
overview, sources, articles, sections, email digests, member subscriptions, fetch log, catalog & OPML and settings.
Public pages `/feeds`, `/feed/<section>`, `/feeds/<section>` and `/feeds/embed` (same-site iframe only), each also
under `/en/…`, are available once the aggregator is switched on. Fetching runs via cron with SSRF checks, per-source
intervals and a lock against parallel runs. Members manage their email subscription at `/member/plugin/feeds`.
Plugin data is migrated automatically and idempotently on update. Templates can be overridden in
`themes/<slug>/feeds/<name>.php`.

**RSS output:** settings are available at `/admin/settings?tab=feeds&section=rss` and require `manage_feeds`. The existing
public `/feed` URL remains unchanged. Administrators can enable the feed, choose 1–100 entries,
excerpt or full-text output, featured images, category and tag variants, and language variants.
Responses support ETag and Last-Modified conditional requests; the five-minute cache is invalidated
when posts, taxonomies, or feed settings change.
