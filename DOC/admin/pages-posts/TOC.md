# 365CMS – Projektdokumentation | Abschnitt: Admin – Inhaltsverzeichnis (TOC)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/table-of-contents` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_toc`

## English (summary)

The automatic table of contents is configured at `/admin/table-of-contents` (`CMS/admin/table-of-contents.php` → `CMS/admin/modules/toc/TocModule.php` → `CMS/admin/views/toc/settings.php`). Settings are stored as JSON in `cms_settings` under `option_name = 'toc_settings'` and read at runtime by `CMS\TableOfContents` (`CMS/core/TableOfContents.php`), which scans headings, injects anchors and renders the TOC block.

## Deutsch

### Überblick

Das Inhaltsverzeichnis wird automatisch aus den Überschriften eines Beitrags oder einer Seite erzeugt. Die Admin-Seite steuert, **wo**, **ab wann** und **wie** es erscheint.

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/table-of-contents.php` |
| Modul | `CMS/admin/modules/toc/TocModule.php` |
| View | `CMS/admin/views/toc/settings.php` |
| Laufzeit | `CMS/core/TableOfContents.php` (`process()`, `renderFromContent()`, `buildPageTitleToc()`) |
| Speicherort | `cms_settings.option_name = 'toc_settings'` (JSON) |

### Einstellungen und Standardwerte

| Schlüssel | Standard | Erlaubte Werte / Bedeutung |
|---|---|---|
| `support_types` | `post`, `page` | Inhaltstypen, für die ein TOC möglich ist |
| `auto_insert_types` | `post` | Typen, in die das TOC automatisch eingefügt wird |
| `position` | `before` | `before` (vor erster Überschrift), `after`, `top`, `bottom` |
| `show_limit` | `4` | Mindestanzahl Überschriften, ab der das TOC erscheint |
| `show_header_label` / `header_label` | `true` / „Inhaltsverzeichnis“ | Titelzeile |
| `allow_toggle` | `true` | Auf-/Zuklappen erlauben |
| `sticky_toggle` | `false` | Toggle bleibt beim Scrollen sichtbar |
| `show_hierarchy` | `true` | Verschachtelte Darstellung (H3 unter H2 …) |
| `show_counter` | `false` | Nummerierung |
| `smooth_scroll` / `smooth_scroll_offset` / `mobile_scroll_offset` | `true` / `30` / `0` | Weiches Scrollen, Offset in px (z. B. für fixierte Header) |
| `width` | `auto` | `auto`, `100%`, `75%`, `50%` |
| `alignment` | `none` | `none`, `left`, `center`, `right` |
| `theme` | `grey` | `grey`, `light`, `dark`, `transparent`, `light-blue`, `white`, `black`, `custom` |
| `custom_bg_color`, `custom_border_color`, `custom_title_color`, `custom_link_color` | `#f9f9f9`, `#aaaaaa`, `#333333`, `#0073aa` | Nur bei `theme = custom`; ungültige Hex-Werte fallen auf den Standard zurück |
| `headings` | `h2`, `h3`, `h4` | Berücksichtigte Ebenen (`h2`–`h6`) |
| `exclude_headings` | leer | Auszuschließende Überschriften, getrennt durch `\|`, Komma oder Zeilenumbruch |
| `limit_path` | leer | TOC nur unter diesem URL-Pfad |
| `lowercase` / `hyphenate` | `true` / `true` | Anker kleinschreiben, Leerzeichen als Bindestrich |
| `anchor_prefix` | leer | Präfix für generierte Anker-IDs |
| `homepage_toc` | `false` | TOC auch auf der Startseite |
| `exclude_css` | `false` | Eigene TOC-Styles nicht laden (Theme liefert Styles) |
| `remove_toc_links` | `false` | Einträge ohne Links (nur Gliederung) |

Alle Werte werden in `TocModule::normalizeSettings()` gegen Allowlists geprüft; unbekannte Werte fallen auf die Standardwerte zurück.

### Zusammenspiel mit Seiten

Im Seiteneditor kann mit „Titel im Inhaltsverzeichnis“ (`show_title_toc`) der Seitentitel als erster Eintrag aufgenommen werden (`TableOfContents::buildPageTitleToc()`).

### Verwandte Dokumente

[PAGES.md](PAGES.md) · [POSTS.md](POSTS.md) · [../../theme/COMPONENTS.md](../../theme/COMPONENTS.md)
