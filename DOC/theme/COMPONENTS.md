# 365CMS – Projektdokumentation | Abschnitt: Theme – Komponenten & Helfer

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Referenz-Theme:** `cms-default` 1.0.9

## English (summary)

Building blocks of the reference theme `CMS/themes/cms-default/`: page templates, partials in `partials/`, PHP helpers in `includes/` and `functions.php` (prefix `meridian_`), plus core-provided components that any theme can reuse (rendered content with TOC and shortcodes, menus, site tables/hub sites, SEO head output, consent banner, search). Only document or rely on components that exist in the runtime theme.

## Deutsch

### Templates von `cms-default`

| Datei | Zweck |
|---|---|
| `header.php` / `footer.php` | Rahmen: Logo, Navigation (Positionen `primary`, `secondary`, `mobile`), Suche, Anmelde-/Konto-Links, Logout (POST mit Token), Footer-Spalten (`footer_topics`, `footer_resources`, `footer_about`, `footer_legal`) |
| `home.php` | Startseite: Modus `posts` (Blog-Startseite, `partials/home-blog.php`) oder `landing` (`partials/home-landing.php`) |
| `page.php` | Seiten und Hub-Sites |
| `blog.php`, `blog-single.php` | Beitragsliste und Einzelbeitrag (Autorbox, Tags, verwandte Beiträge, Lesezeit, Aktualisierungs-Badge, Kommentare) |
| `category.php`, `tag.php`, `archive.php`, `author.php` | Archive und Übersichten (`partials/archive-overview.php`) |
| `search.php` | Suche mit Typ- und Sortierauswahl |
| `contact.php` | Kontaktformular |
| `login.php`, `register.php`, `forgot-password.php` | Theme-Auth-Seiten (nur Modus `legacy`) |
| `404.php`, `error.php` | Fehlerseiten (`error.php` mit eigenem Rahmen) |
| `index.php` | Fallback |

### Partials

| Partial | Inhalt |
|---|---|
| `partials/home-blog.php` | Blog-Startseite (Hero, neueste Beiträge, Seitenleiste) |
| `partials/home-landing.php` | Landingpage aus `LandingPageService` |
| `partials/blog-list-cards.php` / `blog-grid-cards.php` | Beitragskarten als Liste bzw. Raster |
| `partials/archive-overview.php` | Übersicht aller Kategorien/Tags mit Anzahl und Paginierung |
| `partials/sidebar.php` | Seitenleiste (neueste Beiträge, Kategorien, Tags) |
| `partials/newsletter.php` | Newsletter-Box (Texte aus dem Customizer-Bereich `newsletter`) |

### Helfer (`functions.php`, `includes/`)

| Gruppe | Funktionen (Auszug) |
|---|---|
| Einstellungen | `meridian_setting($bereich, $schluessel, $standard)` – Customizer-Wert lesen |
| Inhalte | `meridian_get_posts()`, `meridian_get_recent_posts()`, `meridian_get_related_posts()`, `meridian_get_categories()`, `meridian_get_tags()`, `meridian_post_tags()`, `meridian_excerpt()`, `meridian_reading_time()`, `meridian_post_update_badge()`, `meridian_format_date()` |
| Bilder | `meridian_get_picture_sources()` (WebP), `meridian_image_loading_attributes()` (lazy/eager), `meridian_image_dimension_attributes()`, `meridian_safe_public_media_url()` |
| Navigation | `meridian_nav_menu()`, `meridian_filter_navigation_items()`, `theme_get_menu()`, `meridian_footer_about_links()` |
| Konto | `meridian_is_logged_in()`, `meridian_member_area_url()`, `meridian_auth_url()`, `meridian_account_path()`, `meridian_get_flash()` |
| Sicherheit/URLs | `meridian_safe_public_url()`, `meridian_is_external_url()`, `meridian_normalize_internal_path()`, `meridian_route_exists()` |
| Ausgabe | `meridian_output_fonts()`, `meridian_output_custom_styles()`, `meridian_copyright()`, `meridian_author_initials()`, `meridian_cat_gradient()` |

Die Theme-Klasse `MeridianCMSDefaultTheme` registriert: Preconnect/Fonts/Styles/Meta/Custom-Styles/Custom-Header-Code im `head` (Prioritäten 1–99), Skripte und Theme-Cookie-Banner in `before_footer`, Menüpositionen über `register_menu_locations` und Standardmenüs in `cms_init`.

### Wiederverwendbare Core-Komponenten

| Komponente | Nutzung im Theme |
|---|---|
| Gerenderter Inhalt | `$page['content']` / Beitragsinhalt – Editor.js-HTML inkl. Inhaltsverzeichnis, Tabellen-/Hub-Shortcodes, Lazy Loading |
| Inhaltsverzeichnis | automatisch nach TOC-Einstellungen ([../admin/pages-posts/TOC.md](../admin/pages-posts/TOC.md)) |
| Menüs | `ThemeManager::instance()->getMenu('<position>')` |
| Tabellen & Hub-Sites | Shortcodes `[site-table id="…"]`, `[hub-site id="…"]` |
| SEO-Head | über `head` (Meta, Open Graph, Twitter, Canonical, Hreflang, JSON-LD) |
| Kommentare | Formular an `POST /comments/post` mit Token `comment_<postId>`; freigegebene Kommentare über `CommentService::getApprovedForPost()` |
| Favoriten | POST mit `phinit_toggle_favorite`, `favorite_content_type`, `favorite_content_id`, Token `phinit_favorite_<typ>_<id>` |
| Logout | `<form method="post" action="/logout">` mit `csrf_token` aus `Security::generateToken('logout')` |
| Consent | `CookieConsentService` – eigener Banner nur, wenn nicht extern verwaltet |
| PDF-Export | `?pdf=1` an einer Seiten- oder Beitrags-URL liefert die Seite als PDF (`Router::streamContentAsPdf()`, Dompdf) – im Theme als „PDF“-Link anbieten |

### Regeln

- Nur Komponenten verwenden bzw. dokumentieren, deren Template, Stylesheet oder Skript im Runtime-Theme existiert.
- Dynamische Ausgaben escapen; Partials über `require __DIR__ . '/partials/…'` einbinden.
- Barrierefreiheit: Überschriftenhierarchie, `alt`-Texte, Fokuszustände, Tastaturbedienung der Navigation.

### Verwandte Dokumente

[THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) · [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) · [JAVASCRIPT.md](JAVASCRIPT.md)
