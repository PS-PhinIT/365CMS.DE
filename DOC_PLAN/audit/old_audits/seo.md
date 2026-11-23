# SEO

**Bereichsscore:** 94/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
SEO-Module, Sitemap-Services und Analytics-/Consent-Integration sind vorhanden. Der performance-nahe Tagcloud-Hotspot auf der Startseite wurde durch Caching deutlich entschärft; zusätzlich meldet die Media-Library fehlende Bild-Alt-Texte nun als explizites Qualitätsgate. Für Sitemap-/Canonical-Konsistenz existiert eine zentrale Smoke-Suite, und der Cookie-Manager zeigt jetzt einen Tracking-/Consent-Healthcheck als Deploy-Warnung.

## Score-Begründung
- Startwert 100
- SEO-001: -2 (teilweise reduziert)
- SEO-002: -2 (weitgehend reduziert)
- SEO-003: -1 (weitgehend reduziert)
- SEO-004: -1 (weitgehend reduziert)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|
| SEO-001 | Theme | Startseite | Tagcloud/Sidebar | niedrig | -2 | CMS\themes\cms-default\home.php (Tagcloud-Cache) | Codefund |
| SEO-002 | Media | Alt-Texte | Media-Library Qualitätsgate | niedrig | -2 | CMS\admin\modules\media\MediaModule.php, CMS\admin\views\media\library.php | Codefund |
| SEO-003 | SEO | Sitemap | SitemapService + Smoke-Test | niedrig | -1 | CMS\core\Services\SitemapService.php, TESTS\sitemap-service\run.php | Codefund/Test |
| SEO-004 | Legal/Analytics | Consent/Tracking IDs | Tracking-/Consent-Healthcheck | niedrig | -1 | CMS\admin\modules\legal\CookieManagerModule.php, CMS\admin\views\legal\cookies.php | Codefund |

## Umsetzungsschritte

### step-001
- **Ziel:** Performance-relevante SEO-Signale verbessern.
- **Befund:** ✅ weitgehend umgesetzt.
- **Risiko:** deutlich reduziert.
- **Technische Ursache:** durch Theme-Cache-Layer entschärft.
- **Lösungsweg:** Tagcloud wird gecacht; Frische wird über `COUNT(*)` + `MAX(updated_at/published_at/created_at)` als Cache-Key-Komponente gesteuert.
- **Betroffene Dateien:** CMS\themes\cms-default\home.php.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Cache-Service.

## Umsetzungsstand (2026-06-13)
- ✅ **SEO-001** weitgehend umgesetzt (Tagcloud-Caching aktiv)
- ✅ **SEO-002** weitgehend umgesetzt (Media-Library Alt-Text-Gate mit Score/Warnung aktiv)
- ✅ **SEO-003** weitgehend umgesetzt (Sitemap-Service-Smoke-Suite prüft Index, Teil-Sitemaps und kanonische Felder)
- ✅ **SEO-004** weitgehend umgesetzt (Cookie-Manager Tracking-/Consent-Healthcheck mit Deploy-Warnung aktiv)

### step-002
- **Ziel:** Medien-SEO messbar absichern.
- **Befund:** ✅ weitgehend umgesetzt.
- **Risiko:** deutlich reduziert; fehlende Alt-Texte erscheinen nun als Qualitätsgate in der Media-Library.
- **Technische Ursache:** durch Compliance-Statusmodell entschärft.
- **Lösungsweg:** `MediaModule::buildAltTextComplianceData()` berechnet sichtbare Bilder, fehlende Alt-Texte, verwendete Bilder ohne Alt-Texte und Score; `library.php` zeigt Warnung/Stichprobe und Bulk-Hinweis.
- **Betroffene Dateien:** CMS\admin\modules\media\MediaModule.php, CMS\core\Services\Media\*.
- **Priorität:** P2
- **Aufwand:** M
- **Abhängigkeiten:** Media-Metadatenmodell.

### step-003
- **Ziel:** Sitemap-/Canonical-Konsistenz validieren.
- **Befund:** ✅ weitgehend umgesetzt.
- **Risiko:** reduziert; Index und Teil-Sitemaps werden über eine zentrale Smoke-Suite geprüft.
- **Technische Ursache:** durch Testabdeckung für `SitemapService` entschärft.
- **Lösungsweg:** `TESTS\sitemap-service\run.php` ergänzt und über `TESTS\run.php --suite=sitemap-service` erfolgreich ausgeführt; geprüft werden Index-Referenzen, URLs, `lastmod`, `priority`, `changefreq`, Image- und News-Sitemap-Felder.
- **Betroffene Dateien:** CMS\core\Services\SitemapService.php, CMS\core\Services\SEO\SeoSitemapService.php, CMS\admin\views\seo\sitemap.php.
- **Priorität:** P3
- **Aufwand:** M
- **Abhängigkeiten:** Routing/Permalinks.

### step-004
- **Ziel:** Tracking-Konfiguration produktionssicher machen.
- **Befund:** ✅ weitgehend umgesetzt.
- **Risiko:** deutlich reduziert; Cookie-Manager meldet kritische Tracking-/Consent-Konfigurationen als Deploy-Warnung.
- **Technische Ursache:** durch konsolidierten Healthcheck entschärft.
- **Lösungsweg:** `CookieManagerModule::buildTrackingConsentHealthcheck()` prüft GA4/GTM/Meta Pixel/Matomo auf Aktivierung, Kennung/URL, Placeholder, Formatvalidität, Consent-Aktivierung, aktive Cookie-Service-Zuordnung und Custom-Tracking-Snippets; `cookies.php` zeigt Status, Issues und Integrations-Badges.
- **Betroffene Dateien:** CMS\admin\modules\legal\CookieManagerModule.php, CMS\admin\views\seo\analytics.php.
- **Priorität:** P3
- **Aufwand:** S
- **Abhängigkeiten:** Admin-Dashboard.
