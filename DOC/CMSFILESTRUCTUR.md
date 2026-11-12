# 365CMS – Projektdokumentation | Abschnitt: CMSFILESTRUCTUR
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable | **Update:** 2026-11-10

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

This file is the **CMS runtime inventory** for `CMS/`, counts updated on 2026-11-10 (release 4.0.0). It replaces the old 28.03.2026 snapshot (467 files, versions 2.9.0 / 3.3.47).

Out of scope for a full file listing: deep vendor trees (`CMS/vendor/dompdf/`, large library trees under `CMS/assets/` such as `htmlpurifier`, `tabler`, `editorjs`). Those directories exist and are loaded at runtime; listing every vendor file is not useful for GitHub readers.

**Deutsch**

Diese Datei ist das **CMS-Runtime-Inventar** für `CMS/`, Zählung aktualisiert am 2026-11-10 (Release 4.0.0). Sie ersetzt den alten Snapshot vom 28.03.2026 (467 Dateien, Versionen 2.9.0 / 3.3.47).

Nicht vollständig ausgerollt: tiefe Vendor-Bäume (`CMS/vendor/dompdf/`, große Bibliotheksbäume unter `CMS/assets/` wie `htmlpurifier`, `tabler`, `editorjs`). Diese Verzeichnisse existieren und werden zur Laufzeit geladen; jede Vendor-Datei aufzulisten ist für GitHub-Leser nicht sinnvoll.

---

## Counts | Bestandszahlen

**English**

Versioned files per area (`git ls-files CMS/<area> | wc -l`; runtime files such as `config/media-settings.json`, uploads or logs are not counted):

**Deutsch**

Versionierte Dateien je Bereich (`git ls-files CMS/<bereich> | wc -l`; Laufzeitdateien wie `config/media-settings.json`, Uploads oder Logs zählen nicht):

| Area / Bereich | Files / Dateien |
|---|---:|
| `CMS/admin/` | 372 |
| `CMS/assets/` | 3099 (mostly bundled libraries) |
| `CMS/core/` | 291 |
| `CMS/themes/` | 41 |
| `CMS/member/` | 17 |
| `CMS/plugins/` | 15 |
| `CMS/includes/` | 12 |
| `CMS/install/` | 10 |
| `CMS/config/` | 3 |
| `CMS/lang/` | 2 |
| `CMS/assets/css/` | 27 stylesheets |
| `CMS/assets/js/` | 55 scripts + 15 ES modules (`admin-shell/`, `post-editor/`) |

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
- `SchemaManager.php` (`SCHEMA_VERSION = 'v25'`)
- `Security.php`
- `SubscriptionManager.php`
- `TableOfContents.php`
- `ThemeManager.php`
- `Totp.php`
- `VendorRegistry.php`
- `Version.php` (`CURRENT = '4.0.0'`)
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

Service PHP files currently present at `core/Services/` root include: `AdminNavigationService`, `AdminNoticeService`, `AdminSiteContextService`, `AdminUiPreferenceService`, `AnalyticsService`, `AntispamService`, `AssetOptimizerService`, `AzureMailTokenProvider`, `BackupService`, `BatchJobService`, `CmsAuthPageService`, `CommentService`, `ContentLanguageCopyService`, `ContentLocalizationService`, `ContentMediaPlacementService`, `CookieConsentService`, `CoreModuleService`, `CoreWebVitalsService`, `CronExpressionAdapter`, `CronRunnerService`, `CryptoService`, `CsvExchange`, `DashboardService`, `DataRetentionService`, `EditorJsRenderer`, `EditorJsService`, `EditorService`, `ElfinderService`, `EmailChangeService`, `EmailVerificationService`, `ErrorReportService`, `FeatureUsageService`, `FeedService`, `FileUploadService`, `GraphApiService`, `ImageService`, `IndexingService`, `IntegratedPluginService`, `JwtService`, `LandingPageService`, `MailLogService`, `MailQueueService`, `MailService`, `MarketplaceEndpoints`, `MediaDeliveryService`, `MediaService`, `MediaUsageService`, `MemberService`, `MessageService`, `MonitoringTrendService`, `OpcacheWarmupService`, `OptionStore`, `PageTemplateService`, `PdfService`, `PerformanceSafetyNetService`, `PermalinkService`, `PluginSiteAssignmentService`, `PostPublishChecklistService`, `PostRevisionService`, `PrivateStorageService`, `PurifierService`, `RedirectService`, `SearchService`, `SecurityAlertService`, `SecurityRuntimeService`, `SeoAnalysisService`, `SeoBrokenLinkService`, `SEOService`, `SeoTrendService`, `SettingsService`, `SitemapService`, `SiteSearchService`, `SiteTableService`, `StatusService`, `SystemService`, `ThemeCustomizer`, `TrackingService`, `TranslationService`, `TrustedProxyService`, `UpdateService`, `UserService`.

Service subfolders: `AdminMenu/`, `AI/`, `ContentTypes/`, `EditorJs/`, `FeatureHooks/`, `Landing/`, `Media/`, `PluginMigration/`, `Retention/`, `SEO/`, `SiteTable/`.

New in 4.0.0 / Neu in 4.0.0:

- `Services/AdminMenu/` — admin sidebar with profiles / Admin-Sidebar mit Profilen: `AdminMenuItem`, `AdminMenuService`, `BadgeProvider`, `CoreCatalog`, `LayoutRepository`, `LayoutValidator`, `MenuBuilder`, `MenuRegistry`, `ProfileLayouts` (see / siehe [admin/ADMIN-MENU.md](admin/ADMIN-MENU.md))
- `Services/FeatureHooks/Registry.php`, `Services/PluginMigration/PluginMigrator.php`, `Services/Retention/RetentionRule.php`, `Services/EditorJs/EditorJsBlockRegistry.php`, `Services/ContentTypes/ContentTypeRegistry.php` (+ provider interfaces and value objects / Provider-Schnittstellen und Wertobjekte) — shared extension points / gemeinsame Erweiterungspunkte

---

## includes

**English** / **Deutsch**

- `includes/functions.php`
- `includes/subscription-helpers.php`
- `includes/functions/admin-menu-profiles.php`
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
- `install/views/layout.php`
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

The only installed runtime plugin in this tree is `cms-importer` (15 files).

**Deutsch**

Das einzige installierte Runtime-Plugin in diesem Baum ist `cms-importer` (15 Dateien).

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

- `admin-configuration.css`
- `admin-dashboard.css`
- `admin-hub-site-edit.css`
- `admin-hub-template-editor.css`
- `admin-multisites.css`
- `admin-plugin-assignment.css`
- `admin-plugins.css`
- `admin-post-editor.css`
- `admin-shell.css`
- `admin-sidebar-editor.css`
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
- `admin-batch-runner.js`
- `admin-comments.js`
- `admin-content-editor.js`
- `admin-content-exchange.js`
- `admin-cookie-manager.js`
- `admin-dashboard.js`
- `admin-data-requests.js`
- `admin-downloads.js`
- `admin-knowledgebase.js`
- `admin-newsletter.js`
- `admin-editorial-calendar.js`
- `admin-feeds.js`
- `admin-font-manager.js`
- `admin-form-builder.js`
- `admin-form-submissions.js`
- `admin-hub-site-edit.js`
- `admin-hub-sites.js`
- `admin-hub-template-editor.js`
- `admin-legal-sites.js`
- `admin-media-integrations.js`
- `admin-media-license.js`
- `admin-media-tools.js`
- `admin-member-dashboard.js`
- `admin-menu-editor.js`
- `admin-plugin-assignment.js`
- `admin-plugin-marketplace.js`
- `admin-plugins.js`
- `admin-search-index.js`
- `admin-seo-editor.js`
- `admin-seo-meta.js`
- `admin-seo-redirects.js`
- `admin-settings.js`
- `admin-sidebar-editor.js`
- `admin-site-tables.js`
- `admin-subscriptions.js`
- `admin-system-cron.js`
- `admin-theme-explorer.js`
- `admin-theme-marketplace.js`
- `admin-themes.js`
- `admin-trash.js`
- `admin-user-groups.js`
- `admin-users.js`
- `admin.js`
- `cms-analytics.js`
- `cms-csp-runtime.js`
- `cms-inline-actions.js`
- `cookieconsent-init.js`
- `downloads.js`
- `editor-init.js`
- `feeds.js`
- `forms-public.js`
- `member-dashboard.js`
- `photoswipe-init.js`
- `site-tables.js`
- `tabler-bootstrap-bridge.js`
- `web-vitals.js`

ES modules without build step / ES-Module ohne Build-Schritt (since/seit 4.0.0):

- `admin-shell/`: `api.js`, `dom.js`, `drawer.js`, `recent-links.js`, `shell.js`, `sidebar.js`, `site-switcher.js`, `toolbar.js`, `user-menu.js`
- `post-editor/`: `autosave.js`, `checklist.js`, `dialog.js`, `editor.js`, `focus-mode.js`, `revisions.js`

### Bundled library folders | Gebündelte Bibliotheksordner

Present under `CMS/assets/` (not every inner file listed):

`ai-platform`, `bacon-qr-code`, `Carbon`, `clock`, `cron`, `css`, `dasprid-enum`, `doctrine-deprecations`, `doctrine-lexer`, `dompurify`, `editorjs`, `egulias-email-validator`, `event-dispatcher`, `fonts` (Inter, Source Serif 4, JetBrains Mono; since 4.0.0), `htmlpurifier`, `images`, `js`, `ldaprecord`, `mailer`, `melbahja-seo`, `mime`, `oskarstark-enum-helper`, `photoswipe`, `php-jwt`, `phpdocumentor-reflection-common`, `phpdocumentor-reflection-docblock`, `phpdocumentor-type-resolver`, `phpstan-phpdoc-parser`, `polyfill-ctype`, `polyfill-intl-grapheme`, `polyfill-intl-idn`, `polyfill-intl-normalizer`, `polyfill-mbstring`, `polyfill-uuid`, `property-access`, `property-info`, `psr`, `serializer`, `string`, `suneditor`, `symfony-contracts`, `tabler`, `tabler-icons`, `tntsearchhelper`, `tntsearchsrc`, `translation`, `twofactorauth`, `type-info`, `uid`, `webauthn`, `webmozart-assert`, `yaml`, `autoload.php`

These folders were **not** found under `CMS/assets/` (older docs mentioned them): `cookieconsent`, `elfinder`, `filepond`, `msgraph`. Removed in 3.4.02 as unused: `gridjs`, `simplepielibrary`, `simplepiesrc`. Init scripts such as `assets/js/cookieconsent-init.js` still exist.

---

## admin

**English**

`CMS/admin/` contains 372 files: PHP entry points, `modules/`, `views/`, `partials/`, `logs/` and `settings-tabs/`.

Module directories: `comments`, `content`, `dashboard`, `downloads`, `feeds`, `forms`, `hub`, `knowledgebase`, `landing`, `newsletter`, `legal`, `media`, `member`, `menus`, `pages`, `plugins`, `posts`, `security`, `seo`, `settings`, `subscriptions`, `system`, `tables`, `themes`, `toc`, `trash`, `users`.

Entry examples that match public admin routes include `index.php`, `pages.php`, `posts.php`, `media.php`, `users.php`, `plugins.php`, `themes.php`, `cms-loginpage.php`, `cms-logs.php`, `ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php`, plus the other `CMS/admin/*.php` files in that folder. New in 4.0.0: `admin-ui.php` (JSON endpoint of the admin shell), `post-editor-api.php` (JSON endpoint of the post editor) and `plugin-assignment.php` (plugin assignment per site, view `views/settings/plugin-assignment.php`). Also new: `multisites.php` (MultiSites settings area, view `views/settings/multisites.php`), `configuration.php` (tile pages `/admin/configuration[?group=…]`, views `views/configuration/`), `settings-tabs/admin-menu.php` (settings tab "Admin menu" with the sidebar editor, views `views/settings/admin-menu.php` and `views/settings/admin-sidebar-editor.php`), `settings-tabs/feeds.php` (RSS settings controller and view `views/settings/feeds.php`), `partials/sidebar-nav.php` (sidebar list, also used by the preview) and `views/settings/partials-tabs.php` (shared tab bar of the settings areas).

**Deutsch**

`CMS/admin/` enthält 372 Dateien: PHP-Einstiege, `modules/`, `views/`, `partials/`, `logs/` und `settings-tabs/`.

Modulverzeichnisse: `comments`, `content`, `dashboard`, `downloads`, `feeds`, `forms`, `hub`, `knowledgebase`, `landing`, `newsletter`, `legal`, `media`, `member`, `menus`, `pages`, `plugins`, `posts`, `security`, `seo`, `settings`, `subscriptions`, `system`, `tables`, `themes`, `toc`, `trash`, `users`.

Einstiegsbeispiele zu Admin-Routen sind `index.php`, `pages.php`, `posts.php`, `media.php`, `users.php`, `plugins.php`, `themes.php`, `cms-loginpage.php`, `cms-logs.php`, `ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php` sowie die übrigen `CMS/admin/*.php`-Dateien. Neu in 4.0.0: `admin-ui.php` (JSON-Endpunkt der Admin-Shell), `post-editor-api.php` (JSON-Endpunkt des Beitragseditors) und `plugin-assignment.php` (Plugin-Zuweisung je Site, View `views/settings/plugin-assignment.php`). Ebenfalls neu: `multisites.php` (Einstellungsbereich MultiSites, View `views/settings/multisites.php`), `configuration.php` (Kachelseiten `/admin/configuration[?group=…]`, Views `views/configuration/`), `settings-tabs/admin-menu.php` (Einstellungs-Tab „Admin-Menü“ mit dem Sidebar-Editor, Views `views/settings/admin-menu.php` und `views/settings/admin-sidebar-editor.php`), `settings-tabs/feeds.php` (RSS-Einstellungs-Controller und View `views/settings/feeds.php`), `partials/sidebar-nav.php` (Sidebar-Liste, auch für die Vorschau) und `views/settings/partials-tabs.php` (gemeinsame Tab-Leiste der Einstellungsbereiche).


New admin pages in 4.0.0 / Neue Admin-Seiten in 4.0.0: `forms.php`, `form-submissions.php`, `downloads.php`, `knowledgebase.php`, `newsletter.php`, `editorial-calendar.php` (+ `editorial-calendar-api.php`), `trash.php`, `content-changes.php`, `translations.php`, `search-index.php` (+ `search-index-api.php`), `content-exchange.php` (+ `content-exchange-api.php`), `data-retention.php`, `media-tools.php` (+ `media-tools-api.php`); settings tabs / Einstellungs-Tabs `settings-tabs/admin-menu.php`, `feeds.php`, `security.php`.

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
