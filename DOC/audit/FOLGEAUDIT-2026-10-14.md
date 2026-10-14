# 365CMS – Projektdokumentation | Abschnitt: Folgeaudit 2026-10-14

> **Stand:** 2026-10-14 | **Version:** 3.5.0 | **Prüfumfang:** `CMS/` ohne gebündelte Drittbibliotheken, Theme `cms-default`, Plugin `cms-importer`, `README.md`, `DOC/`, `RELEASE/` | Übersicht: [README.md](README.md)

## English (summary)

Second pass over release 3.5.0 after the full audit of the same day. It covered security, speed, best practice, bugs, broken references and the GUI. 43 findings were recorded. In 3.5.0, 38 were fixed completely and 3 in part (PERF-12, PERF-17, PERF-22); 2 remain open (FUN-34, PERF-18). One finding (SEC-30) changes behaviour for API clients: JWTs are now bound to the password, and tokens issued before 3.5.0 must be fetched again. The open parts are optimisations without a functional defect and are listed as recommendations OFFEN-14 … OFFEN-18; OFFEN-19 is an operator note for API clients.

The fixes with the most impact were:

- **Importer never loaded:** the plugin `cms-importer` never defined its service class because of a load guard, and 34 methods had been missing since the class was split up.
- **SSRF filter gaps:** the filter did not recognise IPv4-mapped IPv6 or NAT64 addresses.
- **Path traversal:** a broken regex allowed path traversal when renaming member folders.
- **SEO settings queries:** the SEO analysis ran one query per setting.

## Deutsch

### Methodik

- Drei parallele Prüfstränge:
  - Sicherheit
  - Fehler und Verweise
  - Performance und GUI
- Danach wurde jeder Befund im Code verifiziert. Befunde, die sich nicht bestätigten, sind unten als „kein Befund“ vermerkt.
- Geprüft wurden:
  - Aufrufketten: tote Methoden, nicht vorhandene Methoden und Routen
  - Regex-Gültigkeit
  - Abfragen je Request
  - Formular-Semantik
  - Versionsangaben in allen Dokumenten
- Ohne neuen Befund blieben:
  - CSRF und SQL-Parameter
  - Redirects
  - Uploads und ZIP-Verarbeitung
  - XSS-Ausgaben
  - `unserialize`
  - Zufallsquellen
  - `hash_equals`

### Befunde und Status

Schwere: 🔴 hoch · 🟠 mittel · 🟡 niedrig · ⚪ Hinweis

#### Sicherheit

| ID | Befund | Schwere | Status |
|---|---|---|---|
| SEC-28 | SSRF-Filter (`Http\Client`, `FeedService`, `UpdateService`) ließ IPv4-mapped/-compatible IPv6 (`::ffff:127.0.0.1`, Hex-Form), NAT64 `64:ff9b::/96` und CGNAT durch | 🟠 | ✅ 3.5.0 – gemeinsame Prüfung `Http\Client::isNonPublicIp()` mit `FILTER_FLAG_GLOBAL_RANGE` |
| SEC-29 | `/media-file` lieferte `uploads/import/` an angemeldete Nicht-Admins | 🟡 | ✅ 3.5.0 – nur Admins |
| SEC-30 | API-JWTs blieben nach Passwortänderung bis zum Ablauf (Refresh: 30 Tage) gültig | 🟠 | ✅ 3.5.0 – Claim `pwf` (Passwort-Fingerabdruck), Prüfung bei Bearer-Anmeldung und Refresh |
| SEC-31 | Prüfung privater Mitgliederpfade abhängig von Groß-/Kleinschreibung | 🟡 | ✅ 3.5.0 |

#### Funktionen und Fehler

| ID | Befund | Schwere | Status |
|---|---|---|---|
| FUN-26 | `cms-importer`: Load-Guard prüfte die per Early Binding bereits deklarierte Klasse `CMS_Importer_DB` → `CMS_Importer_Service` wurde nie definiert; zusätzlich fehlten 34 Methoden seit der Aufteilung | 🔴 | ✅ 3.5.0 – Guard korrigiert, Methoden in `trait-importer-content.php` |
| FUN-27 | Statische Aufrufe `Logger::error()` in Admin-Modulen (Fatal Error im Fehlerpfad) | 🟠 | ✅ 3.5.0 |
| FUN-28 | `MailSettingsModule`: Aktionsergebnis Objekt statt Array, Zusatzfelder der Queue/Graph-Tests gingen verloren | 🟡 | ✅ 3.5.0 |
| FUN-29 | Ungültiger Regex beim Umbenennen von Ordnern (`preg_match` → `false`) erlaubte `../` und `\` | 🔴 | ✅ 3.5.0 – `UploadHandler`, `MemberController` |
| FUN-30 | `PdfService` rief nicht vorhandenes `PurifierService::sanitize()` auf | 🟠 | ✅ 3.5.0 – `purify(…, 'hub')` |
| FUN-31 | `TranslationService` las nur den Altschlüssel `setting_language` | 🟡 | ✅ 3.5.0 |
| FUN-32 | `upsertSetting()` der Marketplaces prüfte `PDOStatement === 0` (nie wahr) | 🟡 | ✅ 3.5.0 – `INSERT … ON DUPLICATE KEY UPDATE` |
| FUN-33 | Hub-Sites: ungültiges Karten-JSON konnte bestehende Karten verwerfen | 🟡 | ✅ 3.5.0 |
| FUN-34 | Customizer-Optionen (u. a. `content_layout`) werden gespeichert, aber im Theme nicht gelesen | ⚪ | Empfehlung OFFEN-17 |

#### Verweise und Best Practice

| ID | Befund | Schwere | Status |
|---|---|---|---|
| REF-12 | Verwaiste Dateien `CMS/db-`, `CMS/getPrefix`, `CMS/prefix` (leer), `CMS/default.php` (Hoster-Platzhalter) | 🟡 | ✅ 3.5.0 – entfernt |
| REF-13 | Upgrade-Links in Abo-Hinweisen zeigten auf `/admin/subscriptions` | 🟡 | ✅ 3.5.0 – `/member/subscription` |
| REF-14 | 37 tote private Methoden (Kern, Admin-Module), teils mit Aufrufen nicht vorhandener Methoden | 🟡 | ✅ 3.5.0 – entfernt |
| REF-15 | `RELEASE/365CMS-3.5.0-SHA256SUMS.txt` von `README-3.5.0.md` referenziert, aber per `.gitignore` ausgeschlossen | 🟡 | ✅ 3.5.0 |
| BP-01 | `fputcsv`/`str_getcsv` ohne Escape-Argument (Deprecation ab PHP 8.4) | ⚪ | ✅ 3.5.0 |
| BP-02 | `StatusService`: `disk_*_space()` = `false` → TypeError/Division durch null | 🟡 | ✅ 3.5.0 |
| BP-03 | `AnalyticsService::getCpuUsage()` blockierte den Request mit `sleep(1)`, Division durch null möglich | 🟡 | ✅ 3.5.0 – 200 ms Messfenster, Guards |
| BP-04 | Debug-Panel mit Inline-`onclick` (CSP), Editor-Vorschau mit `javascript:void(0)` | ⚪ | ✅ 3.5.0 |
| BP-05 | `SeoMetaRepository` nutzte `getPdo()` am `DatabaseInterface` vorbei; doppelte Array-Schlüssel in `admin/views/pages/edit.php`; UTF-8-BOM in `cms-importer/admin/page.php` | ⚪ | ✅ 3.5.0 |
| DOC-01 | `README.md` und `DOC/` nannten `3.4.00` bzw. „Changelog bis 3.4.xx“ als aktuelle Version | 🟡 | ✅ 3.5.0 – überall `3.5.0`; die Changelog-Abschnitte 3.4.01–3.4.25 bleiben als Historie erhalten |

#### Performance

| ID | Befund | Schwere | Status |
|---|---|---|---|
| PERF-12 | Sprachverfügbarkeit von Beiträgen über `CHAR_LENGTH(TRIM(content))` (LONGTEXT-Scan) in Archiv- und Tag-Abfragen | 🟡 | teilweise – Tag-Übersicht einmal je Request; Rest Empfehlung OFFEN-14 |
| PERF-13 | `SeoAnalysisService::getSetting()` mit eigener Abfrage je Schlüssel (~20 je Beitragsseite) | 🟠 | ✅ 3.5.0 – Options-Cache; SEO-Meta je Inhalt einmal pro Request |
| PERF-14 | Unbekannte `/tag/…`-URL lud alle getaggten Beiträge mit `p.*` (Bot-Last) | 🟠 | ✅ 3.5.0 – Legacy-Fallback nur bei leerer Tag-Tabelle |
| PERF-15 | `ThemeManager::hasUnsafeCode()` tokenisierte die ~90 KB `functions.php` bei jedem Request | 🟡 | ✅ 3.5.0 – Ergebnis je Dateiinhalt (SHA-256) in `cache/` |
| PERF-16 | Startseite fragte im Landing-Modus alle Blog-Daten ab; Tag-Wolke lud alle `posts.tags` | 🟡 | ✅ 3.5.0 |
| PERF-17 | Einstellungen am Options-Cache vorbei (`CmsAuthPageService`, Schriften in `Bootstrap`, `ThemeManager::loadSettings`) | 🟡 | ✅ 3.5.0 für Auth-Seiten und Schriften; `ThemeManager` (Präfix-Abfrage) Empfehlung OFFEN-15 |
| PERF-18 | `SiteTableRepository`: `SHOW COLUMNS` je Instanz, `JSON_EXTRACT … OR table_slug` ohne Index | ⚪ | Empfehlung OFFEN-16 |
| PERF-19 | `ThemeCustomizer::setTheme()` lud das bereits geladene Theme erneut | ⚪ | ✅ 3.5.0 |
| PERF-20 | Fallback-Template fragte die nicht vorhandene Spalte `is_sticky` ab (Fehlerabfrage je Aufruf) | ⚪ | ✅ 3.5.0 |
| PERF-21 | Aufrufzähler schrieb auch bei HEAD, Prefetch und Crawlern | 🟡 | ✅ 3.5.0 |
| PERF-22 | Plattform-Check dekodierte bei jedem Request sechs `composer.json`; PhotoSwipe-Prüfung doppelt; redundante Indizes | ⚪ | ✅ 3.5.0 für den Plattform-Check; Rest Empfehlung OFFEN-18 |

#### GUI und Barrierefreiheit

| ID | Befund | Schwere | Status |
|---|---|---|---|
| GUI-08 | Tag-Links über den Namen statt Slug (404 bei eigenem Slug/`slug_en`), Startseite verlinkte Tags auf die Suche | 🟠 | ✅ 3.5.0 – `meridian_tag_link_data()` |
| GUI-09 | Fallback-Template: Newsletter-Formular auf nicht vorhandene Route ohne CSRF, Demo-Tags `href="#"`, englische Monatsnamen | 🟡 | ✅ 3.5.0 |
| GUI-10 | Newsletter-Widgets ohne Beschriftung; Sidebar-Widget auf nicht vorhandene Route | 🟡 | ✅ 3.5.0 – `aria-label`, Weiterleitung zur Registrierung mit vorbelegter E-Mail |
| GUI-11 | Editor-Vorschau-Link mit `javascript:`-URL | ⚪ | ✅ 3.5.0 |
| GUI-12 | ASCII-Umlaute und Mischsprache („Logout“, „Ue…“) in Oberflächentexten | ⚪ | ✅ 3.5.0 |
| GUI-13 | Icon-Buttons/Dropdown-Toggles ohne `type` und zugänglichen Namen | 🟡 | ✅ 3.5.0 – 22 Stellen |
| GUI-14 | Filter-, Such- und Auswahlfelder ohne Label-Verknüpfung | 🟡 | ✅ 3.5.0 – ~140 `for`/`id`, ~55 `aria-label` |
| GUI-15 | Footer-Fallback mit festen Links auf `/about`, `/impressum` ohne Prüfung | ⚪ | ✅ 3.5.0 – nur vorhandene Seiten |
| GUI-16 | Öffentlich abrufbarer Prototyp `themes/cms-default/meridan.html`; ungenutztes `partials/home-blog.php` | ⚪ | ✅ 3.5.0 – entfernt |

Kein Befund nach Prüfung:

- Der Status `private` für Beiträge existiert (`PostsModule`). Die Prüfung in `ThemeRouter` ist also korrekt.
- Anker hinter Überschriften mit HTML-Kommentar (`DOC/assets/README.md`) funktionieren auf GitHub.

### Empfehlungen (ohne Funktionsfehler)

| ID | Bereich | Empfehlung |
|---|---|---|
| OFFEN-14 | Performance | Die Sprachverfügbarkeit (`content_en` befüllt) als persistierte Spalte oder generierte Spalte mit Index führen, statt `CHAR_LENGTH(TRIM(content))` je Abfrage zu berechnen. |
| OFFEN-15 | Performance | `ThemeManager::loadSettings()` (Präfix-Abfrage `color_%`/`font_%`/`site_%`) auf feste Schlüssel über `OptionStore::getMany()` umstellen. |
| OFFEN-16 | Performance | `site_tables.table_slug` im Schema garantieren, dann `SHOW COLUMNS` und den `JSON_EXTRACT`-Fallback entfernen. |
| OFFEN-17 | Funktion | Ungenutzte Customizer-Optionen (`content_layout` u. a.) im Theme auswerten oder aus `config.php` entfernen. |
| OFFEN-18 | Performance | Die PhotoSwipe-Asset-Prüfung nur einmal auswerten und redundante Indizes (Präfix eines zusammengesetzten Index) prüfen. |
| OFFEN-19 | Betrieb | API-Clients nach dem Update auf 3.5.0 neu anmelden lassen. Tokens ohne `pwf` werden abgelehnt (SEC-30). |

### Hinweise für Betreiber

- **API:** Nach dem Update müssen sich alle Clients mit Bearer-Token ein neues Token holen (`POST /api/v1/auth/token`). Eine spätere Passwortänderung entwertet die Tokens des Kontos.
- **Importer:** Das Plugin `cms-importer` funktioniert erst mit 3.5.0 wieder (Beitrags- und Seitenimport, Vorschau).
- **Cache:** Neue Merker unter `CMS/cache/`:
  - `theme_scan_*.ok` für den Theme-Scan
  - `platform_ok_*.flag` für den Plattform-Check

  Beide werden bei Dateiänderungen automatisch neu erzeugt. Wer sie löscht, erzwingt eine neue Prüfung.
- **Aufrufzähler:** HEAD-Anfragen, Prefetch sowie Crawler und Monitoring (User-Agent) zählen nicht mehr. Die Werte steigen daher langsamer als bisher.
