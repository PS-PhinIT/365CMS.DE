# 365CMS – Spezifikation: Freigabe-Ablauf, eigene Inhaltstypen und Felder, beliebig viele Sprachen

> **Stand:** 2026-11-12 | **Ziel-Version:** 4.1 / 4.2 | **Status:** vorbereitet (Spezifikation, Bestandsaufnahme) · **Fortschritt:** PROGRESS.md, Arbeitspaket 5

## English (summary)

Specification for the three largest functional gaps compared with WordPress, Drupal and TYPO3: (1) an editorial approval workflow, (2) custom content types and custom fields, (3) any number of content languages. Each part starts with an inventory of the current code (file references, counts), then defines the target, data model, migration path, permissions, UI, phases with effort and open decisions. Key findings: the capabilities `posts.publish`/`pages.publish` already exist but are never checked, and no default role has them; content languages are stored as fixed `*_en` columns in 8 tables and handled in about 70 files, although `ContentLocalizationService` already has a locale filter and a `*_{locale}` fallback. Recommended order: workflow first (smallest, prerequisite for the others), then fields on existing types, then custom types, then languages.

## Deutsch

Reihenfolge der Umsetzung: **A Freigabe-Ablauf → B Felder und Inhaltstypen → C Sprachen.** A ist am kleinsten und wird von B und C gebraucht (neue Inhaltstypen und Übersetzungen sollen denselben Status- und Freigabeweg nutzen). C ist der größte Umbau und profitiert davon, dass B schon ein allgemeines Inhaltsmodell mitbringt.

---

## A. Freigabe-Ablauf (Redaktions-Workflow)

### A.1 Ist-Stand (12.11.2026)

| Punkt | Befund |
|---|---|
| Status von Beiträgen/Seiten | `draft`, `published`, `private`, `scheduled` (geplant über `planned_at`/Veröffentlichungsdatum), `trash` mit `previous_status`; Spalte `status VARCHAR(20)` in `posts` und `pages` (`SchemaManager`) |
| Verteilung im Code | `'published'` kommt in **65** Dateien vor, `'scheduled'` in 17 – Statuswerte sind über Editor, Listen, Kalender, Suche, Sitemap, Feeds und Themes verstreut |
| Veröffentlichungsrechte | `posts.publish` und `pages.publish` stehen in der Rechteliste (`RolesModule::CAPABILITY_GROUPS`), werden aber **nirgends geprüft**: Wer bearbeiten darf, darf auch veröffentlichen |
| Standardrollen | `UserService::DEFAULT_ROLE_CAPABILITIES`: Redakteur (`manage_pages`, `edit_all_posts`, …), Autor (`edit_own_posts`, …) – **keine Rolle** hat `posts.publish`/`pages.publish`; nur `admin` hat implizit alle Rechte |
| Bearbeitungsrechte je Element | zentral in `ContentEditPermission::canEdit()` (Beiträge: `edit_all_posts` oder Autor + `edit_own_posts`; Seiten: `manage_pages`) |
| Revisionen | `post_revisions`, `page_revisions` mit `created_by`; Übersicht *Letzte Änderungen* (`ContentChangesService`) |
| Benachrichtigungen | Mail-Dienst und Admin-Hinweise (`AdminNoticeService`) vorhanden; keine Redaktions-Benachrichtigungen |

### A.2 Ziel

1. Neuer Status **„Zur Prüfung“** (`pending`) für Beiträge und Seiten.
2. Wer `posts.publish` bzw. `pages.publish` nicht hat, kann nur speichern oder **zur Prüfung einreichen**. „Veröffentlichen“ und „Planen“ sind dann gesperrt – serverseitig, nicht nur in der Oberfläche.
3. Prüfende (mit Veröffentlichungsrecht) sehen eine Liste „Zur Prüfung“ und können **freigeben** (veröffentlichen oder planen) oder **zurückgeben** (mit Kommentar → Status `draft`).
4. **Kommentarverlauf** je Inhalt (Einreichung, Rückgabe mit Begründung, Freigabe) und Benachrichtigung der Beteiligten (Mail und Admin-Badge).
5. **Vorschau-Link zum Teilen**: signierter, ablaufender Link auf den Entwurf für Personen ohne Login (z. B. Fachabteilung).
6. Optional in Stufe 2: mehrstufige Freigaben (z. B. Fachprüfung → Rechtsprüfung) je Inhaltstyp.

### A.3 Datenmodell

```sql
-- Status-Erweiterung: kein Schemawechsel nötig (VARCHAR(20)), nur neue Werte `pending`.
CREATE TABLE {p}content_workflow_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(40) NOT NULL,          -- post, page, später eigene Typen
    content_id INT UNSIGNED NOT NULL,
    locale VARCHAR(10) NOT NULL DEFAULT '',     -- für Übersetzungen (Teil C)
    action VARCHAR(30) NOT NULL,                -- submitted, returned, approved, published, comment
    from_status VARCHAR(20) DEFAULT NULL,
    to_status VARCHAR(20) DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_content (content_type, content_id, created_at)
);
CREATE TABLE {p}content_preview_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(40) NOT NULL,
    content_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,               -- nur Hash speichern
    expires_at DATETIME NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_token (token_hash)
);
```

### A.4 Technik

- **`ContentStatus`** (neu, `core/Services/Content/`): eine Stelle für Statuswerte, Bezeichnungen, Farben, „öffentlich sichtbar?“ und erlaubte Übergänge. Listen, Editor-Badges, Kalender und Filter lesen daraus statt eigener Arrays (die 65 Fundstellen werden schrittweise umgestellt; Frontend-Abfragen bleiben bei `status = 'published'`).
- **`ContentWorkflow`** (neu): `canPublish(type, userId)`, `submit()`, `approve()`, `return()`, schreibt `content_workflow_events`, feuert `cms_content_submitted`, `cms_content_approved`, `cms_content_returned`.
- **Durchsetzung im Speichern** von `PostsModule`/`PagesModule` und `post-editor-api.php`: angefragter Status `published`/`scheduled` ohne Recht → `pending` und Hinweis. `ContentEditPermission` bekommt `canPublish()`.
- **Vorschau-Link:** Route `/preview/<token>` rendert den Entwurf über denselben Theme-Weg wie die normale Ansicht, `noindex`, kein Cache, Ablauf standardmäßig 7 Tage, widerrufbar.
- **Benachrichtigung:** Mails an Prüfende (Einreichung) und Autor (Rückgabe/Freigabe) über `MailService`; Badge „Zur Prüfung“ im Admin-Menü über `BadgeProvider`.

### A.5 Migration und Kompatibilität

Weil heute keine Rolle `posts.publish`/`pages.publish` hat, würde ein bloßes Erzwingen alle Redakteure und Autoren aussperren. Deshalb:

1. Update-Schritt vergibt `posts.publish` an `editor` und `author` sowie `pages.publish` an `editor` (= heutiges Verhalten bleibt).
2. Einstellung *Inhalt → Freigabe*: „Autoren brauchen eine Freigabe“ (entzieht `author` das Veröffentlichungsrecht) – bewusster Schritt der Betreiber.
3. Themes und Plugins, die `status = 'published'` abfragen, bleiben unverändert korrekt (`pending` ist nicht öffentlich).

### A.6 Oberfläche

- Editor: Status-Auswahl abhängig vom Recht; Knopf „Zur Prüfung einreichen“; Seitenleiste mit Verlauf und Kommentarfeld; Knopf „Vorschau-Link erstellen“.
- Listen *Beiträge*/*Seiten*: Filter „Zur Prüfung“, Sammelaktion „Freigeben“.
- Dashboard-Widget „Wartet auf meine Prüfung“; Redaktionskalender zeigt `pending` farbig.

### A.7 Phasen und Aufwand

| Phase | Inhalt | Aufwand |
|---|---|---|
| A1 | `ContentStatus`, `pending`, Rechte-Durchsetzung inkl. Update-Schritt, Listenfilter, Tests | 3–4 Tage |
| A2 | `ContentWorkflow`, Verlauf und Kommentare, Mails, Badge, Dashboard-Widget | 3 Tage |
| A3 | Vorschau-Links | 1–2 Tage |
| A4 | mehrstufige Freigaben je Inhaltstyp (optional) | 3–5 Tage |

### A.8 Offene Entscheidungen

- Dürfen Autoren nach der Freigabe noch ändern (neue Prüfung nötig?) – Vorschlag: Änderungen an veröffentlichten Inhalten ohne Recht landen als **Revision „zur Prüfung“**, der Live-Stand bleibt bis zur Freigabe.
- Gilt der Ablauf auch für Downloads, Knowledgebase, Newsletter-Kampagnen? Vorschlag: zunächst nur Beiträge, Seiten und eigene Inhaltstypen.

---

## B. Eigene Felder und Inhaltstypen

### B.1 Ist-Stand

| Punkt | Befund |
|---|---|
| Inhaltstypen | fest im Code: Seiten, Beiträge, Hub-Sites, Downloads, Formulare, Knowledgebase, Newsletter, Feed-Artikel – jeweils mit eigenen Tabellen, Diensten und Admin-Seiten |
| Gemeinsame Schnittstelle | `ContentTypeRegistry` mit Providern für **Papierkorb**, **Suchindex** und **Import/Export** (`TrashProvider`, `SearchDocumentProvider`, `ExchangeProvider`) – darauf lassen sich neue Typen ohne Sonderfälle anschließen |
| Zusatzdaten | `posts.post_meta_json`, `pages.page_meta_json` (JSON, u. a. SEO); `user_meta`, `plugin_meta`; keine frei definierbaren Felder |
| Feldtypen-Wissen | Formular-Baukasten mit 15 Feldtypen, Prüfregeln, DE/EN-Beschriftungen (`FormFieldTypes`, `FormValidator`, Sortieren per Ziehen/Tastatur) – wiederverwendbar für einen Feld-Baukasten |
| Editor | Editor.js mit Block-Registry (`EditorJsBlockRegistry`) |

### B.2 Ziel

1. **Feldgruppen** an bestehenden Typen (Beiträge, Seiten, später Downloads/KB): z. B. „Veranstaltung“ mit Datum, Ort, Preis an Beiträgen einer Kategorie.
2. **Eigene Inhaltstypen** (z. B. Referenzen, Team, Produkte, Veranstaltungen) mit eigenem URL-Präfix, Archiv- und Einzelseite, Feldern, Taxonomie (Kategorien/Schlagwörtern), Status und Freigabe (Teil A), Revisionen, Papierkorb, Suche und Import/Export.
3. Ausgabe im Theme: Vorlagen `content-types/<typ>/{archive,single}.php` mit Rückfall auf generische Core-Vorlagen; Feldwerte über eine kleine API (`cms_field('preis')`), Editor.js-Block „Inhaltsliste“ (Abfrage nach Typ/Feld/Taxonomie).
4. Felder in Formularen der Website (z. B. Einreichungen) bewusst **nicht** in Phase 1.

### B.3 Datenmodell

```sql
CREATE TABLE {p}content_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_key VARCHAR(40) NOT NULL UNIQUE,        -- [a-z][a-z0-9_]{0,39}, wie Provider-Typen
    labels_json TEXT NOT NULL,                   -- Singular/Plural je Sprache
    url_base VARCHAR(80) NOT NULL,               -- z. B. "referenzen"; Prüfung gegen bestehende Routen
    supports_json TEXT NOT NULL,                 -- editor, excerpt, image, taxonomy, comments, revisions, workflow
    settings_json TEXT NULL,                     -- Archiv: Sortierung, je Seite, Menüpunkt
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE {p}field_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_key VARCHAR(60) NOT NULL UNIQUE,
    labels_json TEXT NOT NULL,
    fields_json MEDIUMTEXT NOT NULL,             -- Felddefinitionen (Typ, Schlüssel, Beschriftung, Prüfregeln, Standard)
    location_json TEXT NOT NULL,                 -- Regeln: Inhaltstyp, Kategorie, Vorlage
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE {p}content_entries (                -- Einträge eigener Typen
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_key VARCHAR(40) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NULL,                       -- Editor.js-JSON
    excerpt TEXT NULL,
    featured_image VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    previous_status VARCHAR(20) NULL,
    author_id INT UNSIGNED NULL,
    published_at DATETIME NULL, planned_at DATETIME NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_type_slug (type_key, slug),
    KEY idx_type_status (type_key, status, published_at)
);
CREATE TABLE {p}field_values (                   -- Werte für alle Typen (auch post/page)
    content_type VARCHAR(40) NOT NULL,
    content_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(60) NOT NULL,
    locale VARCHAR(10) NOT NULL DEFAULT '',      -- '' = sprachunabhängig (Teil C)
    value_text LONGTEXT NULL,
    value_number DECIMAL(20,6) NULL,             -- sortier-/filterbar
    value_date DATETIME NULL,                    -- sortier-/filterbar
    PRIMARY KEY (content_type, content_id, field_key, locale),
    KEY idx_number (field_key, value_number),
    KEY idx_date (field_key, value_date)
);
```

Begründung: Typisierte Wertspalten statt nur JSON, damit Archive nach Datum/Preis sortiert und gefiltert werden können (Veranstaltungen „ab heute“). Feldgruppen-Definitionen bleiben JSON, weil sie nur im Admin gelesen werden.

### B.4 Feldtypen (Phase 1)

Text, Mehrzeilig, Rich-Text (Editor.js-light), Zahl, Datum, Datum+Uhrzeit, Ja/Nein, Auswahl, Mehrfachauswahl, E-Mail, URL, Bild (Mediathek), Datei/Download (Verweis auf Downloads), Verknüpfung (anderer Inhalt), Wiederholgruppe (Phase 2).

### B.5 Technik

- **`FieldRegistry`/`FieldService`**: Feldtypen mit `sanitize`, `validate`, `render_admin`, `format`; Wiederverwendung der Prüflogik aus `FormValidator`.
- **`ContentTypeService`** registriert je aktivem Typ einen generischen Provider (`EntryContentProvider`) bei `ContentTypeRegistry` → Papierkorb, Suche, Import/Export ohne Zusatzcode.
- **Routing:** beim `register_routes` je Typ `/{url_base}` und `/{url_base}/{slug}` (+ `/en/`); Kollisionsprüfung gegen Core-Routen und bestehende Seiten-Slugs beim Anlegen.
- **Admin:** *Inhalt → Inhaltstypen* (Typen und Feldgruppen, Baukasten wie bei Formularen) und je Typ ein eigener Menüpunkt (über `CoreCatalog`-Erweiterung zur Laufzeit, Recht `manage_content_<typ>` bzw. `edit_content_<typ>`).
- **Editor:** gemeinsame Editor-Seite für Einträge (Editor.js, Felder in der Seitenleiste oder als Abschnitt), Felder auch im Beitrags-/Seiteneditor, wenn eine Feldgruppe passt.
- **Revisionen:** Feldwerte werden in die Revision (JSON) aufgenommen.
- **Hooks:** `cms_register_field_types`, `cms_content_type_registered`, `cms_field_value_saved`.

### B.6 Phasen und Aufwand

| Phase | Inhalt | Aufwand |
|---|---|---|
| B1 | Feldgruppen + `field_values` für Beiträge/Seiten, 12 Feldtypen, Ausgabe-API, Revisionen | 5–7 Tage |
| B2 | Eigene Inhaltstypen: Datenmodell, Admin (Typ-Baukasten, Liste, Editor), Routing, Vorlagen, Provider (Papierkorb/Suche/Export) | 7–10 Tage |
| B3 | Block „Inhaltsliste“, Filter/Sortierung nach Feldern, Sitemap, Feeds | 3–4 Tage |
| B4 | Wiederholgruppen, Verknüpfungen in beide Richtungen, Import aus CSV | 4–5 Tage |

### B.7 Offene Entscheidungen

- Typen per Oberfläche **und** per Code (Plugin-API) definierbar? Vorschlag: beides; Code-Definitionen sind schreibgeschützt in der Oberfläche.
- Kommentare für eigene Typen? Vorschlag: über `supports.comments`, Umsetzung in B3.

---

## C. Beliebig viele Inhaltssprachen

### C.1 Ist-Stand

| Punkt | Befund |
|---|---|
| Modell | Deutsch als Hauptsprache, Englisch in **festen Spalten** `*_en`: `posts` (4), `post_revisions` (4), `pages` (3), `page_revisions` (3), `post_categories`, `post_tags`, `feeds_categories` (2), `trash_media` (2) |
| Code-Bezug | rund **70 Dateien** referenzieren `title_en`, `content_en` o. Ä.; 17 Core-Dateien kennen das Präfix `/en/` |
| Erweiterungspunkte | `ContentLocalizationService::getContentLocales()` mit Filter `cms_content_supported_locales`; `postLocaleAvailabilityExpression()` kennt bereits einen Rückfall auf Spalten `*_{locale}` für weitere Sprachen; generierte Spalten `has_locale_de`/`has_locale_en` für schnelle Abfragen |
| Routing | `resolveRequestContext()` trennt das Sprachpräfix ab; Routen werden meist ohne Präfix registriert |
| Oberflächensprachen | `lang/de.yaml`, `lang/en.yaml`; `TranslationService` erkennt weitere Dateien automatisch (`lang/<code>.yaml`) |
| Übersetzung | *Übersetzungsstatus* (`TranslationStatusService`, Tabelle `translation_sync`), KI-Übersetzung von Editor.js-Inhalten (`ai-translate-editorjs`), `hreflang` an 24 Stellen |
| Funktionsbereiche | Formulare, Downloads, Feeds, Knowledgebase, Newsletter speichern DE/EN als Paar (`*_en`-Spalten oder `{de, en}`-JSON) |

### C.2 Ziel

1. **Sprachenverwaltung** (*Einstellungen → Sprachen*): beliebig viele Sprachen mit Code, Name, URL-Präfix (oder eigener Domain über MultiSites), Standardsprache, Reihenfolge, Rückfallsprache, aktiv/entwurf.
2. Inhalte (Beiträge, Seiten, Kategorien, Schlagwörter, eigene Typen, Feldwerte) in jeder Sprache mit **eigenem Slug, Status und Freigabe** (Teil A je Sprache).
3. Sprachumschalter, `hreflang`, Sitemaps und Suche für alle Sprachen; Übersetzungsstatus und KI-Übersetzung für alle Sprachpaare.
4. Funktionsbereiche (Formulare, Downloads, KB, Newsletter, Feeds) schrittweise auf dasselbe Modell.

### C.3 Datenmodell (Empfehlung: Übersetzungstabelle)

```sql
CREATE TABLE {p}languages (
    code VARCHAR(10) PRIMARY KEY,                -- de, en, fr, de-ch …
    name VARCHAR(80) NOT NULL,
    url_prefix VARCHAR(20) NOT NULL DEFAULT '',  -- '' nur für die Standardsprache
    fallback_code VARCHAR(10) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0
);
CREATE TABLE {p}content_translations (
    content_type VARCHAR(40) NOT NULL,           -- post, page, post_category, post_tag, entry, …
    content_id INT UNSIGNED NOT NULL,
    locale VARCHAR(10) NOT NULL,
    title VARCHAR(255) NULL,
    slug VARCHAR(200) NULL,
    content LONGTEXT NULL,
    excerpt TEXT NULL,
    meta_json TEXT NULL,                         -- SEO-Titel/-Beschreibung u. a.
    status VARCHAR(20) NOT NULL DEFAULT 'draft', -- je Sprache veröffentlichen
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (content_type, content_id, locale),
    UNIQUE KEY uq_slug (content_type, locale, slug)
);
```

Abgewogen gegen „weitere Spalten je Sprache“ (heutiges Muster, `*_{locale}`): Spalten skalieren nicht (Schemaänderung je Sprache, ~10 Tabellen), Übersetzungstabelle braucht dafür Joins. Mit Index auf `(content_type, locale, slug)` und dem vorhandenen Suchindex ist das unkritisch.

### C.4 Migrationsweg (ohne Bruch)

1. **C1 Lesepfad:** `ContentLocalizationService` liest Übersetzungen aus `content_translations`, für `en` mit Rückfall auf die `*_en`-Spalten. Alles Bestehende funktioniert weiter.
2. **C2 Schreibpfad:** Editor schreibt neue Sprachen in `content_translations`; Englisch weiterhin zusätzlich in `*_en` (Doppelschreiben), bis alle Leser umgestellt sind.
3. **C3 Umzug Englisch:** Migration kopiert `*_en` in `content_translations` (idempotent, in Blöcken), danach Lesen nur noch aus der Tabelle; `*_en`-Spalten bleiben eine Version lang als Sicherung und werden dann entfernt.
4. Themes: Hilfsfunktionen (`cms_localized_title()`, `cms_localized_url()`) statt direkter `*_en`-Zugriffe; die ~70 Fundstellen werden über diese Funktionen umgestellt, ein Testskript sucht verbliebene Direktzugriffe.

### C.5 Weitere Bausteine

- Routing: Präfix-Erkennung aus `languages` statt fest `en`; Kollisionsprüfung Präfix ↔ Seiten-Slugs.
- Oberflächensprache unabhängig von Inhaltssprache (Admin weiter de/en, Website in allen Inhaltssprachen mit `lang/<code>.yaml`; fehlende Texte fallen auf die Rückfallsprache).
- Sitemaps je Sprache mit `xhtml:link`-Alternativen; Suchindex je Sprache (Struktur je `locale` ist im Suchindex schon vorhanden).
- Übersetzungsstatus und KI-Übersetzung: Sprachpaar wählbar statt fest DE→EN.

### C.6 Phasen und Aufwand

| Phase | Inhalt | Aufwand |
|---|---|---|
| C0 | Sprachenverwaltung, Routing-Präfixe, `hreflang`/Umschalter aus `languages` | 3–4 Tage |
| C1–C2 | Übersetzungstabelle, Lese- und Schreibpfad für Beiträge/Seiten/Taxonomien, Editor mit Sprachauswahl | 8–10 Tage |
| C3 | Umzug der `*_en`-Daten, Umstellung der ~70 Fundstellen und der Themes | 5–7 Tage |
| C4 | Sitemaps, Suche, Übersetzungsstatus, KI-Übersetzung für alle Sprachen | 3–4 Tage |
| C5 | Funktionsbereiche (Formulare, Downloads, KB, Newsletter, Feeds) | je 1–2 Tage |

### C.7 Offene Entscheidungen

- Bleibt Deutsch fest die Hauptsprache (Originaltexte in `posts`/`pages`) oder wird die Standardsprache frei wählbar? Vorschlag: frei wählbar erst nach C3; bis dahin ist die Hauptsprache die der Grundtabellen.
- Eigene Domains je Sprache: über MultiSites (vorhanden) statt eigener Lösung.

---

## Gesamtplan

| Paket | Abhängig von | Aufwand gesamt |
|---|---|---|
| A Freigabe-Ablauf | – | 7–9 Tage (ohne A4) |
| B Felder und Inhaltstypen | A1 (Status), für Freigabe A2 | 15–21 Tage (ohne B4) |
| C Sprachen | A1; B1/B2, damit eigene Typen und Felder gleich mehrsprachig sind | 19–25 Tage (ohne C5) |

Vor jeder Phase: Tests in `TESTS/` (Statusübergänge und Rechte als reine Logik-Tests wie `ContentEditPermission`; Migrationen mit Neuinstallation **und** Update geprüft, wie bei Schema v25).
