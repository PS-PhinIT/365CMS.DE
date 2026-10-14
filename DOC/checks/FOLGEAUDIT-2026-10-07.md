# 365CMS – Projektdokumentation | Abschnitt: Audit 2026-10-07

> **Stand:** 2026-10-07 | **Version:** 3.5.0 | **Prüfumfang:** `CMS/` ohne `CMS/vendor/`, Standard-Theme `cms-default`, Laufzeitprüfung zusätzlich mit dem PhinIT-Theme | Übersicht: [README.md](README.md)

## English (summary)

Follow-up audit of 365CMS covering security, SEO, speed, internal linking ("PageRank"), accessibility and missing or incomplete features. 32 findings were fixed in changelog entry `3.5.0`. The most important ones: the shipped default theme rendered neither canonical, robots, Open Graph nor structured data and used the site name as `<title>` on every page; the core never exposed the current post/page to header hooks; sub-sitemaps returned 404 until the daily cron ran; any registered member could read draft and private pages through the REST API; changing or resetting a password did not end other sessions; password hashes ended up in the activity log; two password-reset rate limits never counted because their rows exceeded the column width; the default theme's start page queried tables without prefix and stayed empty; the core lacked the page-template support the PhinIT theme already ships. Runtime tests ran against MariaDB 10.11 with the PHP development server (PHP 8.3 copy with the platform check disabled). axe-core reported 38 rule violations on 8 default-theme pages before and 0 on 11 pages after the changes.

## Deutsch

### Methodik und Aussagekraft

1. **Syntax:** `php -l` über alle geänderten und neuen PHP-Dateien sowie `node --check` für die geänderten Skripte – ohne Fehler.
2. **Statische Prüfung:** SEO-Ausgabe des Cores (`SeoHeadRenderer`, `SeoSchemaRenderer`, `SeoSitemapService`), Router, Theme-Templates von `cms-default`, API, Auth/Sitzungen, Rate-Limits, Checkout, Seiten-Editor. Jeder Treffer wurde am Code nachvollzogen.
3. **Laufzeittests** gegen MariaDB 10.11.14 mit dem PHP-Entwicklungsserver auf einer **Kopie** von `CMS/`. Die Umgebung hat PHP 8.3; in der Kopie wurden die Mindestversion auf 8.3 gesetzt, die Plattformprüfung der gebündelten Symfony-Pakete übersprungen und `array_find()`/`array_any()` nachgebildet. Geprüft: Neuinstallation über `install.php`, Startseite, Blog, Beitrag DE/EN, Seite, Archive, Suche, 404, Sitemaps, `robots.txt`, API, Anmeldung, Passwort-Reset (Core- und Legacy-Modus), Sitzungsablauf nach Passwortwechsel, Seitenvorlage speichern und rendern mit dem PhinIT-Theme.
4. **Barrierefreiheit:** axe-core 4 (Regelsätze `wcag2a`, `wcag2aa`, `wcag21aa`, `best-practice`) in Chromium auf denselben Testdaten, vorher (Theme-Stand `HEAD`) und nachher.
5. **Theme-Verträge:** Die Testsuiten des PhinIT-Repositorys (`cms-phinit/tests/*`, `cms-365network/tests/*`) laufen gegen diesen Core-Stand vollständig grün, einschließlich `page-template-integration.php` und `editor-panels.test.js`, die vorher an der fehlenden Core-Anbindung scheiterten.

**Grenzen:** Keine Produktivdaten, kein Live-Crawl, keine Search-Console- oder Felddaten. Rankings und „PageRank“ lassen sich nicht garantieren; behoben sind technische Ursachen (Titel, Canonical, Indexierungssignale, Sitemaps, interne Verlinkung). axe-core prüft nur automatisierbare Regeln – das ist keine vollständige WCAG-Konformitätsaussage. Mailversand, LDAP, Passkeys und Zahlungsanbieter wurden nicht real getestet.

### SEO und interne Verlinkung

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| SEO-01 | 🔴 | `cms-default` gab nur `description` aus – **kein** Canonical, kein Robots-Meta, kein Open Graph, keine Twitter Card, kein JSON-LD. `SEOService::renderCurrentHeadTags()` wurde vom ausgelieferten Theme nie aufgerufen; Redaktionsfelder (Canonical, noindex, OG-Bild) blieben wirkungslos. | `outputMetaTags()` gibt die vollständige Core-Ausgabe aus; zusätzlich RSS-`alternate`. |
| SEO-02 | 🔴 | `<title>` war auf **jeder** Seite der Website-Name (doppelte Titel für Beiträge, Seiten, Archive, Suche, 404). | `meridian_document_title()`: Meta-Titel des Cores (inkl. Titel-Template und Override) für Beiträge/Seiten/Startseite, sprechende Präfixe für Archive, Autor, Suche, 404, „Seite N“ bei Paginierung. |
| SEO-03 | 🟠 | Der Core stellte den aktuellen Inhalt Header-Hooks nicht bereit: `SeoHeadRenderer` und die Hub-Erkennung lasen `$GLOBALS['post']`/`$GLOBALS['page']`, die nur einzelne Themes selbst setzten. Ohne das lieferte die Core-SEO-Ausgabe auf Beiträgen generische Startseitendaten. | `ThemeManager::render()` setzt beide Globals vor `header.php` (entfernt sie bei `404`/`error`). |
| SEO-04 | 🟠 | Indexierungssignale: Fehlerseiten erhielten Canonical und JSON-LD, Suche/Login/Registrierung/Mitgliederbereich/Checkout `index,follow`, Admin-Vorschauen von Entwürfen und geplanten Beiträgen ebenso. | Ab Status ≥ 400 `noindex`, kein Canonical/Schema; feste Liste nicht-öffentlicher Pfade `noindex`; nicht veröffentlichte Inhalte `noindex,nofollow`. |
| SEO-05 | 🟡 | Folgeseiten (`?p=2`, `?page=2`) kanonisierten auf Seite 1. | Canonical behält die Seitenzahl. |
| SEO-06 | 🟡 | `og:image`/`twitter:image` und Bild-Sitemap enthielten relative Pfade (`/uploads/…`); Netzwerke und Google erwarten absolute URLs. | Absolute URLs (auch bei Installation im Unterverzeichnis). |
| SEO-07 | 🟡 | Startseiten-Titel und -Beschreibung aus *SEO → Meta* wurden gespeichert, aber nie ausgegeben. | Ausgabe auf `/` und `/en`. |
| SEO-08 | 🟡 | Keine `hreflang`-Paare im Core, obwohl Beiträge/Seiten DE/EN-Fassungen haben. | DE/EN/x-default für Inhalte mit englischer Fassung (abschaltbar über *SEO → Technik*). Neues Feld `slug_base` im lokalisierten Payload. |
| SEO-09 | 🟡 | JSON-LD: `headline` mit „\| Website-Name“, ohne `datePublished`/`author`, `inLanguage` immer `de-DE`, Ausgabe ohne `JSON_HEX_TAG`. | Inhaltstitel als Headline, Veröffentlichungsdatum, Autor, Sprache je Inhalt; `<`/`>` werden maskiert. |
| SEO-10 | 🟠 | Sitemaps: Der Index verlinkt `pages.xml`, `posts.xml`, `images.xml` …, geroutet war nur `/sitemap.xml` – ohne gelaufenen Tages-Cron lieferten alle Teil-Sitemaps **404**. Außerdem: `noindex`-Inhalte und Inhalte mit fremdem Canonical enthalten, neue Beiträge erst nach dem nächsten Cron, News-Sitemap mit `updated_at` als Veröffentlichungsdatum und ohne 2-Tage-Fenster, `lastmod` der Startseite immer „jetzt“, eine `seo_meta`-Abfrage je Eintrag. | Routen für alle Teil-Sitemaps; fehlt das Bundle, wird es einmal erzeugt und als Datei gespeichert. Speichern/Löschen von Beiträgen und Seiten verwirft die Dateien (Neuaufbau beim nächsten Abruf). Nur indexierbare, selbstkanonische URLs; News nur letzte 48 h mit `published_at`; `lastmod` = jüngste Änderung; SEO-Daten mit einer Abfrage je Inhaltstyp. |
| SEO-11 | 🟡 | Gebündeltes `melbahja/seo`: Sitemap-URLs verloren den Port, bereits kodierte Pfadsegmente wurden doppelt kodiert. | Lokaler Patch in `CMS/assets/melbahja-seo/src/Utils/Utils.php` (kommentiert). |
| SEO-12 | 🟡 | Sitemap-Ping an Google (seit 2023 abgeschaltet) und Bing (seit 2022, 410) kostete beim Speichern bis zu 2 × 5 s. | Ping entfernt, Hinweis im Log, falls die Optionen noch aktiv sind. |
| SEO-13 | 🟠 | Interne Verlinkung: `cms-default` verlinkte Kategorien/Tags als `/blog?category=…` – Duplikat des Archivs mit Canonical auf `/blog` – und Beiträge fest als `/blog/<slug>` (bei anderer Permalink-Struktur jeweils 301-Umweg, EN-Links ohne `/en`). | `/blog?category=…`/`?tag=…` leitet per **301** auf das kanonische Archiv. Theme-Links nutzen `meridian_archive_url()` und `meridian_post_url()` (PermalinkService, Sprache). |
| SEO-14 | ⚪ | Standard-`robots.txt` ohne Sperre für `/member/`. | `Disallow: /member/`. |

### Sicherheit

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| SEC-12 | 🟠 | `GET /api/v1/pages/<slug>` lieferte **jedem angemeldeten Benutzer** (Registrierung ist öffentlich) auch Entwürfe, private und geplante Seiten mit allen Spalten. | Nicht öffentliche Seiten nur mit `manage_pages`, sonst 404. Laufzeittest: Mitglied erhält für einen Entwurf `Page not found`. |
| SEC-13 | 🟠 | Passwortwechsel und -Reset beendeten andere Sitzungen nicht – eine gestohlene Session blieb gültig. | Sitzung speichert einen HMAC-Fingerprint des Passwort-Hashes; weicht er ab, endet die Sitzung. Eigene Änderungen (Profil, Admin) erneuern den Fingerprint der aktuellen Sitzung. Laufzeittest: Session nach Passwortänderung → Weiterleitung zur Anmeldung. |
| SEC-14 | 🟠 | `UserService::updateUser()` schrieb bei Passwortänderung den **bcrypt-Hash** in `activity_log.metadata`. | Passwort-/Token-Felder werden als `[geändert]` protokolliert. Bestehende Einträge siehe Betreiberhinweise. |
| SEC-15 | 🟠 | Rate-Limit-Einträge mit `action` > 30 Zeichen (`forgot_password_request_account`) oder Kennung > 60 Zeichen (Token-Hash, lange E-Mail) scheiterten im SQL-Strict-Mode (`Data too long`). Das Konto- und das Token-Limit beim Passwort-Reset zählten **nie**; Anmeldeversuche mit langen Benutzernamen wurden nicht protokolliert. Laufzeittest. | `Security::normalizeRateLimitAction()`/`normalizeRateLimitIdentifier()` kürzen bzw. hashen auf Spaltenbreite – beim Schreiben und beim Zählen. |
| SEC-16 | 🟡 | LIKE-Platzhalter (`%`, `_`) aus Suchbegriffen ungefiltert in öffentlicher Suche, Archiv-Suche, Empfängersuche, Mail-Log- und Benutzersuche – `%` fand alles, Muster wie `%_%_%` erzeugen teure Scans. | `cms_escape_like()`. |
| SEC-17 | 🟡 | Öffentlicher Checkout (`orders.php`) ohne Frequenzbegrenzung; Land ungeprüft gespeichert. | 5 Bestellungen je IP und Stunde, Land gegen Liste. |
| SEC-18 | 🟡 | `cms-default/forgot-password.php` enthielt eine eigene Reset-Logik ohne Rate-Limit mit `mail()` am Mail-Dienst vorbei. | Entfernt; das Template nutzt den Core-Handler (siehe FUN-20). |
| SEC-19 | ⚪ | `/api/v1/status` nannte öffentlich die exakte Version. | Version nur für Admins. |

Geprüft und unauffällig: Ausgaben von Superglobals in Views, Reset-Token (256 Bit, SHA-256 gespeichert), Cron-Token (`hash_equals`), Audit-Log-Maskierung, Sicherheitsheader. Das JSON-LD war wegen der Standard-Maskierung von `/` nicht ausnutzbar; die zusätzliche Maskierung ist Härtung.

### Barrierefreiheit (`cms-default`)

| ID | Befund | Korrektur |
|---|---|---|
| A11Y-01 | Zwei verschachtelte `<main>`-Landmarken, kein Sprunglink, keine sichtbaren Fokusrahmen, Navigationen ohne Namen und `aria-current`, Dropdown nur per Hover, dekorative SVGs/Emojis werden vorgelesen. | Ein `<main id="main-content">`, Sprunglink „Zum Inhalt springen“, `:focus-visible`, benannte `<nav>`-Bereiche, `aria-current="page"`, `:focus-within` für Untermenüs, `aria-hidden` für Deko, `prefers-reduced-motion`. |
| A11Y-02 | Kontraste unter WCAG AA: Sekundärtext 4,0:1, Meta-Text 2,0:1, Akzent als Textfarbe 3,1:1, Footer 1,9–3,2:1, Newsletter-Button 3,1:1. | Neue Standardwerte und `--accent-text`; Customizer-Farben werden bei Bedarf automatisch abgedunkelt, bis 4,5:1 erreicht ist (eigene Farben mit ausreichendem Kontrast bleiben unverändert). |
| A11Y-03 | Startseite und Blog ohne `<h1>`, übersprungene Überschriftenebenen, Platzhalter-Bildlinks ohne Namen. | Versteckte `<h1>`, Hierarchie h1 → h2 → h3 ohne optische Änderung, Platzhalterlinks aus der Tab-Reihenfolge. |
| A11Y-04 | Checkout-Felder ohne zugeordnete Labels/`autocomplete`, Meldungen ohne `role`, Cookie-Leiste ohne Region, blockiertes `localStorage` brach das Cookie-Skript ab. | `for`/`id`, `autocomplete`, `role="alert"`/`status`, `role="region"`, `try/catch`. |

**axe-core** (gleiche Testdaten): vorher 38 Regelverstöße auf 8 Seiten (u. a. `color-contrast` mit über 200 Elementen, `landmark-no-duplicate-main`, `page-has-heading-one`, `link-name`, `heading-order`), nachher **0** auf 11 Seiten (zusätzlich `/cms-login`, `/cms-register`, `/cms-password-forgot`).

### Fehlende oder unvollständige Funktionen

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| FUN-15 | 🔴 | Startseite von `cms-default` (Blog-Modus) und Beitragsliste der Landing-Variante fragten `posts`/`users`/`post_categories` **ohne Tabellenpräfix** ab – mit dem Standardpräfix `cms_` blieb die Startseite leer. Außerdem zeigten Startseite, „Zuletzt erschienen“ und „Verwandte Artikel“ geplante Beiträge vorab. | Präfix, `cms_post_publication_where()` und Autorenname wie im Router. |
| FUN-16 | 🟠 | Such-Button im Header ohne Ziel (`#headerSearch` fehlte). | Suchleiste mit Label, Escape/Schließen, `aria-expanded`. |
| FUN-17 | 🟠 | Seitenvorlagen (`page_templates` in `theme.json`) wurden vom Core nicht unterstützt, obwohl das PhinIT-Theme `page-wide.php`/`page-landing.php` und die zugehörigen Tests ausliefert. | Neu `CMS\Services\PageTemplateService`; Auswahl und validierte Zusatzfelder im Seiteneditor (`admin/views/partials/page-template-fields.php`), Speicherung mit Revisionen und Revisionsvergleich, Bestands-/Neuinstallationsschema, Rendering über `ThemeManager`. Laufzeittest mit dem PhinIT-Theme: Speichern mit `javascript:`-URL abgelehnt, mit gültigen Daten gespeichert und als Landing-Page gerendert. |
| FUN-18 | 🟠 | Checkout versprach eine Bestätigungs-Mail, verschickte aber keine; der Drucken-Button war per Inline-`onclick` von der CSP blockiert. | Bestätigung an Kunde und Kopie an `ADMIN_EMAIL` über den Mail-Dienst; ehrliche Meldung, wenn der Versand scheitert; Button per Nonce-Skript. |
| FUN-19 | 🟡 | „Aktive Sessions“ im Mitgliederbereich und die Sitzungsstatistik waren immer leer – die Tabelle `sessions` wurde nie beschrieben. | `Auth` trägt Sitzungen ein (nur Hash der Session-ID), aktualisiert höchstens alle 5 Minuten, entfernt sie bei Abmeldung und Passwortwechsel; die Übersicht markiert die aktuelle Sitzung. |
| FUN-20 | 🟠 | Auth-Modus `legacy`: Das Theme-Formular „Passwort vergessen“ sendete `fp_email` ohne `forgot_password_action` an den Core-Handler – Reset-Anfrage und neues Passwort schlugen immer fehl; nach erfolgreichem Reset war `?step=done` nicht erreichbar. | Felder des Core-Handlers, Rücksprung-Schritte, Werte-Erhalt. Laufzeittest: Reset-Link wird angelegt. |

### Geschwindigkeit

- Sitemap: statt einer `seo_meta`-Abfrage **je Eintrag** eine je Inhaltstyp; das Bundle wird einmal erzeugt und danach als Datei ausgeliefert statt bei jedem Abruf in einem Temp-Verzeichnis neu gebaut.
- Kein blockierender Sitemap-Ping mehr (bis zu 10 s beim Speichern).
- LIKE-Maskierung verhindert teure Platzhalter-Scans über öffentliche Suchparameter.
- Mehraufwand: Die Sitzungsübersicht schreibt je angemeldetem Benutzer höchstens einmal in 5 Minuten; `Auth` liest den Passwort-Hash in der ohnehin laufenden Benutzerabfrage mit.
- Gemessen (MariaDB General Log, je 3 Aufrufe, PhinIT-Theme): Abfragezahl auf Beitrag und Seite **unverändert** (232 bzw. 319) – das PhinIT-Theme setzte die Globals schon selbst. Eine Beschleunigung der Seitenauslieferung wird daher nicht behauptet.

### Betreiberhinweise

- **Schema:** `pages.page_template`, `pages.page_meta_json` und die gleichnamigen Spalten in `page_revisions` legt `PageManager` beim ersten Zugriff an (benötigt `ALTER`-Recht); Neuinstallationen erhalten sie über `SchemaManager`.
- **Alte Passwort-Hashes im Aktivitätsprotokoll entfernen** (MariaDB/MySQL mit JSON-Funktionen, Präfix anpassen):
  ```sql
  UPDATE cms_activity_log
     SET metadata = JSON_REPLACE(metadata, '$.password', '[geändert]')
   WHERE action = 'user_updated' AND JSON_EXTRACT(metadata, '$.password') IS NOT NULL;
  ```
- Bestehende Sitzungen werden beim ersten Aufruf nach dem Update an das aktuelle Passwort gebunden; niemand wird abgemeldet.
- Wer eigene Farben im Customizer gewählt hat, die WCAG AA verfehlen, sieht Sekundärtexte und Akzent-Links etwas dunkler.
- `Version::CURRENT` bleibt `3.4.00` (offene Release-Entscheidung FUN-12 aus dem Audit vom 03.10.2026).

### Geänderte Dateien

Core: `core/ThemeManager.php`, `core/Auth.php`, `core/Security.php`, `core/Api.php`, `core/PageManager.php`, `core/SchemaManager.php`, `core/Bootstrap.php`, `core/Routing/ThemeRouter.php`, `core/Routing/ApiRouter.php`, `core/Services/SEOService.php`, `core/Services/SEO/SeoHeadRenderer.php`, `core/Services/SEO/SeoSchemaRenderer.php`, `core/Services/SEO/SeoSitemapService.php`, `core/Services/ContentLocalizationService.php`, `core/Services/CmsAuthPageService.php`, `core/Services/MemberService.php`, `core/Services/UserService.php`, `core/Services/MessageService.php`, `core/Services/MailLogService.php`, neu `core/Services/PageTemplateService.php`; `includes/functions/options-runtime.php`; `orders.php`; `member/security.php`; Admin: `admin/pages.php`, `admin/modules/pages/PagesModule.php`, `admin/views/pages/edit.php`, neu `admin/views/partials/page-template-fields.php`, `assets/js/admin-content-editor.js`; Bibliothek: `assets/melbahja-seo/src/Utils/Utils.php`; Theme `cms-default`: `header.php`, `footer.php`, `functions.php`, `style.css`, `js/theme.js`, `home.php`, `blog.php`, `blog-single.php`, `forgot-password.php`, Archiv-/Fehler-/Auth-Templates und `partials/*`.

### Verwandte Dokumente

[README.md](README.md) · [SECURITY.md](SECURITY.md) · [FUNKTIONEN.md](FUNKTIONEN.md) · [PERFORMANCE.md](PERFORMANCE.md) · [../theme/THEME-DEVELOPMENT.md](../theme/THEME-DEVELOPMENT.md) · [../../Changelog.md](../../Changelog.md)
