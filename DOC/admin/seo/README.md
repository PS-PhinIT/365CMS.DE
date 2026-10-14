# 365CMS – Projektdokumentation | Abschnitt: Admin – SEO

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **SEO**. The group is controlled by the core module `seo` (`/admin/modules`); all pages require `manage_settings` (analytics also accepts `view_analytics`).

## Deutsch

| Menüpunkt | Route | Dokument |
|---|---|---|
| SEO-Dashboard | `/admin/seo-dashboard` | [SEO.md](SEO.md) |
| Analysen | `/admin/analytics` | [ANALYTICS.md](ANALYTICS.md) |
| SEO-Prüfung | `/admin/seo-audit` | [SEO.md](SEO.md#seiten-und-aktionen) |
| Meta-Daten | `/admin/seo-meta` | [SEO.md](SEO.md#meta-daten-adminseo-meta) |
| Soziale Medien | `/admin/seo-social` | [SEO.md](SEO.md#social-adminseo-social) |
| Strukturierte Daten | `/admin/seo-schema` | [SEO.md](SEO.md#strukturierte-daten-adminseo-schema) |
| Sitemap & robots.txt | `/admin/seo-sitemap` | [SEO.md](SEO.md#sitemap-adminseo-sitemap) |
| Technisches SEO | `/admin/seo-technical` | [SEO.md](SEO.md#technisches-seo-adminseo-technical) |
| Weiterleitungen | `/admin/redirect-manager` | [REDIRECTS.md](REDIRECTS.md) |
| 404-Monitor | `/admin/not-found-monitor` | [REDIRECTS.md](REDIRECTS.md#404-monitor-adminnot-found-monitor) |

Die Alt-Route `/admin/seo` leitet auf `/admin/seo-dashboard` um. Alle SEO-Seiten teilen sich die Unternavigation `views/seo/subnav.php`.

### Öffentliche SEO-Endpunkte

| URL | Inhalt |
|---|---|
| `/sitemap.xml` | XML-Sitemap (+ optional Bild-/News-Sitemap) |
| `/robots.txt` | aus der SEO-Suite gepflegt |
| `/sitemap` | HTML-Sitemap des Themes |
| `/feed` | RSS-Feed der Beiträge |
| `/<indexnow-key>.txt` | IndexNow-Schlüsseldatei |
| `/security.txt`, `/.well-known/security.txt` | Sicherheitskontakt |

### Verwandte Dokumente

- KI-gestützte SEO-Texte: [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md)
- SEO-Felder im Editor: [../pages-posts/PAGES.md](../pages-posts/PAGES.md)
- Cookie-Einwilligung für Tracking: [../legal/COOKIES.md](../legal/COOKIES.md)
