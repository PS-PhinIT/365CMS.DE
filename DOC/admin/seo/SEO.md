# 365CMS – Projektdokumentation | Abschnitt: Admin – SEO-Suite

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Routen:** `/admin/seo-dashboard`, `/admin/seo-audit`, `/admin/seo-meta`, `/admin/seo-social`, `/admin/seo-schema`, `/admin/seo-sitemap`, `/admin/seo-technical` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_seo_suite` | **Core-Modul:** `seo`

## English (summary)

All SEO screens share one controller, `CMS/admin/seo-page.php`, which maps each route to a section of `CMS/admin/modules/seo/SeoSuiteModule.php` and a view in `CMS/admin/views/seo/`. Frontend output is produced by `CMS\Services\SEOService` and the classes in `CMS/core/Services/SEO/` (`SeoHeadRenderer`, `SeoSchemaRenderer`, `SeoSitemapService`, `SeoAnalyticsRenderer`, `SeoMetaService`, `SeoMetaRepository`, `SeoSettingsStore`, `SeoAuditService`). Per-content SEO data lives in `cms_seo_meta`. Public endpoints: `/sitemap.xml`, `/robots.txt`, `/sitemap` (HTML), `/feed`, `/<indexnow-key>.txt`.

Since 3.5.0 template variables in stored meta fields (`%title%`, `%%sitename%%`, …) are resolved by `SeoAnalysisService::resolveTemplateVariables()`.

## Deutsch

### Seiten und Aktionen

| Route | Bereich | Aktionen (zusätzlich überall `regenerate_sitemap_bundle`, `save_robots`) |
|---|---|---|
| `/admin/seo-dashboard` | Übersicht: SEO-Score, Problemzähler, Sitemap-Status | – |
| `/admin/seo-audit` | Inhalte mit SEO-Problemen, Einzelkorrektur | `save_audit_item` |
| `/admin/seo-meta` | Titel-Format, Trenner, Startseite, Standard-Robots, Lesbarkeitsgrenzen, CSV-Export/-Import der Beitrags-SEO-Metadaten | `save_meta_defaults`, `save_templates`, `import_post_meta_csv`; Export per GET `?export=posts_csv` |
| `/admin/seo-social` | Open Graph / Twitter-Standards, Profile | `save_social_defaults` |
| `/admin/seo-schema` | Strukturierte Daten (JSON-LD) | `save_schema_defaults` |
| `/admin/seo-sitemap` | Sitemap-Einstellungen, IndexNow, Google Indexing API | `save_sitemap_settings`, `submit_indexing_urls`, `submit_recent_content_indexnow`, `delete_google_url`, `save_google_access_token`, `clear_google_access_token` |
| `/admin/seo-technical` | Technisches SEO, Broken-Link-Scan | `save_technical_settings`, `run_broken_link_scan`, `ignore_broken_link_target`, `unignore_broken_link_target` |

Analysen (`/admin/analytics`), Weiterleitungen und 404-Monitor: siehe [ANALYTICS.md](ANALYTICS.md) und [REDIRECTS.md](REDIRECTS.md).

### Meta-Daten (`/admin/seo-meta`)

| Feld | Hinweis |
|---|---|
| Titel-Format (`site_title_format`) | Standard `%%title%% %%sep%% %%sitename%%` |
| Trenner (`title_separator`) | z. B. `\|`, `–`, `·` |
| Startseiten-Titel / -Beschreibung | `homepage_title`, `homepage_description` |
| Standard-Meta-Beschreibung | Fallback für Inhalte ohne eigene Beschreibung |
| Standard-Robots | `default_robots_index`, `default_robots_follow` |
| Selbstreferenzierender Canonical | `self_referencing_canonical` |
| Lesbarkeitsanalyse | Mindestwörter (`analysis_min_words`), max. Wörter je Satz/Absatz |

**Template-Variablen** (Yoast `%%var%%` und Rank Math `%var%`): `title`, `sitename`, `sep`, `page`, `excerpt`, `category`, `currentyear` u. a. Unbekannte Variablen werden samt verwaister Trenner entfernt. Gilt für Meta-Titel, Meta-Beschreibung sowie OG-/Twitter-Titel und -Beschreibung – wichtig nach WordPress-Importen.

#### Beitrags-SEO-Metadaten per CSV

Die Karte *SEO-Metadaten der Beiträge per CSV* (bzw. der Button *Beitrags-Metadaten als CSV* in der Toolbar) exportiert alle Beiträge außer dem Papierkorb (max. 20.000) als UTF-8-CSV mit Semikolon. Nach dem Bearbeiten wird die Datei über dieselbe Karte wieder importiert (max. 5 MB / 5.000 Zeilen). Logik: `CMS\Services\SEO\PostSeoMetaCsvService`, CSV-Hilfen: `CMS\Services\CsvExchange`.

| Spalten | Verhalten beim Import |
|---|---|
| `post_id`, `slug` | Zuordnung. Vorrang hat `post_id`; passt der Slug nicht zur ID, wird die Zeile abgelehnt. Ohne `post_id` wird über den Slug gesucht. |
| `title`, `status`, `url` | Nur zur Orientierung, werden ignoriert. |
| `meta_title`, `meta_description` | Spalten in `cms_posts` |
| `focus_keyphrase`, `keywords`, `canonical_url`, `robots_index`, `robots_follow`, `og_title`, `og_description`, `og_image`, `twitter_card`, `twitter_title`, `twitter_description`, `twitter_image`, `schema_type`, `sitemap_priority`, `sitemap_changefreq`, `hreflang_group` | Spalten in `cms_seo_meta` |

- Titel, Slug, Inhalt, Auszug, Status, Kategorien und `updated_at` der Beiträge bleiben unverändert.
- Fehlt eine Spalte in der Datei, bleibt das Feld unverändert. Eine leere Zelle setzt das Feld auf den Standard zurück (bei `robots_index`/`robots_follow`: `1`).
- Validierung: URLs müssen mit `/` beginnen oder `http(s)://` sein; `twitter_card` = `summary_large_image` oder `summary`; `schema_type` = `Article`, `BlogPosting` oder `NewsArticle`; `sitemap_priority` 0–1 (Komma oder Punkt); `sitemap_changefreq` = `always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never`. Fehlerhafte Zeilen werden mit Zeilennummer gemeldet und übersprungen, der Rest wird übernommen (in einer Datenbank-Transaktion).
- Deutsche Spaltennamen (z. B. *Meta-Titel*, *Fokus-Keyphrase*, *Sitemap-Priorität*) sowie Komma- oder Tab-getrennte Dateien werden ebenfalls erkannt.
- Export und Import werden im Audit-Log protokolliert (`seo.post_meta.export.csv`, `seo.post_meta.import.csv`). Nach Änderungen werden die Sitemaps und – sofern aktiviert – der Content-Cache invalidiert.

### Social (`/admin/seo-social`)

Standard-OG-Typ (`default_og_type`), Standard-Twitter-Card (`default_twitter_card`), Standardbild (`default_image`), Facebook-Seite, Twitter-/X-Profil, Pinterest Rich Pins.

### Strukturierte Daten (`/admin/seo-schema`)

`SeoSchemaRenderer` gibt JSON-LD aus:

- **WebSite/Organization:** Organisation (`organization_enabled`, Name `org_name`, Logo `org_logo`) und Markenname,
- **BreadcrumbList:** wenn `breadcrumb_enabled` aktiv ist,
- **Inhaltstyp je Seite/Beitrag:** wählbar im Editor – Beiträge `Article` (Standard), `BlogPosting`, `NewsArticle`; Seiten `WebPage` (Standard), `Article`, `Organization`. Gespeicherte Altwerte (`FAQPage`, `HowTo`, `Person`, `Event` …) fallen seit 3.5.0 auf den Standard des Inhaltstyps zurück (`SeoSchemaRenderer::effectiveTypeFor()`).

Die Schalter für FAQ, HowTo, Person, Event und Review (`faq_enabled`, `howto_enabled`, `person_enabled`, `event_enabled`, `review_enabled`) werden gespeichert, erzeugen aber ohne passende Inhaltsstruktur kein Markup – FAQ- und HowTo-Rich-Results werden bewusst nicht angeboten.

### Sitemap (`/admin/seo-sitemap`)

- `/sitemap.xml` (`SeoSitemapService`, Bibliothek `melbahja/seo`) indexiert `pages.xml` und `posts.xml` für veröffentlichte deutsche Inhalte sowie `en-pages.xml` und `en-posts.xml` nur für veröffentlichte Inhalte mit tatsächlich gepflegtem englischem Titel und Text. Englische URLs verwenden die englischen Slugs (mit Fallback auf den deutschen Slug) und die englische Permalink-Struktur. Die englische Startseite `/en/` ist in `en-pages.xml` enthalten. Zusätzlich werden von Plugins gemeldete Seiten (siehe [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)) über den Filter `cms_sitemap_entries` aufgenommen (Pfad oder absolute URL der eigenen Domain, optional `lastmod`, `changefreq`, `priority`; max. 10 000, fremde Hosts werden verworfen) – sie landen in `plugins.xml`. Optional gibt es eine Bild- und eine Google-News-Sitemap. Die Dateien werden als Bundle gespeichert und von `ThemeRouter::serveSitemap()` ausgeliefert; Suchmaschinen können nach dem Neuaufbau angepingt werden (`ping_google`, `ping_bing`).
- Einstellungen: Priorität und Änderungsfrequenz für Seiten/Beiträge, Bild-Sitemap (`image_enabled`), Google-News-Sitemap (`news_enabled`, `news_publication_name`, `news_language`).
- `regenerate_sitemap_bundle` erzeugt Sitemap und `robots.txt` neu; `save_robots` speichert den `robots.txt`-Inhalt.
- **IndexNow:** Schlüssel `indexnow_key`; die Schlüsseldatei wird unter `/<key>.txt` ausgeliefert. `submit_indexing_urls` meldet einzelne URLs, `submit_recent_content_indexnow` die zuletzt geänderten Inhalte (Bing, Yandex u. a.).
- **Google Indexing API:** optionales Access-Token (`save_google_access_token` / `clear_google_access_token`), `delete_google_url` meldet entfernte URLs.

### Technisches SEO (`/admin/seo-technical`)

`noindex` für Archive und Tag-Seiten, `rel="prev/next"` für Paginierung, Breadcrumbs, Hreflang (DE/EN), Pflicht-Alt-Texte für Bilder, automatische Weiterleitung bei Slug-Änderung (`auto_redirect_slug`), Broken-Link-Scan (`SeoBrokenLinkService`) mit Ignorierliste.

### SEO-Felder im Editor

Seiten- und Beitragseditor zeigen SEO-Score, Lesbarkeitsanalyse und erweiterte Felder (`admin-seo-editor.js`). Gespeichert in `cms_seo_meta`:

`content_type` (`page`/`post`), `content_id`, `canonical_url`, `robots_index`, `robots_follow`, `og_title`, `og_description`, `og_image`, `og_type`, `twitter_card`, `twitter_title`, `twitter_description`, `twitter_image`, `focus_keyphrase`, `keywords`, `schema_type`, `sitemap_priority`, `sitemap_changefreq`, `hreflang_group`.

Optional erzeugt der KI-Assistent Meta-Titel/-Beschreibung (`/admin/ai-generate-seo-metadata`, siehe [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md)).

### Verwandte Dokumente

[README.md](README.md) · [ANALYTICS.md](ANALYTICS.md) · [REDIRECTS.md](REDIRECTS.md) · [../../assets/melbahja-seo/README.md](../../assets/melbahja-seo/README.md)
