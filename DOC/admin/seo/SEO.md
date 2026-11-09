# 365CMS – Projektdokumentation | Abschnitt: Admin – SEO-Suite

> **Stand:** 2026-11-08 | **Version:** 4.0.0 | **Status:** Stable
> **Routen:** `/admin/seo-dashboard`, `/admin/seo-audit`, `/admin/seo-meta`, `/admin/seo-social`, `/admin/seo-schema`, `/admin/seo-sitemap`, `/admin/seo-search-engines`, `/admin/seo-technical` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_seo_suite` | **Core-Modul:** `seo`

## English (summary)

All SEO screens share one controller, `CMS/admin/seo-page.php`, which maps each route to a section of `CMS/admin/modules/seo/SeoSuiteModule.php` and a view in `CMS/admin/views/seo/`. Frontend output is produced by `CMS\Services\SEOService` and the classes in `CMS/core/Services/SEO/` (`SeoHeadRenderer`, `SeoSchemaRenderer`, `SeoSitemapService`, `SeoAnalyticsRenderer`, `SeoMetaService`, `SeoMetaRepository`, `SeoSettingsStore`, `SeoAuditService`, `SearchEngineVerification`). Per-content SEO data lives in `cms_seo_meta`. Public endpoints: `/sitemap.xml`, `/robots.txt`, `/sitemap` (HTML), `/feed`, `/<indexnow-key>.txt`.

Since 3.5.6 everything search-engine related lives in **SEO → Suchmaschinen** (`/admin/seo-search-engines`): IndexNow key, key generator and key file, opt-in automatic IndexNow submission (queue, flush after the response, hourly cron incl. scheduled posts, 5-minute resubmit debounce), manual submissions, Google Search Console property, Google Indexing API token and webmaster verification meta tags for Google, Bing, Yandex, Seznam, Naver and Baidu.

Since 3.4.08 template variables in stored meta fields (`%title%`, `%%sitename%%`, …) are resolved by `SeoAnalysisService::resolveTemplateVariables()`.

## Deutsch

### Seiten und Aktionen

| Route | Bereich | Aktionen (zusätzlich überall `regenerate_sitemap_bundle`, `save_robots`) |
|---|---|---|
| `/admin/seo-dashboard` | Übersicht: SEO-Score, Problemzähler, Sitemap-Status | – |
| `/admin/seo-audit` | Inhalte mit SEO-Problemen, Einzelkorrektur | `save_audit_item` |
| `/admin/seo-meta` | Titel-Format, Trenner, Startseite, Standard-Robots, Lesbarkeitsgrenzen, CSV-Export/-Import der Beitrags-SEO-Metadaten | `save_meta_defaults`, `save_templates`, `import_post_meta_csv`; Export per GET `?export=posts_csv` |
| `/admin/seo-social` | Open Graph / Twitter-Standards, Profile | `save_social_defaults` |
| `/admin/seo-schema` | Strukturierte Daten (JSON-LD) | `save_schema_defaults` |
| `/admin/seo-sitemap` | Sitemap-Einstellungen, Einreichung in Webmaster-Tools | `save_sitemap_settings` |
| `/admin/seo-search-engines` | Suchmaschinen: IndexNow, Google Search Console, Google Indexing API, Website-Verifizierung | `save_search_engine_settings`, `generate_indexnow_key`, `write_indexnow_key_file`, `flush_indexnow_queue`, `clear_indexnow_queue`, `submit_indexing_urls`, `submit_recent_content_indexnow`, `delete_google_url`, `save_google_access_token`, `clear_google_access_token` |
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
- **Inhaltstyp je Seite/Beitrag:** wählbar im Editor – Beiträge `Article` (Standard), `BlogPosting`, `NewsArticle`; Seiten `WebPage` (Standard), `Article`, `Organization`. Gespeicherte Altwerte (`FAQPage`, `HowTo`, `Person`, `Event` …) fallen seit 3.4.04 auf den Standard des Inhaltstyps zurück (`SeoSchemaRenderer::effectiveTypeFor()`).

Die Schalter für FAQ, HowTo, Person, Event und Review (`faq_enabled`, `howto_enabled`, `person_enabled`, `event_enabled`, `review_enabled`) werden gespeichert, erzeugen aber ohne passende Inhaltsstruktur kein Markup – FAQ- und HowTo-Rich-Results werden bewusst nicht angeboten.

### Sitemap (`/admin/seo-sitemap`)

- `/sitemap.xml` (`SeoSitemapService`, Bibliothek `melbahja/seo`) indexiert `pages.xml` und `posts.xml` für veröffentlichte deutsche Inhalte sowie `en-pages.xml` und `en-posts.xml` nur für veröffentlichte Inhalte mit tatsächlich gepflegtem englischem Titel und Text. Englische URLs verwenden die englischen Slugs (mit Fallback auf den deutschen Slug) und die englische Permalink-Struktur. Die englische Startseite `/en/` ist in `en-pages.xml` enthalten. Zusätzlich werden von Plugins gemeldete Seiten (siehe [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)) über den Filter `cms_sitemap_entries` aufgenommen (Pfad oder absolute URL der eigenen Domain, optional `lastmod`, `changefreq`, `priority`; max. 10 000, fremde Hosts werden verworfen) – sie landen in `plugins.xml`. Optional gibt es eine Bild- und eine Google-News-Sitemap. Die Dateien werden beim ersten Abruf als Bundle im Webroot gespeichert (sonst dynamisch erzeugt) und von `ThemeRouter::serveSitemap()` ausgeliefert. Seit 4.0.0 gilt das auch für `en-pages.xml` und `en-posts.xml`: vorher antworteten beide mit 404, solange keine gespeicherte Datei existierte, und wurden beim Speichern von Inhalten nicht verworfen. Ändert sich die Site-URL unter *Einstellungen → Allgemein*, verwirft das CMS das gespeicherte Bundle (absolute URLs).
- Die Tabelle der Bundle-Dateien zeigt je Datei: *vorhanden* (Datei mit Datum und Größe), *bei Abruf* (noch nicht gespeichert, entsteht beim ersten Abruf von `/sitemap.xml`), *keine Einträge* (Teil ohne Inhalte, wird nicht gespeichert), *deaktiviert* (Bild-/News-Sitemap aus), *dynamisch* (`robots.txt` ohne statische Datei) und *fehlt* (nur nach einem Fehler beim Erzeugen). Vor 4.0.0 erschienen nicht gespeicherte, aber abrufbare Dateien als „fehlt“; das SEO-Dashboard zeigte für Sitemap und `robots.txt` entsprechend Fehlerzustände.
- `robots.txt` liefert `ThemeRouter::serveRobotsTxt()` dynamisch aus: der Inhalt aus *Einstellungen → Allgemein → robots.txt* oder die Standardregeln. Fehlt in eigenen Regeln eine `Sitemap:`-Zeile, hängt das CMS seit 4.0.0 `Sitemap: <SITE_URL>/sitemap.xml` an.
- Einstellungen: Priorität und Änderungsfrequenz für Seiten/Beiträge, Bild-Sitemap (`image_enabled`), Google-News-Sitemap (`news_enabled`, `news_publication_name`, `news_language`).
- `regenerate_sitemap_bundle` speichert das Sitemap-Bundle neu. `save_robots` (*robots.txt schreiben*) legt eine statische `robots.txt` im Webroot an; der Webserver liefert sie vor der dynamischen Route aus. Seit 4.0.0 aktualisiert das CMS eine unveränderte, selbst geschriebene Datei, wenn sich die `robots.txt`-Einstellung oder die Site-URL ändert. Eine manuell angepasste Datei bleibt erhalten; beim Speichern erscheint dann ein Hinweis.
- Die Karte *Sitemap bei Suchmaschinen einreichen* zeigt die URL der Index-Datei und wo sie in Google Search Console, Bing Webmaster Tools und den übrigen Webmaster-Tools eingetragen wird.
- Den anonymen Sitemap-Ping haben Bing (2022) und Google (2023) abgeschaltet. Die früheren Schalter `seo_sitemap_ping_google`/`seo_sitemap_ping_bing` werden beim Speichern auf `0` gesetzt und nicht mehr angezeigt; sofortige Benachrichtigungen übernimmt IndexNow (siehe *Suchmaschinen*).

### Suchmaschinen (`/admin/seo-search-engines`)

Seit 3.5.6 sind alle suchmaschinenbezogenen Einstellungen hier gebündelt (vorher verteilt auf *Sitemap*, *Technisches SEO* und *Analytics*). Daten: `SeoSuiteModule::getSearchEnginesData()`, Logik: `CMS\Services\IndexingService` und `CMS\Services\SEO\SearchEngineVerification`.

| Einstellung (Option) | Hinweis |
|---|---|
| IndexNow-Key (`seo_indexnow_key`) | 8–128 Zeichen, nur `a-z`, `A-Z`, `0-9` und `-`. Vorrang: gespeicherter Key → Alt-Setting `seo.indexnow_key` → Konstante `SEO_INDEXNOW_KEY`; die Quelle wird angezeigt. |
| Root-TXT-Datei (`seo_indexnow_key_file`) | Optional eine physische `.txt` im Web-Root; ohne Auswahl liefert der `ThemeRouter` `/<key>.txt` dynamisch aus (nur für protokollgültige Keys). |
| Automatische Meldung (`seo_indexnow_auto_submit`) | Opt-in, Standard aus. Siehe unten. |
| Search-Console-Property (`seo_analytics_gsc_property`) | `sc-domain:example.de` (Domain-Property, Bestätigung per DNS) oder URL-Präfix wie `https://www.example.de/`; wird für den Direktlink in die Search Console genutzt. |
| Verifizierung (`seo_verification_google`, `…_bing`, `…_yandex`, `…_seznam`, `…_naver`, `…_baidu`) | Code oder kompletter Meta-Tag aus dem Webmaster-Tool; gespeichert wird nur der `content`-Wert. Meta-Tags anderer Dienste und HTML-Bestätigungsdateien werden abgelehnt. |

**Verifizierungs-Tags:** `Bootstrap` hängt im Web-Modus an den Hook `head` (Priorität 16) `SEOService::getSearchEngineVerificationTags()` an. Ausgegeben werden `google-site-verification`, `msvalidate.01` (Bing), `yandex-verification`, `seznam-wmt`, `naver-site-verification` und `baidu-site-verification` – jeweils nur, wenn ein Code gespeichert ist.

**IndexNow-Key erzeugen:** `generate_indexnow_key` erzeugt einen hexadezimalen Key per `random_bytes()` (16, 32 – empfohlen –, 64 oder 128 Zeichen), speichert ihn und legt optional die UTF-8-Keydatei `<key>.txt` mit dem Key als Inhalt im Web-Root (`ABSPATH`) ab; ohne Root-Datei wird der neue Key dynamisch ausgeliefert. Auf Wunsch wird die Keydatei des bisherigen Keys entfernt – nur, wenn sie exakt diesen Key enthält. `write_indexnow_key_file` legt die Datei für den bestehenden Key an. Die Prüfansicht zeigt Key-Quelle, `keyLocation`, Beschreibbarkeit des Roots, die geprüften Root-Pfade und die Antwortcodes des Protokolls.

**IndexNow-Übermittlung:** Gesendet wird gemäß Protokoll per JSON-`POST` an `https://api.indexnow.org/indexnow` (`host`, `key`, `keyLocation`, `urlList`), je Host gruppiert und in Blöcken zu max. 10.000 URLs; `keyLocation` verweist auf den jeweiligen Host. Teilnehmende Suchmaschinen (u. a. Bing, Yandex, Seznam, Naver, Yep) teilen die Meldung untereinander. HTTP 200 und 202 (Key-Prüfung ausstehend) gelten als Erfolg; 400, 403, 422 und 429 werden mit Ursache gemeldet.

- `submit_indexing_urls` meldet bis zu 100 URLs der eigenen Website an IndexNow und/oder die Google Indexing API; ungültige, doppelte oder fremde Einträge werden ignoriert und in der Rückmeldung gezählt.
- `submit_recent_content_indexnow` meldet die im gewählten Zeitraum veröffentlichten Beiträge und geänderten Seiten (max. 500 je Typ) mit DE- und – falls übersetzt – EN-URL; `noindex`-Inhalte werden wie in der Sitemap ausgelassen.
- **Automatische Meldung** (wenn `seo_indexnow_auto_submit = 1` und ein gültiger Key vorhanden ist): Die Hooks `cms_after_post_save` und `cms_after_page_save` (Priorität 95) merken die öffentlichen URLs (DE und – falls übersetzt – EN) veröffentlichter, indexierbarer Inhalte in einer Warteschlange vor (`seo_indexing.indexnow_queue`, max. 1.000 URLs). Gesendet wird nach Abschluss der Antwort (`fastcgi_finish_request()`/`litespeed_finish_request()`), damit das Speichern nicht wartet.
- Der stündliche Cron (`cms_cron_hourly`, Priorität 45, `IndexingService::runScheduledIndexNowFlush()`) merkt zusätzlich Beiträge vor, die seit dem letzten Lauf öffentlich geworden sind, ohne beim Speichern gemeldet worden zu sein (geplante Beiträge, Massen-Veröffentlichungen; Zeitfenster max. 24 Stunden, der erste Lauf setzt nur den Startzeitpunkt), und sendet die Warteschlange. Vorübergehende Fehler (Netzwerk, 403, 429, 5xx) werden so wiederholt; bei 400/422 werden die URLs verworfen.
- Gemäß IndexNow-FAQ wird dieselbe URL frühestens nach 5 Minuten erneut gemeldet: Jüngere URLs bleiben in der Warteschlange und gehen mit dem nächsten Lauf raus. `flush_indexnow_queue` sendet sofort, `clear_indexnow_queue` verwirft die Warteschlange; das Ergebnis des letzten Laufs wird angezeigt.
- Gelöschte (404/410), depublizierte oder umgeleitete URLs werden nicht automatisch erkannt und sollten manuell gemeldet werden.

**Google:** Google nimmt nicht an IndexNow teil; neue Inhalte findet Google über die in der Search Console eingereichte Sitemap. Die Google Indexing API (`save_google_access_token` / `clear_google_access_token`, Token verschlüsselt gespeichert; `delete_google_url` meldet entfernte URLs) ist laut Google offiziell nur für Seiten mit `JobPosting`- oder `BroadcastEvent`-Markup vorgesehen.

### Technisches SEO (`/admin/seo-technical`)

`noindex` für Archive und Tag-Seiten, `rel="prev/next"` für Paginierung, Breadcrumbs, Hreflang (DE/EN), Pflicht-Alt-Texte für Bilder, automatische Weiterleitung bei Slug-Änderung (`auto_redirect_slug`), Broken-Link-Scan (`SeoBrokenLinkService`) mit Ignorierliste. IndexNow und Webmaster-Verifizierung: siehe *Suchmaschinen*.

### SEO-Felder im Editor

Seiten- und Beitragseditor zeigen SEO-Score, Lesbarkeitsanalyse und erweiterte Felder (`admin-seo-editor.js`). Gespeichert in `cms_seo_meta`:

`content_type` (`page`/`post`), `content_id`, `canonical_url`, `robots_index`, `robots_follow`, `og_title`, `og_description`, `og_image`, `og_type`, `twitter_card`, `twitter_title`, `twitter_description`, `twitter_image`, `focus_keyphrase`, `keywords`, `schema_type`, `sitemap_priority`, `sitemap_changefreq`, `hreflang_group`.

Optional erzeugt der KI-Assistent Meta-Titel/-Beschreibung (`/admin/ai-generate-seo-metadata`, siehe [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md)).

### Verwandte Dokumente

[README.md](README.md) · [ANALYTICS.md](ANALYTICS.md) · [REDIRECTS.md](REDIRECTS.md) · [../../assets/melbahja-seo/README.md](../../assets/melbahja-seo/README.md)
