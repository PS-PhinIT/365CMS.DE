# 365CMS Audit: Kontext-Slices

> **Stand:** 2026-11-23 | **Basis:** 4.2.0 / Schema v27 | **Gilt für:** [Delta-Audit](AUDIT-ABLAUF-PLAN-DELTA.md), [Vollaudit](AUDIT-ABLAUF-PLAN-VOLLAUDIT.md)
> **Katalog:** [AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md) · **Protokoll:** [AUDIT-AGENT-PROTOKOLL.md](AUDIT-AGENT-PROTOKOLL.md) · **Lokal:** [AUDIT-LOCAL-LLM.md](AUDIT-LOCAL-LLM.md)

## English (summary)

The first-party code of 365CMS is roughly 5.4 million tokens (bytes ÷ 3.5) – far more than any context window.
This file cuts it into fixed, reproducible slices of at most ~150k source tokens so that a 256k-token window still has
room for instructions, the catalog excerpt, notes and output. Each slice lists the paths, its measured size and the
work packages (AP) it primarily serves. Local models use half-slices (`a`/`b`). Data files, bundled libraries and
translation catalogs are checked by scripts, not read by a model.

## Deutsch

### 1. Budgetregel

| Fenster | Quellcode je Slice | Rest für |
|---|---|---|
| 256k (Cloud-Modelle, lokale Modelle mit 256k) | **≤ 150k** Token | Protokoll/Prompt (~10k), Katalogauszug (~8–15k), Notizen und Antwort (≥ 60k) |
| lokal, Tiefenprüfung | **≤ 64–100k** Token (Halb-Slice `a`/`b`) | Genauigkeit bei langem Kontext sinkt messbar; siehe [AUDIT-LOCAL-LLM.md §6](AUDIT-LOCAL-LLM.md#6-kontextstrategie-für-256k) |
| lokal, Inventar/Querverweise | bis 200k | nur Listen, Signaturen, Hook-/Routen-Inventar; keine Tiefenbewertung |

Schätzung: **Bytes ÷ 3,5** für PHP/JS (deutsche Kommentare und YAML eher ÷ 3). Reale Zählung hängt vom Tokenizer ab –
vor dem Lauf mit dem Skript in §5 nachmessen und Werte im Ledger festhalten. Werte unten: Stand `V4.2.0`, gerundet.

### 2. Nicht ins Modell laden (Skript statt LLM)

| Inhalt | Größe | Prüfung stattdessen |
|---|---|---|
| `CMS/vendor/`, Bundles unter `CMS/assets/<bibliothek>/` (u. a. `htmlpurifier`, `suneditor`, `editorjs`, `commonmark`, `ldaprecord`, `tabler-icons`, `Carbon`) | > 3 Mio. Token | AP-16: Inventar, Versionen, Prüfsummen, Trivy; gezielt nur Fundstellen lesen |
| `CMS/core/Services/Knowledgebase/csv/*.csv` (Glossare) | ~0,7 Mio. Token | Formel-Injection und Kodierung per Skript (`^[=+\-@]`), Import-Code im Slice D2 |
| `CMS/lang/de.yaml`, `CMS/lang/en.yaml` | ~150k | AP-13.4 per Skript: Schlüsselmengen vergleichen, Platzhalter `{…}`/`%s` abgleichen |
| `CMS/assets/images/`, `CMS/assets/fonts/` | binär/groß | nur Lizenz und Herkunft (AP-16) |
| `TESTS/` gesamt | ~1,2 Mio. Token | nur die zum Slice gehörende Suite lesen und ausführen |
| `DOC/`, `DOC_PLAN/` | ~0,5 Mio. Token | AP-13.7 per Link-/Zahlenprüfskript; einzelne Dateien gezielt |

### 3. Slices

Spalte „AP“: primäre Arbeitspakete; Querschnitts-APs (AP-13, AP-15) gelten in jedem Slice. Pfade relativ zu `CMS/`.
`Services/` steht für `core/Services/`.

#### 3.1 Kern

| Slice | Inhalt | ≈ Token | AP |
|---|---|---|---|
| **K01** | `index.php`, `config.php`, `core/Bootstrap.php`, `core/Router.php`, `core/Security.php`, `core/Hooks.php`, `core/Container.php`, `core/Http/*` | 61k | 01, 03, 04, 05, 07 |
| **K02a** | `core/Auth.php`, `core/Auth/*`, `core/Totp.php`, `Services/Security/*` | 89k | 01, 02, 07 |
| **K02b** | `Services/`: `CmsAuthPageService`, `EmailVerificationService`, `EmailChangeService`, `JwtService`, `CryptoService`, `TrustedProxyService`, `SecurityRuntimeService`, `LoginThrottleSettings` | 56k | 01, 03, 07 |
| **K03** | `core/Routing/*`, `views/*` (inkl. `views/community/`, `views/auth/`) | 120k | 02, 04, 05, 11 |
| **K04** | `core/SchemaManager.php`, `core/MigrationManager.php`, `core/DatabaseUpdateRunner.php`, `core/Database.php`, `install.php`, `install/*`, `update.php` | 110k | 09, 10 |
| **K05** | übrige `core/*.php`: `CacheManager`, `PluginManager`, `ThemeManager`, `SubscriptionManager`, `VendorRegistry`, `TableOfContents`, `PageManager`, `Debug`, `Logger`, `AuditLogger`, `Api`, `Slug`, `WP_Error`; dazu `cron.php`, `orders.php` | ~100k | 08, 09, 12, 15 |

#### 3.2 Admin

Einstiegsdateien `admin/*.php` werden alphabetisch geteilt; Grenze ist die erste Datei ab `member-dashboard-plugin-widgets.php`.

| Slice | Inhalt | ≈ Token | AP |
|---|---|---|---|
| **A01** | `admin/*.php` von `a…` bis vor `member-dashboard-plugin-widgets.php` | 80k | 02, 05 |
| **A02** | `admin/*.php` ab `member-dashboard-plugin-widgets.php` | 89k | 02, 05 |
| **AM1** | `admin/modules/system/` | 132k | 02, 09, 18 |
| **AM2** | `admin/modules/{users,security,settings,member}/` | 114k | 01, 02, 07 |
| **AM3** | `admin/modules/{legal,subscriptions,seo}/` | 129k | 08, 17 |
| **AM4** | `admin/modules/{posts,pages,comments,content,trash,toc,reusable-blocks}/` | 93k | 02, 04 |
| **AM5** | `admin/modules/{themes,menus,landing,hub}/` | 116k | 02, 04, 09 |
| **AM6** | `admin/modules/{media,downloads,forms,knowledgebase,newsletter,feeds,tables,plugins,dashboard,community}/` | 143k | 02, 06, 11, 20 |
| **AV1** | `admin/views/system/` | 136k | 04, 18 |
| **AV2** | `admin/views/{seo,settings}/` | 139k | 04, 13 |
| **AV3** | `admin/views/{legal,media}/`, `admin/partials/` | 143k | 04, 05, 17 |
| **AV4** | `admin/views/{posts,pages,comments,content,trash,toc}/` | 116k | 04 |
| **AV5** | `admin/views/{users,member,subscriptions,security}/` | 124k | 04, 08 |
| **AV6** | `admin/views/{themes,performance,logs,menus,landing,hub}/` | 119k | 04, 12 |
| **AV7** | `admin/views/{forms,downloads,newsletter,knowledgebase,tables,plugins,dashboard,community,reusable-blocks,configuration}/` | 148k | 04, 11, 20 |

#### 3.3 Dienste (`Services/`, Root-Dateien)

| Slice | Inhalt | ≈ Token | AP |
|---|---|---|---|
| **R1** Inhalt/Editor | `EditorJsRenderer`, `EditorJsService`, `EditorService`, `PurifierService`, `PostRevisionService`, `EditorialCalendarService`, `CommentService`, `Content*Service`, `ContentEditPermission`, `PageTemplateService`, `PermalinkService`, `PostPublishChecklistService`, `TranslationService`, `TranslationStatusService`, `LandingPageService`, `ThemeCustomizer`, `MemberContentService` | 120k | 02, 04 |
| **R2** Medien/Dateien/Update | `BackupService`, `UpdateService`, `MediaService`, `MediaDeliveryService`, `MediaUsageService`, `ImageService`, `FileUploadService`, `PrivateStorageService`, `ElfinderService`, `PdfService`, `MarketplaceEndpoints`, `IntegratedPluginService`, `PluginSiteAssignmentService` | 133k | 06, 09, 18 |
| **R3** Mail/Betrieb | `MailService`, `MailQueueService`, `MailLogService`, `AzureMailTokenProvider`, `GraphApiService`, `CronRunnerService`, `CronExpressionAdapter`, `BatchJobService`, `DataRetentionService`, `FeatureUsageService`, `MonitoringTrendService`, `StatusService`, `SystemService`, `EnvironmentInfoService`, `ErrorReportService`, `OpcacheWarmupService`, `PerformanceSafetyNetService`, `AssetOptimizerService`, `CoreWebVitalsService`, `SecurityAlertService`, `NotificationService`, `MessageService`, `CsvExchange` | 128k | 12, 17, 18, 20 |
| **R4** SEO/Suche/Redirects | `IndexingService`, `FeedService`, `RedirectService`, `SearchService`, `SiteSearchService`, `SEOService`, `Seo*Service`, `SitemapService`, `TrackingService`, `AnalyticsService`, `CookieConsentService` | 126k | 03, 12, 17, 20 |
| **R5** Admin-UI/Benutzer | `Admin*Service`, `DashboardService`, `CoreModuleService`, `SettingsService`, `OptionStore`, `UserService`, `MemberService`, `AntispamService`, `SiteTableService` | 77k | 02, 07, 13 |

#### 3.4 Dienste (Unterverzeichnisse)

| Slice | Inhalt | ≈ Token | AP |
|---|---|---|---|
| **D1** | `Services/Forms/`, `Services/Downloads/` | 133k | 03, 06, 20 |
| **D2** | `Services/Feed/`, `Services/Knowledgebase/` **ohne** `csv/` | 115k | 03, 20 |
| **D3** | `Services/Media/`, `Services/EditorJs/` | 113k | 04, 06 |
| **D4** | `Services/AI/`, `Services/Newsletter/`, `Services/Landing/`, `Services/Legal/` | 118k | 17, 19, 20 |
| **D5** | `Services/Payments/`, `Services/Backup/`, `Services/Webhooks/`, `Services/Retention/`, `Services/Exchange/` | 98k | 06, 08, 17, 20 |
| **D6** | `Services/AdminMenu/`, `Services/SiteTable/`, `Services/Trash/`, `Services/ContentTypes/` | 129k | 02, 10, 13 |
| **D7** | `Services/Content/`, `Services/ReusableBlocks/`, `Services/FeatureHooks/`, `Services/PluginMigration/`, `Services/Search/`, `Services/SEO/` | 131k | 04, 13, 17 |

#### 3.5 Community

| Slice | Inhalt | ≈ Token | AP |
|---|---|---|---|
| **C1** | `Services/Community/Service/` A–M (`AccessDecision` … `ModerationService`, u. a. `CommunityAccess`, `AttachmentService`, `CommunitySearch`, `ContentGuard`, `CounterBook`, `CaptchaGate`, `BanService`) | 121k | 11, 02, 06 |
| **C2** | `Services/Community/Service/` N–Z (`NotificationDispatcher` … `WriteGate`, u. a. `PostService`, `TopicService`, `TopicTrashService`, `ReportService`, `RenderService`) | 104k | 11, 10, 17 |
| **C3** | `Services/Community/Repository/` | 89k | 10, 11, 12 |
| **C4** | `Services/Community/Support/`, `Services/Community/Http/`, `Services/Community/*.php` | 84k | 11, 04, 17 |
| **C5** | `views/community/`, `assets/js/community/`, `admin/modules/community/`, `admin/views/community/`, `Services/FeatureHooks/CommunityHooks.php` | 58k | 11, 04, 14 |

#### 3.6 Frontend, Mitglieder, Plugins

| Slice | Inhalt | ≈ Token | AP |
|---|---|---|---|
| **F1** | `member/`, `includes/` | 97k | 02, 13 |
| **F2** | `themes/cms-default/` (PHP, JS, Theme-JSON) | 120k | 04, 14 |
| **F3a** | `assets/js/editor-init.js`, `assets/js/editor-blocks/*` | ~86k | 04 |
| **F3b** | `assets/js/admin-content-editor.js`, `assets/js/admin-sidebar-editor.js`, `assets/js/post-editor/*` | ~95k | 04, 05 |
| **F4a** | übrige `assets/js/admin-*.js` von `admin-a…` bis `admin-l…` | ~95k | 04, 05 |
| **F4b** | übrige `assets/js/admin-*.js` ab `admin-m…` | ~95k | 04, 05 |
| **F5** | `assets/js/admin-shell/*`, `captcha/*`, `member/*` und öffentliche Skripte (`cms-csp-runtime.js`, `cms-analytics.js`, `cms-inline-actions.js`, `cookieconsent-init.js`, `downloads.js`, `feeds.js`, `forms-public.js`, `knowledgebase-tooltip.js`, `member-dashboard.js`, `photoswipe-init.js`, `site-tables.js`, `tabler-bootstrap-bridge.js`, `web-vitals.js`) | ~55k | 04, 05, 17 |
| **F6** | `assets/css/` (nur bei CSP-/A11y-Fragen; sonst Skript) | 198k → halbieren | 04, 14 |
| **P1** | `plugins/cms-importer/` | 152k → `a`/`b` | 03, 06, 20 |

### 4. Slice-Abhängigkeiten (immer mitgeben)

Ein Slice-Prüfer bekommt **zusätzlich** eine kompakte Vertragsliste (≤ 10k Token), keine ganzen Dateien:

| Wenn Slice … | dann Kurzfassung von … |
|---|---|
| A*, AM*, AV* | `core/Security.php` (CSRF-, Escape-, Rate-Limit-API), `Auth::isAdmin()`, `DelegatedAdminAccess::PAGES`, Capability-Liste |
| C* | `CommunityAccess`-Signaturen, Capability-Liste `community.*`, Entscheidungen ZUGRIFF/ZÄHLER/BUDGET |
| R*, D* | `Database`-API (Platzhalter, `runSchemaOnce`), `Hooks`-API, `Http\Client`-SSRF-Schutz |
| K04 | Tabellenliste und Spaltenzahl aus `TESTS/schema-v27` |
| F* | CSP-Header-Erzeugung (Nonce, Trusted Types), `InlineStyleRewriter` |

Diese Kurzfassungen erzeugt der Orchestrator einmal je Lauf in `runs/<run-id>/CONTRACTS.md` (siehe Protokoll §3).

### 5. Messen und Manifest erzeugen

```bash
# Token-Schätzung je Slice (Python 3, plattformunabhängig, aus dem Repo-Wurzelverzeichnis)
python - <<'PY'
import os, subprocess, fnmatch
files = [f for f in subprocess.run(["git","ls-files","CMS"],capture_output=True,text=True).stdout.split()
         if not f.startswith("CMS/vendor/") and "/csv/" not in f]
def tok(pats): return round(sum(os.path.getsize(f) for f in files if any(fnmatch.fnmatch(f,p) for p in pats))/3500)
print("K03", tok(["CMS/core/Routing/*","CMS/views/*"]), "k")
PY
```

Unter Windows PowerShell gibt es kein Heredoc: das Skript als Datei speichern oder per
`@' … '@ | python -` übergeben. Das Manifest je Slice (Dateiliste mit Byte-Größe und `git hash-object`) wird in
`runs/<run-id>/INVENTORY.md` abgelegt, damit ein späterer Lauf feststellen kann, ob sich ein Slice geändert hat.

### 6. Delta-Zuordnung

Beim Delta-Audit wird jede geänderte Datei (`git diff --name-only V4.2.0..HEAD`) ihrem Slice zugeordnet. Geprüft
werden nur betroffene Slices, aber **vollständig** (nicht nur die Diff-Zeilen), plus die Abhängigkeiten aus §4 und
die Aufrufer der geänderten öffentlichen Methoden (`rg -n 'methodenName\('`).
