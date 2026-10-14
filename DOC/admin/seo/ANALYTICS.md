# 365CMS – Projektdokumentation | Abschnitt: Admin – Analysen & Tracking

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/analytics` | **Capability:** `manage_settings` oder `view_analytics` | **CSRF-Aktion:** `admin_seo_suite` | **Core-Modul:** `seo`

## English (summary)

`/admin/analytics` is the analytics section of the SEO suite (`CMS/admin/seo-page.php` → `SeoSuiteModule` / `AnalyticsModule` → `CMS/admin/views/seo/analytics.php`). It shows first-party page views (`cms_page_views`, IP anonymised by `TrackingService`), top pages, visitors, Core Web Vitals (`cms_core_web_vitals`, collected via `POST /api/v1/analytics/web-vitals`) and feature usage. Action `save_analytics_settings` configures external tools (Google Search Console property, GA4, Matomo, Google Tag Manager, Meta Pixel) which `SeoAnalyticsRenderer` only loads after cookie consent.

## Deutsch

### Kennzahlen

| Bereich | Quelle |
|---|---|
| Seitenaufrufe heute / gestern / Woche / Monat / gesamt | `cms_page_views` über `TrackingService` / `AnalyticsService::getVisitorStats()` |
| Verlauf nach Datum, Top-Seiten, eindeutige Besucher | `TrackingService::getPageViewsByDate()`, `getTopPages()`, `getUniqueVisitors()` |
| Core Web Vitals (LCP, CLS, INP u. a.) | `cms_core_web_vitals` über `CoreWebVitalsService::getDashboardSummary()` |
| Nutzung von Admin-Funktionen | `FeatureUsageService` (z. B. aufgerufene Admin-Seiten) |

Beiträge mit Veröffentlichungsdatum in der Zukunft werden in Auswertungen nicht als veröffentlicht gezählt.

### Erfassung im Frontend

- **Seitenaufrufe:** `TrackingService::trackPageView()` speichert Seite, Slug, Titel, Benutzer-ID, Session, Referrer, User-Agent und eine **anonymisierte IP** (IPv4: letztes Oktett genullt; IPv6: nur die ersten 48 Bit).
- **Web Vitals:** `CoreWebVitalsService::renderTrackingScript()` bindet ein kleines Skript ein, das Messwerte an `POST /api/v1/analytics/web-vitals` sendet. Stichprobenrate 1–100 % (`web_vitals_sample_rate`).

### Einstellungen (`save_analytics_settings`)

| Feld | Option | Validierung |
|---|---|---|
| Search-Console-Property | `seo_analytics_gsc_property` | Freitext (Domain-/URL-Property) |
| Google Analytics 4 | `seo_analytics_ga4_id` | `G-…` |
| Matomo-URL / Site-ID | `seo_analytics_matomo_url`, `seo_analytics_matomo_site_id` | HTTPS-URL, Zahl |
| Google Tag Manager | `seo_analytics_gtm_id` | `GTM-…` |
| Meta-(Facebook-)Pixel | `seo_analytics_fb_pixel_id` | 5–20 Ziffern |
| Administratoren ausschließen | `seo_analytics_exclude_admins` | an/aus |
| „Do Not Track“ respektieren | `seo_analytics_respect_dnt` | an/aus |
| IP anonymisieren | `seo_analytics_anonymize_ip` | an/aus (GA4 `anonymize_ip`, Matomo ohne Cookies) |
| Web Vitals erfassen / Stichprobe | `seo_analytics_web_vitals_enabled`, `seo_analytics_web_vitals_sample_rate` | an/aus, 1–100 |

### Datenschutz

- Externe Dienste werden von `SeoAnalyticsRenderer` **consent-gesteuert** und CSP-konform eingebunden: Matomo und GA4 gehören zur Kategorie *Analyse*, GTM und Meta-Pixel zur Kategorie *Marketing*. Ohne Zustimmung im Cookie-Banner wird nichts geladen (siehe [../legal/COOKIES.md](../legal/COOKIES.md)).
- Die interne Seitenaufruf-Statistik arbeitet mit anonymisierten IPs. Aufbewahrung und Bereinigung alter Einträge: *Performance → Datenbank-Wartung* ([../performance/PERFORMANCE.md](../performance/PERFORMANCE.md)).
- Die Datenschutzerklärung muss die eingesetzten Dienste nennen ([../legal/LEGAL.md](../legal/LEGAL.md)).

### Verwandte Dokumente

[SEO.md](SEO.md) · [README.md](README.md) · [../dashboard/DASHBOARD.md](../dashboard/DASHBOARD.md)
