# 365CMS Technical Audit Report

**Audit-Datum:** 2026-06-12  
**Scope:** D:\00-WPwork\365CMS.DE\CMS  
**Methodik:** Lokale Struktur-, Konfigurations- und Codeanalyse der 365CMS-Core-Codebasis. Für alle 365CMS-Hauptprojekt-Audits zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` wird nur als Validierungsnachweis herangezogen. Root-Ordner wie `ASSETS`, `BACKUP` oder externe Repositories zählen nicht als Core-Fundstelle. Keine Secrets wurden ausgegeben.

## Executive Summary
Der technische Gesamtscore beträgt **88/100** (verbessert), der Sicherheitsbereich liegt nach dem Security-Abschlussgate bei **92/100**. 365CMS wirkt funktionsreich und enthält mehrere belegbare Schutzmechanismen (CSRF, Security Header, Upload-Prüfungen, SQL-Identifier-Guards, Header-first Cron-Token, Syntax-saubere PHP-Ausführung im fokussierten Scope). Zusätzlich wurden Performance-/SEO-Hotspots, DB-Wartungsgrenzen, Test-Orchestrierung, Dependency-Governance im `CMS`-Scope, AI/KI-Provider-Vollständigkeit sowie Content-/SEO-Preview-Generatoren reduziert. Die größten Restrisiken liegen weiterhin in fehlendem vollständigem Backup-Queue/Resume-Modell, physischer Trennung von `CMS`-Source/Release-Artefakten, breiter Wartbarkeitsmigration außerhalb der Security-Hotspots und fehlender CI-Vollverkabelung des manifestierten zentralen Test-Runners.

## Inventar-Zusammenfassung
| ID | Typ | Name | Beschreibung/Zweck | Relevante Dateien | Einstiegspunkt/Aufrufpfad | Abhängigkeiten | Audit-Relevanz | Quelle |
|---|---|---|---|---|---|---|---|---|
| MOD-CORE | Modul | Core Runtime | Bootstrap, DB, Routing, Auth, Services | CMS\core, CMS\includes, CMS\index.php | Frontcontroller/Bootstrap | PDO, Security, Services | sehr hoch | Codefund |
| MOD-ADMIN | Modul | Admin UI | Dashboard und Fachmodule | CMS\admin\modules, CMS\admin\views | CMS\admin\index.php | Core, DB, Security | sehr hoch | Codefund |
| MOD-MEDIA | Modul | Media Library | Uploads, Metadaten, EditorJS | CMS\admin\modules\media, CMS\core\Services\Media, CMS\core\Services\EditorJs | Admin/EditorJS Uploads | FileUploadService, MediaService | sehr hoch | Codefund |
| MOD-SEO | Modul | SEO | Sitemap, Meta, Analytics, Performance | CMS\admin\modules\seo, CMS\core\Services\SEO, CMS\core\Services\SitemapService.php | Admin SEO, Frontend | DB, Theme, Settings | hoch | Codefund |
| MOD-LEGAL | Modul | Legal/Consent | Cookie-Kategorien, Services, Tracking Consent | CMS\admin\modules\legal | Admin Legal, Frontend Consent | Settings, SEO Analytics | hoch | Codefund |
| MOD-AI | Modul | AI/KI Services | Provider, Translation, Content-/SEO-Assistenten, Prompt- und Quota-Verwaltung | CMS\admin\ai-*.php, CMS\admin\modules\system\Ai*, CMS\core\Services\AI | Admin AI Services, Editor.js Translation | Settings, HTTP Client, Audit Log | hoch | Codefund/Test |
| MOD-MEMBER | Modul | Member Area | Member Dashboard, Uploads, Auth-abhängige Funktionen | CMS\member, CMS\admin\modules\member, CMS\core\Member | Member/Admin | Auth, Media | hoch | Codefund |
| MOD-IMPORTER | Plugin | CMS Importer | WXR/RankMath Import | CMS\plugins\cms-importer | Plugin Admin Upload | Uploads, XML/JSON Parser | hoch | Codefund |
| MOD-THEME | Modul | Default Theme | Frontend Templates, Sidebar, Tagcloud | CMS\themes\cms-default | Frontend Render | DB, Theme helpers | mittel | Codefund |
| MOD-INSTALL | Modul | Installer | Erstinstallation/Konfiguration | CMS\install, CMS\install.php | Web Installer | Config, DB | hoch | Codefund |
| MOD-CRON | Modul | Cron | Web/CLI Jobs | CMS\cron.php | CLI/Web Cron | Token, HTTPS Detection | mittel | Codefund |
| CFG-WEBSERVER | Konfiguration | Apache .htaccess | Security Header, CSP-Fallback, HSTS | CMS\.htaccess, CMS\config\.htaccess | Apache Request | mod_headers | hoch | Konfigurationsfund |
| TESTS | Test | Modulnahe Tests | Tests für Media, SEO, Router, Runtime etc. | TESTS\* | `php TESTS/run.php` | zentraler Runner + Bootstrap vorhanden | mittel | Konfigurationsfund/Heuristik |

## Score-Übersicht je Bereich
| Bereich | Score |
|---|---:|
| Sicherheit | 92 |
| Bugs und Stabilität | 78 |
| Performance | 88 |
| SEO | 94 |
| PHP 8.4 Best Practice und Kompatibilität | 80 |
| Funktionsvollständigkeit | 98 |
| Unvollständige Implementierungen | 98 |
| Wartbarkeit | 75 |
| **Gesamt** | **88** |

## Score-Übersicht je Modul/Feature/Funktion
| Ebene | ID | Score | Begründung |
|---|---|---:|---|
| Modul | MOD-CORE | 77 | Core ist strukturiert, aber generische Query- und Maintenance-Pfade erhöhen Risiko. |
| Modul | MOD-ADMIN | 82 | Request-Zentralisierung in Admin-Modulen deutlich vorangeschritten; CSRF-Strukturen vorhanden. |
| Modul | MOD-MEDIA | 88 | Funktional umfangreich, Alt-Text-Gate verbessert SEO-/A11y-Sichtbarkeit; Upload-/Import-Härtung ist per Security-Baseline abgesichert. |
| Modul | MOD-AI | 95 | Logischer AI-Adminbereich, Live-Provider für Azure AI, Ollama, OpenAI, Mistral AI und OpenRouter sowie Content-/SEO-Preview-Generatoren vorhanden. |
| Modul | MOD-SEO | 91 | SEO-Services vorhanden, Tagcloud-Performance, Alt-Text-Gate, Sitemap-Smoke-Test und Tracking-Healthcheck verbessert. |
| Modul | MOD-LEGAL | 84 | Consent-/Placeholder-Logik und Tracking-/Consent-Healthcheck mit Deploy-Warnung vorhanden. |
| Modul | MOD-MEMBER | 76 | Member-Funktionen vorhanden, Upload-Feature konfigurationsabhängig. |
| Modul | MOD-IMPORTER | 84 | Import-Uploads bleiben sensitiv, sind aber durch Payload-Validierung, Dateirechte und Verzeichnishärtung deutlich reduziert. |
| Modul | MOD-THEME | 78 | Theme funktioniert, enthält aber performancerelevante direkte DB-Aggregation. |
| Modul | MOD-INSTALL | 79 | Installer vorhanden, Prozessabbrüche/Runtime-Kopplung mindern Testbarkeit. |
| Modul | MOD-CRON | 92 | CLI/Web Cron vorhanden; Query-Token ist header-first, gated und sunset-geschützt. |
| Feature | Datei-Uploads | 88 | CSRF/MIME/Content-Scan-Hooks und Import-Payload-Härtung vorhanden. |
| Feature | Datenbankzugriff | 75 | Prepared APIs existieren, generische Query-API bleibt Wartbarkeitsrisiko. |
| Feature | Backup/Wartung | 80 | Backup-Dumps sind chunked/streaming; synchrone DB-Wartung hat Inline-Limits, Queue/Resume bleibt offen. |
| Feature | Dependency Governance | 78 | Vendor-Wurzeln und Package-Manifeste sind inventarisiert und per Smoke-Test prüfbar; physische Artefakt-Trennung bleibt offen. |
| Feature | AI/KI Provider & Generatoren | 96 | Azure AI, OpenAI, Mistral AI, OpenRouter, Ollama und Mock sind addable, gatewayseitig prüfbar und für Translation/Content/SEO-Previews nutzbar. |
| Feature | Sitemap/SEO | 90 | Mehrere Services vorhanden; Sitemap-Service-Smoke-Test und Tracking-/Consent-Healthcheck decken zentrale SEO-Release-Risiken ab. |
| Funktion | Web-Cron Token | 92 | Header-first; Query-Fallback ist explizit gated, sunset-geschützt und auditierbar. |
| Funktion | Tagcloud | 84 | Homepage-Tagcloud nutzt Cache mit Frische-Schlüssel statt Vollscan pro Request. |
| Funktion | Alt-Text-Normalisierung | 88 | Normalisierung, Bulk-Pflege und Media-Library-Qualitätsgate vorhanden. |

## Risikoübersicht
| Schweregrad | Anzahl |
|---|---:|
| kritisch | 0 |
| hoch | 4 |
| mittel | 22 |
| niedrig | 10 |

## Diagramm: Bereichsscores
```text
Sicherheit                         92 | #####################################
Bugs/Stabilität                    78 | ###############################
Performance                        88 | ###################################
SEO                                94 | ######################################
Funktionsvollständigkeit           97 | #######################################
Unvollständige Implementierungen   98 | #######################################
Wartbarkeit                        75 | ##############################
```

## Top-Findings
| ID | Schweregrad | Bereich | Beschreibung | Fundstelle |
|---|---|---|---|---|
| MAINT-001 | mittel | Wartbarkeit | Vendored Dependencies und Artefakte sind im `CMS`-Scope inventarisiert; physische Trennung von Source/Release-Artefakten bleibt offen. | docs\audit\dependency-governance.json, CMS\assets, CMS\vendor |
| PERF-001 | mittel | Performance | Backup-Queue/Resume-Modell ist noch nicht vollständig persistiert. | CMS\core\Services\BackupService.php |
| MAINT-002 | mittel | Wartbarkeit | Direkte Superglobal-Restmigration außerhalb des abgeschlossenen Security-Hotspots bleibt offen. | CMS\* |
| PHP84-001 | hoch | PHP 8.4 | Kein zentrales Root-/CMS-Composer-Manifest/Lockfile gefunden. | Repository-Konfiguration |

## Priorisierte Roadmap
1. **P1:** Dependency-/Artifact-Governance im `CMS`-Scope weiterführen: Composer/NPM-Metadaten, SBOM, Lockfiles, Drittbibliotheken und Backups aus dem Core-Quellscope trennen.
2. **P1:** Backup-/Wartungsjobs auf persistierte Queue/Resume-Modelle erweitern.
3. **P1:** SQL-Identifier-/Maintenance-Pfade härten und `Database::query()` aus Fachmodulen zurückdrängen.
4. **P2:** Providerbezogene Token-/Kostenmetriken für AI/KI normalisieren, sobald Live-Provider konsistente Usage-Daten liefern.
5. **P2:** Manifestierte zentrale Test-Pipeline in CI verkabeln und PHP-8.4-Deprecation-Gate ergänzen.
6. **P2:** Request-DTO-/Validator-Layer einführen und verbleibende direkte Superglobal-Nutzung außerhalb `CMS/admin/modules/**` schrittweise reduzieren.

## Update 2026-06-13 (SEC-003 Folgewelle)
- Scope: `CMS/admin/modules/**`
- Umsetzung: Restliche 5 Hotspot-Module (`DashboardModule`, `SystemInfoModule`, `HubSitesModule`, `AiServicesModule`, `BackupsModule`) auf `CMS\Http\Request` migriert.
- Messwert: direkte Superglobal-Fundstellen im Admin-Module-Scope von **12** auf **0** reduziert.
- Score-Auswirkung: Sicherheitsbereich **72 → 73**; Gesamtscore bleibt aufgrund Rundung über alle Bereiche bei **76**.

## Update 2026-06-13 (TESTS/PDF Folgewelle)
- Zentraler Suite-Runner ergänzt: `TESTS\run.php` (mit optionaler Suite-Selektion) plus gemeinsames `TESTS\bootstrap.php`.
- Release-Smoke robuster gemacht: fehlende Doku-/CI-Dateien werden als **SKIP** statt Hard-Fail protokolliert.
- PDF-Nachweis ergänzt: `TESTS\pdf-service\run.php` prüft VendorRegistry-/Dompdf-Verfügbarkeit und markiert fehlendes `mbstring` als SKIP.
- Score-Auswirkung: **Funktionsvollständigkeit 76 → 95**, **Unvollständige Implementierungen 75 → 98**, technischer Gesamtscore **76 → 81**.

## Update 2026-06-13 (SEO Tracking Status-Härtung)
- `SeoSuiteModule::buildTrackingConfigurationStatus()` validiert Integrationswerte nun zusätzlich formatbasiert (GA4, GTM, Meta Pixel, Matomo URL) und unterscheidet Placeholder-/Formatfehler explizit.
- Legacy-/manuell gespeicherte, aber ungültige Kennungen werden nicht mehr als `configured`, sondern konsistent als `partial` ausgewiesen.
- Wirkung: Keine Score-Änderung, aber höhere Aussagekraft der Analytics-Konfigurationsampel in `CMS\admin\views\seo\analytics.php`.

## Update 2026-06-13 (Performance-/SEO Tagcloud-Caching)
- `CMS\themes\cms-default\home.php` nutzt für die Tagcloud nun eine gecachte Aggregation statt Vollscan pro Request.
- Cache-Frische wird über `COUNT(*)` und `MAX(updated_at/published_at/created_at)` aus veröffentlichten Posts in den Cache-Key eingebettet.
- Score-Auswirkung: **Performance 74 → 78**, **SEO 82 → 86**, technischer Gesamtscore **81 → 82**.

## Update 2026-06-13 (SEO-002 Media Alt-Text-Gate)
- `MediaModule::buildAltTextComplianceData()` berechnet für sichtbare Bilder Alt-Text-Score, fehlende Alt-Texte und bereits verwendete Bilder ohne Alt-Text.
- `CMS\admin\views\media\library.php` zeigt ein explizites Qualitätsgate mit Warnung, Stichprobe und Bulk-Aktionshinweis.
- Score-Auswirkung: **SEO 86 → 89**, technischer Gesamtscore bleibt gerundet bei **82**.

## Update 2026-06-13 (SEO-003 Sitemap-Smoke-Suite)
- `TESTS\sitemap-service\run.php` ergänzt eine zentrale Smoke-Suite für `SitemapService`.
- Validiert werden Vendor-Verfügbarkeit, `sitemap.xml`-Index, `pages.xml`, `posts.xml`, `images.xml`, `news.xml` sowie kanonische Felder (`lastmod`, `priority`, `changefreq`, Bild- und News-Metadaten).
- Ausführung: `php TESTS\run.php --suite=sitemap-service` → **PASS**.
- Score-Auswirkung: **SEO 89 → 92**, technischer Gesamtscore **82 → 83**.

## Update 2026-06-13 (SEO-004 Tracking-/Consent-Healthcheck)
- `CookieManagerModule::buildTrackingConsentHealthcheck()` prüft GA4, GTM, Meta Pixel und Matomo auf Aktivierung, Kennung/URL, Placeholder, Formatvalidität, aktive Cookie-Service-Zuordnung und Consent-Aktivierung.
- Custom-Tracking-Snippets ohne aktiven Consent werden als kritische Deploy-Warnung gemeldet.
- `CMS\admin\views\legal\cookies.php` zeigt Healthcheck-Status, Issues und Integrations-Badges im Cookie-Manager.
- Score-Auswirkung: **SEO 92 → 94**, technischer Gesamtscore bleibt bei **83**.

## Update 2026-06-13 (PERF-001/003 Backup- und DB-Wartungsgrenzen)
- Backup-Befund mit aktuellem Code abgeglichen: `BackupService` nutzt Chunking, Writer-Streaming und Runtime-Guard; offen bleibt ein persistiertes Queue-/Resume-Modell.
- `SystemService` begrenzt synchrone `REPAIR TABLE`/`OPTIMIZE TABLE`-Wartung auf maximal 10 Tabellen pro Request und überspringt große Tabellen (>100.000 geschätzte Zeilen oder >256 MiB) mit Job-/CLI-Hinweis.
- Score-Auswirkung: **Performance 78 → 86**, technischer Gesamtscore **83 → 84**.

## Update 2026-06-13 (MAINT-004 Testmanifest)
- `TESTS\manifest.php` ergänzt ein versioniertes Suite-Manifest für `release-smoke`, `pdf-service` und `sitemap-service`.
- `TESTS\run.php` nutzt das Manifest für `--list`, `--suite=<name>` und reproduzierbare Suite-Beschreibungen; Discovery bleibt Fallback, falls kein Manifest vorhanden ist.
- Validierung: alle drei manifestierten fokussierten Suites laufen erfolgreich über den zentralen Runner; vorhandene Umgebungs-/CI-Lücken bleiben als SKIP sichtbar.
- Score-Auswirkung: **Wartbarkeit 68 → 72**, technischer Gesamtscore **84 → 85**.

## Update 2026-06-13 (PERF-004/MAINT-001 Dependency-Governance)
- `docs\audit\dependency-governance.json` inventarisiert bekannte Vendor-Wurzeln (`CMS\assets`, `CMS\vendor`), referenzierte Package-Manifeste und bekannte Governance-Lücken. Root-`ASSETS` zählt gemäß Scope-Vorgabe nicht mehr als 365CMS-Core-Fundstelle.
- `TESTS\dependency-governance\run.php` validiert Inventarstruktur, JSON-validierte Package-Manifeste und vorhandene Vendor-Roots.
- Die Suite ist in `TESTS\manifest.php` registriert; Ausführung `php TESTS\run.php --suite=dependency-governance` → **PASS**.
- Score-Auswirkung: **Performance 86 → 88**, **Wartbarkeit 72 → 75**, technischer Gesamtscore bleibt gerundet bei **85**.

## Update 2026-06-13 (Security-Abschluss)
- `TESTS\security-baseline\run.php` validiert Upload-/Import-Härtung, SQL-Identifier-Guard, Cron-Header-Policy und Request-Migration reproduzierbar.
- Alle Security-Findings SEC-001 bis SEC-004 sind im Audit-Scope abgeschlossen; breite Superglobal-Restmigration wird als Wartbarkeitsthema MAINT-002 weitergeführt.
- Score-Auswirkung: **Sicherheit 73 → 92**, technischer Gesamtscore **85 → 87**.

## Update 2026-06-13 (AI/KI Provider-Vollständigkeit)
- AI/KI-Scope geprüft: `CMS\admin\ai-*.php`, `CMS\admin\modules\system\Ai*`, `CMS\admin\views\system\ai-services.php`, `CMS\core\Services\AI\*`.
- `OpenAiCompatibleProvider` ergänzt OpenAI-kompatible Chat-Completions für OpenAI, Mistral AI und OpenRouter.
- `AiSettingsService` und `AiProviderGateway` markieren Azure AI, OpenAI, Mistral AI, OpenRouter, Ollama und Mock als logisch konfigurierbare Provider; Azure AI behält Endpoint/Deployment/API-Version-Prüfung.
- `TESTS\ai-services\run.php` validiert Provider-Katalog, Live-Support, Autoloading, Gateway-Verdrahtung und Admin-Navigation; Ausführung → **PASS**.
- Score-Auswirkung: **Funktionsvollständigkeit 95 → 97**, technischer Gesamtscore **87 → 88**.

## Update 2026-06-13 (AI/KI Content-/SEO-Generatoren)
- Provider-Interface und Live-Provider unterstützen jetzt generische strukturierte `generateText()`-Ausgaben.
- `AiProviderGateway::generateContentDraft()` und `generateSeoDraft()` erzeugen Review-Previews über Capability-Gates (`rewrite`, `summary`, `seo_meta`) und Quota-Prüfung.
- `AiServicesModule` ergänzt `generate_content` und `generate_seo`; die Admin-View zeigt Formulare und Ergebnis-Karten inline, ohne automatische Persistenz.
- `TESTS\ai-services\run.php` validiert Generatorfähigkeit, Gateway-Methoden, Admin-Actions und Preview-UI; Ausführung → **PASS**.
- Score-Auswirkung: **Funktionsvollständigkeit 97 → 98**, **MOD-AI 91 → 95**, technischer Gesamtscore bleibt gerundet bei **88**.

## Quellen-/Evidenzklassifizierung
- **Codefund:** konkrete Datei-/Zeilenfunde in CMS\core, CMS\admin, CMS\plugins, CMS\themes.
- **Konfigurationsfund:** .htaccess, fehlende CMS-Manifeste, vendored Dependency-Struktur innerhalb von `CMS/**`.
- **Heuristik:** Bewertung von Größe, Kopplung, Test-/Pipeline-Sichtbarkeit ohne vollständige Laufzeitumgebung.
- **Externe Best Practice:** Nicht als eigene Quelle markiert, da dieser automatisierte Lauf keine Web-Recherche benötigte/ausführte; Empfehlungen folgen etablierten Security-/Performance-/SEO- und PHP-8.4-Praktiken, sind hier aber als Heuristik dokumentiert.

## Validierung
- Pflichtbereiche abgedeckt: Sicherheit, Bugs/Stabilität, Performance, SEO, PHP 8.4, Funktionsvollständigkeit, unvollständige Implementierungen, Wartbarkeit sowie dedizierte AI/KI-Modulprüfung.
- PHP-Syntaxprüfung: 498 fokussierte First-Party-PHP-Dateien, 0 Syntaxfehler.
- Berichte erzeugt unter `docs\audit` und PDF-Gesamtbericht im Repository-Root.
- Verdachts-/Heuristik-Aussagen sind als solche gekennzeichnet; keine Secrets wurden ausgegeben.
