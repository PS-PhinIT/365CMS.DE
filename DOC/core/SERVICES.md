# 365CMS – Projektdokumentation | Abschnitt: Core – Service-Schicht

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Business logic lives in `CMS/core/Services/` (namespace `CMS\Services`). Services are singletons (`Service::getInstance()`); the most important ones are also registered in the DI container under their class name and a short alias (`Bootstrap::initializeCore()`), e.g. `'mail'`, `'seo'`, `'search'`, `'settings'`. Larger domains are split into sub-namespaces: `AI\`, `AdminMenu\`, `ContentTypes\`, `EditorJs\`, `FeatureHooks\`, `Landing\`, `Media\`, `PluginMigration\`, `Retention\`, `SEO\`, `SiteTable\`. Since 4.0.0 shared extension points serve the core features: `FeatureHooks\Registry`, `BatchJobService` (table `batch_jobs`), `PrivateStorageService` (`CMS_PRIVATE_PATH`), `IntegratedPluginService` with `PluginMigration\PluginMigrator`, `EditorJs\EditorJsBlockRegistry`, retention rules (`DataRetentionService::registerRule()`), notice checks (`AdminNoticeService::registerCheck()`) and the content type provider registry `ContentTypes\ContentTypeRegistry` for trash, export/import and search. Admin modules and themes should call services instead of accessing tables directly.

## Deutsch

### Zugriff

```php
use CMS\Services\MailService;

$mail = MailService::getInstance();                       // direkt
$mail = \CMS\Container::instance()->get('mail');          // über den Container
$mail = \CMS\Container::instance()->get(MailService::class);
```

Container-Aliase: `db`, `logger`, `cache`, `purifier`, `mail`, `settings`, `mail.azure`, `mail.logs`, `mail.queue`, `graph`, `search`, `image`, `feed`, `translation`, `editorjs`, `editorjs.renderer`, `fileupload`, `comments`, `seo`, `member`, `messages`, `users`, `status`, `dashboard`, `landingpage`, `tracking`, `featureusage`, `backup`, `system`, `update`.

### Inhalte & Darstellung

| Service | Aufgabe |
|---|---|
| `EditorJsService` | Editor.js-Assets, Medien-API (`/api/media`), Upload-Token |
| `EditorJsRenderer` | Editor.js-JSON → HTML (Blöcke, Galerien, Tabellen, Embeds) |
| `EditorJs\…` | `ContentNormalizer`, `Sanitizer`, `HtmlSanitizer`, `MediaService`, `UploadService`, `RemoteMediaService`, `ImageLibraryService`, `AssetService`, `RequestGuard` |
| `EditorService` | Auswahl/Integration SunEditor bzw. Editor.js |
| `PurifierService` | HTMLPurifier-Konfiguration für Rich-Text |
| `ContentLocalizationService` | Sprachkontext (`/en/…`), lokalisierte Pfade und Payloads |
| `ContentLanguageCopyService` | DE→EN-Kopie von Inhalten |
| `ContentMediaPlacementService` | Platzierung von Medien in Inhalten |
| `PermalinkService` | Beitrags-URLs aus der Permalink-Struktur (`/blog/%postname%` …) |
| `PageTemplateService` | Seitenvorlagen aus dem Theme-Manifest (`theme.json` → `page_templates`), Prüfung der Zusatzfelder |
| `CommentService` | Kommentare anlegen (pending), moderieren, durch Moderation bearbeiten (`updateByModerator()`), Flood-Limit, Admin-Benachrichtigung |
| `SiteTableService` + `SiteTable\…` | Tabellen und Hub-Sites rendern, Shortcodes, Export |
| `LandingPageService` + `Landing\…` | Landingpage-Daten, Plugin-Overrides |
| `ThemeCustomizer` | Theme-Optionen speichern/lesen, CSS generieren, Import/Export |
| `TranslationService` | UI-Übersetzungen (`CMS/lang/*.yaml`, Symfony Translation) |
| `PdfService` | PDF-Erzeugung mit Dompdf (`CMS/vendor/dompdf/`) |
| `FeedService` | öffentlicher RSS-2.0-Feed `/feed` mit Kategorie-, Tag- und Sprachvarianten (`serveRssFeed()`, Einstellungen `feeds.*`, ETag/304, Cache-Invalidierung über `FeatureHooks\FeedHooks`, [FEEDS.md](../admin/system-settings/FEEDS.md)) sowie natives RSS/Atom-Parsing für Plugins/Widgets |

### Suche & SEO

| Service | Aufgabe |
|---|---|
| `SiteSearchService` | Seitensuche `/search`: Vorauswahl per LIKE, Prüfung auf sichtbaren Text, Relevanz-/Datumssortierung, Plugin-Treffer (seit 3.4.06) |
| `SearchService` | TNTSearch-Volltextindex für Plugins (`search_register_indices`) |
| `SEOService` + `SEO\…` | Head-Ausgabe (Meta, OG, Twitter, Canonical, Hreflang), Schema.org, Sitemaps, Analytics-Einbindung, Einstellungen, Audit |
| `SeoAnalysisService` | SEO-/Lesbarkeitsanalyse im Editor, Auflösung von Titel-Variablen (`%title%`, `%%sitename%%`, seit 3.4.08) |
| `SeoBrokenLinkService` | stündlicher Broken-Link-Scan |
| `SeoTrendService` | SEO-Kennzahlen-Verlauf |
| `SitemapService` | Sitemap-Erzeugung mit `melbahja/seo` |
| `IndexingService` | IndexNow, Google-URL-Benachrichtigungen |
| `RedirectService` | Weiterleitungen, 404-Protokoll, automatische Slug-Redirects |

### Medien

| Service | Aufgabe |
|---|---|
| `MediaService` (+ `Media\MediaRepository`, `Media\UploadHandler`, `Media\ImageProcessor`) | Dateisystem-Bibliothek, Metadaten, Kategorien/Tags, Upload-Regeln, `.htaccess`-Schutz. Metadaten-Schreibzugriffe nur über `MediaRepository::updateMeta()` (Dateisperre) bzw. `setFileMeta()`/`mergeFileMeta()` je Datei |
| `MediaUsageService` | Verwendungsnachweis (Beitragsbilder, Inhalte) |
| `MediaDeliveryService` | geschützte Auslieferung `/media-file` |
| `FileUploadService` | allgemeiner Upload-Endpunkt `/api/upload` |
| `ImageService` | GD-Bildbearbeitung (Skalieren, WebP, Thumbnails) |
| `ElfinderService` | abgesicherter elFinder-Connector (nur wenn die Bibliothek vorhanden ist) |

### Benutzer, Mitglieder, Kommunikation

| Service | Aufgabe |
|---|---|
| `UserService` | Benutzer-CRUD, Sammelaktionen, Rollen, Statistik |
| `MemberService` | Profil, Privatsphäre, Datenexport, Löschantrag, Sicherheitsempfehlungen |
| `MessageService` | Nachrichten zwischen Mitgliedern |
| `JwtService` | JWT erzeugen/prüfen für die optionale Bearer-Anmeldung der API (`/api/v1/auth/token`, nur mit `JWT_SECRET`, siehe [API-REFERENCE.md](API-REFERENCE.md)) |
| `EmailVerificationService` | Bestätigung neuer Konten per Link (`/cms-verify-email`, Option `member_email_verification`, Status `unverified`), danach Zuordnung von Gastbestellungen |
| `EmailChangeService` | E-Mail-Änderung mit Bestätigungslink an die neue Adresse (`/cms-confirm-email-change`, 48 h), Hinweis an die alte Adresse, Zuordnung von Gastbestellungen (seit 4.0.0) |
| `CmsAuthPageService` | CMS-eigene Login-/Registrierungs-/Passwort-Seiten, Reset-Ablauf |

### Mail & Integration

| Service | Aufgabe |
|---|---|
| `MailService` | Versand über `mail()`/SMTP (Symfony Mailer), XOAUTH2 |
| `MailQueueService` | Warteschlange, Retry/Backoff, Cron-Token |
| `MailLogService` | Versandprotokoll |
| `AzureMailTokenProvider` | OAuth2-Token für SMTP mit Microsoft 365 |
| `GraphApiService` | Microsoft-Graph-Token (Client Credentials) |
| `AI\…` | KI-Dienste ([../ai/AI-SERVICES.md](../ai/AI-SERVICES.md)) |

### Sicherheit, Recht, Abos

| Service | Aufgabe |
|---|---|
| `SecurityRuntimeService` | Firewall und Rate-Limit je Request |
| `SecurityAlertService` | Alarm-Scans (Brute Force, Spam, Firewall) |
| `AntispamService` | Formular-Spamprüfung |
| `CookieConsentService` | Consent-Banner, Zustimmungsabfrage |
| `CoreModuleService` | Feature-Module an/aus ([../admin/system-settings/MODULES.md](../admin/system-settings/MODULES.md)) |
| `SettingsService` | gruppierte, optional verschlüsselte Einstellungen (Secrets über `CryptoService`) |
| `CryptoService` | authentifizierte Verschlüsselung gespeicherter Geheimnisse (Einstellungen, TOTP) und Backups, Schlüsselring mit `CMS_ENCRYPTION_KEY`/`CMS_ENCRYPTION_KEYS_PREVIOUS` (seit 4.0.0, [SECURITY.md](SECURITY.md)) |
| `TrustedProxyService` | vertrauenswürdige Proxys/CDNs, echte Client-IP (über `Security::getClientIp()`) und Länder-Header |

### Betrieb & Analyse

| Service | Aufgabe |
|---|---|
| `SystemService` | Systeminfo, Tabellenprüfung, Logs, Wartung |
| `StatusService` | Health-Checks (DB, Dateisystem, PHP, Sicherheit, Performance) |
| `DashboardService` | Kennzahlen für das Admin-Dashboard |
| `AnalyticsService`, `TrackingService`, `CoreWebVitalsService` | Seitenaufrufe, Besucher, Web Vitals |
| `FeatureUsageService` | Nutzung von Admin-Funktionen |
| `MonitoringTrendService` | Verläufe für Monitoring |
| `ErrorReportService` | Fehlerberichte |
| `BackupService` | Backups, Restore, Validierung; Ablageort `CMS_BACKUP_PATH` und optionale Verschlüsselung (seit 4.0.0, [../admin/system-settings/BACKUP.md](../admin/system-settings/BACKUP.md)) |
| `UpdateService` | Update-Prüfung und -Installation, Systemvoraussetzungen |
| `CronRunnerService`, `CronExpressionAdapter` | Cron-Tasks und -Ausdrücke |
| `OpcacheWarmupService` | OPcache-Vorwärmen |
| `AssetOptimizerService` | Asset-Versionierung/Minifizierung |
| `PerformanceSafetyNetService` | Snapshots/Rollback für Wartungsaktionen |
| `DataRetentionService` | tägliches Löschen alter Telemetrie- und Protokolldaten (Filter `cms_data_retention_days`); weitere Regeln über `registerRule()` (siehe unten) |
| `OptionStore` | Request-Cache für die Tabelle `settings` (Autoload-Werte in einer Abfrage) |
| `CsvExchange` | CSV-Export/-Import im Admin (BOM, Trennzeichen-Erkennung, Schutz vor Formel-Injection) |
| `MarketplaceEndpoints` | zentrale Marketplace- und Core-Update-URLs mit Fallback auf die offiziellen Werte |

### Admin-Oberfläche (seit 4.0.0)

| Service | Aufgabe |
|---|---|
| `AdminMenu\AdminMenuService` | Einstieg für den Menübaum der Seitenleiste ([ADMIN-MENU.md](../admin/ADMIN-MENU.md)): wählt Profil bzw. eigenes Layout (je Site oder global), liest Katalog und registrierte Plugin-Menüs ein (nur lesend) und cacht den Baum. Der Cache-Schlüssel enthält Rechte, Site, Profil, Layout-Version, Core-Module, vorhandene Seitendateien, Marketplace-Einstellung, Sprache und Plugin-Fingerprint |
| `AdminMenu\CoreCatalog`, `AdminMenu\ProfileLayouts` | Katalog aller Core-Seiten (Route, Capabilities, Seitendatei, Kachelkategorie, Badge) und die Bäume der Profile *Klassisch* und *Strukturiert* |
| `AdminMenu\MenuRegistry`, `AdminMenu\MenuBuilder` | Registry aus Katalog, Plugin-Menüs (Standard-Icon `puzzle`) und Wunschplätzen; der Builder setzt Layout, Rechte, Module und Plugin-Einträge zum Baum zusammen |
| `AdminMenu\LayoutValidator`, `AdminMenu\LayoutRepository` | prüft eigene Layouts (Editor, Import) bzw. speichert sie in der Tabelle `admin_menu_layouts` (global oder je Site, mit Version) |
| `AdminMenu\BadgeProvider` | Badge-Zähler (Updates, Kommentare, Protokolle, DSGVO-Anfragen); nie gecacht, weitere Quellen über `register()` |
| `AdminNavigationService` | bereitet den Baum für die Ausgabe auf (`build()`): aktiver Eintrag, Badges, Brotkrumen, Aufklappzustand (Abschnitte offen, Gruppen zu, aktive Gruppe offen), Kachelseiten und Plugin-Hinweis |
| `AdminUiPreferenceService` | Admin-Oberflächen-Präferenzen je Benutzer in `user_meta` (`admin_ui_prefs`): eingeklappte Seitenleiste, Gruppenzustände, gewählte Site |
| `AdminSiteContextService` | Site-Kontext der Administration (Sites aus Option `admin_sites`, gepflegt unter *Einstellungen › MultiSites*; ohne Einträge nur die Haupt-Site aus `SITE_URL`): Akzentfarben, `<html data-site>`, Site-Wechsel. Auflösung: Benutzer-Präferenz > Request-Host > erste Site |
| `PluginSiteAssignmentService` | Plugin-Zuweisung je Site (Option `admin_plugin_site_assignments`, ohne Eintrag = alle Sites), Zuordnung Menü → Plugin; Grundlage für den Hinweis-Banner (`partials/sidebar.php`), wenn eine Plugin-Seite in einer nicht zugewiesenen Site aufgerufen wird |
| `AdminNoticeService` | Systemhinweise für den Sidebar-Footer: letzter Cron-Lauf fehlgeschlagen, letztes Backup älter als 24 h oder keines vorhanden, Core-/Plugin-Updates; Cache 5 Minuten (Plugin-Update-Abfrage zusätzlich 1 Stunde). Weitere Prüfungen über `registerCheck()`, Einträge über `makeNotice()` |
| `PostPublishChecklistService` | Veröffentlichungs-Checkliste (Beitragsbild, Meta-Beschreibung 120–160 Zeichen, Kategorie), serverseitige Prüfung beim Veröffentlichen/Planen |
| `PostRevisionService` | Beitragsrevisionen (Tabelle `post_revisions`, max. 30 je Beitrag, Autosave-Revision höchstens alle 10 Minuten), Vergleich blockweise, Wiederherstellen als neue Revision |

### Erweiterungspunkte der Core-Funktionen (seit 4.0.0)

| Service | Aufgabe |
|---|---|
| `FeatureHooks\Registry` | registriert am Ende von `Bootstrap::initializeCore()` die Core-Funktionsbereiche: je Bereich eine Klasse `FeatureHooks\<Bereich>Hooks` mit `register()` (Routen, Cron, Inhalts-Hooks, Aufbewahrungsregeln, Systemhinweise). Noch nicht vorhandene Klassen werden übersprungen; wirft ein Bereich, bleiben die übrigen aktiv (Kanal `core.features`) |
| `BatchJobService` | lange Admin-Aufgaben in Schritten (Tabelle `batch_jobs`, [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md)): `start()`, `step()` mit Worker-Callback, `cancel()`, `findActive()`. Je Typ und Scope höchstens ein aktiver Auftrag; jeder Schritt läuft unter einer Sperre mit Ablaufzeit. Browser-Gegenstück: `assets/js/admin-batch-runner.js` |
| `PrivateStorageService` | privater Dateispeicher für geschützte Downloads, Formular-Uploads und Import-/Exportdateien: `CMS_PRIVATE_PATH` (absolut, empfohlen außerhalb des Webroots), sonst `CMS/private/`. `path('<bereich>')` legt Bereiche an, `resolve()` prüft Pfade per `realpath` gegen Traversal, `randomFilename()` vergibt nicht erratbare Namen. Jeder Ordner erhält `.htaccess` und `index.html`; Nginx siehe [../INSTALLATION.md](../INSTALLATION.md) |
| `IntegratedPluginService`, `PluginMigration\PluginMigrator` | in den Core übernommene Plugins (`cms-feed`, `cms-downloads`, `cms-contact`): aktive Exemplare werden deaktiviert (nicht gelöscht) und nicht mehr geladen. Ihre Daten übernimmt je Plugin ein idempotenter Migrator in Blöcken (bei Admin-Aufrufen mit 5 s Zeitbudget und im täglichen Cron mit 60 s). Alte Plugin-Adminseiten leiten per 301 auf die Core-Seiten um; der Sidebar-Footer zeigt 30 Tage lang den Hinweis „jetzt Teil des Cores“. Das alles greift je Plugin erst, wenn sein Core-Ersatz im Code vorhanden ist (`isIntegrated()` prüft die Migrator-Klasse); bis dahin läuft das Plugin unverändert weiter |
| `EditorJs\EditorJsBlockRegistry` | zusätzliche Editor.js-Blöcke aus Core-Funktionen (z. B. Download, Formular): ein Eintrag registriert das Tool-Skript (vor `editor-init.js`, Anmeldung über `window.CMS_EDITORJS_BLOCKS`), den Sanitizer beim Speichern und den Renderer. `render($type, $data, $tunes, $context)` übergibt den Kontext `['mode' => 'frontend']` bzw. `['mode' => 'preview']`; die Server-Vorschau im Editor läuft über `window.CMSBlockPreview` und `POST /admin/post-editor-api action=block_preview` ([../admin/ADMIN-API-AJAX.md](../admin/ADMIN-API-AJAX.md)) |
| `ContentTypes\ContentTypeRegistry` | Inhaltstyp-Provider für Papierkorb (`TrashProvider`), Export/Import (`ExchangeProvider`) und Suchindex (`SearchDocumentProvider`); Anmeldung über die Action `cms_register_content_type_providers`. Diese Funktionen enthalten keine Sonderfälle je Typ ([CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md)) |
| `Retention\RetentionRule` | Aufbewahrungsregel eines Funktionsbereichs (Frist, Trockenlauf-Zählung, Löschen oder Anonymisieren), registriert über `DataRetentionService::registerRule()`. Der tägliche Cron ruft `prune()` auf; die Seite *Löschfristen* (`/admin/data-retention`) zeigt Frist, Trockenlauf und letzten Lauf |

### Konventionen für eigene Services (Plugins)

- Singleton mit `getInstance()`, keine globalen Variablen.
- Datenbank nur über `CMS\Database` mit Platzhaltern.
- Fehler per `Logger::instance()->withChannel('plugin.<slug>')` protokollieren, Ergebnis-Arrays `['success' => bool, 'error' => '…']` zurückgeben.
- Ausgehende HTTP-Aufrufe über `CMS\Http\Client`.

### Verwandte Dokumente

[CORE-CLASSES.md](CORE-CLASSES.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md) · [CONTENT-TYPE-PROVIDERS.md](CONTENT-TYPE-PROVIDERS.md)
