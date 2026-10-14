# 365CMS – Projektdokumentation | Abschnitt: Core – Verzeichnisstruktur

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Layout of `CMS/core/` and its role inside the runtime directory `CMS/`. There are no `public/`, `resources/` or `storage/` directories: the web root is `CMS/` itself, writable runtime data lives in `CMS/cache/`, `CMS/logs/`, `CMS/uploads/`, `CMS/backups/` and `CMS/config/`, and third-party libraries are bundled under `CMS/assets/` (plus Dompdf under `CMS/vendor/`).

## Deutsch

### `CMS/core/`

```text
CMS/core/
├── autoload.php               # PSR-4-Autoloader für Namespace CMS\ (+ Klassenaliase)
├── Bootstrap.php  Container.php  Router.php  Hooks.php  Api.php
├── Database.php  SchemaManager.php  MigrationManager.php  DatabaseUpdateRunner.php
├── Security.php  Auth.php  Totp.php
├── CacheManager.php  Logger.php  AuditLogger.php  Debug.php  Json.php
├── PageManager.php  TableOfContents.php  ThemeManager.php  PluginManager.php  SubscriptionManager.php
├── VendorRegistry.php  Version.php  WP_Error.php
├── Auth/
│   ├── AuthManager.php
│   ├── LDAP/LdapAuthProvider.php
│   ├── MFA/TotpAdapter.php, BackupCodesManager.php
│   └── Passkey/WebAuthnAdapter.php
├── Contracts/                 # CacheInterface, DatabaseInterface, LoggerInterface
├── Http/                      # Client, Request, InlineStyleRewriter
├── Member/                    # PluginDashboardRegistry
├── Routing/                   # ApiRouter, AdminRouter, MemberRouter, PublicRouter, ThemeRouter, ThemeArchiveRepository
└── Services/                  # ~60 Fachservices
    ├── AI/ (+ Providers/)      # KI-Dienste
    ├── EditorJs/              # Editor.js-Hilfsklassen
    ├── Landing/               # Landingpage
    ├── Media/                 # Medienbibliothek
    ├── SEO/                   # SEO-Ausgabe, Sitemaps, Analytics
    └── SiteTable/             # Tabellen & Hub-Sites
```

### Einordnung in `CMS/`

| Verzeichnis | Inhalt | beschreibbar |
|---|---|---|
| `core/` | Kernklassen (dieses Dokument) | nein |
| `admin/` | Adminbereich ([../admin/FILESTRUCTURE.md](../admin/FILESTRUCTURE.md)) | nein |
| `member/` | Mitgliederbereich ([../member/README.md](../member/README.md)) | nein |
| `includes/` | globale Hilfsfunktionen | nein |
| `themes/` | Themes (`cms-default`) | durch Theme-Installation |
| `plugins/` | Plugins (`cms-importer`) | durch Plugin-Installation |
| `assets/` | gebündelte Bibliotheken, CSS/JS, Bilder | durch Updates |
| `vendor/` | Dompdf | durch Updates |
| `lang/` | `de.yaml`, `en.yaml` | nein |
| `install/` | Installer-Klassen und -Views | nein (nach Installation entfernen/sperren) |
| `marketplace/` | Beispiel-/Spiegel-Manifeste: `core/365cms/update.json`, `plugins/index.json`, `themes/index.json` | nein |
| `views/auth/` | Templates der CMS-eigenen Auth-Seiten (`CmsAuthPageService`) | nein |
| `config/` | `app.php`, `media-settings.json`, `media-meta.json` | **ja** |
| `cache/` | Dateicache, Schema-Flag | **ja** |
| `logs/` | Kanal-Logs, `error.log` | **ja** |
| `uploads/` | Medien, Mitglieder-Uploads, Schriften | **ja** |
| `backups/` | Backups, Performance-Snapshots | **ja** |

### Namespaces und Autoloading

| Namespace | Pfad | Loader |
|---|---|---|
| `CMS\` | `CMS/core/` | `core/autoload.php` |
| `CMS\Services\…`, `CMS\Routing\…`, `CMS\Auth\…` | Unterordner von `core/` | `core/autoload.php` |
| Vendor-Namespaces (`Symfony\…`, `HTMLPurifier`, `LdapRecord\…`, `Carbon\…`, `TeamTNT\TNTSearch\…`, `Melbahja\Seo\…` …) | `CMS/assets/<paket>/` | `assets/autoload.php` (über `VendorRegistry`) |
| `Dompdf\` | `CMS/vendor/dompdf/` | `vendor/dompdf/autoload.php` (bei Bedarf) |

Die Admin-Module (`CMS/admin/modules/…`) sind globale Klassen ohne Namespace und werden von ihren Einstiegsdateien per `require` geladen.

### Verwandte Dokumente

[CORE-CLASSES.md](CORE-CLASSES.md) · [../CMSFILESTRUCTUR.md](../CMSFILESTRUCTUR.md) · [../FILESTRUCTUR.md](../FILESTRUCTUR.md)
