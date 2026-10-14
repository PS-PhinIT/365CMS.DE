# 365CMS – Projektdokumentation | Abschnitt: CMSFILESTRUCTUR
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02

## Table of contents | Inhaltsverzeichnis

- [Purpose](#purpose--zweck)
- [Counts](#counts--bestandszahlen)
- [Area roles](#area-roles--kurzbeschreibung-der-bereiche)
- [Root files](#root-files--root-dateien)
- [config](#config)
- [core](#core)
- [includes](#includes)
- [lang](#lang)
- [install](#install)
- [member](#member)
- [plugins](#plugins)
- [themes](#themes)
- [assets](#assets)
- [admin](#admin)
- [Other runtime directories](#other-runtime-directories--weitere-runtime-verzeichnisse)

---

## Purpose | Zweck

**English**

This file is the **CMS runtime inventory** for `CMS/` as counted on 2026-09-13. It replaces the old 28.03.2026 snapshot (467 files, versions 2.9.0 / 3.3.47).

Out of scope for a full file listing: deep vendor trees (`CMS/vendor/dompdf/`, large library trees under `CMS/assets/` such as `htmlpurifier`, `tabler`, `editorjs`). Those directories exist and are loaded at runtime; listing every vendor file is not useful for GitHub readers.

**Deutsch**

Diese Datei ist das **CMS-Runtime-Inventar** für `CMS/`, gezählt am 2026-09-13. Sie ersetzt den alten Snapshot vom 28.03.2026 (467 Dateien, Versionen 2.9.0 / 3.3.47).

Nicht vollständig ausgerollt: tiefe Vendor-Bäume (`CMS/vendor/dompdf/`, große Bibliotheksbäume unter `CMS/assets/` wie `htmlpurifier`, `tabler`, `editorjs`). Diese Verzeichnisse existieren und werden zur Laufzeit geladen; jede Vendor-Datei aufzulisten ist für GitHub-Leser nicht sinnvoll.

---

## Counts | Bestandszahlen

**English**

File counts from the current tree (`Get-ChildItem -Recurse -File`):

**Deutsch**

Dateizahlen aus dem aktuellen Baum (`Get-ChildItem -Recurse -File`):

| Area / Bereich | Files / Dateien |
|---|---:|
| `CMS/admin/` | 298 |
| `CMS/assets/` | 2984 (mostly bundled libraries) |
| `CMS/core/` | 155 |
| `CMS/themes/` | 43 |
| `CMS/member/` | 17 |
| `CMS/plugins/` | 13 |
| `CMS/includes/` | 10 |
| `CMS/install/` | 9 |
| `CMS/config/` | 4 |
| `CMS/lang/` | 2 |
| `CMS/assets/css/` | 13 stylesheets |
| `CMS/assets/js/` | 41 scripts |

---

## Area roles | Kurzbeschreibung der Bereiche

**English** / **Deutsch**

| Path | English | Deutsch |
|---|---|---|
| `CMS/` | runtime root, entry points, metadata | Runtime-Wurzel, Einstiege, Metadaten |
| `CMS/admin/` | backend entries, modules, views, partials | Backend-Einstiege, Module, Views, Partials |
| `CMS/assets/` | CSS, JS, bundled libraries | CSS, JS, gebündelte Bibliotheken |
| `CMS/config/` | app and media configuration | App- und Medienkonfiguration |
| `CMS/core/` | bootstrap, routing, services, auth | Bootstrap, Routing, Services, Auth |
| `CMS/includes/` | helpers and WordPress-compat shims | Hilfsfunktionen und WP-Kompatibilität |
| `CMS/install/` | installer service, controller, views | Installer-Service, Controller, Views |
| `CMS/lang/` | `de.yaml`, `en.yaml` | Sprachdateien `de.yaml`, `en.yaml` |
| `CMS/member/` | member dashboard and related pages | Mitgliederbereich |
| `CMS/plugins/` | installed plugins | installierte Plugins |
| `CMS/themes/` | installed themes | installierte Themes |
| `CMS/marketplace/` | marketplace manifests | Marktplatz-Manifeste |
| `CMS/vendor/` | extra vendor code (`dompdf`) | zusätzlicher Vendor-Code (`dompdf`) |
| `CMS/logs/` | log directory (`LOG_PATH`) | Log-Verzeichnis (`LOG_PATH`) |
| `CMS/cache/` | runtime cache | Laufzeit-Cache |
| `CMS/backups/` | local backups | lokale Backups |
| `CMS/uploads/` | upload target | Upload-Ziel |
| `CMS/views/` | extra views (including `views/auth/`) | zusätzliche Views (einschließlich `views/auth/`) |
| `CMS/db/` | DB-related artefacts | DB-nahe Artefakte |

---

## Root files | Root-Dateien

**English** / **Deutsch**

Verified files in `CMS/`:

- `.htaccess`
- `config.php`
- `cron.php`
- `default.php`
- `index.php`
- `install.php`
- `LICENSE`
- `orders.php`
- `README.md`
- `robots.txt`
- `update.json`
- `update.php`

Directories at this level: `admin`, `assets`, `backups`, `cache`, `config`, `core`, `db`, `includes`, `install`, `lang`, `logs`, `marketplace`, `member`, `plugins`, `themes`, `uploads`, `vendor`, `views`.

---

## config

**English** / **Deutsch**

| File | Purpose / Zweck |
|---|---|
| `config/.htaccess` | block direct web access / direkter Webzugriff gesperrt |
| `config/app.php` | application configuration template / App-Konfigurations-Template |
| `config/media-meta.json` | media metadata / Medien-Metadaten |
| `config/media-settings.json` | media settings / Medien-Einstellungen |

---

## core

**English**

Root classes in `CMS/core/`:

**Deutsch**

Root-Klassen in `CMS/core/`:

- `Api.php`
- `AuditLogger.php`
- `Auth.php`
- `autoload.php`
- `Bootstrap.php`
- `CacheManager.php`
- `Container.php`
- `Database.php`
- `DatabaseUpdateRunner.php`
- `Debug.php`
- `Hooks.php`
- `Json.php`
- `Logger.php`
- `MigrationManager.php`
- `PageManager.php`
- `PluginManager.php`
- `Router.php`
- `SchemaManager.php` (`SCHEMA_VERSION = 'v23'`)
- `Security.php`
- `SubscriptionManager.php`
- `TableOfContents.php`
- `ThemeManager.php`
- `Totp.php`
- `VendorRegistry.php`
- `Version.php` (`CURRENT = '3.5.0'`)
- `WP_Error.php`

Subdirectories:

| Path | Contents / Inhalt |
|---|---|
| `core/Auth/` | `AuthManager.php`, `LDAP/LdapAuthProvider.php`, `MFA/BackupCodesManager.php`, `MFA/TotpAdapter.php`, `Passkey/WebAuthnAdapter.php` |
| `core/Contracts/` | interfaces (cache, DB, logger, …) |
| `core/Http/` | HTTP client / transport |
| `core/Member/` | member core registration |
| `core/Routing/` | `AdminRouter.php`, `ApiRouter.php`, `MemberRouter.php`, `PublicRouter.php`, `ThemeArchiveRepository.php`, `ThemeRouter.php` |
| `core/Services/` | domain services (see [FILELIST.md](FILELIST.md) and [DEVLIST.md](DEVLIST.md)) |

Service PHP files currently present at `core/Services/` root include: `AnalyticsService`, `AntispamService`, `AssetOptimizerService`, `AzureMailTokenProvider`, `BackupService`, `CmsAuthPageService`, `CommentService`, `ContentLanguageCopyService`, `ContentLocalizationService`, `ContentMediaPlacementService`, `CookieConsentService`, `CoreModuleService`, `CoreWebVitalsService`, `CronExpressionAdapter`, `CronRunnerService`, `DashboardService`, `EditorJsRenderer`, `EditorJsService`, `EditorService`, `ElfinderService`, `ErrorReportService`, `FeatureUsageService`, `FeedService`, `FileUploadService`, `GraphApiService`, `ImageService`, `IndexingService`, `JwtService`, `LandingPageService`, `MailLogService`, `MailQueueService`, `MailService`, `MediaDeliveryService`, `MediaService`, `MediaUsageService`, `MemberService`, `MessageService`, `MonitoringTrendService`, `OpcacheWarmupService`, `PdfService`, `PerformanceSafetyNetService`, `PermalinkService`, `PurifierService`, `RedirectService`, `SearchService`, `SecurityAlertService`, `SecurityRuntimeService`, `SeoAnalysisService`, `SeoBrokenLinkService`, `SEOService`, `SeoTrendService`, `SettingsService`, `SiteSearchService`, `SitemapService`, `SiteTableService`, `StatusService`, `SystemService`, `ThemeCustomizer`, `TrackingService`, `TranslationService`, `UpdateService`, `UserService`.

Service subfolders: `AI/`, `EditorJs/`, `Landing/`, `Media/`, `SEO/`, `SiteTable/`.

---

## includes

**English** / **Deutsch**

- `includes/functions.php`
- `includes/subscription-helpers.php`
- `includes/functions/admin-menu.php`
- `includes/functions/escaping.php`
- `includes/functions/mail.php`
- `includes/functions/options-runtime.php`
- `includes/functions/plugin-public-i18n.php`
- `includes/functions/redirects-auth.php`
- `includes/functions/roles.php`
- `includes/functions/translation.php`
- `includes/functions/wordpress-compat.php`

---

## lang

- `lang/de.yaml`
- `lang/en.yaml`

---

## install

- `install/InstallerController.php`
- `install/InstallerService.php`
- `install/views/admin.php`
- `install/views/blocked.php`
- `install/views/database.php`
- `install/views/site.php`
- `install/views/success.php`
- `install/views/update.php`
- `install/views/welcome.php`

---

## member

- `member/dashboard.php`
- `member/favorites.php`
- `member/media.php`
- `member/messages.php`
- `member/notifications.php`
- `member/plugin-section.php`
- `member/privacy.php`
- `member/profile.php`
- `member/security.php`
- `member/subscription.php`
- `member/includes/bootstrap.php`
- `member/includes/class-member-controller.php`
- `member/partials/alerts.php`
- `member/partials/footer.php`
- `member/partials/header.php`
- `member/partials/plugin-not-found.php`
- `member/partials/sidebar.php`

---

## plugins

**English**

The only installed runtime plugin in this tree is `cms-importer` (13 files).

**Deutsch**

Das einzige installierte Runtime-Plugin in diesem Baum ist `cms-importer` (13 Dateien).

- `plugins/cms-importer/cms-importer.php`
- `plugins/cms-importer/update.json`
- `plugins/cms-importer/admin/log.php`
- `plugins/cms-importer/admin/page.php`
- `plugins/cms-importer/assets/css/importer.css`
- `plugins/cms-importer/assets/js/importer.js`
- `plugins/cms-importer/includes/class-admin.php`
- `plugins/cms-importer/includes/class-importer.php`
- `plugins/cms-importer/includes/class-xml-parser.php`
- `plugins/cms-importer/includes/trait-admin-cleanup.php`
- `plugins/cms-importer/includes/trait-importer-preview.php`
- `plugins/cms-importer/includes/trait-importer-reporting.php`
- `plugins/cms-importer/reports/EXAMPLE_meta-report.md`

---

## themes

**English**

The only installed runtime theme in this tree is `cms-default`.

**Deutsch**

Das einzige installierte Runtime-Theme in diesem Baum ist `cms-default`.

- `404.php`
- `archive.php`
- `author.php`
- `blog-single.php`
- `blog.php`
- `category.php`
- `contact.php`
- `error.php`
- `footer.php`
- `forgot-password.php`
- `functions.php`
- `header.php`
- `home.php`
- `index.php`
- `login.php`
- `meridan.html`
- `page.php`
- `register.php`
- `search.php`
- `style.css`
- `tag.php`
- `theme.json`
- `update.json`

---

## assets

### CSS (`CMS/assets/css/`)

- `admin-dashboard.css`
- `admin-hub-site-edit.css`
- `admin-hub-template-editor.css`
- `admin-plugins.css`
- `admin-sidebar.css`
- `admin-site-tables.css`
- `admin-tabler.css`
- `admin.css`
- `cms-cookie-consent.css`
- `cms-mfa.css`
- `editorjs-content.css`
- `hub-sites.css`
- `main.css`
- `member-dashboard.css`

### JavaScript (`CMS/assets/js/`)

- `admin-ai-services.js`
- `admin-comments.js`
- `admin-content-editor.js`
- `admin-cookie-manager.js`
- `admin-dashboard.js`
- `admin-data-requests.js`
- `admin-font-manager.js`
- `admin-hub-site-edit.js`
- `admin-hub-sites.js`
- `admin-hub-template-editor.js`
- `admin-legal-sites.js`
- `admin-media-integrations.js`
- `admin-member-dashboard.js`
- `admin-menu-editor.js`
- `admin-plugin-marketplace.js`
- `admin-plugins.js`
- `admin-seo-editor.js`
- `admin-seo-meta.js`
- `admin-seo-redirects.js`
- `admin-settings.js`
- `admin-site-tables.js`
- `admin-subscriptions.js`
- `admin-system-cron.js`
- `admin-theme-explorer.js`
- `admin-theme-marketplace.js`
- `admin-themes.js`
- `admin-user-groups.js`
- `admin-users.js`
- `admin.js`
- `cms-csp-runtime.js`
- `cms-inline-actions.js`
- `cookieconsent-init.js`
- `editor-init.js`
- `member-dashboard.js`
- `photoswipe-init.js`
- `site-tables.js`
- `tabler-bootstrap-bridge.js`
- `web-vitals.js`

### Bundled library folders | Gebündelte Bibliotheksordner

Present under `CMS/assets/` (not every inner file listed):

`ai-platform`, `bacon-qr-code`, `Carbon`, `clock`, `cron`, `css`, `dasprid-enum`, `doctrine-deprecations`, `doctrine-lexer`, `dompurify`, `editorjs`, `egulias-email-validator`, `event-dispatcher`, `htmlpurifier`, `images`, `js`, `ldaprecord`, `mailer`, `melbahja-seo`, `mime`, `oskarstark-enum-helper`, `photoswipe`, `php-jwt`, `phpdocumentor-reflection-common`, `phpdocumentor-reflection-docblock`, `phpdocumentor-type-resolver`, `phpstan-phpdoc-parser`, `polyfill-ctype`, `polyfill-intl-grapheme`, `polyfill-intl-idn`, `polyfill-intl-normalizer`, `polyfill-mbstring`, `polyfill-uuid`, `property-access`, `property-info`, `psr`, `serializer`, `string`, `suneditor`, `symfony-contracts`, `tabler`, `tabler-icons`, `tntsearchhelper`, `tntsearchsrc`, `translation`, `twofactorauth`, `type-info`, `uid`, `webauthn`, `webmozart-assert`, `yaml`, `autoload.php`

These folders were **not** found under `CMS/assets/` (older docs mentioned them): `cookieconsent`, `elfinder`, `filepond`, `msgraph`. Removed in 3.5.0 as unused: `gridjs`, `simplepielibrary`, `simplepiesrc`. Init scripts such as `assets/js/cookieconsent-init.js` still exist.

---

## admin

**English**

`CMS/admin/` contains 298 files: PHP entry points, `modules/`, `views/`, `partials/`, and `logs/`.

Module directories: `comments`, `dashboard`, `hub`, `landing`, `legal`, `media`, `member`, `menus`, `pages`, `plugins`, `posts`, `security`, `seo`, `settings`, `subscriptions`, `system`, `tables`, `themes`, `toc`, `users`.

Entry examples that match public admin routes include `index.php`, `pages.php`, `posts.php`, `media.php`, `users.php`, `plugins.php`, `themes.php`, `cms-loginpage.php`, `cms-logs.php`, `ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php`, plus the other `CMS/admin/*.php` files in that folder.

**Deutsch**

`CMS/admin/` enthält 298 Dateien: PHP-Einstiege, `modules/`, `views/`, `partials/` und `logs/`.

Modulverzeichnisse: `comments`, `dashboard`, `hub`, `landing`, `legal`, `media`, `member`, `menus`, `pages`, `plugins`, `posts`, `security`, `seo`, `settings`, `subscriptions`, `system`, `tables`, `themes`, `toc`, `users`.

Einstiegsbeispiele zu Admin-Routen sind `index.php`, `pages.php`, `posts.php`, `media.php`, `users.php`, `plugins.php`, `themes.php`, `cms-loginpage.php`, `cms-logs.php`, `ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php` sowie die übrigen `CMS/admin/*.php`-Dateien.

---

## Other runtime directories | Weitere Runtime-Verzeichnisse

**English**

| Path | Notes |
|---|---|
| `CMS/marketplace/` | `core/365cms/update.json`, `plugins/index.json`, `themes/index.json` |
| `CMS/vendor/` | `vendor/dompdf/` (no TCPDF tree in this repository) |
| `CMS/logs/` | `.htaccess`, `.gitignore`, rotating log files |
| `CMS/cache/` | runtime cache, including HTMLPurifier serializer cache |
| `CMS/backups/` | `.htaccess`, `index.html` |
| `CMS/uploads/` | `.gitkeep` (content is installation-specific) |
| `CMS/views/` | additional views, including auth |
| `CMS/db/` | DB-related artefacts |

**Deutsch**

| Pfad | Hinweise |
|---|---|
| `CMS/marketplace/` | `core/365cms/update.json`, `plugins/index.json`, `themes/index.json` |
| `CMS/vendor/` | `vendor/dompdf/` (kein TCPDF-Baum in diesem Repository) |
| `CMS/logs/` | `.htaccess`, `.gitignore`, rotierende Logdateien |
| `CMS/cache/` | Laufzeit-Cache, einschließlich HTMLPurifier-Serializer-Cache |
| `CMS/backups/` | `.htaccess`, `index.html` |
| `CMS/uploads/` | `.gitkeep` (Inhalt ist installationsspezifisch) |
| `CMS/views/` | zusätzliche Views, einschließlich Auth |
| `CMS/db/` | DB-nahe Artefakte |
