# 365CMS – Projektdokumentation | Abschnitt: Core – Service-Schicht

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Business logic lives in `CMS/core/Services/` (namespace `CMS\Services`). Services are singletons (`Service::getInstance()`); the most important ones are also registered in the DI container under their class name and a short alias (`Bootstrap::initializeCore()`), e.g. `'mail'`, `'seo'`, `'search'`, `'settings'`. Larger domains are split into sub-namespaces: `AI\`, `EditorJs\`, `Landing\`, `Media\`, `SEO\`, `SiteTable\`. Admin modules and themes should call services instead of accessing tables directly.

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
| `CommentService` | Kommentare anlegen (pending), moderieren, Flood-Limit, Admin-Benachrichtigung |
| `SiteTableService` + `SiteTable\…` | Tabellen und Hub-Sites rendern, Shortcodes, Export |
| `LandingPageService` + `Landing\…` | Landingpage-Daten, Plugin-Overrides |
| `ThemeCustomizer` | Theme-Optionen speichern/lesen, CSS generieren, Import/Export |
| `TranslationService` | UI-Übersetzungen (`CMS/lang/*.yaml`, Symfony Translation) |
| `PdfService` | PDF-Erzeugung mit Dompdf (`CMS/vendor/dompdf/`) |
| `FeedService` | natives RSS/Atom-Parsing für Plugins/Widgets |

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
| `MediaService` (+ `Media\MediaRepository`, `Media\UploadHandler`, `Media\ImageProcessor`) | Dateisystem-Bibliothek, Metadaten, Kategorien/Tags, Upload-Regeln, `.htaccess`-Schutz |
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
| `JwtService` | JWT erzeugen/prüfen (vorbereitet, derzeit nicht in API-Routen verdrahtet) |
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
| `SettingsService` | gruppierte, optional verschlüsselte Einstellungen |

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
| `BackupService` | Backups, Restore, Validierung, E-Mail/S3 |
| `UpdateService` | Update-Prüfung und -Installation, Systemvoraussetzungen |
| `CronRunnerService`, `CronExpressionAdapter` | Cron-Tasks und -Ausdrücke |
| `OpcacheWarmupService` | OPcache-Vorwärmen |
| `AssetOptimizerService` | Asset-Versionierung/Minifizierung |
| `PerformanceSafetyNetService` | Snapshots/Rollback für Wartungsaktionen |

### Konventionen für eigene Services (Plugins)

- Singleton mit `getInstance()`, keine globalen Variablen.
- Datenbank nur über `CMS\Database` mit Platzhaltern.
- Fehler per `Logger::instance()->withChannel('plugin.<slug>')` protokollieren, Ergebnis-Arrays `['success' => bool, 'error' => '…']` zurückgeben.
- Ausgehende HTTP-Aufrufe über `CMS\Http\Client`.

### Verwandte Dokumente

[CORE-CLASSES.md](CORE-CLASSES.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md)
