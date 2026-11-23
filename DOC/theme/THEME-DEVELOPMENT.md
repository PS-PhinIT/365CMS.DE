# 365CMS Projektdokumentation | Theme-Entwicklung (Referenz)

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Referenz-Theme:** `cms-default` 1.1.0 | **Status:** Stable

## English (summary)

A theme is a directory `CMS/themes/<slug>/` with at least `style.css` (header comment) and `index.php` or `functions.php`; `theme.json` declares templates, partials, menu locations and customizer sections, `update.json` the version metadata. `ThemeManager::render($template, $data)` includes `header.php`, `<template>.php` (fallback `index.php`) and `footer.php`; templates receive their data as local variables. Themes must be CSP-compatible (nonce on every inline `<script>`/`<style>`, no inline handlers), fire `head` and `body_end`, escape all output and respect the privacy settings (local fonts, external consent banner).

Content images may be wrapped in `<picture>` (AVIF/WebP `<source>` before the original `<img>`; `display: contents`, so child selectors like `figure > img` no longer match, use `figure img`). When registration captcha is enabled, legacy and modern auth templates must render `cms_captcha_field('register', 'pow')` and `cms_captcha_asset_tags()`. Posts and pages have the extra status `pending` (never public, treat as "not public" in own status lists); plugin admin pages use the workspace partial `admin/partials/plugin-workspace.php` (`CMS_PLUGIN_WORKSPACE_VERSION`), see [section 9](#9-änderungen-für-themes-in-420).

## Deutsch

### 1. Mindestaufbau

```text
CMS/themes/mein-theme/
├── style.css          # Pflicht: Header-Kommentar (Theme Name, Version, Author, Description)
├── functions.php      # Theme-Bootstrap (Hooks, Helfer, Assets) – oder index.php
├── theme.json         # Metadaten, Templates, Partials, Menüpositionen, Customizer-Bereiche
├── update.json        # Version, min_cms_version, Changelog (für Marketplace/Updates)
├── header.php  footer.php
├── index.php          # Fallback für alle Templates
├── home.php  page.php  blog.php  blog-single.php  category.php  tag.php
├── author.php  archive.php  search.php  contact.php  404.php  error.php
├── login.php  register.php  forgot-password.php   # nur für Auth-Modus „legacy“
├── partials/          # wiederverwendbare Bausteine
├── includes/          # PHP-Helfer
├── js/  …             # Theme-Skripte (extern, CSP)
├── admin/customizer.php (+ admin/customizer/)     # optional: eigener Customizer
└── member/<seite>.php                              # optional: Overrides des Mitgliederbereichs
```

Health-Check beim Aktivieren (`ThemeManager::healthCheckTheme()`): `style.css` vorhanden, `index.php` oder `functions.php` vorhanden, alle PHP-Dateien syntaktisch gültig.

### 2. `theme.json`

```json
{
  "name": "Mein Theme",
  "slug": "mein-theme",
  "version": "1.0.0",
  "author": "Firma",
  "description": "…",
  "tags": ["blog", "responsive"],
  "supports": ["custom-logo", "sticky-header", "custom-css", "categories"],
  "menus": { "primary": "Hauptmenü", "footer": "Footer" },
  "templates": { "home": "home.php", "page": "page.php", "blog-single": "blog-single.php", "404": "404.php" },
  "partials": { "header": "header.php", "footer": "footer.php", "sidebar": "partials/sidebar.php" },
  "customization": { "colors": { … } },
  "post_templates": [
    { "id": "default", "label": "Standard", "description": "", "file": "blog-single.php", "meta_fields": [] }
  ],
  "page_templates": [
    { "id": "default", "label": "Standard", "file": "page.php" },
    { "id": "landing", "label": "Landing Page", "file": "page-landing.php",
      "meta_fields": {
        "hero_subtitle": { "label": "Hero-Untertitel", "type": "textarea" },
        "hero_cta_url": { "label": "CTA-URL", "type": "url" },
        "features": { "label": "Feature-Cards", "type": "array-of-objects", "fields": ["icon", "title", "text", "url"] }
      } }
  ]
}
```

- `menus` registriert Menüpositionen (zusätzlich Filter `register_menu_locations`).
- `post_templates` (optional) bietet im Beitragseditor eine Template-Auswahl mit eigenen Metafeldern an; ohne Angabe gibt es nur „Standard“. `cms-default` definiert keine Beitrags-Templates.
- `page_templates` (optional, ab 3.4.20) bietet im Seiteneditor die Auswahl „Seitenvorlage“ mit Zusatzfeldern an (`CMS\Services\PageTemplateService`). Registriert werden nur IDs `[a-z0-9_-]`, deren `file` als PHP-Datei im Theme-Hauptverzeichnis existiert. Feldtypen: `text`, `textarea`, `url` (nur `/pfad`, `#anker`, `http(s)://`, `mailto:`, `tel:`), `array-of-objects` (JSON-Liste, höchstens 20 Einträge, nur die in `fields` genannten Schlüssel; leere Einträge entfallen). HTML wird zu Klartext. Gespeichert wird in `pages.page_template` und `pages.page_meta_json`; `ThemeManager::render('page', …)` lädt dann die Vorlagendatei statt `page.php` und stellt die Felder als `$page['meta']` bereit. Unbekannte gespeicherte Vorlagen und beschädigtes JSON fallen protokolliert auf `page.php` bzw. `[]` zurück.

### 3. Rendering-Vertrag

- **Rahmen:** `ThemeManager::render()` bindet `header.php`, das Template und `footer.php` ein und zählt den Seitenaufruf (`TrackingService`). Templates rufen den Rahmen nicht selbst auf; nur `error.php` im eigenständigen Modus (Fatal-Handler, Wartungsmodus) gibt ein vollständiges Dokument aus.
- **Fehlerseiten (ab 3.4.16):** `Router::renderError(int $status, string $title = '', string $message = '')` rendert für jeden Status außer 404 das Theme-Template `<status>.php` (z. B. `403.php`), sonst `error.php`; fehlt beides, die eingebaute Core-Seite. 404 läuft weiter über `render404()` → `404.php`. Der Router nutzt das für CSRF-Fehler (403), Methoden ohne Route (405 mit `Allow`-Header), Webserver-Fehler per `ErrorDocument` (`REDIRECT_STATUS` 400–599) und den Wartungsmodus (503). Bei 429/503 setzt er `Retry-After`, bei allen Fehlern `Cache-Control: no-store`.
- **Template-Hierarchie:** `<template>.php` → `index.php`. Der Filter `template_name` kann umleiten. Für `authors` und `sitemap` (Routen `/autoren`, `/sitemap`) greift ohne eigenes Template der Fallback.
- **Daten je Template** (als Variablen verfügbar):

| Template | Variablen |
|---|---|
| `home` |: (Startseite liest selbst: Customizer-Modus `posts` oder `landing`) |
| `page` | `$page` (Array, `content` = gerendertes, bereinigtes HTML; auch Hub-Sites; ab 3.4.20 zusätzlich `meta` aus der Seitenvorlage), `$contentLocale`. Mit gewählter Seitenvorlage wird deren Datei (z. B. `page-landing.php`) statt `page.php` gerendert. |
| `blog` | `$posts` (Objekte), `$total`, `$currentPage`, `$totalPages`, `$perPage` (Paginierung `?p=N`) |
| `blog-single` | `$post` (Objekt), `$contentLocale` |
| `category` | `$category` (Array), `$posts`, `$query`, `$total`, `$currentPage`, `$totalPages`, `$perPage`; Übersicht `/kategorie` mit `$overviewItems` |
| `tag` | `$tag` (`name`, `slug`, `slug_de`, `slug_en`), `$posts`, `$query`, … ; Übersicht `/tag` mit `$overviewItems` |
| `author` | `$author`, `$posts`, `$total`, `$currentPage`, `$totalPages`, `$perPage` |
| `search` | `$results` (Arrays mit `_type`, `_type_label`, `slug`, `title`, `meta_description`; Plugin-Treffer zusätzlich `url`, optional `date`), `$query`, `$type`, `$sort` (`relevance`/`date`), `$total`, `$location`, `$filter` |
| `contact`, `404` | – |
| `error` (und `<status>.php`) | `$error_code` (int), `$error_title`, `$error_message` (leer = Theme-Standardtext), `$title` (nur wenn ein Titel übergeben wurde). Eigenständiger Modus zusätzlich `$error_standalone = true` und bei 503 `$error_retry_after` (Sekunden): ohne Header/Footer, ohne Datenbankzugriff, das Template gibt das ganze HTML-Dokument aus. |
| `login`, `register`, `forgot-password` | nur im Auth-Modus `legacy` ([../admin/themes-design/CMS-LOGINPAGE.md](../admin/themes-design/CMS-LOGINPAGE.md)) |

- **Suche:** Treffer sind bereits gefiltert (jedes Suchwort im sichtbaren Text) und sortiert; ohne Suchbegriff keine Treffer. Formulare senden `q`, `type`, `sort`.
- **Links:** Beitrags-URLs mit `PermalinkService::buildPostUrl()`, Archive mit `cms_get_archive_url()`, lokalisierte Pfade mit `ContentLocalizationService::buildLocalizedPath()`.
- **Aktueller Inhalt im Header (ab 3.4.20):** `ThemeManager::render()` setzt vor `header.php` `$GLOBALS['post']` bzw. `$GLOBALS['page']` (bei `404`/`error` werden beide entfernt). Darauf bauen die Core-SEO-Ausgabe (`SEOService::renderCurrentHeadTags()`: Beschreibung, Robots, Canonical, hreflang, Open Graph, Twitter, JSON-LD) und `SEOService::getCurrentSeoPayload()` auf. Themes müssen die Variablen nicht mehr selbst setzen.
- **SEO im Theme:** Entweder `SEOService::renderCurrentHeadTags()` im `head`-Hook ausgeben (so `cms-default`) oder die Werte aus `getCurrentSeoPayload()` selbst rendern. Der Dokumenttitel steht in `payload['title']` (Meta-Titel inkl. Titel-Template).
- Es gibt **keine** Klassen `\CMS\CMS`, `PageService`, `PostService`, `ContentHelper` und keine Methode `ThemeManager::registerMenuLocation()`.

### 4. Hooks im Theme

| Pflicht/Empfehlung | Hook | Wo |
|---|---|---|
| Pflicht | `Hooks::doAction('head')` | im `<head>` von `header.php` (SEO-Meta, Consent, Analytics, Styles) |
| Pflicht | `Hooks::doAction('body_end')` | vor `</body>` in `footer.php` (Consent-Skript, Web Vitals, PhotoSwipe) |
| Empfohlen | `Hooks::applyFilters('page_title', $titel)` | `<title>` |
| Optional | `before_header`, `after_header`, `before_footer`, `after_footer` | löst der Core aus |

`before_footer` und `body_end` laufen je Request nur einmal. Eigene Theme-Aktionen meldet man in `functions.php` an, z. B. `Hooks::addAction('head', [$this, 'enqueueStyles'], 10)`.

### 5. Sicherheit & CSP

- `header.php` gibt `cms_csp_runtime_tags()` als erstes Skript aus (der Core ergänzt es zur Sicherheit als erste `head`-Aktion).
- Jedes Inline-`<style>`/`<script>` trägt `Security::instance()->nonceAttr()`. Inline-Eventhandler (`onclick=`), `javascript:`-URLs, `eval()` und Fremdskripte ohne `Security::allowCspSources()` werden blockiert; `style`-Attribute schreibt der Core in nonce-geschützte Klassen um.
- Alle Ausgaben escapen (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, `esc_html()`, `esc_url()`, `esc_attr()`); `$page['content']` / `$post`-Inhalt ist bereits bereinigtes HTML.
- Formulare (Kontakt, Newsletter, Favoriten) brauchen ein CSRF-Token: öffentliche POSTs prüft der Router global mit der Aktion `form_guard` (`Security::instance()->generateToken('form_guard')`), Logout mit `logout`.
- Keine Zugangsdaten oder Deployment-Daten im Theme-Verzeichnis.

### 6. Datenschutz

- Bei `privacy_use_local_fonts = 1` keine Google-Fonts laden; lokale Schriften kommen aus der Schriftverwaltung ([../admin/themes-design/FONTS.md](../admin/themes-design/FONTS.md)).
- Eigene Cookie-Banner nur ausgeben, wenn `CookieConsentService::getInstance()->isManagedExternally()` **false** ist.
- Externe Einbettungen (YouTube, Karten) nur nach `hasConsentForService()`.

### 7. Customizer, Menüs, Mitgliederbereich

- **Customizer:** `admin/customizer.php` wird unter `/admin/theme-editor` eingebunden ([../admin/themes-design/CUSTOMIZER.md](../admin/themes-design/CUSTOMIZER.md)). Werte über `ThemeCustomizer::instance()->get($bereich, $schluessel, $standard)` lesen, als CSS-Variablen ausgeben.
- **Menüs:** `ThemeManager::instance()->getMenu('primary')` liefert die Einträge (`title`, `url`, `target`, `icon`, `parent_id`).
- **Mitgliederbereich:** `member/<seite>.php` im Theme überschreibt die Core-Seite; `member/dashboard.php` aktiviert zusätzlich die Route `/dashboard`.

### 8. Versionierung & Auslieferung

- `theme.json`, `update.json` (`min_cms_version` muss zum verwendeten Kern passen; `cms-default` nutzt aktuell `4.0.0`), `style.css` und die Versionskonstante in `functions.php` (bei `cms-default`: `MERIDIAN_THEME_VERSION`) tragen dieselbe Version.
- Auslieferung als ZIP mit dem Theme-Ordner auf oberster Ebene; für den Marketplace mit SHA-256-Prüfsumme ([../admin/themes-design/MARKETPLACE.md](../admin/themes-design/MARKETPLACE.md)).
- Statischer Vertragsprüfer: `php TESTS/theme-contract/run.php` (aus der CMS-Wurzel). Weitere Theme-Wurzelverzeichnisse können als Argumente folgen.

### 9. Änderungen für Themes in 4.2.0

- **`<picture>`-Ausgabe.** Editor.js-Bild, Galerie und Medien-Text geben `<picture>` aus, sobald eine AVIF- oder WebP-Fassung existiert (`<source type="image/avif">`, dann `<source type="image/webp">`, danach das ursprüngliche `<img>`). Der Core setzt dafür `display: contents` (`editorjs-content.css`, Selektoren `.editorjs-image picture`, `.editorjs-gallery picture`, `.editorjs-media-text__media picture`), damit das Layout unverändert bleibt. **Eigene Regeln mit Kindselektor `figure > img` greifen nicht mehr**, `<img>` ist jetzt ein Kind von `<picture>`; Nachfahren-Selektoren (`figure img`) wirken weiter. Das gilt auch für bestehende WebP-Fassungen (`auto_webp` ist standardmäßig an). Eigene Vorlagen gehen wie `cms-default` vor: `<source type="image/avif">` nur, wenn die AVIF-Datei existiert (`avif_url` aus `meridian_get_picture_sources()` in `cms-default/functions.php`), dann WebP, dann `<img>` mit `width`/`height`/`alt`; auf `<picture>` selbst keine Layout-Regeln setzen.
- **Status `pending`.** Beiträge und Seiten kennen den Status `pending` („Zur Prüfung“, [Vier-Augen-Prinzip](../admin/pages-posts/REVIEW.md)). Er ist nie öffentlich; das Frontend liefert weiter nur `status = 'published'` aus. Themes, die eigene Statuslisten, Badges oder Abfragen führen, behandeln `pending` als „nicht öffentlich“. Vorschau-Links (`/preview/<token>`) rendern Entwürfe und eingereichte Inhalte mit den normalen Theme-Vorlagen, ein Theme braucht dafür keinen Sonderfall.
- **Abgelaufene Inhalte.** Zurückgestufte Beiträge und Seiten liefern 404 oder (Einstellung) 410 über die normale Fehlerseite des Themes (`error.php`/`404.php`).
- **EditorJS Block Vorlagen.** Der Block „Vorlagen“ wird serverseitig zu normalem Block-HTML aufgelöst; Themes stylen keine eigene Wrapper-Klasse, sondern die enthaltenen Blöcke.
- **Plugin-Adminseiten** nutzen den Layout-Vertrag aus `admin/partials/plugin-workspace.php` (`CMS_PLUGIN_WORKSPACE_VERSION`, Container `.cms-plugin-admin-content`, Kurzadresse `/admin/plugins/<plugin>`); das betrifft das Admin-Layout, nicht das Frontend-Theme. Details in [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md#plugin-layout-vertrag).
- **Captcha bei Registrierung.** Ist unter *Anmeldeseite → Registrierung* `register_captcha` aktiv, muss jede Registrierungsseite innerhalb des `<form>` das Fragment `cms_captcha_field('register', 'pow')` ausgeben. Die Assets werden durch `cms_captcha_asset_tags()` geladen. Das Standard-Theme (`register.php`) und die CMS-Anmeldeseite erfüllen das. Der Browser löst den selbst gehosteten Proof-of-Work; ohne JavaScript/Web Crypto bleibt eine Rechenaufgabe. `PublicRouter` prüft den Kontext `register` vor dem Konto-Anlegen; ohne die Felder kann ein eigenes Theme die Registrierungsanfrage nicht erfolgreich senden.
- **Captcha in eigenen Formularen.** Für beliebige Formulare gilt derselbe Vertrag: zuerst ein stabiler, eindeutiger Kontext, dann im Formular `cms_captcha_field($context, $provider)` und serverseitig `CaptchaService::instance()->verify($_POST, $context)`. Verfahren: `pow`, `image` oder `math`; nicht verfügbare Auswahl fällt auf `math`. Details: [../admin/security/ANTISPAM.md](../admin/security/ANTISPAM.md).
- **Bilder in eigenen Vorlagen.** Der Core entfernt Metadaten erhaltener Originale ohne Reencode. Für eigene GD-Verarbeitung gilt: JPEG-EXIF wird nur aus JPEG gelesen, alle acht Orientierungen vor dem Speichern auf Pixel anwenden (`ImageOrientation::orient()`) und `imagesavealpha(true)` setzen. PNG-`eXIf`/WebP-EXIF wird nicht über diesen GD-Leser ausgewertet.

### Theme-Tests

```bash
php TESTS/theme-contract/run.php
php TESTS/captcha/run.php
php TESTS/media-images/run.php
```

### Verwandte Dokumente

[README.md](README.md) · [DEVELOPMENT.md](DEVELOPMENT.md) · [COMPONENTS.md](COMPONENTS.md) · [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) · [JAVASCRIPT.md](JAVASCRIPT.md) · [../core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md)
