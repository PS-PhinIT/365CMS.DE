# 365CMS – Projektdokumentation | Abschnitt: Admin – Beiträge, Kategorien und Tags

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable
> **Routen:** `/admin/posts`, `/admin/post-categories`, `/admin/post-tags`, `/admin/post-editor-api` (JSON) | **CSRF-Aktionen:** `admin_posts`, `admin_post_editor` (Editor-API)

## English (summary)

Blog posts are managed at `/admin/posts` (`CMS/admin/posts.php` → `CMS/admin/modules/posts/PostsModule.php`, views under `CMS/admin/views/posts/`). Categories (`/admin/post-categories`) and tags (`/admin/post-tags`) reuse the same module.

- Access: `edit_all_posts` (all posts), `edit_own_posts` (own posts only), or `posts.view` (read). Categories and tags require `edit_all_posts`.
- List filters: `status=published|scheduled|draft|private`, `category`, `q`.
- POST actions: `save`, `delete`, `bulk`, `save_category`, `delete_category`, `switch_locale`, `copy_de_to_en`.
- Bulk actions: `delete`, `publish`, `draft`, `set_category`, `clear_category`, `set_author_display_name`, `clear_author_display_name`.
- Scheduling: a published post with `published_at` in the future is listed as *scheduled* and stays hidden from the frontend, archives, search and sitemap until that time (`cms_post_publication_where()`).
- Post templates and their meta fields come from `post_templates` in the active theme's `theme.json`.
- Editor layout (4.0.0): header bar (back link, save state "Gespeichert um HH:MM", focus mode, Vorschau, Speichern/Planen/Veröffentlichen), writing column (category label, serif title, EditorJS; max. width from *Beitrags-Editorbreite*, default 750 px) and a settings column (~300 px). Behaviour lives in ES modules under `CMS/assets/js/post-editor/`, styles in `CMS/assets/css/admin-post-editor.css`. The EditorJS integration and the stored JSON format are unchanged.
- Publish checklist: featured image set, meta description with 120–160 characters, category set. While an item is missing, "Veröffentlichen" and "Planen" stay focusable with `aria-disabled="true"` and open a dialog listing the gaps. The server (`PostPublishChecklistService`) enforces the same rules on publish/schedule, on the bulk action `publish` and when restoring a revision of a scheduled post. Drafts, private posts and updates of already live posts are exempt.
- Focus mode: Ctrl+Shift+F (macOS ⌘+Shift+F) or the header button hides sidebar, topbar and settings column; Escape exits. The state is kept in `sessionStorage` per post.
- Revisions (`cms_post_revisions`, schema v24, `PostRevisionService`): created on manual save, by autosave at most every 10 minutes, on restore and as a baseline; the last 30 per post are kept. "Vergleichen" diffs a revision against its predecessor (title/meta word by word, EditorJS block by block); "Diese Version wiederherstellen" creates a new revision and never deletes history.
- JSON endpoint `/admin/post-editor-api` (autosave, revision list, compare, restore; CSRF action `admin_post_editor`).

## Deutsch

### Überblick

| Bestandteil | Datei |
|---|---|
| Beiträge | `CMS/admin/posts.php`, `CMS/admin/views/posts/list.php`, `edit.php` |
| Kategorien | `CMS/admin/post-categories.php`, `CMS/admin/views/posts/categories.php` |
| Tags | `CMS/admin/post-tags.php`, `CMS/admin/views/posts/tags.php` |
| Fachlogik | `CMS/admin/modules/posts/PostsModule.php`, `PostsCategoryViewModelBuilder.php` |
| Checkliste & Revisionen | `CMS/core/Services/PostPublishChecklistService.php`, `CMS/core/Services/PostRevisionService.php` |
| Editor-API (JSON) | `CMS/admin/post-editor-api.php` |
| Editor-Oberfläche | `CMS/assets/js/post-editor/` (`editor.js`, `checklist.js`, `dialog.js`, `focus-mode.js`, `autosave.js`, `revisions.js`), `CMS/assets/css/admin-post-editor.css` |
| Frontend | `CMS/core/Routing/PublicRouter.php`, `ThemeArchiveRepository.php`, `PermalinkService.php` |

### Berechtigungen

| Capability | Wirkung |
|---|---|
| `edit_all_posts` | Alle Beiträge sehen und bearbeiten, Kategorien und Tags verwalten |
| `edit_own_posts` | Nur eigene Beiträge (Filter `author_id = <aktueller Benutzer>` in Liste, Bearbeiten und Sammelaktionen) |
| `posts.view` | Lesender Zugriff auf die Liste |

Der Zugriff setzt zusätzlich `isAdmin()` voraus (Admin-Bereich).

### Beitragsliste

- **Statuskacheln:** Gesamt, veröffentlicht, **geplant**, Entwürfe, privat.
- **Filter:** `?status=` (`published`, `scheduled`, `draft`, `private`), `?category=<id>`, `?q=` (Titel/Slug).
- „Geplant“ = Status `published` **und** `published_at` liegt in der Zukunft.
- **Sammelaktionen:** `publish`, `draft`, `set_category`, `clear_category`, `set_author_display_name`, `clear_author_display_name`, `delete`.
- `publish` veröffentlicht nur Beiträge, die die [Veröffentlichungs-Checkliste](#veröffentlichungs-checkliste) erfüllen; bereits veröffentlichte oder geplante Beiträge bleiben unverändert erlaubt. Übersprungene Beiträge werden in der Meldung mit Titel genannt.

### Beitragseditor

Zusätzlich zu den Feldern der Seiten (Titel/Slug/Inhalt DE+EN, Status, Beitragsbild, Meta-Titel, Meta-Beschreibung, erweiterte SEO-Felder) bietet der Beitragseditor:

| Feld | Hinweis |
|---|---|
| Auszug DE/EN (`excerpt`, `excerpt_en`) | Teaser für Archive, Feeds und Suche |
| Kategorie(n) | Hauptkategorie in `category_id`, weitere Zuordnungen über `cms_post_category_rel` |
| Tags | Kommagetrennt, Zuordnung über `cms_post_tag_rel` |
| Veröffentlichungsdatum (`published_at`) | Zukunftsdatum = geplanter Beitrag |
| „Inhalt aktualisiert am“ (`content_updated_at`) | Sichtbares Aktualisierungsdatum |
| Autor-Anzeigename / -URL | Überschreibt den angezeigten Autor (z. B. Gastautor); URL nur `http`/`https` |
| Beitrags-Template (`post_template`) | Aus `post_templates` der `theme.json` des aktiven Themes, Fallback `default` („Standard“) |
| Template-Metafelder (`post_meta_json`) | Felder, die das gewählte Template in `meta_fields` definiert |
| Kommentare erlauben | Nur wirksam, wenn Kommentare global aktiviert sind |

Sprachumschaltung (`switch_locale`), DE→EN-Kopie (`copy_de_to_en`) und KI-Helfer funktionieren wie bei [Seiten](PAGES.md). Revisionen, Autosave und die Veröffentlichungs-Checkliste haben im Beitragseditor seit 4.0.0 einen eigenen Ablauf (siehe unten).

### Editor-Layout (seit 4.0.0)

| Bereich | Inhalt |
|---|---|
| Kopfleiste | Link „Beiträge“ (zurück zur Liste), Speicherstatus („Gespeichert um HH:MM“ bzw. „Noch nicht gespeichert“), Fokusmodus, „Vorschau“ (nach dem ersten Speichern), „Speichern“, „Planen“ und „Veröffentlichen“. Bereits veröffentlichte Beiträge zeigen stattdessen „Aktualisieren“. |
| Schreibbereich (max. Breite aus *Einstellungen › Beitrags-Editorbreite*, Standard 750 px) | Kategorie-Label, mitwachsender Titel in Source Serif 4, EditorJS. Template-, Auszugs- und SEO-Karten folgen darunter. |
| Einstellungsspalte (ca. 300 px) | Veröffentlichungs-Checkliste, Veröffentlichung, Kategorie, Tags, Beitragsbild, SEO-Beschreibung mit Live-Zeichenzähler, Permalink, Autor, Revisionen |

Unter 1200 px Fensterbreite rückt die Einstellungsspalte unter den Schreibbereich. Das Verhalten steckt in kleinen ES-Modulen unter `CMS/assets/js/post-editor/` (Einstieg `editor.js`, geladen über `$pageAssets['modules']`), die Gestaltung in `CMS/assets/css/admin-post-editor.css`. Die EditorJS-Integration (`admin-content-editor.js`) und das gespeicherte JSON-Format bleiben unverändert.

### Veröffentlichungs-Checkliste

Die Checkliste in der Einstellungsspalte prüft live:

| Punkt | Bedingung | Hinweis bei Fehlen |
|---|---|---|
| Beitragsbild | `featured_image` ist gesetzt | „Beitragsbild fehlt.“ |
| SEO-Beschreibung | `meta_description` hat 120–160 Zeichen (getrimmt, Mehrfach-Leerzeichen zählen einfach) | „Meta-Beschreibung fehlt / zu kurz / zu lang“ mit aktueller Zeichenzahl |
| Kategorie | Hauptkategorie gesetzt | „Kategorie fehlt.“ |

- Solange ein Punkt rot ist, tragen „Veröffentlichen“ und „Planen“ `aria-disabled="true"`, bleiben aber fokussierbar. Ein Klick öffnet einen Warndialog mit den fehlenden Punkten; „Zum ersten fehlenden Feld“ springt direkt dorthin. Sind alle Punkte grün, sind die Buttons normal aktiv.
- „Speichern“ eines Entwurfs bleibt immer möglich. Wird im Statusfeld „Veröffentlicht“ gewählt, gilt die Prüfung auch für „Speichern“.
- **Serverseitig** prüft `PostPublishChecklistService` dieselben Regeln (`PostsModule::save()`), wenn ein Beitrag veröffentlicht oder geplant wird, bei der Sammelaktion `publish` und beim Wiederherstellen einer Revision eines geplanten Beitrags. Abgelehnte Speicherungen melden z. B. „Veröffentlichen nicht möglich – die Veröffentlichungs-Checkliste ist unvollständig.“ mit den fehlenden Punkten als Liste.
- Ausgenommen sind Entwürfe, private Beiträge und Änderungen an bereits live veröffentlichten Beiträgen (kein nachträgliches Sperren bestehender Inhalte).

### Fokusmodus

- Umschalten mit **Strg+Umschalt+F** (macOS: **⌘+Umschalt+F**) oder dem Fokus-Button in der Kopfleiste (`aria-pressed`).
- Blendet Seitenleiste, Topbar und Einstellungsspalte aus; **Escape** beendet den Modus, sofern nicht gerade ein Dialog oder EditorJS-Menü offen ist.
- Der Zustand gilt nur für die aktuelle Bearbeitungssitzung (`sessionStorage` je Beitrag), nicht dauerhaft.

### Revisionen und Autosave

| Typ | Anzeige | Entsteht |
|---|---|---|
| `manual` | „Gespeichert“ | bei jedem manuellen Speichern mit geändertem Stand |
| `autosave` | „Autosave“ | durch den Autosave, höchstens alle 10 Minuten je Beitrag |
| `restore` | „Wiederhergestellt“ | bei „Diese Version wiederherstellen“ |
| `baseline` | „Ausgangsstand“ | vor der ersten erfassten Änderung bzw. vor einer Wiederherstellung, falls der aktuelle Stand noch nicht gesichert ist |
| `legacy` | „Ältere Version“ | Revisionen aus Versionen vor 4.0.0 |

- Eine Revision enthält Titel, Slugs, Inhalte (Editor.js-JSON) und Auszüge DE/EN, Beitragsbild, Meta-Titel und -Beschreibung, Kategorie, Tags, Autor-Anzeige, Status und Datumswerte des Beitrags sowie Bearbeiter (`created_by`) und Zeitpunkt (`created_at`). Identische Stände werden über einen SHA-1-Fingerabdruck erkannt und nicht doppelt gespeichert.
- Pro Beitrag bleiben die **letzten 30** Revisionen erhalten; ältere werden beim Speichern entfernt. Das gilt auch für Revisionen aus Versionen vor 4.0.0 (bis dahin unbegrenzt): Die erste neue Revision nach dem Update (Speichern, Autosave oder Wiederherstellung) kürzt den Verlauf des Beitrags auf 30 Einträge.
- **Autosave** läuft nur für gespeicherte Beiträge und nur nach Änderungen: Entwürfe werden höchstens jede Minute selbst gespeichert, veröffentlichte, geplante und private Beiträge bleiben unverändert – ihr Zwischenstand landet nur als Revision. Die Kopfleiste zeigt danach „Gespeichert um HH:MM“.
- Das Panel **Revisionen** listet Zeitpunkt, Autor und Typ. **Vergleichen** stellt die Revision ihrer Vorgängerversion gegenüber: Titel und Meta-Felder als Wort-Diff, der Editor.js-Inhalt Block für Block mit markierten Ergänzungen und Löschungen.
- **Diese Version wiederherstellen** übernimmt Titel, Inhalte, Auszüge, Beitragsbild, Meta-Felder, Kategorie, Tags und Autor-Anzeige der Revision. Status, Slugs und Datumswerte bleiben unverändert. Vorher wird der aktuelle Stand gesichert; die Wiederherstellung wird als neue Revision abgelegt, es wird nie Historie gelöscht. Existiert die damalige Kategorie nicht mehr, bleibt die aktuelle erhalten.

### Editor-API (`/admin/post-editor-api`)

| Methode | Aktion | Zweck |
|---|---|---|
| GET | `revisions&post_id=…` | Revisionsliste |
| GET | `compare&post_id=…&revision_id=…` | Vergleich mit der Vorgängerversion |
| GET | `token` | frisches CSRF-Token (nur bei gleicher Herkunft) |
| POST | `autosave` | Autosave mit den Formulardaten des Editors |
| POST | `restore` (`post_id`, `revision_id`) | Version wiederherstellen |

Voraussetzungen: angemeldet und `isAdmin()`; lesen mit `posts.view`, `edit_own_posts`, `edit_all_posts` oder `posts.edit`, schreiben nur mit einer der Bearbeiten-Capabilities. POST-Aktionen verlangen das CSRF-Token der Aktion `admin_post_editor`. Mit `edit_own_posts` gelten wie im Editor nur eigene Beiträge. Antworten sind JSON (`success`, `error`, ggf. `code: "csrf"`).

### Kategorien (`/admin/post-categories`)

- Hierarchisch (`parent_id`, `sort_order`), Slugs für DE und EN (`slug`, `slug_en`).
- Aktionen: `save_category`, `delete_category`, `delete_categories_with_replacement`, `bulk_delete_categories`.
- **Ersatzkategorie:** Beim Löschen kann eine Ersatzkategorie gewählt werden; zugeordnete Beiträge werden umgehängt. Eine hinterlegte `replacement_category_id` erlaubt das gesammelte Löschen markierter Kategorien.
- Bei Neuinstallationen legt das Modul Standard-Kategoriebäume an (u. a. Microsoft‑365-Themen).
- Die Archiv-URL-Basis ist unter *Einstellungen → Inhalte* konfigurierbar (Standard `/kategorie/<slug>` bzw. `/en/category/<slug>`).

### Tags (`/admin/post-tags`)

- Aktionen: `save_tag`, `delete_tag`, `bulk_delete_tags`, jeweils optional mit Ersatz-Tag.
- Archiv-Basis Standard `/tag/<slug>` (DE und EN), konfigurierbar unter *Einstellungen → Inhalte*. Kategorie- und Tag-Basis müssen sich unterscheiden.

### Permalinks

Die Beitrags-URL folgt der Struktur aus *Einstellungen → Inhalte* (`setting_post_permalink_structure`):

| Preset | Struktur |
|---|---|
| `blog` (Standard) | `/blog/%postname%` |
| `slug` | `/%postname%` |
| `year` | `/%year%/%postname%` |
| `dated` | `/%year%/%monthnum%/%day%/%postname%` |
| `custom` | frei, Platzhalter `%year%`, `%monthnum%`, `%day%`, `%postname%` |

### Datenmodell

`cms_posts` (inkl. `title_en`, `slug_en`, `content_en`, `excerpt_en`, `featured_image`, `meta_title`, `meta_description`, `author_display_name`, `author_display_url`, `post_template`, `post_meta_json`, `published_at`, `content_updated_at`), `cms_post_categories`, `cms_post_tags`, `cms_post_tag_rel`, `cms_post_category_rel`, `cms_post_revisions` (seit Schema v24 mit `revision_type`, `fingerprint` und den Spalten für Beitragsbild, Meta-Felder, Kategorie, Tags und Autor-Anzeige, siehe [DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md#schema-v24-400)). Fehlende Spalten werden vom Modul bei Bedarf per `ALTER TABLE` ergänzt (Abwärtskompatibilität mit älteren Installationen).

### Hinweise

- Beiträge mit Veröffentlichungsdatum in der Zukunft erscheinen weder im Frontend noch in Archiven, Suche oder Sitemap, bis der Zeitpunkt erreicht ist. Zentral dafür ist der SQL-Helfer `cms_post_publication_where()` (`CMS/includes/functions/options-runtime.php`).
- Gelöschte Beiträge sind nicht wiederherstellbar (kein Papierkorb). Vor Sammel-Löschungen ein Backup anlegen.
- Aus WordPress importierte Beiträge (Plugin `cms-importer`) können fehlerhafte Slugs enthalten; *Einstellungen → Inhalte → Importierte Slugs reparieren* korrigiert sie.

### Verwandte Dokumente

[PAGES.md](PAGES.md) · [COMMENTS.md](COMMENTS.md) · [SETTINGS.md](SETTINGS.md) · [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md) · [../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md](../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md)
