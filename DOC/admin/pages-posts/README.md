# 365CMS – Projektdokumentation | Abschnitt: Admin – Seiten & Beiträge

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **Pages & Posts** (*Seiten & Beiträge*). All screens are rendered through the shared admin section shell and follow the same contract: capability check → CSRF token per page → normalized input → module method → flash message → Post/Redirect/Get.

## Deutsch

### Menüpunkte und Dokumente

| Menüpunkt | Route | Capability | Dokument |
|---|---|---|---|
| Seiten | `/admin/pages` | `manage_pages` | [PAGES.md](PAGES.md) |
| Beiträge | `/admin/posts` | `edit_all_posts` / `edit_own_posts` / `posts.view` | [POSTS.md](POSTS.md) |
| Kategorien | `/admin/post-categories` | `edit_all_posts` | [POSTS.md](POSTS.md#kategorien-adminpost-categories) |
| Tags | `/admin/post-tags` | `edit_all_posts` | [POSTS.md](POSTS.md#tags-adminpost-tags) |
| Kommentare | `/admin/comments` | `comments.view` / `.moderate` / `.delete` | [COMMENTS.md](COMMENTS.md) |
| Inhaltsverzeichnis | `/admin/table-of-contents` | `manage_settings` | [TOC.md](TOC.md) |
| Hub-Sites | `/admin/hub-sites` | `manage_settings` | [HUBSITES.md](HUBSITES.md) |
| Tabellen | `/admin/site-tables` | `manage_settings` | [TABLES.md](TABLES.md) |
| Einstellungen | `/admin/settings?tab=content` | `manage_settings` | [SETTINGS.md](SETTINGS.md) |

### Gemeinsames Verhalten

- **Request-Shell:** `CMS/admin/partials/section-page-shell.php` lädt Modul und View, prüft das CSRF-Token (`csrf_action` je Seite), speichert Meldungen als Flash in der Session und leitet nach erfolgreichem POST weiter (PRG). Bei Validierungsfehlern wird der Editor inline mit den Eingaben erneut gerendert.
- **Mehrsprachigkeit:** Seiten, Beiträge, Kategorien und Tags führen deutsche und englische Felder (`*_en`). Der Editor wechselt über `?lang=en` bzw. den DE/EN-Schalter.
- **Editor:** Editor.js (lokal unter `CMS/assets/editorjs/`), alternativ SunEditor (siehe [SETTINGS.md](SETTINGS.md)).
- **Shortcodes im Inhalt:** `[site-table id="…"]`, `[table id="…"]`, `[hub-site id="…"]`.
- **Audit:** Speichern, Löschen und Sammelaktionen werden über `AuditLogger` bzw. den Logger-Kanal des Moduls protokolliert.

### Verwandte Bereiche

- SEO-Felder im Editor: [../seo/SEO.md](../seo/SEO.md)
- Medien und Beitragsbilder: [../media/MEDIA.md](../media/MEDIA.md)
- KI-Übersetzung und SEO-Metadaten: [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md)
- Ablauf von Entwurf bis Veröffentlichung: [../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md](../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md)
