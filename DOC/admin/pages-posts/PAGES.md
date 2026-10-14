# 365CMS – Projektdokumentation | Abschnitt: Admin – Seiten

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/pages` | **Capability:** `manage_pages` | **CSRF-Aktion:** `admin_pages`

## English (summary)

Static pages are managed at `/admin/pages` (entry `CMS/admin/pages.php`, logic in `CMS/admin/modules/pages/PagesModule.php`, views `CMS/admin/views/pages/list.php` and `edit.php`). The entry is rendered through the shared section shell (`CMS/admin/partials/section-page-shell.php`) which handles CSRF, flash messages and Post/Redirect/Get.

- Views: `list` (default) and `edit` (`?action=edit&id=<id>`, optional `&lang=en`).
- POST actions: `save`, `delete`, `bulk`, `switch_locale`, `copy_de_to_en`.
- Bulk actions: `delete`, `publish`, `draft`, `set_category`, `clear_category` (max. 200 IDs per request).
- Bilingual content: `title`/`title_en`, `slug`/`slug_en`, `content`/`content_en` (Editor.js JSON).
- Revisions are stored in `cms_page_revisions` and displayed as a field diff in the editor.
- Optional AI helpers (translation, SEO metadata) appear only when the `ai_services` module and the matching features are enabled.

## Deutsch

### Überblick

Die Seitenverwaltung pflegt statische Inhalte wie „Über uns“, Leistungsseiten oder Landing-Unterseiten. Seiten sind – anders als Beiträge – nicht datiert, haben keine Tags und erscheinen nicht in Archiven. Sie können aber einer Kategorie zugeordnet werden (dieselbe Tabelle `cms_post_categories` wie bei Beiträgen).

| Bestandteil | Datei |
|---|---|
| Einstieg / Controller | `CMS/admin/pages.php` |
| Fachlogik | `CMS/admin/modules/pages/PagesModule.php` |
| Listenansicht | `CMS/admin/views/pages/list.php` |
| Editor | `CMS/admin/views/pages/edit.php` |
| Gemeinsame Editor-Partials | `CMS/admin/views/partials/content-*.php`, `featured-image-picker.php` |
| Request-Shell | `CMS/admin/partials/section-page-shell.php` |
| Frontend-Ausgabe | `CMS/core/PageManager.php`, `CMS/core/Routing/PublicRouter.php` |

### Aufruf und Berechtigung

- Zugriff nur für Administratoren mit Capability `manage_pages` (`cms_admin_pages_can_access()`); andernfalls Umleitung auf `/`.
- Der Link „KI-Einstellungen“ im Editor erscheint nur mit `manage_settings` und wenn die Adminseite `ai-settings` im Modul-Manager aktiviert ist.

### Listenansicht (`/admin/pages`)

- **Filter:** `?status=published|draft|private`, `?category=<id>`, Suche über `?q=`. Unbekannte Werte werden verworfen.
- **Sammelaktionen:** Auswahl per Checkbox, dann `bulk_action`:
  - `publish` / `draft` – Status setzen
  - `set_category` / `clear_category` – Kategorie zuweisen bzw. entfernen
  - `delete` – endgültig löschen
- Es werden maximal **200 IDs** pro Anfrage verarbeitet; nicht existierende IDs werden übersprungen.

### Editor (`/admin/pages?action=edit[&id=…][&lang=en]`)

| Feld | Hinweis |
|---|---|
| Titel / Slug (DE, EN) | Slug wird bei leerem Feld aus dem Titel erzeugt; EN-Slug ist optional (`NULL`, wenn leer). |
| Inhalt (DE, EN) | Editor.js-JSON. Der lokale Editor.js-Runtime wird über `EditorJsService::getPageAssets()` geladen; schlägt die Initialisierung fehl, greift ein clientseitiger Fallback. |
| Status | `draft`, `published`, `private`. Standardwert aus *Einstellungen → Inhalte* (`setting_page_default_status`). |
| Titel ausblenden (`hide_title`) | Unterdrückt die H1 im Theme. |
| Titel im Inhaltsverzeichnis (`show_title_toc`) | Nimmt den Seitentitel als ersten TOC-Eintrag auf. |
| Kategorie | Optional, aus `cms_post_categories`. |
| Beitragsbild | Medienauswahl über `featured-image-picker.php`; Referenzen werden normalisiert. |
| Meta-Titel / Meta-Beschreibung | Direkt in der Seite gespeichert; Platzhalter wie `%title%` oder `%%sitename%%` werden seit 3.4.08 bei der Ausgabe aufgelöst. |
| Erweiterte SEO-Felder | Fokus-Keyphrase, Keywords, Canonical, Robots (index/follow), Open Graph, Twitter Card, Schema-Typ (Standard `WebPage`), Sitemap-Priorität/-Frequenz, Hreflang-Gruppe – nur sichtbar, wenn das Modul `seo` aktiv ist (`admin-seo-editor.js`). |
| „Inhalt aktualisiert am“ | Optionales Datum/Uhrzeit (`content_updated_at`) für die sichtbare Aktualisierungsangabe. |
| Seitenvorlage (`page_template`) | Auswahl aus `page_templates` der `theme.json` des aktiven Themes (Fallback „Standard“). Zusatzfelder der gewählten Vorlage werden vor dem Speichern validiert und als JSON in `page_meta_json` abgelegt; inaktive Vorlagen-Panels sind deaktiviert. Formulare ohne Vorlagenfeld (ältere Plugins) lassen Vorlage und Zusatzfelder unverändert. Änderungen erzeugen eine Revision und erscheinen im Revisionsvergleich. |

**Seitenvorlagen:** `PageTemplateService` akzeptiert nur registrierte Vorlagen-IDs und vorhandene PHP-Dateien im Theme-Hauptverzeichnis. Zusatzfelder – einschließlich `array-of-objects` mit höchstens 20 Einträgen – werden vor Datenbankänderungen validiert; ungültige JSON-Werte, nicht erlaubte URLs und ungültige Werte führen zu einem sichtbaren Fehler. Inaktive Panels und Controls sind deaktiviert, damit gleichnamige Felder keine aktiven Werte überschreiben. Nicht gespeicherte Werte bleiben bei Sprachwechsel und Fehleranzeige erhalten; ältere Requests ohne `page_template` bewahren die vorhandene Auswahl und Metadaten.

**Sprachumschaltung:** Der Button „DE/EN“ sendet `switch_locale:de|en`. Die Seite wird *ohne Speichern* mit den aktuellen Formularwerten in der anderen Sprache neu gerendert, damit keine Eingaben verloren gehen.

**DE → EN kopieren** (`copy_de_to_en`): Übernimmt Titel und Inhalt der deutschen Fassung in die englischen Felder. Editor.js-Block-IDs werden dabei entfernt, damit es keine doppelten IDs gibt. Die Seite muss vorher gespeichert sein.

**Revisionen:** Bei jedem Speichern entsteht ein Eintrag in `cms_page_revisions`. Der Editor zeigt die letzten Revisionen mit Autor, Zeitpunkt, geänderten Feldern und einer Gegenüberstellung „Aktuell / Revision“ (Text-Diff bzw. Block-Zusammenfassung für Editor.js-Inhalte).

**KI-Helfer (optional):**
- Übersetzung (`/admin/ai-translate-editorjs`, Token `admin_ai_editorjs_translation`) – nur, wenn das Core-Modul `ai_services` aktiv ist.
- SEO-Metadaten (`/admin/ai-generate-seo-metadata`, Token `admin_ai_seo_metadata`) – nur, wenn `ai_services_enabled`, `ai_seo_meta_enabled` und `ai_editorjs_enabled` aktiv sind und der aktive Provider für die Editor-Sprache freigegeben ist. Andernfalls zeigt der Editor einen Hinweis (z. B. „Die Sprache EN ist für den aktiven AI-Provider nicht freigegeben“).

### POST-Aktionen

| `action` | Wirkung | Weiterleitung |
|---|---|---|
| `save` | Validiert und speichert, legt Revision an | `/admin/pages?action=edit&id=<id>[&lang=en]` |
| `switch_locale:<de\|en>` | Rendert den Editor inline in der Zielsprache | keine (Inline-Render) |
| `copy_de_to_en` | Kopiert DE-Inhalte nach EN | Editor in EN |
| `delete` | Löscht die Seite | `/admin/pages` |
| `bulk` | Sammelaktion (siehe oben) | `/admin/pages` |

Alle Formulare tragen das CSRF-Token der Aktion `admin_pages`. Bei Validierungsfehlern bleibt der Editor mit den eingegebenen Werten geöffnet und zeigt die Fehlerdetails an (`render_inline`). Nicht-skalare Eingaben werden mit Ausnahme des explizit validierten `page_meta`-Arrays zurückgewiesen.

### Datenmodell

- Tabelle `cms_pages` (Präfix aus `config/app.php`): `id`, `title`, `title_en`, `slug`, `slug_en`, `content`, `content_en`, `status`, `hide_title`, `show_title_toc`, `category_id`, `featured_image`, `meta_title`, `meta_description`, `content_updated_at`, `author_id`, Zeitstempel; lokale Erweiterung: `page_template` (VARCHAR 80), `page_meta_json` (TEXT).
- Revisionen: `cms_page_revisions`.
- SEO-Zusatzfelder: über `SEOService`/`SeoAnalysisService` (Meta-Speicher des SEO-Moduls).
- Details: [../../core/DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md).

### Frontend

Veröffentlichte Seiten werden über `PublicRouter` unter `/<slug>` bzw. `/en/<slug_en>` ausgeliefert. `PageManager::search()` liefert für leere Suchbegriffe seit 3.4.06 keine Treffer mehr. Shortcodes wie `[site-table id="…"]` und `[hub-site id="…"]` im Inhalt werden von `SiteTableService` ersetzt.

Bei `ThemeManager::render('page', …)` löst der neue `PageTemplateService` die gespeicherte ID auf und stellt validierte Zusatzfelder unter `page['meta']` bereit. Unbekannte/fehlende Vorlagen, etwa nach einem Theme-Wechsel, fallen mit Protokollierung auf das Standardlayout zurück. Explizite Plugin-Template-Overrides bleiben bestehen. Schema und Revisions-Snapshots enthalten die neuen Felder; Bestandsinstallationen erhalten idempotente Kompatibilitätsmigrationen. Migration und Rendering wurden bislang nur isoliert mit Datenbank-Doubles geprüft, nicht gegen eine laufende Installation.

### Fehlerbilder

| Meldung | Ursache |
|---|---|
| „Die angeforderte Seite existiert nicht mehr.“ | ID wurde gelöscht; Liste neu laden. |
| „Bitte die Seite zuerst speichern, bevor Inhalte nach EN kopiert werden.“ | `copy_de_to_en` auf neuer Seite. |
| „Unbekannte Bulk-Aktion für Seiten.“ | Manipulierter oder veralteter Request. |

### Verwandte Dokumente

[POSTS.md](POSTS.md) · [SETTINGS.md](SETTINGS.md) · [TOC.md](TOC.md) · [TABLES.md](TABLES.md) · [../seo/SEO.md](../seo/SEO.md) · [../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md](../../workflow/CONTENT-MANAGEMENT-WORKFLOW.md)
