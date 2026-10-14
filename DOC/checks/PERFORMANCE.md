# 365CMS – Projektdokumentation | Abschnitt: Audit 2026-10-03 – Geschwindigkeit

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | Übersicht: [README.md](README.md)

## English (summary)

No severe bottleneck was found in the request path. Recent releases already removed render-blocking scripts (3.4.11) and throttled the OPcache deploy scan. Fixed in this audit: N+1 setting queries in the default theme (cookie banner, contact block) and a duplicated font-setting query. Fixed in 3.4.13: a central per-request options cache (`OptionStore`), schema v23 with a composite index for blog listings and without a redundant settings index, and removal of an unbounded unused helper.

## Deutsch

### Geprüft und unauffällig

| Prüfpunkt | Ergebnis |
|---|---|
| Render-blockierende Skripte | Seit 3.4.11 lädt das Frontend DOMPurify per `defer`, Trusted-Types-Runtime inline mit Nonce. |
| OPcache-Warmup | Deploy-Signatur wird seit 3.4.11 höchstens alle 10 Minuten berechnet. |
| Theme-/Plugin-Ermittlung | `scandir()` in `ThemeManager::getActiveThemeSlug()` nur, wenn der aktive Theme-Ordner fehlt; Plugin-Bootstrap lädt nur aktive Plugins. |
| Rate-Limit-Tabelle | `login_attempts` hat `idx_ip_action` und `idx_time`; Bereinigung mit Wahrscheinlichkeit 1:20 pro Prüfung. |
| Kommentar-, Such- und Archivlisten | Paginierung über begrenzte `LIMIT/OFFSET`-Werte; Suche mit Kandidatenlimit (`SiteSearchService::CANDIDATE_LIMIT`). |
| HTTP-Caching | `Router::applyRequestCacheHeaders()` und `sendConditionalPublicPageHeaders()` setzen Cache-/ETag-Header für öffentliche Seiten. |

### Behobene Befunde

#### PERF-01 🟡 N+1-Abfragen im Standard-Theme

- `MeridianCMSDefaultTheme::outputCookieBanner()` (`CMS/themes/cms-default/functions.php`) las vier Banner-Texte mit vier Einzelabfragen – bei aktivem Legacy-Banner auf **jeder** Seite.
- Der Kontaktblock in `CMS/themes/cms-default/contact.php` las vier Kontaktfelder einzeln.

**Fix:** jeweils eine Abfrage `WHERE option_name IN (?, ?, ?, ?)`.

#### PERF-02 ⚪ Doppelte Font-Abfrage

`isLocalFontsEnabled()` wurde pro Seitenaufruf zweimal ausgeführt (`outputLocalFonts()` und `shouldLoadExternalFonts()`). **Fix:** Ergebnis wird pro Request in der Theme-Instanz gehalten.

### Mit 3.4.13 behoben

#### PERF-03 🟠 Zentraler Options-Cache `OptionStore`

Neu: `CMS\Services\OptionStore` (`CMS/core/Services/OptionStore.php`).
- Erster Zugriff lädt alle Optionen mit `autoload = 1` in **einer** Abfrage; andere Schlüssel werden einzeln nachgeladen und – auch als „nicht vorhanden“ – für den Request gemerkt. `getMany()` lädt fehlende Schlüssel gesammelt per `IN (…)`.
- `CMS\Database::prepare()`/`query()` leeren den Cache automatisch, sobald ein `INSERT`/`UPDATE`/`DELETE`/`REPLACE` die Tabelle `settings` betrifft. Ausnahme: Schreibzugriffe direkt über `getPdo()->exec()` (nur Migrationen).
- `get_option()` nutzt den Store und liefert jetzt auch Optionen mit `autoload = 0` (vorher immer `$default`).
- Umgestellt: `Router` (Wartungsmodus), `PublicRouter` (Health), `ThemeManager` (aktives Theme, Menüs, eigene Menü-Positionen), `PluginManager` (aktive Plugins), `Bootstrap` (lokale Fonts), `TableOfContents`, `SubscriptionManager`, `CoreModuleService`, `CookieConsentService`, `TranslationService`, `AssetOptimizerService`, `MediaDeliveryService`, `PermalinkService` sowie das Standard-Theme (`meridian_option()`/`meridian_options()` mit Fallback für ältere Cores).

Zusätzlich gemerkt bzw. umgestellt: `CmsAuthPageService::getSettings()` (lief bis zu 7× pro Request), `SettingsService::get()`, `SEO\SeoSettingsStore`, `IndexingService`, `ThemeCustomizer`. Der Store kennt nach der ersten Abfrage auch die Namen aller Nicht-Autoload-Optionen; nicht existierende Schlüssel kosten keine Abfrage mehr.

**Gemessen** (MariaDB General Log, gleiche Datenbank, PHP-Entwicklungsserver, Median aus 15 Aufrufen):

| Seite | Abfragen gesamt `main` → 3.4.13 | davon `cms_settings` | Antwortzeit `main` → 3.4.13 |
|---|---|---|---|
| `/` | 122 → 58 | 74 → 10 | 54 ms → 36 ms |
| `/blog` | 125 → 63 | 72 → 10 | 57 ms → 43 ms |
| `/cms-login` | 82 → 53 | 36 → 7 | – |

Mit einer entfernten Datenbank (Netzwerklatenz je Abfrage) fällt die Ersparnis entsprechend größer aus.

#### PERF-04 🟡 Composite-Index für Blog-Listen

Schema **v23**: `ALTER TABLE cms_posts ADD INDEX idx_status_published (status, published_at)` (Migration in `MigrationManager`, Neuinstallationen über `SchemaManager`).

#### PERF-05 ⚪ Redundanter Index

Schema **v23**: `ALTER TABLE cms_settings DROP INDEX idx_key`; `UNIQUE(option_name)` deckt alle Lookups ab.

### Teilweise / Hinweis

#### PERF-06 ⚪ `SELECT *` und unbegrenzte Listen

Der ungenutzte Helfer `PageManager::listPages()` (ohne `LIMIT`) wurde entfernt. Die übrigen `SELECT *`-Abfragen sind begrenzt (Einzelzeilen, `LIMIT`) und bleiben ein Hinweis für künftige Refactorings – eine pauschale Umstellung wäre ohne Laufzeittests gegen eine Datenbank zu riskant.

### Verwandte Dokumente

[README.md](README.md) · [../admin/performance/README.md](../admin/performance/README.md) · [../core/DATABASE-SCHEMA.md](../core/DATABASE-SCHEMA.md)
