# 365CMS – Projektdokumentation | Abschnitt: Admin – Beiträge, Kategorien und Tags

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Routen:** `/admin/posts`, `/admin/post-categories`, `/admin/post-tags` | **CSRF-Aktion:** `admin_posts`

## English (summary)

Blog posts are managed at `/admin/posts` (`CMS/admin/posts.php` → `CMS/admin/modules/posts/PostsModule.php`, views under `CMS/admin/views/posts/`). Categories (`/admin/post-categories`) and tags (`/admin/post-tags`) reuse the same module.

- Access: `edit_all_posts` (all posts), `edit_own_posts` (own posts only), or `posts.view` (read). Categories and tags require `edit_all_posts`.
- List filters: `status=published|scheduled|draft|private`, `category`, `q`.
- POST actions: `save`, `delete`, `bulk`, `save_category`, `delete_category`, `switch_locale`, `copy_de_to_en`.
- Bulk actions: `delete`, `publish`, `draft`, `set_category`, `clear_category`, `set_author_display_name`, `clear_author_display_name`.
- Scheduling: a published post with `published_at` in the future is listed as *scheduled* and stays hidden from the frontend, archives, search and sitemap until that time (`cms_post_publication_where()`).
- Post templates and their meta fields come from `post_templates` in the active theme's `theme.json`.
- Revisions are stored in `cms_post_revisions`.

## Deutsch

### Überblick

| Bestandteil | Datei |
|---|---|
| Beiträge | `CMS/admin/posts.php`, `CMS/admin/views/posts/list.php`, `edit.php` |
| Kategorien | `CMS/admin/post-categories.php`, `CMS/admin/views/posts/categories.php` |
| Tags | `CMS/admin/post-tags.php`, `CMS/admin/views/posts/tags.php` |
| Fachlogik | `CMS/admin/modules/posts/PostsModule.php`, `PostsCategoryViewModelBuilder.php` |
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

Sprachumschaltung (`switch_locale`), DE→EN-Kopie (`copy_de_to_en`), Revisionen (`cms_post_revisions`) und KI-Helfer funktionieren wie bei [Seiten](PAGES.md).

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

`cms_posts` (inkl. `title_en`, `slug_en`, `content_en`, `excerpt_en`, `featured_image`, `meta_title`, `meta_description`, `author_display_name`, `author_display_url`, `post_template`, `post_meta_json`, `published_at`, `content_updated_at`), `cms_post_categories`, `cms_post_tags`, `cms_post_tag_rel`, `cms_post_category_rel`, `cms_post_revisions`. Fehlende Spalten werden vom Modul bei Bedarf per `ALTER TABLE` ergänzt (Abwärtskompatibilität mit älteren Installationen).

### Hinweise

- Beiträge mit Veröffentlichungsdatum in der Zukunft erscheinen weder im Frontend noch in Archiven, Suche oder Sitemap, bis der Zeitpunkt erreicht ist. Zentral dafür ist der SQL-Helfer `cms_post_publication_where()` (`CMS/includes/functions/options-runtime.php`).
- Gelöschte Beiträge sind nicht wiederherstellbar (kein Papierkorb). Vor Sammel-Löschungen ein Backup anlegen.
- Aus WordPress importierte Beiträge (Plugin `cms-importer`) können fehlerhafte Slugs enthalten; *Einstellungen → Inhalte → Importierte Slugs reparieren* korrigiert sie.

### Verwandte Dokumente

[PAGES.md](PAGES.md) · [COMMENTS.md](COMMENTS.md) · [SETTINGS.md](SETTINGS.md) · [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md) · [../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md](../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md)
