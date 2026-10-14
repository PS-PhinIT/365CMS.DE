# 365CMS – Projektdokumentation | Abschnitt: Workflow – Content Management
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Quellen:** `CMS/admin/pages.php`, `CMS/admin/posts.php`, `CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/views/pages/`, `CMS/admin/views/posts/`, `CMS/core/PageManager.php`, `CMS/core/Services/EditorJsService.php`, `CMS/includes/functions/options-runtime.php`

## English (summary)

Pages (`/admin/pages`, capability `manage_pages`) and posts (`/admin/posts`, `edit_all_posts` or `edit_own_posts`) share one editorial flow: draft in Editor.js (German and optional English fields), set SEO data, choose a featured image, save (each save creates a revision), then publish. A post with status `published` and a future `published_at` is *scheduled* and stays invisible in frontend, archives, search and sitemap until the date is reached (`cms_post_publication_where()`). Bulk actions process at most 200 IDs per request; deletions are permanent (no trash). Optional AI helpers translate DE→EN and generate SEO metadata, but never publish automatically.

## Deutsch

### Rollen und Rechte

| Aufgabe | Capability |
|---|---|
| Seiten anlegen/bearbeiten/löschen | `manage_pages` |
| Alle Beiträge, Kategorien, Tags | `edit_all_posts` |
| Nur eigene Beiträge | `edit_own_posts` |
| Kommentare ansehen/moderieren/löschen | `comments.view`, `comments.moderate`, `comments.delete` (siehe [admin/pages-posts/COMMENTS.md](../admin/pages-posts/COMMENTS.md)) |

Der Adminbereich setzt zusätzlich die Rolle `admin` voraus (`Auth::isAdmin()`); Redakteure ohne Admin-Rolle haben keinen Zugriff auf `/admin`.

### Ablauf: neuer Beitrag

1. **Anlegen:** `/admin/posts?action=edit` öffnen. Titel eingeben – der Slug wird beim Speichern aus dem Titel erzeugt, wenn das Feld leer bleibt.
2. **Inhalt:** Editor.js-Blöcke (Absatz, Überschrift, Liste, Bild, Tabelle, Code, Zitat …). Bilder über die Medienauswahl oder Upload (`/api/media`, Token `editorjs_media`).
3. **Einordnung:** Hauptkategorie + weitere Kategorien, Tags (kommagetrennt), Auszug (Teaser für Archive, Feeds, Suche).
4. **Beitragsbild** über den Featured-Image-Picker; Referenzen werden normalisiert.
5. **SEO:** Meta-Titel/-Beschreibung (Platzhalter wie `%title%`, `%%sitename%%` werden seit 3.5.0 bei der Ausgabe aufgelöst); erweiterte Felder (Canonical, Robots, OG, Schema-Typ, Sitemap) nur bei aktivem Modul `seo`.
6. **Englische Fassung (optional):** Sprachumschalter DE/EN (`switch_locale`, rendert ohne Speichern neu) oder „DE → EN kopieren“ (`copy_de_to_en`, nach dem ersten Speichern). KI-Übersetzung über `/admin/ai-translate-editorjs`, wenn das Modul `ai_services` aktiv ist.
7. **Speichern als Entwurf** (`status = draft`) – jede Speicherung legt einen Eintrag in `cms_post_revisions` an.
8. **Vorschau/Prüfung:** Revisionsvergleich im Editor, Medien-Check (`/admin/media?tab=check`) für fehlende Beitragsbilder.
9. **Veröffentlichen:** Status `published`. Für eine geplante Veröffentlichung `published_at` in die Zukunft setzen – die Liste zeigt den Beitrag dann unter „Geplant“.
10. **Pflege:** „Inhalt aktualisiert am“ (`content_updated_at`) setzen, wenn sich Inhalte wesentlich ändern.

### Ablauf: neue Seite

Wie oben, jedoch ohne Datum, Tags und Auszug. Zusätzliche Felder: „Titel ausblenden“ (`hide_title`), „Titel im Inhaltsverzeichnis“ (`show_title_toc`). Revisionen landen in `cms_page_revisions`. Der Standardstatus neuer Seiten kommt aus *Einstellungen → Inhalte*.

### Sammelaktionen

| Bereich | Aktionen |
|---|---|
| Seiten | `publish`, `draft`, `set_category`, `clear_category`, `delete` |
| Beiträge | `publish`, `draft`, `set_category`, `clear_category`, `set_author_display_name`, `clear_author_display_name`, `delete` |

Pro Anfrage höchstens 200 IDs; unbekannte IDs werden übersprungen. Mit `edit_own_posts` wirken Sammelaktionen nur auf eigene Beiträge.

### URLs und Sichtbarkeit

- Beitrags-Permalinks nach *Einstellungen → Inhalte* (`blog` = `/blog/%postname%` (Standard), `slug`, `year`, `dated`, `custom`).
- Kategorie-/Tag-Archive: Standard `/kategorie/<slug>` und `/tag/<slug>`, englisch `/en/category/<slug>`.
- Englische Inhalte unter `/en/…`, sofern `slug_en`/`content_en` gepflegt sind.
- Sichtbar ist ein Beitrag nur, wenn `status = 'published'` und `published_at` leer oder ≤ jetzt ist. Private Inhalte (`private`) erscheinen nicht öffentlich.

### Löschen und Wiederherstellen

Es gibt keinen Papierkorb: `delete` entfernt Datensätze endgültig. Vor Sammel-Löschungen ein Backup anlegen ([admin/system-settings/BACKUP.md](../admin/system-settings/BACKUP.md)). Inhalte älterer Fassungen lassen sich über die Revisionsansicht manuell zurückkopieren.

### Typische Fehler

| Symptom | Ursache / Lösung |
|---|---|
| Beitrag erscheint nicht | Zukunftsdatum in `published_at` oder Status `draft`/`private` |
| 404 nach Slug-Änderung | Weiterleitung unter `/admin/redirect-manager` anlegen |
| EN-Seite leer | `slug_en`/`content_en` nicht gepflegt |
| Editor lädt nicht | CSP-/Asset-Fehler in der Browser-Konsole prüfen; der Editor fällt auf einen Fallback zurück |
| Importierte Slugs fehlerhaft | *Einstellungen → Inhalte → Importierte Slugs reparieren* |

## Verwandte Dokumente

- [admin/pages-posts/PAGES.md](../admin/pages-posts/PAGES.md) · [admin/pages-posts/POSTS.md](../admin/pages-posts/POSTS.md) · [admin/pages-posts/SETTINGS.md](../admin/pages-posts/SETTINGS.md)
- [admin/seo/SEO.md](../admin/seo/SEO.md) · [ai/AI-SERVICES.md](../ai/AI-SERVICES.md)
- [MEDIA-UPLOAD-WORKFLOW.md](MEDIA-UPLOAD-WORKFLOW.md)
